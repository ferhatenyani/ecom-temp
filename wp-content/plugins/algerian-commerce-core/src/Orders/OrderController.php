<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Order endpoints — roadmap §50.
 *
 * There is no DELETE. An order is cancelled, never removed: it is the record
 * of a commercial event, and the accounting, the courier and the customer's
 * history all continue to refer to it after the shop stops working on it.
 */
final class OrderController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly OrderService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_ORDERS);

        register_rest_route($this->restNamespace(), '/orders', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
                'args' => $this->indexArgs(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'store']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'cancel']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/notes', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'notes']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->limitArg(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storeNote']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/timeline', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'timeline']),
            'permission_callback' => $guard,
            'args' => $this->idArg() + $this->limitArg(),
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'show']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'update']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);
    }

    /**
     * Every arg with a sanitize_callback also declares a validate_callback —
     * see AbstractController::paginationArgs() for why leaving it out silently
     * disables `enum` and `pattern`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexArgs(): array
    {
        return $this->paginationArgs() + [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'type' => 'string',
                'enum' => OrderStatus::ALL,
                'validate_callback' => 'rest_validate_request_arg',
                // No sanitize_callback: sanitize_key() would strip the hyphen
                // out of "on-hold" and the enum would then reject a status the
                // caller spelled correctly.
            ],
            'customer_id' => [
                'type' => 'integer',
                'minimum' => 1,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'date_from' => $this->dateArg(),
            'date_to' => $this->dateArg(),
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => OrderRepository::ORDERBY,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    /**
     * A depth limit rather than `page`/`per_page`.
     *
     * Notes and the timeline are read newest-first as a feed, and the timeline
     * merges three stores that cannot share an offset — page 2 of a merged feed
     * would need a cursor per source. "The newest N" is what a client actually
     * wants here, and it is correct without one.
     *
     * @return array<string, array<string, mixed>>
     */
    private function limitArg(): array
    {
        return [
            'limit' => [
                'type' => 'integer',
                'default' => OrderService::DEFAULT_NOTES,
                'minimum' => 1,
                'maximum' => OrderService::MAX_NOTES,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dateArg(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => 'Y-m-d. Both ends cover the whole day.',
        ];
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'status' => (string) $request->get_param('status'),
            'customer_id' => (int) $request->get_param('customer_id'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            OrderPresenter::toArrayList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(OrderPresenter::toArray(
            $this->service->get((int) $request->get_param('id'))
        ));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            OrderPresenter::toArray($this->service->create($this->payload($request))),
            201
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(OrderPresenter::toArray(
            $this->service->update((int) $request->get_param('id'), $this->payload($request))
        ));
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->payload($request);
        $reason = is_scalar($payload['reason'] ?? null) ? trim((string) $payload['reason']) : '';

        return Response::success(OrderPresenter::toArray(
            $this->service->cancel((int) $request->get_param('id'), $reason)
        ));
    }

    public function notes(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->notes(
            (int) $request->get_param('id'),
            (int) $request->get_param('limit')
        ));
    }

    public function storeNote(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->addNote((int) $request->get_param('id'), $this->payload($request)),
            201
        );
    }

    public function timeline(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->timeline(
            (int) $request->get_param('id'),
            (int) $request->get_param('limit')
        ));
    }

    /**
     * The JSON body only. Route and query parameters are not order fields, and
     * folding them in would let `?status=completed` masquerade as one.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
