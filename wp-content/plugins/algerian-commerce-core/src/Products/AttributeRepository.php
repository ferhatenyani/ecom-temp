<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use WP_Error;
use WP_Term;

/**
 * The WooCommerce adapter for global attributes — roadmap §88.
 *
 * Everything here goes through `wc_create_attribute()`, `wc_update_attribute()`
 * and `wc_delete_attribute()` rather than through `$wpdb`, and that is not
 * merely the layering rule. `wc_update_attribute()` delegates to
 * `wc_create_attribute()` with an `old_slug`, and a rename then migrates the
 * `term_taxonomy` rows, every product's `_product_attributes` meta, every
 * variation's `attribute_pa_*` meta key, the term-order meta and the in-request
 * globals. Writing that table directly would rename the attribute and silently
 * orphan every product on it.
 */
final class AttributeRepository
{
    /** @return list<object> rows as `wc_get_attribute_taxonomies()` returns them */
    public function all(): array
    {
        return array_values(wc_get_attribute_taxonomies());
    }

    public function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($this->all() as $attribute) {
            if ((int) $attribute->attribute_id === $id) {
                return $attribute;
            }
        }

        return null;
    }

    public function taxonomyFor(object $attribute): string
    {
        $slug = (string) ($attribute->attribute_name ?? '');

        return $slug === '' ? '' : wc_attribute_taxonomy_name($slug);
    }

    /** @param array<string, mixed> $args */
    public function create(array $args): int
    {
        $id = wc_create_attribute($args);

        if ($id instanceof WP_Error) {
            throw self::fromWpError($id);
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $args */
    public function update(int $id, array $args): int
    {
        $result = wc_update_attribute($id, $args + ['id' => $id]);

        if ($result instanceof WP_Error) {
            throw self::fromWpError($result);
        }

        return (int) $result;
    }

    /**
     * `wc_delete_attribute()` removes the row **and every term on it**, and
     * does not touch the `_product_attributes` meta of products that used it.
     * That is why `AttributeService::guardNotInUse()` exists.
     */
    public function delete(int $id): bool
    {
        return wc_delete_attribute($id) === true;
    }

    /**
     * Make a just-created attribute usable for the rest of *this* request.
     *
     * **WooCommerce does not do this, including in its own REST controller** —
     * measured against WooCommerce 11.0.1. `wc_create_attribute()` writes the
     * row, clears the `wc_attribute_taxonomies` transient and schedules a
     * rewrite flush; the taxonomy itself is registered on `init` by
     * `WC_Post_Types::register_taxonomies()`, which already ran. So for the
     * remainder of the request the attribute exists in the database and
     * `taxonomy_exists()` is false, `wp_insert_term()` fails, and §82's facet
     * counter skips it — the trap CLAUDE.md records against §82's fixtures.
     *
     * Re-running WooCommerce's registration is not available: it returns early
     * on `taxonomy_exists('product_type')`, so a second call is a no-op. So the
     * taxonomy is registered here with the **minimum that makes the write path
     * work**, deliberately not a copy of WooCommerce's sixty lines of labels,
     * rewrite rules and capabilities — that would be forking their data model
     * to drift on the next upgrade. This registration lives for one request and
     * WooCommerce's own supersedes it on the next one.
     *
     * `update_count_callback` is the one argument that is load-bearing rather
     * than incidental: it maintains `$term->count`, which the term delete guard
     * reads.
     *
     * The `$wc_product_attributes` global matters just as much as the
     * registration. `taxonomy_is_product_attribute()` checks both, and §82's
     * `ProductCollectionData` skips any taxonomy failing it — so without this
     * line the facet counter answers 200 with an empty list.
     */
    public function registerForRequest(int $id): bool
    {
        $attribute = $this->find($id);

        if ($attribute === null) {
            return false;
        }

        $taxonomy = $this->taxonomyFor($attribute);

        if ($taxonomy === '') {
            return false;
        }

        global $wc_product_attributes;

        if (!is_array($wc_product_attributes)) {
            $wc_product_attributes = [];
        }

        $wc_product_attributes[$taxonomy] = $attribute;

        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'hierarchical' => false,
                'update_count_callback' => '_update_post_term_count',
                'show_ui' => false,
                'show_in_menu' => false,
                'meta_box_cb' => false,
                'query_var' => false,
                'rewrite' => false,
                'public' => false,
                'sort' => false,
            ]);
        }

        return taxonomy_exists($taxonomy);
    }

    /**
     * How many products carry a term of this attribute, and a few of their ids.
     *
     * `wc_get_products()` with a `tax_query` is §82's measured-good path — the
     * same measurement found `meta_query` and `attribute` silently ignored. The
     * status list is explicit because a draft product using the attribute is
     * still a product that would break.
     *
     * **Guarded on `taxonomy_exists()` and the guard is load-bearing**: a
     * `tax_query` naming an unregistered taxonomy does not error, it produces a
     * clause WP_Query drops, and the query then returns *every* product. A
     * false positive here refuses a legitimate delete forever.
     *
     * @return array{total: int, ids: list<int>}
     */
    public function productUsage(string $taxonomy, int $sample = 5): array
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return ['total' => 0, 'ids' => []];
        }

        $result = wc_get_products([
            'limit' => max(1, $sample),
            'paginate' => true,
            'return' => 'ids',
            'status' => ['publish', 'draft', 'pending', 'private'],
            'tax_query' => [[
                'taxonomy' => $taxonomy,
                'operator' => 'EXISTS',
            ]],
        ]);

        return [
            'total' => (int) ($result->total ?? 0),
            'ids' => array_map('intval', (array) ($result->products ?? [])),
        ];
    }

    public function termCount(string $taxonomy): int
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        return (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    }

    /**
     * @param array{page: int, per_page: int, search: string, orderby: string, order: string, hide_empty: bool} $criteria
     * @return array{items: list<WP_Term>, total: int}
     */
    public function terms(string $taxonomy, array $criteria): array
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return ['items' => [], 'total' => 0];
        }

        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => $criteria['hide_empty'],
            'orderby' => $criteria['orderby'],
            'order' => strtoupper($criteria['order']) === 'DESC' ? 'DESC' : 'ASC',
        ];

        if ($criteria['search'] !== '') {
            $args['search'] = $criteria['search'];
        }

        $total = (int) wp_count_terms($args + ['fields' => 'count']);

        $terms = get_terms($args + [
            'number' => $criteria['per_page'],
            'offset' => max(0, ($criteria['page'] - 1) * $criteria['per_page']),
        ]);

        $items = [];

        foreach (is_array($terms) ? $terms : [] as $term) {
            if ($term instanceof WP_Term) {
                $items[] = $term;
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function findTerm(string $taxonomy, int $termId): ?WP_Term
    {
        if ($taxonomy === '' || $termId <= 0 || !taxonomy_exists($taxonomy)) {
            return null;
        }

        $term = get_term($termId, $taxonomy);

        return $term instanceof WP_Term ? $term : null;
    }

    public function termSlugExists(string $taxonomy, string $slug, int $ignoreId = 0): bool
    {
        $term = get_term_by('slug', $slug, $taxonomy);

        return $term instanceof WP_Term && (int) $term->term_id !== $ignoreId;
    }

    public function createTerm(string $taxonomy, AttributeTermInput $input): WP_Term
    {
        $args = [];

        foreach (['slug' => 'slug', 'description' => 'description'] as $field => $key) {
            if ($input->has($field)) {
                $args[$key] = (string) $input->get($field);
            }
        }

        $result = wp_insert_term((string) $input->get('name'), $taxonomy, $args);

        if ($result instanceof WP_Error) {
            throw self::fromWpError($result);
        }

        $termId = (int) ($result['term_id'] ?? 0);

        $this->applyMenuOrder($termId, $taxonomy, $input);

        $term = $this->findTerm($taxonomy, $termId);

        if ($term === null) {
            throw ApiException::internal('The term was created but could not be read back.');
        }

        return $term;
    }

    public function updateTerm(string $taxonomy, WP_Term $term, AttributeTermInput $input): WP_Term
    {
        $args = [];

        foreach (['name', 'slug', 'description'] as $field) {
            if ($input->has($field)) {
                $args[$field] = (string) $input->get($field);
            }
        }

        if ($args !== []) {
            $result = wp_update_term((int) $term->term_id, $taxonomy, $args);

            if ($result instanceof WP_Error) {
                throw self::fromWpError($result);
            }
        }

        $this->applyMenuOrder((int) $term->term_id, $taxonomy, $input);

        return $this->findTerm($taxonomy, (int) $term->term_id) ?? $term;
    }

    public function deleteTerm(string $taxonomy, int $termId): bool
    {
        $result = wp_delete_term($termId, $taxonomy);

        return $result === true;
    }

    private function applyMenuOrder(int $termId, string $taxonomy, AttributeTermInput $input): void
    {
        if ($input->has('menu_order')) {
            update_term_meta($termId, 'order_' . $taxonomy, (int) $input->get('menu_order'));
        }
    }

    /**
     * WooCommerce and WordPress both answer with a WP_Error whose *message* is
     * worth showing and whose *code* is not — a client matching on
     * `invalid_product_attribute_slug_already_exists` would be coupled to
     * WooCommerce's internals through our envelope.
     *
     * A duplicate is a 409 rather than a 400, because the payload is well
     * formed and the state is what refuses it — the rule `docs/API.md` states
     * and `ProductService` already follows for a duplicate SKU.
     */
    private static function fromWpError(WP_Error $error): ApiException
    {
        $code = (string) $error->get_error_code();
        $message = wp_strip_all_tags((string) $error->get_error_message());

        if (str_contains($code, 'already_exists') || str_contains($code, 'term_exists') || $code === 'duplicate_term_slug') {
            return ApiException::conflict($message === '' ? 'That attribute already exists.' : $message);
        }

        return ApiException::invalidRequest('The attribute data is invalid.', [
            'fields' => ['attribute' => $message === '' ? 'WooCommerce refused the write.' : $message],
        ]);
    }
}
