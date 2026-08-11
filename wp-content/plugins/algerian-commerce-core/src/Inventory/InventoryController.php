<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use DateTimeImmutable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inventory endpoints — roadmap §49, docs/PLAN.md §7.
 *
 * Every route carries ac_manage_inventory, which Product Manager and Manager
 * hold but Support Agent and Marketing Manager do not: stock is a number that
 * decides whether the shop can sell, and it is a separate grant from
 * ac_manage_products for that reason.
 *
 * Note the split between PATCH /inventory/{id} and POST /inventory/{id}/adjust
 * — settings against quantity. It is not decoration: routing every quantity
 * change through one endpoint is what guarantees the movement ledger has no
 * gaps.
 */
final class InventoryController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly InventoryService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_INVENTORY);

        register_rest_route($this->restNamespace(), '/inventory', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => $guard,
            'args' => $this->indexArgs(),
        ]);

        /*
         * Literal segments are registered before the {id} pattern. The pattern
         * is \d+ so "movements" could not match it today, but a future
         * non-numeric id would silently turn these into product lookups.
         */
        register_rest_route($this->restNamespace(), '/inventory/low-stock', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'lowStock']),
            'permission_callback' => $guard,
            'args' => $this->lowStockArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/inventory/movements', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'movements']),
            'permission_callback' => $guard,
            'args' => $this->movementArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/inventory/movements/summary', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'movementSummary']),
            'permission_callback' => $guard,
            'args' => $this->movementFilterArgs(),
        ]);

        /*
         * SKU arrives as a query parameter, not a path segment. WooCommerce
         * SKUs are free text and routinely contain "/", which no amount of
         * encoding makes safe to carry in a WordPress REST route pattern.
         */
        register_rest_route($this->restNamespace(), '/inventory/lookup', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'lookup']),
            'permission_callback' => $guard,
            'args' => [
                'sku' => [
                    'type' => 'string',
                    'required' => true,
                    'minLength' => 1,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/inventory/bulk', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'bulk']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/inventory/(?P<id>\d+)/adjust', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'adjust']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/inventory/(?P<id>\d+)', [
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

    /** @return array<string, array<string, mixed>> */
    private function indexArgs(): array
    {
        return $this->paginationArgs() + $this->idArg('category', false) + [
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sku' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'type' => 'string',
                'enum' => InventoryRepository::STATUSES,
            ],
            // The out-of-stock report is this filter; there is no separate
            // route because it is the ordinary query with one value set.
            'stock_status' => [
                'type' => 'string',
                'enum' => InventorySettingsInput::STOCK_STATUSES,
            ],
            'manage_stock' => [
                'type' => 'boolean',
            ],
            'include_variations' => [
                'type' => 'boolean',
                'default' => false,
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => ['date', 'id', 'title', 'sku'],
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function lowStockArgs(): array
    {
        return $this->paginationArgs() + [
            'status' => [
                'type' => 'string',
                'enum' => InventoryRepository::STATUSES,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function movementFilterArgs(): array
    {
        $filters = [
            'reason' => [
                'type' => 'string',
                // Every reason, not just the manual ones: the ledger is read
                // in full even though only some reasons may be written.
                'enum' => MovementReason::all(),
            ],
            'date_from' => [
                'type' => 'string',
                'format' => 'date',
                'validate_callback' => [$this, 'validateDate'],
            ],
            'date_to' => [
                'type' => 'string',
                'format' => 'date',
                'validate_callback' => [$this, 'validateDate'],
            ],
        ];

        return $this->idArg('product_id', false)
            + $this->idArg('order_id', false)
            + $this->idArg('actor_id', false)
            + $filters;
    }

    /** @return array<string, array<string, mixed>> */
    private function movementArgs(): array
    {
        return $this->paginationArgs() + $this->movementFilterArgs();
    }

    /**
     * Y-m-d, and a real date.
     *
     * The repository widens these to cover a whole day by appending a time, so
     * anything that is not exactly Y-m-d would build a nonsense comparison
     * string rather than fail loudly.
     */
    public function validateDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $criteria = [
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'sku' => (string) $request->get_param('sku'),
            'status' => (string) $request->get_param('status'),
            'category' => (int) $request->get_param('category'),
            'stock_status' => (string) $request->get_param('stock_status'),
            'include_variations' => (bool) $request->get_param('include_variations'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ];

        // Absent is not the same as false, so it is only passed when sent.
        if ($request->has_param('manage_stock')) {
            $criteria['manage_stock'] = (bool) $request->get_param('manage_stock');
        }

        $result = $this->service->list($criteria);

        return Response::success(
            InventoryPresenter::itemList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $product = $this->service->get((int) $request->get_param('id'));

        return Response::success(InventoryPresenter::item($product));
    }

    public function lookup(WP_REST_Request $request): WP_REST_Response
    {
        $product = $this->service->getBySku((string) $request->get_param('sku'));

        return Response::success(InventoryPresenter::item($product));
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $product = $this->service->updateSettings(
            (int) $request->get_param('id'),
            $this->payload($request)
        );

        return Response::success(InventoryPresenter::item($product));
    }

    public function adjust(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->adjust((int) $request->get_param('id'), $this->payload($request));

        return Response::success([
            'item' => InventoryPresenter::item($result['product']),
            'movement' => $result['movement']->toArray(),
        ]);
    }

    /** Always 200, even when some items failed — see InventoryService::bulk(). */
    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->bulk(BulkStockRequest::fromPayload($this->payload($request)));

        return Response::success(
            $result['results'],
            200,
            [
                'total' => count($result['results']),
                'succeeded' => $result['succeeded'],
                'failed' => $result['failed'],
            ]
        );
    }

    public function lowStock(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        $status = (string) $request->get_param('status');

        $result = $this->service->lowStock(
            $page,
            $perPage,
            $status === '' ? InventoryRepository::STATUSES : [$status]
        );

        return Response::success(
            InventoryPresenter::itemList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function movements(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->movements($this->movementFilters($request), $page, $perPage);

        return Response::success(
            $result['items'],
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function movementSummary(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->movementSummary($this->movementFilters($request)));
    }

    /** @return array<string, mixed> */
    private function movementFilters(WP_REST_Request $request): array
    {
        return array_filter([
            'product_id' => (int) $request->get_param('product_id'),
            'order_id' => (int) $request->get_param('order_id'),
            'actor_id' => (int) $request->get_param('actor_id'),
            'reason' => (string) $request->get_param('reason'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ]);
    }

    /**
     * The JSON body only. Route and query parameters are not payload fields,
     * and folding them in would let `?reason=correction` masquerade as one.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
