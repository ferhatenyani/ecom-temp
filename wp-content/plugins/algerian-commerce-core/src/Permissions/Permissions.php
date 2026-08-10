<?php

declare(strict_types=1);

namespace AlgerianCommerce\Permissions;

use AlgerianCommerce\API\ApiException;
use WP_Error;

/**
 * Authorization checks for routes and services.
 *
 * Two layers, both required by docs/SECURITY.md:
 *
 *  - `callback()` guards the route. Nothing reaches a controller without it.
 *  - `assert()` guards the operation inside a service, including object-level
 *    checks. Holding `ac_manage_orders` does not imply access to *that*
 *    order — re-checking at the point of use is the IDOR defence.
 */
final class Permissions
{
    /**
     * Build a permission_callback for register_rest_route().
     *
     * Returns WP_Error rather than false so the response carries a real
     * status: 401 when nobody is signed in, 403 when they are but lack the
     * capability. ErrorNormalizer rewrites both into our envelope.
     */
    public static function callback(string $capability): callable
    {
        return static function () use ($capability): bool|WP_Error {
            if (!is_user_logged_in()) {
                return new WP_Error(
                    'rest_not_logged_in',
                    'Authentication is required.',
                    ['status' => 401]
                );
            }

            if (!current_user_can($capability)) {
                return new WP_Error(
                    'rest_forbidden',
                    'You are not allowed to perform this action.',
                    ['status' => 403]
                );
            }

            return true;
        };
    }

    public static function can(string $capability): bool
    {
        return is_user_logged_in() && current_user_can($capability);
    }

    /**
     * Service-layer check.
     *
     * @throws ApiException 401 when unauthenticated, 403 when not permitted.
     */
    public static function assert(string $capability): void
    {
        if (!is_user_logged_in()) {
            throw ApiException::unauthenticated();
        }

        if (!current_user_can($capability)) {
            throw ApiException::forbidden();
        }
    }

    /**
     * Object-level check for resources with an owner.
     *
     * The capability alone is not enough: a customer may read their own
     * order, while reading anyone else's requires the management capability.
     *
     * @throws ApiException
     */
    public static function assertOwnsOr(int $ownerId, string $capability): void
    {
        if (!is_user_logged_in()) {
            throw ApiException::unauthenticated();
        }

        if (get_current_user_id() === $ownerId) {
            return;
        }

        if (!current_user_can($capability)) {
            throw ApiException::forbidden();
        }
    }
}
