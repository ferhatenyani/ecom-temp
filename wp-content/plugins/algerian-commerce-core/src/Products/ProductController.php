<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Product CRUD — roadmap §47.
 *
 * Domain controllers live with their domain; src/API/ holds cross-cutting
 * API infrastructure and the platform endpoints (health, audit).
 *
 * Every route carries the same capability. Finer-grained rules — a Product
 * Manager who may edit but not delete — belong in the service once the
 * capability set grows read/write variants.
 */
final class ProductController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly ProductService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_PRODUCTS);

        register_rest_route($this->restNamespace(), '/products', [
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

        // Registered before the {id} routes so "bulk" is never matched as an
        // id by a future non-numeric pattern.
        register_rest_route($this->restNamespace(), '/products/bulk', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'bulk']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/products/(?P<id>\d+)/duplicate', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'duplicate']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/products/(?P<id>\d+)', [
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
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroy']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + [
                    'force' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Delete permanently instead of moving to trash.',
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function idArg(): array
    {
        return [
            'id' => [
                'type' => 'integer',
                'required' => true,
                'minimum' => 1,
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function indexArgs(): array
    {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => Response::DEFAULT_PER_PAGE,
                'minimum' => 1,
                'maximum' => Response::MAX_PER_PAGE,
                'sanitize_callback' => 'absint',
            ],
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
                'enum' => ProductInput::STATUSES,
            ],
            'category' => [
                'type' => 'integer',
                'minimum' => 1,
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => ProductInput::ORDERBY,
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
            ],
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
            'sku' => (string) $request->get_param('sku'),
            'status' => (string) $request->get_param('status'),
            'category' => (int) $request->get_param('category'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            ProductPresenter::toArrayList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $product = $this->service->get((int) $request->get_param('id'));

        return Response::success(ProductPresenter::toArray($product));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $product = $this->service->create($this->payload($request));

        return Response::success(ProductPresenter::toArray($product), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $product = $this->service->update((int) $request->get_param('id'), $this->payload($request));

        return Response::success(ProductPresenter::toArray($product));
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $this->service->delete($id, (bool) $request->get_param('force'));

        return Response::success(['id' => $id, 'deleted' => true]);
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response
    {
        $copy = $this->service->duplicate((int) $request->get_param('id'));

        return Response::success(ProductPresenter::toArray($copy), 201);
    }

    /**
     * Always 200, even when some items failed — see ProductService::bulk().
     */
    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->bulk(BulkRequest::fromPayload($this->payload($request)));

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

    /**
     * The JSON body only. Route and query parameters are not product fields,
     * and folding them in would let `?status=publish` masquerade as one.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
