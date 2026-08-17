<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\SEO\SeoInput;
use AlgerianCommerce\SEO\SeoRepository;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * The WooCommerce adapter for products.
 *
 * The only place that knows WC_Product exists. Everything above it works with
 * plain arrays, so the domain never depends on WooCommerce's data model
 * (docs/ARCHITECTURE.md §2).
 *
 * No direct SQL: WooCommerce owns this data and its CRUD layer keeps lookup
 * tables and caches in step. Bypassing it is how catalogues drift.
 */
final class ProductRepository
{
    public function find(int $id): ?WC_Product
    {
        $product = wc_get_product($id);

        return $product instanceof WC_Product ? $product : null;
    }

    /**
     * @param array{search?: string, status?: string, sku?: string, page: int, per_page: int} $criteria
     * @return array{items: list<WC_Product>, total: int}
     */
    public function paginate(array $criteria, ?ProductFilters $filters = null): array
    {
        $filters ??= ProductFilters::none();

        $args = [
            'limit' => $criteria['per_page'],
            'page' => $criteria['page'],
            'paginate' => true,
            'orderby' => in_array($criteria['orderby'] ?? '', ProductInput::ORDERBY, true)
                ? $criteria['orderby']
                : 'date',
            'order' => strtoupper((string) ($criteria['order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC',
            // Without this WooCommerce returns only published products.
            'status' => ['draft', 'pending', 'private', 'publish'],
            // Variations are addressed through their parent, never listed
            // alongside top-level products.
            'type' => ['simple', 'variable'],
        ];

        if (!empty($criteria['status'])) {
            $args['status'] = $criteria['status'];
        }

        if (!empty($criteria['search'])) {
            $args['s'] = $criteria['search'];
        }

        if (!empty($criteria['sku'])) {
            $args['sku'] = $criteria['sku'];
        }

        $args += $this->filterArgs($filters);

        $results = $this->query($args, $filters);

        return [
            'items' => is_object($results) ? $results->products : [],
            'total' => is_object($results) ? (int) $results->total : 0,
        ];
    }

    /**
     * `wc_get_products()` with the price and rating bands attached — roadmap §82.
     *
     * **`wc_get_products()` silently ignores a `meta_query` passed to it.**
     * Measured on this install 2026-08-17: a `_price BETWEEN 150 AND 450`
     * clause handed to `wc_get_products()` returned all six fixture products,
     * priced 100 to 590, both on its own and beside a `tax_query` that *did*
     * apply. `WC_Product_Data_Store_CPT` builds its own WP_Query args from the
     * vocabulary it recognises and drops the rest — so the filter does not
     * fail, it simply does not filter, and a price band that matches everything
     * looks exactly like a shop whose prices are all in range.
     *
     * `woocommerce_product_data_store_cpt_get_products_query` is WooCommerce's
     * own documented seam for precisely this, and it is where the clause is
     * added. The filter is attached for the length of one call and removed in a
     * `finally`, because a price band left hooked would quietly narrow every
     * later product query in the same request.
     *
     * The same measurement found `attribute` + `attribute_term` ignored in the
     * same way, which is why attributes go through `tax_query` — that one works.
     *
     * @param array<string, mixed> $args
     */
    private function query(array $args, ProductFilters $filters): mixed
    {
        $metaQuery = $this->metaQuery($filters);

        if ($metaQuery === []) {
            return wc_get_products($args);
        }

        $attach = static function (array $wpQueryArgs) use ($metaQuery): array {
            $existing = isset($wpQueryArgs['meta_query']) && is_array($wpQueryArgs['meta_query'])
                ? $wpQueryArgs['meta_query']
                : [];

            /*
             * Nested and AND-ed, rather than appended.
             *
             * WooCommerce builds this list with `relation` absent today, which
             * WP_Meta_Query reads as AND — so appending would be correct. It
             * would also be correct only for as long as that stays true: a
             * future `relation => 'OR'` on WooCommerce's own clauses would make
             * a price band **widen** the result set instead of narrowing it,
             * which is this section's whole failure mode and the one thing a
             * green test suite would not notice. Keeping their group intact
             * inside an explicit AND costs one array and cannot go that way.
             */
            $wpQueryArgs['meta_query'] = $existing === []
                ? array_merge(['relation' => 'AND'], $metaQuery)
                : array_merge(['relation' => 'AND', $existing], $metaQuery);

            return $wpQueryArgs;
        };

        add_filter('woocommerce_product_data_store_cpt_get_products_query', $attach);

        try {
            return wc_get_products($args);
        } finally {
            remove_filter('woocommerce_product_data_store_cpt_get_products_query', $attach);
        }
    }

    /**
     * The price and rating bands, as WP_Query meta clauses.
     *
     * `_price` is multi-valued on a variable product — one row per variation —
     * so a band matches a product when *any* of its prices falls inside it,
     * which is what a shopper filtering a catalogue means. It is also the
     * effective price: WooCommerce writes the sale price into `_price` while a
     * sale is running.
     *
     * @return list<array<string, mixed>>
     */
    private function metaQuery(ProductFilters $filters): array
    {
        $clauses = [];
        $min = $filters->minPrice;
        $max = $filters->maxPrice;

        if ($min !== null && $max !== null) {
            $clauses[] = [
                'key' => '_price',
                'value' => [$min, $max],
                'compare' => 'BETWEEN',
                'type' => 'DECIMAL(20,6)',
            ];
        } elseif ($min !== null) {
            $clauses[] = ['key' => '_price', 'value' => $min, 'compare' => '>=', 'type' => 'DECIMAL(20,6)'];
        } elseif ($max !== null) {
            $clauses[] = ['key' => '_price', 'value' => $max, 'compare' => '<=', 'type' => 'DECIMAL(20,6)'];
        }

        if ($filters->ratingMin !== null) {
            $clauses[] = [
                'key' => '_wc_average_rating',
                'value' => $filters->ratingMin,
                'compare' => '>=',
                'type' => 'DECIMAL(10,2)',
            ];
        }

        return $clauses;
    }

    /**
     * Everything `wc_get_products()` does honour — roadmap §82.
     *
     * Measured 2026-08-17: `tax_query` (including an AND relation across
     * several taxonomies), `stock_status`, `featured`, `tag` and `include` all
     * apply correctly. Attributes, categories and tags therefore go through one
     * `tax_query` rather than through WooCommerce's per-kind shorthands, so
     * that one code path builds every taxonomy clause and there is one place
     * for a taxonomy name to be checked before it gets there.
     *
     * @return array<string, mixed>
     */
    private function filterArgs(ProductFilters $filters): array
    {
        $args = [];
        $taxQuery = [];

        foreach ($filters->attributes as $taxonomy => $slugs) {
            $taxQuery[] = [
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => $slugs,
                'operator' => 'IN',
            ];
        }

        if ($filters->categories !== []) {
            $taxQuery[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $filters->categories,
                'operator' => 'IN',
            ];
        }

        if ($filters->tags !== []) {
            $taxQuery[] = [
                'taxonomy' => 'product_tag',
                'field' => 'term_id',
                'terms' => $filters->tags,
                'operator' => 'IN',
            ];
        }

        if ($taxQuery !== []) {
            // Different axes narrow together; values inside one axis are
            // alternatives, which is the IN operator above.
            $taxQuery['relation'] = 'AND';
            $args['tax_query'] = $taxQuery;
        }

        if ($filters->stockStatus !== null) {
            $args['stock_status'] = $filters->stockStatus;
        }

        if ($filters->featured !== null) {
            $args['featured'] = $filters->featured;
        }

        if ($filters->onSale !== null) {
            $args += $this->onSaleArgs($filters->onSale);
        }

        return $args;
    }

    /**
     * On sale, expressed the only way WooCommerce supports.
     *
     * There is no `on_sale` query var — measured 2026-08-17, passing one
     * returned the whole catalogue. `wc_get_product_ids_on_sale()` is
     * WooCommerce's own answer to the question (it is what the storefront's own
     * shortcodes use) and it returns an id list an `include` honours.
     *
     * **An empty `include` is not an empty result.** WP_Query treats
     * `post__in => []` as no restriction at all, so a shop with nothing on sale
     * would answer `on_sale=true` with its entire catalogue. The impossible id
     * `0` is the sentinel that makes "no products are on sale" mean it.
     *
     * @return array<string, mixed>
     */
    private function onSaleArgs(bool $onSale): array
    {
        $ids = array_map('intval', wc_get_product_ids_on_sale());

        if ($onSale) {
            return ['include' => $ids === [] ? [0] : $ids];
        }

        return $ids === [] ? [] : ['exclude' => $ids];
    }

    /**
     * Whether anything already holds this SKU — **including a trashed
     * product**, which is the case that made this two calls instead of one.
     *
     * `wc_get_product_id_by_sku()` excludes `post_status = 'trash'`, but
     * WooCommerce's product data store does not: `wc_product_meta_lookup` keeps
     * the trashed product's row, and inserting against it throws
     * "The product with SKU (…) you are trying to insert is already present in
     * the lookup table" from inside `$product->save()`. So the cheap lookup
     * answers "free" for a SKU the write is about to refuse, and the caller
     * gets a 500 out of WooCommerce instead of the 409 this method exists to
     * produce. Found by the roadmap §69 CRUD walkthrough in
     * scripts/test-api.sh, which trashes a product and re-creates it.
     *
     * The second call is `wc_get_products()` with the statuses named
     * explicitly, rather than SQL against the lookup table: it is WooCommerce's
     * supported API for the question and it keeps this file free of opinions
     * about where WooCommerce stores SKUs. It only runs when the fast path
     * found nothing, so the common case is unchanged.
     */
    public function skuExists(string $sku, int $ignoreId = 0): bool
    {
        if ($sku === '') {
            return false;
        }

        $existing = (int) wc_get_product_id_by_sku($sku);

        if ($existing > 0) {
            return $existing !== $ignoreId;
        }

        return $this->trashedSkuOwner($sku, $ignoreId) > 0;
    }

    /**
     * The id of a trashed product holding this SKU, or 0.
     *
     * Kept separate so `ProductService` can say *why* a SKU is unavailable —
     * "already in use" sends an admin looking through a catalogue the product
     * is no longer in.
     */
    public function trashedSkuOwner(string $sku, int $ignoreId = 0): int
    {
        if ($sku === '') {
            return 0;
        }

        /** @var list<int> $ids */
        $ids = wc_get_products([
            'sku' => $sku,
            'status' => 'trash',
            'limit' => 2,
            'return' => 'ids',
        ]);

        foreach ($ids as $id) {
            if ((int) $id !== $ignoreId) {
                return (int) $id;
            }
        }

        return 0;
    }

    public function create(ProductInput $input): WC_Product
    {
        $product = $input->get('type') === 'variable'
            ? new WC_Product_Variable()
            : new WC_Product_Simple();

        $this->apply($product, $input);
        $product->save();
        $this->applySeo($product, $input);

        return $product;
    }

    public function update(WC_Product $product, ProductInput $input): WC_Product
    {
        $this->apply($product, $input);
        $product->save();
        $this->applySeo($product, $input);

        $type = $input->get('type');

        if (is_string($type) && $type !== '' && $type !== $product->get_type()) {
            $product = $this->changeType($product, $type);
        }

        return $product;
    }

    /**
     * SEO overrides, written **after** the save — roadmap §62.
     *
     * After, and not inside `apply()`, because on a create there is no post id
     * to attach meta to until WooCommerce has written the row. The same call
     * then serves both paths rather than the create silently dropping its SEO.
     */
    private function applySeo(WC_Product $product, ProductInput $input): void
    {
        $seo = $input->get('seo');

        if (!$seo instanceof SeoInput || $seo->isEmpty()) {
            return;
        }

        if ($seo->has('image_id')) {
            $this->assertImageAttachment((int) $seo->get('image_id'), 'seo.image_id');
        }

        (new SeoRepository())->save($product->get_id(), $seo);
    }

    /**
     * Product type lives in the `product_type` taxonomy, and the PHP class is
     * chosen from it at load time. Changing type therefore means updating the
     * term and re-loading, not calling a setter.
     */
    private function changeType(WC_Product $product, string $type): WC_Product
    {
        $id = $product->get_id();

        wp_set_object_terms($id, $type, 'product_type');

        // Caches hold the old class; force a fresh read.
        wc_delete_product_transients($id);
        clean_post_cache($id);

        $reloaded = wc_get_product($id);

        if (!$reloaded instanceof WC_Product) {
            throw ApiException::internal('The product type could not be changed.');
        }

        return $reloaded;
    }

    /**
     * @param bool $force permanently delete instead of moving to trash
     */
    public function delete(WC_Product $product, bool $force): bool
    {
        return (bool) $product->delete($force);
    }

    /**
     * Only fields present in the payload are touched, so a PATCH cannot blank
     * a field the caller never mentioned.
     */
    private function apply(WC_Product $product, ProductInput $input): void
    {
        $setters = [
            'name' => 'set_name',
            'slug' => 'set_slug',
            'description' => 'set_description',
            'short_description' => 'set_short_description',
            'sku' => 'set_sku',
            'regular_price' => 'set_regular_price',
            'sale_price' => 'set_sale_price',
            'status' => 'set_status',
            'featured' => 'set_featured',
            'catalog_visibility' => 'set_catalog_visibility',
            'manage_stock' => 'set_manage_stock',
            'stock_quantity' => 'set_stock_quantity',
            'stock_status' => 'set_stock_status',
            'weight' => 'set_weight',
            'category_ids' => 'set_category_ids',
            'tag_ids' => 'set_tag_ids',
        ];

        foreach ($setters as $field => $setter) {
            if ($input->has($field)) {
                $product->{$setter}($input->get($field));
            }
        }

        if ($input->has('attributes')) {
            $product->set_attributes($this->buildAttributes($input->attributes()));
        }

        if ($input->has('image_id')) {
            $this->assertImageAttachment((int) $input->get('image_id'), 'image_id');
            $product->set_image_id((int) $input->get('image_id'));
        }

        if ($input->has('gallery_image_ids')) {
            /** @var list<int> $ids */
            $ids = $input->get('gallery_image_ids');

            foreach ($ids as $id) {
                $this->assertImageAttachment($id, 'gallery_image_ids');
            }

            $product->set_gallery_image_ids($ids);
        }
    }

    /**
     * Attachment ids are checked before they are stored.
     *
     * WooCommerce accepts any post id here, so an unchecked value produces a
     * product whose image silently resolves to nothing — or to an unrelated
     * post. 0 is the documented "no image" value.
     */
    public function assertImageAttachment(int $id, string $field): void
    {
        if ($id === 0) {
            return;
        }

        $post = get_post($id);

        if (!is_object($post) || $post->post_type !== 'attachment' || !wp_attachment_is_image($id)) {
            throw ApiException::invalidRequest('The product data is invalid.', [
                'fields' => [$field => "{$id} is not an image attachment."],
            ]);
        }
    }

    /**
     * Copy a product, including its attributes and variations.
     *
     * Deliberately not WooCommerce's own duplicator: that lives in
     * `WC_Admin_Duplicate_Product`, an admin-only class that echoes and
     * redirects, neither of which belongs in a REST request.
     */
    public function duplicate(WC_Product $product): WC_Product
    {
        $data = $product->get_data();

        // Identity, derived values and anything that must not be inherited.
        foreach ([
            'id', 'slug', 'sku', 'date_created', 'date_modified', 'permalink',
            'price', 'total_sales', 'rating_counts', 'average_rating', 'review_count',
            'children', 'variations', 'date_on_sale_from', 'date_on_sale_to',
        ] as $key) {
            unset($data[$key]);
        }

        $class = $product->get_type() === 'variable' ? WC_Product_Variable::class : WC_Product_Simple::class;

        /** @var WC_Product $copy */
        $copy = new $class();
        $copy->set_props($data);
        $copy->set_attributes($product->get_attributes());
        $copy->set_name($product->get_name() . ' (copy)');
        // A copy starts as a draft: an accidental duplicate must never appear
        // in the storefront before someone has looked at it.
        $copy->set_status('draft');
        $copy->set_sku('');
        $copy->save();

        foreach ($product->get_children() as $childId) {
            $child = wc_get_product($childId);

            if (!$child instanceof WC_Product_Variation) {
                continue;
            }

            $childData = $child->get_data();
            foreach (['id', 'slug', 'sku', 'date_created', 'date_modified', 'permalink', 'price'] as $key) {
                unset($childData[$key]);
            }

            $copyChild = new WC_Product_Variation();
            $copyChild->set_props($childData);
            $copyChild->set_parent_id($copy->get_id());
            $copyChild->set_attributes($child->get_attributes());
            $copyChild->set_sku('');
            $copyChild->save();
        }

        if ($copy->get_type() === 'variable') {
            WC_Product_Variable::sync($copy->get_id());
        }

        return $this->find($copy->get_id()) ?? $copy;
    }

    /**
     * Translate validated attribute input into WooCommerce's objects.
     *
     * Global attributes store **term ids**, custom ones store plain strings —
     * getting that wrong produces attributes that look right in the database
     * and match nothing when variations are resolved.
     *
     * @param list<AttributeInput> $attributes
     * @return list<WC_Product_Attribute>
     */
    private function buildAttributes(array $attributes): array
    {
        $built = [];

        foreach ($attributes as $attribute) {
            $wc = new WC_Product_Attribute();
            $wc->set_visible($attribute->visible);
            $wc->set_variation($attribute->variation);
            $wc->set_position($attribute->position);

            if ($attribute->isGlobal()) {
                $taxonomy = wc_attribute_taxonomy_name_by_id((int) $attribute->id);

                if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                    throw ApiException::invalidRequest('The product data is invalid.', [
                        'fields' => ['attributes' => "Unknown global attribute id {$attribute->id}."],
                    ]);
                }

                $wc->set_id((int) $attribute->id);
                $wc->set_name($taxonomy);
                $wc->set_options($this->resolveTermIds($taxonomy, $attribute->options));
            } else {
                $wc->set_id(0);
                $wc->set_name($attribute->name);
                $wc->set_options($attribute->options);
            }

            $built[] = $wc;
        }

        return $built;
    }

    /**
     * Terms are resolved, never created. Creating a taxonomy term as a side
     * effect of saving a product makes typos permanent and unreviewable.
     *
     * @param list<string> $options
     * @return list<int>
     */
    private function resolveTermIds(string $taxonomy, array $options): array
    {
        $ids = [];

        foreach ($options as $option) {
            $term = get_term_by('slug', sanitize_title($option), $taxonomy)
                ?: get_term_by('name', $option, $taxonomy);

            if (!is_object($term)) {
                throw ApiException::invalidRequest('The product data is invalid.', [
                    'fields' => ['attributes' => "Unknown term \"{$option}\" for attribute {$taxonomy}."],
                ]);
            }

            $ids[] = (int) $term->term_id;
        }

        return $ids;
    }
}
