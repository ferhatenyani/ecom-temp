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

    /**
     * List, narrow and count — roadmap §47, §82.
     *
     * **Every arg that declares a `sanitize_callback` declares
     * `'validate_callback' => 'rest_validate_request_arg'` beside it.** That is
     * CLAUDE.md's standing rule and §82 restates it, because a custom sanitize
     * callback displaces the default that would otherwise enforce `minimum`,
     * `maximum`, `enum` and `pattern` — leaving them advisory on exactly the
     * args a filter payload arrives in.
     *
     * `category` widened from a single id to a comma-separated list, per §82's
     * "category (repeatable)". `category=12` is unchanged for every existing
     * caller; `category=12,15` is the new form.
     *
     * The shapes WordPress cannot express — the `attributes` map, the ordering
     * of a price band — are `ProductFilters`' job, so this method never
     * inspects a value it has not declared.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexArgs(): array
    {
        $termList = [
            'type' => 'string',
            'description' => 'A term id, or a comma-separated list of them.',
            // Empty is allowed: a storefront clearing a filter sends `category=`.
            'pattern' => '^$|^[0-9]+(,[0-9]+)*$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
        ];

        return $this->paginationArgs() + [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sku' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ProductInput::STATUSES,
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
            'category' => $termList,
            'tag' => $termList,
            'min_price' => [
                'type' => 'number',
                'minimum' => 0,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'max_price' => [
                'type' => 'number',
                'minimum' => 0,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            /*
             * `attributes[pa_size]=m,l`. Declared as an object so WordPress
             * refuses a scalar before ProductFilters sees it; the taxonomy name
             * itself is matched against the registered attributes in
             * AttributeCatalogue and never reaches a query as free text.
             */
            'attributes' => [
                'type' => 'object',
                'description' => 'Global attribute filters, e.g. attributes[pa_size]=m,l.',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'stock_status' => [
                'type' => 'string',
                'enum' => ProductInput::STOCK_STATUSES,
            ],
            'on_sale' => ['type' => 'boolean'],
            'featured' => ['type' => 'boolean'],
            'rating_min' => [
                'type' => 'number',
                'minimum' => 0,
                'maximum' => 5,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'facets' => [
                'type' => 'string',
                'description' => 'Opt-in facet groups: ' . implode(', ', ProductFilters::FACET_GROUPS) . '.',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list(
            [
                'page' => $page,
                'per_page' => $perPage,
                'search' => (string) $request->get_param('search'),
                'sku' => (string) $request->get_param('sku'),
                'status' => (string) $request->get_param('status'),
                'orderby' => (string) $request->get_param('orderby'),
                'order' => (string) $request->get_param('order'),
            ],
            ProductFilters::fromParams($request->get_params())
        );

        $meta = Response::paginationMeta($result['total'], $page, $perPage);

        if (isset($result['facets'])) {
            $meta['facets'] = $result['facets'];
        }

        return Response::success(ProductPresenter::toArrayList($result['items']), 200, $meta);
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
