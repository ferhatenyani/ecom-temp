<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\API\Response;
use WP_REST_Request;

use const AlgerianCommerce\REST_NAMESPACE;

/**
 * Refuses every request from a suspended staff account.
 *
 * Suspension has to be enforced somewhere that runs before any route, or it is
 * a flag the panel renders and the API ignores. Two candidates, and the choice
 * matters:
 *
 *  - `rest_authentication_errors` is where core reports a bad credential, and
 *    it is the obvious home. But `rest_do_request()` does not fire it — it goes
 *    straight to `WP_REST_Server::dispatch()` — so every in-process suite in
 *    `tests/Api` would be blind to this guard, and a security property that
 *    only the HTTP stage can see is one that gets verified once.
 *  - `rest_pre_dispatch` runs inside `dispatch()`, so it fires for both, and
 *    returning a response short-circuits the route. That is what
 *    `RateLimitGuard::guard()` already does.
 *
 * So: `rest_pre_dispatch`, at priority 9 — before the rate limiter at 10, since
 * a refused account should not spend somebody else's allowance.
 *
 * **Scoped to this plugin's namespace**, as the CORS handler and the rate
 * limiter are. A suspended account can still reach `/wp/v2` and wp-admin, which
 * is a named limitation rather than an oversight: revoking platform access is
 * WordPress's own job and doing it from here would mean this plugin deciding
 * who may log into a dashboard it does not own. Revoke the account's
 * application passwords to close that door — `DELETE
 * /users/{id}/application-passwords/{uuid}`.
 */
final class SuspensionGuard
{
    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'guard'], 9, 3);
    }

    /**
     * @param mixed $result short-circuits dispatch when a response is returned
     * @return mixed
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

        $userId = get_current_user_id();

        if ($userId <= 0 || !UserStatus::isSuspended($userId)) {
            return $result;
        }

        /*
         * 401 rather than 403: the credential is no longer valid, which is a
         * different fact from "this credential may not do that" and is what
         * tells a client to stop retrying and sign in again. `docs/API.md`
         * maps 401 to clearing the session, which is exactly right here.
         *
         * The code is specific because the panel has something useful to say —
         * "your account has been suspended" is actionable in a way that
         * "signed out" is not, and it is not a disclosure: the caller already
         * holds the credential for the account it describes.
         */
        return Response::fromException(new ApiException(
            'account_suspended',
            'This account is suspended.',
            401
        ));
    }
}
