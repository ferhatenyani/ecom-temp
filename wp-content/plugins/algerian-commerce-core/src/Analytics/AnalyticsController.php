<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Analytics endpoints — roadmap §63, docs/PLAN.md §27.
 *
 * Seven reads and no writes. Every route takes the same window arguments and
 * every route is gated on `ac_view_analytics`; the further check that protects
 * money lives in `AnalyticsService`, which is where docs/SECURITY.md puts the
 * second of the two authorization layers.
 *
 * There is deliberately no pagination. These are aggregates — a fixed number of
 * rows whatever the shop's size, with best sellers and the wilaya breakdown
 * capped in the service — so `paginationArgs()` would offer a page parameter
 * over a result that is already one page.
 */
final class AnalyticsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly AnalyticsService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::VIEW_ANALYTICS);

        foreach ([
            'overview' => 'overview',
            'revenue' => 'revenue',
            'orders' => 'orders',
            'products' => 'products',
            'customers' => 'customers',
            'shipping' => 'shipping',
            'cod' => 'cod',
        ] as $path => $method) {
            register_rest_route($this->restNamespace(), "/analytics/{$path}", [
                'methods' => 'GET',
                'callback' => $this->handle(fn (WP_REST_Request $request): WP_REST_Response
                    => $this->report($method, $request)),
                'permission_callback' => $guard,
                'args' => $this->rangeArgs(),
            ]);
        }
    }

    /**
     * The window, shared by all seven routes.
     *
     * Every arg with a `sanitize_callback` also declares a `validate_callback` —
     * see `AbstractController::paginationArgs()` for why leaving it out silently
     * disables `enum` and `pattern`. Here that would let `range=everything`
     * through to a query with no upper bound.
     *
     * The `pattern` only proves the *shape* of a date. Whether `2026-02-31` is a
     * real day is `AnalyticsRange`'s job, because it is the kind of check that
     * belongs next to the arithmetic it protects.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rangeArgs(): array
    {
        return [
            'range' => [
                'type' => 'string',
                'default' => AnalyticsRange::LAST_30,
                'enum' => AnalyticsRange::PRESETS,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'One of: ' . implode(', ', AnalyticsRange::PRESETS) . '.',
            ],
            'date_from' => $this->dateArg('Y-m-d. Required when range is custom.'),
            'date_to' => $this->dateArg('Y-m-d. Inclusive, in the shop\'s timezone.'),
        ];
    }

    /** @return array<string, mixed> */
    private function dateArg(string $description): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => $description,
        ];
    }

    private function report(string $method, WP_REST_Request $request): WP_REST_Response
    {
        /** @var array{data: array<string, mixed>, meta: array<string, mixed>} $report */
        $report = $this->service->{$method}([
            'range' => (string) $request->get_param('range'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ]);

        return Response::success($report['data'], 200, $report['meta']);
    }
}
