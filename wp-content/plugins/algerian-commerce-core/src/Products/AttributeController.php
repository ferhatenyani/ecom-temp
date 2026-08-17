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
 * Global attribute endpoints — roadmap §88.
 *
 * `GET /product-categories` has existed since §47 and is read-only, because
 * "deleting a category silently detaches every product on it deserves more than
 * a footnote". §88 is that argument taken seriously rather than deferred again:
 * the write surface exists, and the detaching is a 409 with the count in it.
 *
 * Everything is `ac_manage_products` — no new capability.
 */
final class AttributeController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly AttributeService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_PRODUCTS);

        register_rest_route($this->restNamespace(), '/attributes', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'store']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/attributes/(?P<id>\d+)/terms', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'terms']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->termIndexArgs(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storeTerm']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/attributes/(?P<id>\d+)/terms/(?P<term_id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateTerm']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->idArg('term_id'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroyTerm']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->idArg('term_id') + $this->forceArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/attributes/(?P<id>\d+)', [
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
                'args' => $this->idArg() + $this->forceArg(),
            ],
        ]);
    }

    /**
     * `force` matches the products endpoints, where `?force=true` is the
     * difference between trashing and removing. Here there is no trash — an
     * attribute has no bin — so it is the difference between a refusal that
     * names what would break and doing it anyway.
     *
     * @return array<string, array<string, mixed>>
     */
    private function forceArg(): array
    {
        return ['force' => ['type' => 'boolean', 'default' => false]];
    }

    /** @return array<string, array<string, mixed>> */
    private function termIndexArgs(): array
    {
        return $this->paginationArgs(Response::MAX_PER_PAGE) + [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'hide_empty' => ['type' => 'boolean', 'default' => false],
            'orderby' => [
                'type' => 'string',
                'default' => 'name',
                'enum' => ['name', 'slug', 'count', 'term_id'],
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'asc',
                'enum' => ['asc', 'desc'],
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    /**
     * Unpaginated on purpose. A shop has a handful of attributes — this install
     * had none until §88 — and a client building a filter UI needs all of them
     * to render one screen. `meta.total` is still reported so the shape matches
     * every other list.
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $attributes = $this->service->list();

        return Response::success(
            AttributePresenter::toArrayList($attributes),
            200,
            ['total' => count($attributes)]
        );
    }

    /** The single read carries usage; the list does not — two queries per row. */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $attribute = $this->service->get((int) $request->get_param('id'));

        return Response::success(
            AttributePresenter::toArray($attribute, $this->service->usage($attribute))
        );
    }

    /**
     * **A new attribute can be filtered on immediately, and cannot be counted
     * on until something uses it.** Those are two different facts and the
     * response says both rather than leaving a client to discover the second as
     * an empty facet group it reads as a bug.
     */
    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        $result = $this->service->create(is_array($body) ? $body : []);

        return Response::success(
            AttributePresenter::toArray($result['attribute'], ['products' => 0, 'terms' => 0]),
            201,
            [
                'filterable' => $result['registered'],
                'note' => 'Add terms at POST /attributes/{id}/terms, then tag products with them. '
                    . 'Facet counts cover published products, so this attribute counts zero until one is tagged and published.',
            ]
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        $result = $this->service->update((int) $request->get_param('id'), is_array($body) ? $body : []);

        /*
         * Reported in `meta` rather than in the resource: it describes what this
         * request did, not what the attribute is. A slug change rewrites the
         * taxonomy every saved filter and every storefront link is built on —
         * WooCommerce migrates the stored data, and it cannot migrate a URL
         * somebody bookmarked.
         */
        return Response::success(
            AttributePresenter::toArray($result['attribute'], $this->service->usage($result['attribute'])),
            200,
            $result['slug_changed'] ? ['slug_changed' => true] : []
        );
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $result = $this->service->delete($id, (bool) $request->get_param('force'));

        return Response::success([
            'id' => $id,
            'deleted' => true,
            'products_detached' => $result['products'],
        ]);
    }

    public function terms(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->terms((int) $request->get_param('id'), [
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'hide_empty' => (bool) $request->get_param('hide_empty'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            AttributePresenter::terms($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function storeTerm(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        return Response::success(
            AttributePresenter::term($this->service->createTerm(
                (int) $request->get_param('id'),
                is_array($body) ? $body : []
            )),
            201
        );
    }

    public function updateTerm(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        $result = $this->service->updateTerm(
            (int) $request->get_param('id'),
            (int) $request->get_param('term_id'),
            is_array($body) ? $body : []
        );

        return Response::success(
            AttributePresenter::term($result['term']),
            200,
            $result['slug_changed'] ? ['slug_changed' => true] : []
        );
    }

    public function destroyTerm(WP_REST_Request $request): WP_REST_Response
    {
        $termId = (int) $request->get_param('term_id');

        $result = $this->service->deleteTerm(
            (int) $request->get_param('id'),
            $termId,
            (bool) $request->get_param('force')
        );

        return Response::success([
            'id' => $termId,
            'deleted' => true,
            'products_detached' => $result['products'],
        ]);
    }
}
