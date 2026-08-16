<?php

declare(strict_types=1);

namespace AlgerianCommerce\Account;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Permissions\Capabilities;
use WP_Session_Tokens;
use WP_REST_Request;
use WP_User;

/**
 * The shopper's session — roadmap §59c, §44.
 *
 * **Nothing here is invented cryptography, and that is the point.** A session
 * token is a WordPress auth-cookie string — `wp_generate_auth_cookie()` in,
 * `wp_validate_auth_cookie()` out — which buys five properties for free, all
 * measured on 2026-08-16 before a line of this module was written:
 *
 * ``` text
 * a valid token          -> the user id
 * a tampered payload     -> false     (HMAC over wp_salt('logged_in'))
 * after logout           -> false     (bound to a WP_Session_Tokens entry)
 * after a password change-> false     (the HMAC covers a fragment of the hash)
 * after expiry           -> false
 * ``` text
 *
 * Writing our own would mean owning all five, and `Auth/AuthService` already
 * declined to reimplement credential storage for exactly this reason. The
 * revocation property is the one worth noticing: a shopper who changes their
 * password logs every stolen session out, and nothing in this file had to
 * arrange it.
 *
 * ## It is not a cookie, and §44 still holds
 *
 * SECURITY.md asks for HTTP-only cookies for browser authentication. This API
 * cannot set one that a browser would return: the storefront is a different
 * origin, and a cross-site cookie is exactly what §65's CSRF rule-out depends
 * on not existing. So the **token is returned in the response body and the
 * Next.js server puts it in its own HTTP-only cookie** — the browser never
 * holds it, which is the property §44 is protecting. The rule "do not expose
 * privileged credentials to browser JavaScript" is satisfied more strictly this
 * way than by a cookie this API could set.
 *
 * ## A customer session is not a staff session
 *
 * `authenticate()` refuses any account holding an `ac_*` capability, even with
 * the right password. Without that rule this endpoint would be a second login
 * for administrators — one that mints a bearer token, bypassing the Application
 * Passwords §44 chose and the brute-force guard that watches them. A shop
 * manager who is also a shopper needs two accounts, which is the same answer
 * every other admin API gives.
 */
final class AccountSession
{
    /** Where the token arrives, and the name the Next.js server should send. */
    public const HEADER = 'X-Customer-Token';

    /** A fallback for clients that cannot set headers; same token, same checks. */
    public const PARAM = 'customer_token';

    /** Two weeks, matching WordPress's own "remember me" window. */
    public const LIFETIME = 1209600;

    /** The only role this session may ever belong to. */
    public const ROLE = 'customer';

    /**
     * Verify an email and password, and refuse anything that is not a shopper.
     *
     * @throws ApiException 401 with one message for every failure — a login
     *         that says "no such account" tells an attacker which addresses are
     *         registered, which is a user-enumeration oracle on the shop's
     *         customer list.
     */
    public function authenticate(string $email, string $password): WP_User
    {
        $user = get_user_by('email', $email);

        if (!$user instanceof WP_User || !wp_check_password($password, $user->user_pass, $user->ID)) {
            throw ApiException::unauthenticated('Those credentials are not valid.');
        }

        if (!self::isShopper($user)) {
            // Deliberately the same message. Telling a staff account that it
            // exists but may not use this door is still an oracle.
            throw ApiException::unauthenticated('Those credentials are not valid.');
        }

        return $user;
    }

    /**
     * Mint a session for a user.
     *
     * The `WP_Session_Tokens` entry is what makes logout mean something: the
     * auth-cookie string embeds its id, and destroying the entry invalidates
     * the string wherever it has been copied to.
     *
     * @return array{token: string, expires_at: string}
     */
    public function issue(WP_User $user): array
    {
        $expiry = time() + self::LIFETIME;
        $sessionToken = WP_Session_Tokens::get_instance($user->ID)->create($expiry);

        return [
            'token' => wp_generate_auth_cookie($user->ID, $expiry, 'logged_in', $sessionToken),
            'expires_at' => gmdate('c', $expiry),
        ];
    }

    /**
     * The shopper this request belongs to, or null.
     *
     * **Never takes a user id from the caller**, which is roadmap §59c's first
     * rule and the reason `/account/orders` cannot be asked for somebody
     * else's: there is no parameter that could name them.
     */
    public function current(WP_REST_Request $request): ?WP_User
    {
        $token = self::tokenFrom($request);

        if ($token === '') {
            return null;
        }

        $userId = wp_validate_auth_cookie($token, 'logged_in');

        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        $user = get_user_by('id', $userId);

        // Re-checked on every request rather than trusted from the token. A
        // shopper promoted to staff, or an account whose role changed since it
        // signed in, must not keep a session this module would never issue.
        return $user instanceof WP_User && self::isShopper($user) ? $user : null;
    }

    /**
     * Establish the caller for this request.
     *
     * @throws ApiException 401 when there is no valid session
     */
    public function require(WP_REST_Request $request): WP_User
    {
        $user = $this->current($request);

        if ($user === null) {
            throw ApiException::unauthenticated();
        }

        // From here `get_current_user_id()` is the shopper, which is what
        // `Permissions::assertOwnsOr()` compares against. A `customer` holds
        // `read` and nothing else, so running the rest of the request as them
        // grants no capability this module has not already checked.
        wp_set_current_user($user->ID);

        return $user;
    }

    /**
     * End one session, leaving the shopper's other devices alone.
     *
     * Destroying every session on logout is a defensible choice and the wrong
     * default: signing out on a shared computer should not sign you out of your
     * phone. A password change already destroys all of them.
     */
    public function destroy(WP_REST_Request $request, WP_User $user): void
    {
        $token = self::tokenFrom($request);

        if ($token === '') {
            return;
        }

        $parts = wp_parse_auth_cookie($token, 'logged_in');

        if (is_array($parts) && isset($parts['token'])) {
            WP_Session_Tokens::get_instance($user->ID)->destroy((string) $parts['token']);
        }
    }

    /**
     * A shopper is an account with no administrative capability at all.
     *
     * Checked against the whole `ac_*` vocabulary rather than against the role
     * name, because a site owner can add a capability to any role and the
     * question this asks is "could this session do something staff-shaped",
     * not "what is this account called".
     */
    public static function isShopper(WP_User $user): bool
    {
        foreach (Capabilities::ALL as $capability) {
            if ($user->has_cap($capability)) {
                return false;
            }
        }

        return !$user->has_cap('manage_options') && !$user->has_cap('edit_posts');
    }

    private static function tokenFrom(WP_REST_Request $request): string
    {
        $header = (string) ($request->get_header(self::HEADER) ?? '');

        if (trim($header) !== '') {
            return trim($header);
        }

        return trim((string) ($request->get_param(self::PARAM) ?? ''));
    }
}
