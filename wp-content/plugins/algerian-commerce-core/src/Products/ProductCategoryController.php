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
 * GET /product-categories — read-only, for populating pickers.
 *
 * Assignment already happens through a product's `category_ids`; what was
 * missing is a way to discover which ids exist. Full taxonomy CRUD (creating,
 * renaming, reordering, deleting categories) is docs/PLAN.md §5 and belongs in
 * its own phase — deleting a category silently detaches every product on it,
 * which deserves more than a footnote in the product endpoints.
 */
final class ProductCategoryController extends AbstractController
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->restNamespace(), '/product-categories', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => Permissions::callback(Capabilities::MANAGE_PRODUCTS),
            'args' => $this->paginationArgs(Response::MAX_PER_PAGE) + [
                'search' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                // 0 is a real value here — the top level of the hierarchy.
                'parent' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
                'hide_empty' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => (bool) $request->get_param('hide_empty'),
            'orderby' => 'name',
            'order' => 'ASC',
        ];

        if ($request->get_param('search') !== null && $request->get_param('search') !== '') {
            $args['search'] = (string) $request->get_param('search');
        }

        if ($request->get_param('parent') !== null) {
            $args['parent'] = (int) $request->get_param('parent');
        }

        $total = (int) wp_count_terms(array_merge($args, ['fields' => 'count']));

        $terms = get_terms(array_merge($args, [
            'number' => $perPage,
            'offset' => max(0, ($page - 1) * $perPage),
        ]));

        $items = [];

        if (is_array($terms)) {
            foreach ($terms as $term) {
                $items[] = [
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent' => (int) $term->parent,
                    'description' => $term->description,
                    'count' => (int) $term->count,
                ];
            }
        }

        return Response::success($items, 200, Response::paginationMeta($total, $page, $perPage));
    }
}
