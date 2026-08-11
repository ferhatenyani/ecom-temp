<?php

declare(strict_types=1);

namespace AlgerianCommerce\Auth;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /auth/me — who am I, and what may I do here.
 *
 * The endpoint the Next.js server calls once at startup to confirm its
 * Application Password is valid and carries the capabilities it expects,
 * rather than discovering a misconfigured credential on the first customer
 * order.
 *
 * The returned capability list is for **rendering**, never for access control.
 * Every route enforces its own `permission_callback` and every service calls
 * `Permissions::assert()`; a client that hides a button is a convenience, not
 * a security boundary (docs/SECURITY.md).
 */
final class AuthController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly AuthService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->restNamespace(), '/auth/me', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'me']),
            /*
             * Authentication only, no capability. Every signed-in caller may
             * ask who they are — that is the point — and the response is
             * scoped to the caller's own record, so there is nothing here a
             * capability could usefully gate.
             */
            'permission_callback' => static function (): bool|WP_Error {
                if (!is_user_logged_in()) {
                    return new WP_Error(
                        'rest_not_logged_in',
                        'Authentication is required.',
                        ['status' => 401]
                    );
                }

                return true;
            },
        ]);
    }

    public function me(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->me()->toArray());
    }
}
