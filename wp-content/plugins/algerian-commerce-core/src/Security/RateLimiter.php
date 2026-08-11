<?php

declare(strict_types=1);

namespace AlgerianCommerce\Security;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;

/**
 * Rate limiting — docs/SECURITY.md, docs/PLAN.md §35.
 *
 * Two counters run for every guarded request: one keyed on the authenticated
 * identity and one on the source IP. SECURITY.md asks for both because they
 * catch different things — the identity counter bounds a single compromised
 * credential, the IP counter bounds an unauthenticated flood that has no
 * identity yet. The stricter of the two decides.
 *
 * Limits are configurable, because the right number depends on how the client
 * is built: a Next.js admin rendering a dashboard makes far more calls per
 * operator than a storefront does per shopper.
 */
final class RateLimiter
{
    public const DEFAULT_READS = 600;
    public const DEFAULT_WRITES = 120;
    public const DEFAULT_AUTH_FAILURES = 10;

    public const WINDOW = 60;
    public const AUTH_FAILURE_WINDOW = 900;

    public function __construct(
        private readonly RateLimitStore $store,
        private readonly Logger $logger,
        private readonly Config $config
    ) {
    }

    public function readLimit(): RateLimit
    {
        return new RateLimit('read', $this->limitFrom('AC_RATE_LIMIT_READS', self::DEFAULT_READS), self::WINDOW);
    }

    public function writeLimit(): RateLimit
    {
        return new RateLimit('write', $this->limitFrom('AC_RATE_LIMIT_WRITES', self::DEFAULT_WRITES), self::WINDOW);
    }

    /**
     * Failed authentication attempts, per IP.
     *
     * Enabling Application Passwords put a credential-guessing surface on the
     * public internet, and WordPress does not throttle it. This is the counter
     * that closes that.
     */
    public function authFailureLimit(): RateLimit
    {
        return new RateLimit(
            'authfail',
            $this->limitFrom('AC_RATE_LIMIT_AUTH_FAILURES', self::DEFAULT_AUTH_FAILURES),
            self::AUTH_FAILURE_WINDOW
        );
    }

    public function isDisabled(): bool
    {
        return Config::isTruthy($this->config->get('AC_RATE_LIMIT_DISABLED'));
    }

    /**
     * Addresses exempt from the authentication lockout.
     *
     * The lockout is keyed on IP, so an attacker guessing against a known
     * service account will lock that account out for everyone sharing the
     * address — including the Next.js server itself. That is the accepted
     * trade every brute-force defence makes, and this is the escape hatch it
     * has to come with: put the application server's address in
     * `AC_RATE_LIMIT_TRUSTED_IPS` and its credential keeps working while
     * everyone else is throttled.
     *
     * Ordinary read/write limits still apply to a trusted address — this
     * exempts it from being locked *out*, not from being rate limited.
     */
    public function isTrusted(string $ip): bool
    {
        return $ip !== '' && in_array($ip, self::parseIpList($this->config->get('AC_RATE_LIMIT_TRUSTED_IPS')), true);
    }

    /**
     * Pure so the parsing is testable: a malformed entry is dropped rather
     * than honoured, so a typo cannot silently exempt something unintended.
     *
     * @return list<string>
     */
    public static function parseIpList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $valid = [];

        foreach (explode(',', $raw) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $valid[] = $candidate;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * Record a request and throw when the caller is over the limit.
     *
     * @param string $identifier who is being counted — a user id or an IP
     *
     * @throws ApiException 429 with a Retry-After hint in the details
     */
    public function enforce(RateLimit $limit, string $identifier, int $now, string $context = ''): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $key = $limit->keyFor($identifier, $now);
        $used = $this->store->hit($key, $limit->secondsUntilReset($now));

        if (!$limit->isExceeded($used)) {
            return;
        }

        $retryAfter = $limit->secondsUntilReset($now);

        /*
         * Rejections are logged: a blocked request leaves no other server-side
         * trace, and the pattern of them is what tells an operator the
         * difference between one broken client and a credential under attack.
         * The identifier is not logged — SECURITY.md forbids unnecessary PII,
         * and the hashed key is enough to correlate repeats.
         */
        $this->logger->warning('Rate limit exceeded', [
            'limit' => $limit->name,
            'allowed' => $limit->limit,
            'window' => $limit->windowSeconds,
            'context' => $context,
            'key' => $key,
        ]);

        throw new ApiException(
            'too_many_requests',
            'Too many requests. Please retry shortly.',
            429,
            ['retry_after' => $retryAfter]
        );
    }

    /** Count a failed authentication without throwing — the caller decides. */
    public function recordAuthFailure(string $ip, int $now): int
    {
        if ($this->isDisabled() || $ip === '' || $this->isTrusted($ip)) {
            return 0;
        }

        $limit = $this->authFailureLimit();

        return $this->store->hit($limit->keyFor($ip, $now), $limit->secondsUntilReset($now));
    }

    public function authFailuresFor(string $ip, int $now): int
    {
        if ($ip === '') {
            return 0;
        }

        $limit = $this->authFailureLimit();

        return $this->store->peek($limit->keyFor($ip, $now));
    }

    public function isAuthBlocked(string $ip, int $now): bool
    {
        if ($this->isDisabled() || $ip === '' || $this->isTrusted($ip)) {
            return false;
        }

        return $this->authFailureLimit()->isExceeded($this->authFailuresFor($ip, $now) + 1);
    }

    private function limitFrom(string $key, int $default): int
    {
        $value = $this->config->get($key);

        if ($value === null || !ctype_digit(trim($value)) || (int) trim($value) < 1) {
            return $default;
        }

        return (int) trim($value);
    }
}
