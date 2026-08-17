<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tracking;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The public tracking route — roadmap §84.
 *
 * One route, and it is `__return_true` for the reason `CartController` and
 * `AccountController` are: **no capability can express "whoever holds this
 * link"**. A guest has no WordPress account, §44 forbids giving one an
 * Application Password, and requiring staff auth would mean the storefront
 * proxying every tracking page load with an admin credential — the arrangement
 * §44 exists to prevent.
 *
 * So the token is the authorization, checked one layer down in
 * `TrackingService::track()`, and because the route table cannot show that,
 * `tests/Api/tracking.php` calls this route with no token, a malformed token, a
 * token whose MAC has been altered, another order's token and an expired one —
 * each against a positive control with the real token.
 *
 * It is registered on `/orders/track` rather than `/track/{token}` on purpose.
 * A token in a *path* lands in web-server access logs, proxy logs and browser
 * history as part of the resource name; a query parameter does too, which is
 * why the route also accepts it in a header. But the deciding reason is
 * narrower: `/orders/(?P<id>\d+)` already exists and reads an order behind
 * `ac_manage_orders`, so a sibling literal segment keeps the public and private
 * order routes visibly adjacent in the router and in docs/API.md — where anybody
 * reviewing guards will see them together.
 */
final class TrackingController extends AbstractController
{
    /**
     * Where a token may arrive instead of the query string.
     *
     * Offered because a query parameter is written to access logs and to
     * `Referer` on every outbound link from the tracking page; a header is not.
     * The query parameter stays because an email client's link cannot set one,
     * which is where these tokens actually come from.
     */
    public const HEADER = 'X-Tracking-Token';

    public const PARAM = 'token';

    public function __construct(
        Logger $logger,
        private readonly TrackingService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->restNamespace(), '/orders/track', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'track']),
            // See the class docblock: the token is the owner, and the check is
            // TrackingService::track(). Not the same thing as unguarded.
            'permission_callback' => '__return_true',
            'args' => [
                self::PARAM => [
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                    // Shape only, so a nonsense value is refused before it costs
                    // a database read. `TrackingToken` is what decides whether a
                    // well-shaped token is genuine.
                    'maxLength' => 64,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'The tracking token. The ' . self::HEADER . ' header takes precedence.',
                ],
            ],
        ]);
    }

    public function track(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->track($request, self::tokenFrom($request)));
    }

    /** Header first, query parameter second; both are the same token. */
    private static function tokenFrom(WP_REST_Request $request): string
    {
        $header = trim((string) ($request->get_header(self::HEADER) ?? ''));

        return $header !== '' ? $header : trim((string) ($request->get_param(self::PARAM) ?? ''));
    }
}
