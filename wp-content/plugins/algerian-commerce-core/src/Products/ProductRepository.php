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
     * @param array{search?: string, status?: string, category?: int, sku?: string, page: int, per_page: int} $criteria
     * @return array{items: list<WC_Product>, total: int}
     */
    public function paginate(array $criteria): array
    {
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

        if (!empty($criteria['category'])) {
            $args['category'] = [$this->categorySlug((int) $criteria['category'])];
        }

        $results = wc_get_products($args);

        return [
            'items' => is_object($results) ? $results->products : [],
            'total' => is_object($results) ? (int) $results->total : 0,
        ];
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

    private function categorySlug(int $termId): string
    {
        $term = get_term($termId, 'product_cat');

        return is_object($term) && isset($term->slug) ? (string) $term->slug : '';
    }
}
