<?php

declare(strict_types=1);

namespace AlgerianCommerce\Auth;

use AlgerianCommerce\API\ApiException;

/**
 * Identity for the authenticated caller — roadmap §44, docs/PLAN.md §3.
 *
 * There is deliberately no login endpoint here. Authentication is WordPress's
 * job and it already does it: an Application Password sent as HTTP Basic auth
 * is verified by core on `determine_current_user`, before any route in this
 * plugin runs. Reimplementing that — issuing our own tokens, storing our own
 * hashes — would mean owning a credential store, rotation and revocation that
 * core already provides and that security researchers already audit.
 *
 * What core does not provide is a way for a client to ask *what this
 * credential is allowed to do here*, which is what this service answers.
 */
final class AuthService
{
    public const METHOD_APPLICATION_PASSWORD = 'application_password';
    public const METHOD_COOKIE = 'cookie';

    /**
     * The caller's own identity.
     *
     * Self-introspection only. It takes no user id argument on purpose: an
     * endpoint that let a caller name someone else would be a directory of
     * every account and its capabilities, reachable by the least privileged
     * credential in the system.
     */
    public function me(): Identity
    {
        $user = wp_get_current_user();

        if (!$user instanceof \WP_User || $user->ID === 0) {
            throw ApiException::unauthenticated();
        }

        return Identity::create(
            (int) $user->ID,
            (string) $user->user_login,
            (string) $user->display_name,
            (string) $user->user_email,
            array_values((array) $user->roles),
            (array) $user->allcaps,
            $this->authMethod()
        );
    }

    /**
     * How this request authenticated.
     *
     * `rest_get_authenticated_app_password()` returns the uuid of the
     * application password core accepted, or false when the session came from
     * a cookie. The uuid itself is not returned to the client — it identifies
     * a specific credential, and naming it in a response body only helps
     * someone who has already stolen it.
     */
    private function authMethod(): string
    {
        if (function_exists('rest_get_authenticated_app_password')
            && rest_get_authenticated_app_password() !== false
        ) {
            return self::METHOD_APPLICATION_PASSWORD;
        }

        return self::METHOD_COOKIE;
    }
}
