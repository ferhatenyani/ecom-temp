<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;

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
            'orderby' => 'date',
            'order' => 'DESC',
            // Without this WooCommerce returns only published products.
            'status' => ['draft', 'pending', 'private', 'publish'],
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

    public function skuExists(string $sku, int $ignoreId = 0): bool
    {
        if ($sku === '') {
            return false;
        }

        $existing = (int) wc_get_product_id_by_sku($sku);

        return $existing > 0 && $existing !== $ignoreId;
    }

    public function create(ProductInput $input): WC_Product
    {
        $product = $input->get('type') === 'variable'
            ? new WC_Product_Variable()
            : new WC_Product_Simple();

        $this->apply($product, $input);
        $product->save();

        return $product;
    }

    public function update(WC_Product $product, ProductInput $input): WC_Product
    {
        $this->apply($product, $input);
        $product->save();

        $type = $input->get('type');

        if (is_string($type) && $type !== '' && $type !== $product->get_type()) {
            $product = $this->changeType($product, $type);
        }

        return $product;
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
