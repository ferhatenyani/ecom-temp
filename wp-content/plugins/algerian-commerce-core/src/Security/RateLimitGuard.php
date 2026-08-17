<?php

declare(strict_types=1);

namespace AlgerianCommerce\Security;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Config;
use WP_REST_Request;
use WP_REST_Response;

use const AlgerianCommerce\REST_NAMESPACE;

/**
 * Wires the rate limiter into WordPress.
 *
 * Scoped to `algerian-commerce/v1` only. Throttling `wp/v2` or `wc/v3` would
 * change behaviour other code depends on, exactly as the CORS handler is
 * scoped to our namespace and leaves core's alone.
 */
final class RateLimitGuard
{
    public function __construct(
        private readonly RateLimiter $limiter,
        /* Optional, defaulting to the pre-§86 behaviour. See clientIp(). */
        private readonly ?Config $config = null
    ) {
    }

    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'guard'], 10, 3);

        /*
         * Core fires this for every rejected Application Password, which is
         * the only place a failed credential guess is visible — by the time a
         * request reaches rest_pre_dispatch it simply looks unauthenticated.
         */
        add_action('application_password_failed_authentication', [$this, 'recordAuthFailure']);

        /*
         * `rest_pre_dispatch` is too late to stop credential guessing. When
         * Basic auth credentials are present and wrong, core turns that into a
         * `rest_authentication_errors` failure and serves 401 without ever
         * dispatching — so a guard that only runs at dispatch watches an
         * attacker walk past it, returning 401 to every attempt forever.
         *
         * These two hooks are what actually close it:
         *
         *  - `wp_is_application_passwords_available` makes core refuse to
         *    *attempt* application-password auth for a blocked address, so the
         *    password hash comparison never runs. That is the expensive part,
         *    and skipping it is what stops the endpoint being a CPU oracle.
         *  - `rest_authentication_errors` replaces the resulting 401 with a
         *    429, so a client is told to back off rather than to keep guessing.
         */
        add_filter('wp_is_application_passwords_available', [$this, 'blockApplicationPasswords'], 10, 1);
        add_filter('rest_authentication_errors', [$this, 'rejectBlockedAuth'], 5, 1);
        add_filter('rest_post_dispatch', [$this, 'addRetryAfterHeader'], 10, 1);
    }

    /**
     * Refuse application-password auth outright for an address that has spent
     * its failure budget.
     *
     * Scoped to this plugin's namespace: an unrelated integration
     * authenticating against `wp/v2` must not be locked out by traffic aimed
     * at us, the same way the CORS handler leaves core's namespaces alone.
     *
     * @param bool $available
     */
    public function blockApplicationPasswords(mixed $available): bool
    {
        if (!$available || !$this->isOurNamespaceRequest()) {
            return (bool) $available;
        }

        return !$this->limiter->isAuthBlocked($this->clientIp(), time());
    }

    /**
     * Turn a blocked address's 401 into a 429 with a Retry-After hint.
     *
     * @param mixed $errors null when authentication succeeded or was not attempted
     */
    public function rejectBlockedAuth(mixed $errors): mixed
    {
        if (is_user_logged_in() || !$this->isOurNamespaceRequest()) {
            return $errors;
        }

        $now = time();

        if (!$this->limiter->isAuthBlocked($this->clientIp(), $now)) {
            return $errors;
        }

        return new \WP_Error(
            'too_many_requests',
            'Too many failed authentication attempts. Please retry later.',
            [
                'status' => 429,
                'retry_after' => $this->limiter->authFailureLimit()->secondsUntilReset($now),
            ]
        );
    }

    /**
     * A WP_Error served by the REST server carries no Retry-After of its own,
     * so it is attached on the way out.
     *
     * `get_headers()` returns only the headers that were set, so the absent
     * case has to be coalesced rather than compared: reading the key directly
     * raised an "undefined array key" warning on the very path this exists for.
     */
    public function addRetryAfterHeader(mixed $response): mixed
    {
        if ($response instanceof WP_REST_Response
            && $response->get_status() === 429
            && ($response->get_headers()['Retry-After'] ?? null) === null
        ) {
            $response->header('Retry-After', (string) $this->limiter->authFailureLimit()->secondsUntilReset(time()));
        }

        return $response;
    }

    /**
     * Whether the request in flight targets this plugin's namespace.
     *
     * `rest_authentication_errors` receives no request object, so the route is
     * read from the query var WordPress parsed it into, falling back to the
     * raw URI for the `?rest_route=` form.
     */
    private function isOurNamespaceRequest(): bool
    {
        $route = '';

        if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && isset($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
        }

        if ($route === '') {
            $route = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }

        return str_contains($route, REST_NAMESPACE);
    }

    /**
     * @param mixed $result
     * @return mixed short-circuits dispatch when a WP_REST_Response is returned
     */
    public function guard(mixed $result, mixed $server, WP_REST_Request $request): mixed
    {
        // Something earlier already produced a response; do not override it.
        if ($result !== null) {
            return $result;
        }

        if (!str_starts_with(ltrim($request->get_route(), '/'), REST_NAMESPACE)) {
            return $result;
        }

        $now = time();
        $ip = $this->clientIp();

        /*
         * An IP that has burned through its failed-authentication allowance is
         * refused before the route runs.
         *
         * This blocks the *next* attempt rather than the current one: core
         * verifies the password on `determine_current_user`, long before
         * dispatch, so by the time we get here the expensive hash comparison
         * has already happened. Bounding the sustained rate is the goal, and
         * that it does.
         */
        if (!is_user_logged_in() && $this->limiter->isAuthBlocked($ip, $now)) {
            return $this->reject(
                new ApiException(
                    'too_many_requests',
                    'Too many failed authentication attempts. Please retry later.',
                    429,
                    ['retry_after' => $this->limiter->authFailureLimit()->secondsUntilReset($now)]
                )
            );
        }

        $isWrite = !in_array(strtoupper($request->get_method()), ['GET', 'HEAD', 'OPTIONS'], true);
        $limit = $isWrite ? $this->limiter->writeLimit() : $this->limiter->readLimit();

        try {
            /*
             * Both counters, as SECURITY.md requires. The identity counter
             * bounds one compromised credential; the IP counter bounds a flood
             * that has no identity yet. An unauthenticated caller is counted
             * by IP alone — there is no identity to key on.
             */
            $userId = get_current_user_id();

            if ($userId > 0) {
                $this->limiter->enforce($limit, 'user:' . $userId, $now, $request->get_route());
            }

            if ($ip !== '') {
                $this->limiter->enforce($limit, 'ip:' . $ip, $now, $request->get_route());
            }
        } catch (ApiException $exception) {
            return $this->reject($exception);
        }

        return $result;
    }

    public function recordAuthFailure(mixed $error = null): void
    {
        $this->limiter->recordAuthFailure($this->clientIp(), time());
    }

    /**
     * A 429 carrying Retry-After, which is what makes a well-behaved client
     * back off instead of hammering.
     */
    private function reject(ApiException $exception): WP_REST_Response
    {
        $response = Response::fromException($exception);

        $retryAfter = $exception->details()['retry_after'] ?? null;

        if (is_int($retryAfter)) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * One rule, in `Security\ClientIp`.
     *
     * **The "revisit when a trusted proxy exists" this used to end with is what
     * §86 did.** The old reasoning — X-Forwarded-For is client-controlled, and
     * trusting it lets an attacker rotate the header for a fresh allowance on
     * every request — is exactly why `ClientIp` reads it only from an address
     * in `AC_TRUSTED_PROXIES`.
     *
     * Both directions matter here and only here. Reading the header when we
     * should not hands out unlimited requests; *not* reading it behind a proxy
     * collapses every caller into one bucket, so one shopper exhausts the
     * limit for the shop and the 10-failure lockout locks out everybody at once.
     */
    private function clientIp(): string
    {
        return ClientIp::resolve($_SERVER, $this->config?->get('AC_TRUSTED_PROXIES'));
    }
}
