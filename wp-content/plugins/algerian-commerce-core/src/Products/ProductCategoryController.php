<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * /product-categories — read, create, update, delete.
 *
 * Assignment already happens through a product's `category_ids`; the read side
 * lets a client discover which ids exist, and the write side lets the admin
 * panel create and rename categories without dropping into wp-admin.
 *
 * A category cannot be deleted while it still holds products, because a delete
 * silently detaches every product on it — the caller must pass `force=true`
 * to accept that. That is the same shape products/{id}?force= already uses.
 */
final class ProductCategoryController extends AbstractController
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_PRODUCTS);

        register_rest_route($this->restNamespace(), '/product-categories', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
                'args' => $this->paginationArgs(Response::MAX_PER_PAGE) + [
                    'search' => [
                        'type' => 'string',
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    // 0 is a real value here — the top level of the hierarchy.
                    'parent' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'absint',
                    ],
                    'hide_empty' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'store']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/product-categories/(?P<id>\d+)', [
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
                        'description' => 'Delete even when the category still contains products.',
                    ],
                ],
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
                $items[] = self::present($term);
            }
        }

        return Response::success($items, 200, Response::paginationMeta($total, $page, $perPage));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $term = $this->findTerm((int) $request->get_param('id'));

        return Response::success(self::present($term));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $payload = $this->payload($request);
        $input = CategoryInput::fromPayload($payload);

        $insertArgs = ['description' => $input->description];

        if ($input->slug !== null) {
            $insertArgs['slug'] = $input->slug;
        }

        if ($input->parent !== null) {
            $this->assertParentExists($input->parent);
            $insertArgs['parent'] = $input->parent;
        }

        $result = wp_insert_term($input->name, 'product_cat', $insertArgs);

        if (is_wp_error($result)) {
            throw ApiException::invalidRequest(
                (string) $result->get_error_message(),
                ['code' => (string) $result->get_error_code()]
            );
        }

        $term = $this->findTerm((int) $result['term_id']);

        return Response::success(self::present($term), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $id = (int) $request->get_param('id');
        $current = $this->findTerm($id);
        $input = CategoryInput::fromPatch($this->payload($request));

        $updateArgs = [];

        if ($input->has('name')) {
            $updateArgs['name'] = $input->name;
        }

        if ($input->has('slug')) {
            $updateArgs['slug'] = $input->slug;
        }

        if ($input->has('description')) {
            $updateArgs['description'] = $input->description;
        }

        if ($input->has('parent')) {
            if ($input->parent === $id) {
                throw ApiException::invalidRequest(
                    'A category cannot be its own parent.',
                    ['fields' => ['parent' => 'Cannot be the category itself.']]
                );
            }

            if ($input->parent > 0) {
                $this->assertParentExists($input->parent);
            }

            $updateArgs['parent'] = $input->parent;
        }

        if ($updateArgs === []) {
            return Response::success(self::present($current));
        }

        $result = wp_update_term($id, 'product_cat', $updateArgs);

        if (is_wp_error($result)) {
            throw ApiException::invalidRequest(
                (string) $result->get_error_message(),
                ['code' => (string) $result->get_error_code()]
            );
        }

        return Response::success(self::present($this->findTerm($id)));
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $id = (int) $request->get_param('id');
        $term = $this->findTerm($id);
        $force = (bool) $request->get_param('force');

        if ((int) $term->count > 0 && !$force) {
            throw ApiException::invalidRequest(
                'The category still contains products. Reassign them, or pass force=true to detach.',
                ['fields' => ['id' => 'Category is not empty.'], 'count' => (int) $term->count]
            );
        }

        $deleted = wp_delete_term($id, 'product_cat');

        if (is_wp_error($deleted)) {
            throw ApiException::internal(
                (string) $deleted->get_error_message()
            );
        }

        if ($deleted === false || $deleted === 0) {
            throw ApiException::notFound('The category could not be deleted.');
        }

        return Response::success(['id' => $id, 'deleted' => true]);
    }

    /** @return array<string, mixed> */
    private static function present(WP_Term $term): array
    {
        return [
            'id' => (int) $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'parent' => (int) $term->parent,
            'description' => $term->description,
            'count' => (int) $term->count,
        ];
    }

    private function findTerm(int $id): WP_Term
    {
        $term = get_term($id, 'product_cat');

        if (!$term instanceof WP_Term) {
            throw ApiException::notFound('That category was not found.');
        }

        return $term;
    }

    private function assertParentExists(int $parent): void
    {
        if ($parent === 0) {
            return;
        }

        $term = get_term($parent, 'product_cat');

        if (!$term instanceof WP_Term) {
            throw ApiException::invalidRequest(
                'That parent category does not exist.',
                ['fields' => ['parent' => 'Unknown parent id.']]
            );
        }
    }

    /**
     * The JSON body only. Route and query parameters are not category fields.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
