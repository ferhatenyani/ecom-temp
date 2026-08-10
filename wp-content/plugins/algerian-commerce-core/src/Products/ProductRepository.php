<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WC_Product;
use WC_Product_Simple;

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
        $product = new WC_Product_Simple();

        $this->apply($product, $input);
        $product->save();

        return $product;
    }

    public function update(WC_Product $product, ProductInput $input): WC_Product
    {
        $this->apply($product, $input);
        $product->save();

        return $product;
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
    }

    private function categorySlug(int $termId): string
    {
        $term = get_term($termId, 'product_cat');

        return is_object($term) && isset($term->slug) ? (string) $term->slug : '';
    }
}
