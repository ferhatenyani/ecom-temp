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
 * Variation CRUD, nested under the parent product — docs/PLAN.md §6.
 *
 * Nested rather than flat (`/variations/{id}`) because a variation has no
 * meaning without its parent, and the nesting makes the ownership check
 * unavoidable: the service verifies the variation actually belongs to the
 * product in the path.
 */
final class VariationController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly VariationService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_PRODUCTS);
        $parent = $this->idArg();

        register_rest_route($this->restNamespace(), '/products/(?P<id>\d+)/variations', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
                'args' => $parent,
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'store']),
                'permission_callback' => $guard,
                'args' => $parent,
            ],
        ]);

        $withVariation = $parent + $this->idArg('variation_id');

        register_rest_route(
            $this->restNamespace(),
            '/products/(?P<id>\d+)/variations/(?P<variation_id>\d+)',
            [
                [
                    'methods' => 'GET',
                    'callback' => $this->handle([$this, 'show']),
                    'permission_callback' => $guard,
                    'args' => $withVariation,
                ],
                [
                    'methods' => 'PATCH',
                    'callback' => $this->handle([$this, 'update']),
                    'permission_callback' => $guard,
                    'args' => $withVariation,
                ],
                [
                    'methods' => 'DELETE',
                    'callback' => $this->handle([$this, 'destroy']),
                    'permission_callback' => $guard,
                    'args' => $withVariation + [
                        'force' => ['type' => 'boolean', 'default' => true],
                    ],
                ],
            ]
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $variations = $this->service->list((int) $request->get_param('id'));

        return Response::success(ProductPresenter::variationList($variations));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        // list() already enforces parent ownership; filtering here keeps the
        // 404-for-wrong-parent behaviour in one place.
        $variations = $this->service->list((int) $request->get_param('id'));
        $wanted = (int) $request->get_param('variation_id');

        foreach ($variations as $variation) {
            if ($variation->get_id() === $wanted) {
                return Response::success(ProductPresenter::variation($variation));
            }
        }

        return Response::error('not_found', 'No variation with that id for this product.', 404);
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $variation = $this->service->create((int) $request->get_param('id'), $this->payload($request));

        return Response::success(ProductPresenter::variation($variation), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $variation = $this->service->update(
            (int) $request->get_param('id'),
            (int) $request->get_param('variation_id'),
            $this->payload($request)
        );

        return Response::success(ProductPresenter::variation($variation));
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $variationId = (int) $request->get_param('variation_id');

        $this->service->delete(
            (int) $request->get_param('id'),
            $variationId,
            (bool) $request->get_param('force')
        );

        return Response::success(['id' => $variationId, 'deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
