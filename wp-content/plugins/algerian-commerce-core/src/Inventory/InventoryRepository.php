<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\API\ApiException;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use wpdb;

/**
 * The WooCommerce adapter for stock.
 *
 * Writes go through wc_update_product_stock(), never through $wpdb. That
 * function performs increase and decrease as a *relative* SQL update
 * (`meta_value = meta_value - n`), which is what makes two concurrent
 * decrements compose instead of one overwriting the other, and it keeps the
 * product lookup table, stock status and caches in step. Reimplementing it as
 * read-modify-write would quietly oversell the shop.
 *
 * The low-stock report is the one read that cannot go through
 * wc_get_products(): the threshold is per product, so the comparison is
 * between two columns, and WC_Product_Query only ever builds `meta_key = value`.
 * WooCommerce solves it with prepared SQL over the same lookup table
 * (Automattic\WooCommerce\Admin\API\ProductsLowInStock), and so do we — as a
 * read, in a repository, with every value bound.
 */
final class InventoryRepository
{
    /** Product statuses the API exposes, matching the products endpoint. */
    public const STATUSES = ['draft', 'pending', 'private', 'publish'];

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function find(int $id): ?WC_Product
    {
        $product = wc_get_product($id);

        return $product instanceof WC_Product ? $product : null;
    }

    public function findBySku(string $sku): ?WC_Product
    {
        if ($sku === '') {
            return null;
        }

        $id = (int) wc_get_product_id_by_sku($sku);

        return $id > 0 ? $this->find($id) : null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Product>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $args = [
            'limit' => $criteria['per_page'],
            'page' => $criteria['page'],
            'paginate' => true,
            'status' => self::STATUSES,
            'orderby' => in_array($criteria['orderby'] ?? '', ['date', 'id', 'title', 'sku'], true)
                ? $criteria['orderby']
                : 'date',
            'order' => strtoupper((string) ($criteria['order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC',
            // Variations hold their own stock, so an inventory list that hides
            // them hides most of a variable shop's real stock. Off by default
            // to match the products list; `include_variations` turns them on.
            'type' => !empty($criteria['include_variations'])
                ? ['simple', 'variable', 'variation']
                : ['simple', 'variable'],
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

        if (!empty($criteria['stock_status'])) {
            $args['stock_status'] = $criteria['stock_status'];
        }

        if (!empty($criteria['category'])) {
            $args['category'] = [$this->categorySlug((int) $criteria['category'])];
        }

        // Only sent when the caller asked: WooCommerce treats '' as "no
        // filter", so passing false unconditionally would be a real filter.
        if (isset($criteria['manage_stock']) && is_bool($criteria['manage_stock'])) {
            $args['manage_stock'] = $criteria['manage_stock'];
        }

        $results = wc_get_products($args);

        return [
            'items' => is_object($results) ? $results->products : [],
            'total' => is_object($results) ? (int) $results->total : 0,
        ];
    }

    /**
     * Products at or below their low-stock threshold, lowest first.
     *
     * The threshold is `_low_stock_amount` on the product when set, otherwise
     * the store-wide `woocommerce_notify_low_stock_amount`. Rows with a NULL
     * stock_quantity are products that do not manage stock and cannot be low.
     * Backordered items are excluded, as they are in WooCommerce's own report:
     * they are past "low" rather than approaching it.
     *
     * @param list<string> $statuses
     * @return array{items: list<WC_Product>, total: int}
     */
    public function lowStock(int $page, int $perPage, array $statuses = self::STATUSES): array
    {
        $statuses = array_values(array_intersect($statuses, self::STATUSES));

        if ($statuses === []) {
            return ['items' => [], 'total' => 0];
        }

        $threshold = $this->globalLowStockThreshold();
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '%s'));

        $from = $this->lowStockFrom();
        $where = "WHERE posts.post_type IN ('product', 'product_variation')
                    AND posts.post_status IN ({$statusPlaceholders})
                    AND lookup.stock_quantity IS NOT NULL
                    AND lookup.stock_status IN ('instock', 'outofstock')
                    AND (
                          (
                            threshold.meta_value > ''
                            AND lookup.stock_quantity <= CAST(threshold.meta_value AS SIGNED)
                          )
                          OR (
                            (threshold.meta_value IS NULL OR threshold.meta_value <= '')
                            AND lookup.stock_quantity <= %d
                          )
                        )";

        $total = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) {$from} {$where}",
            [...$statuses, $threshold]
        ));

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT lookup.product_id {$from} {$where}
             ORDER BY lookup.stock_quantity ASC, lookup.product_id ASC
             LIMIT %d OFFSET %d",
            [...$statuses, $threshold, $perPage, max(0, ($page - 1) * $perPage)]
        ));

        $items = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            $product = $this->find((int) $id);

            // A row can outlive its product between the two queries.
            if ($product !== null) {
                $items[] = $product;
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * FROM/JOIN shared by the low-stock count and page queries.
     *
     * wc_product_meta_lookup is WooCommerce's own indexed projection of stock
     * and price — reading it avoids a postmeta join per product, which is why
     * WooCommerce built it.
     */
    private function lowStockFrom(): string
    {
        $lookup = $this->wpdb->wc_product_meta_lookup ?? ($this->wpdb->prefix . 'wc_product_meta_lookup');

        return "FROM {$lookup} lookup
                INNER JOIN {$this->wpdb->posts} posts ON posts.ID = lookup.product_id
                LEFT JOIN {$this->wpdb->postmeta} threshold
                       ON threshold.post_id = posts.ID
                      AND threshold.meta_key = '_low_stock_amount'";
    }

    /**
     * Apply an adjustment and return the resulting quantity.
     *
     * The return value is authoritative: for increase and decrease WooCommerce
     * computes it in SQL, so it accounts for anything that landed concurrently.
     */
    public function applyStock(WC_Product $product, string $mode, int $quantity): int
    {
        $result = wc_update_product_stock($product, $quantity, $mode);

        if ($result === false || $result === null) {
            throw ApiException::internal('The stock level could not be updated.');
        }

        $this->syncParent($product);

        return (int) $result;
    }

    /** Only the fields present are touched, so a PATCH cannot reset the rest. */
    public function saveSettings(WC_Product $product, InventorySettingsInput $input): WC_Product
    {
        $setters = [
            'manage_stock' => 'set_manage_stock',
            'stock_status' => 'set_stock_status',
            'backorders' => 'set_backorders',
            'low_stock_amount' => 'set_low_stock_amount',
        ];

        foreach ($setters as $field => $setter) {
            if ($input->has($field)) {
                $product->{$setter}($input->get($field));
            }
        }

        $product->save();

        $this->syncParent($product);

        return $this->find($product->get_id()) ?? $product;
    }

    /**
     * The threshold that applies to this product, with WooCommerce's own
     * fallbacks: the product's own value, then its parent's, then the store's.
     */
    public function lowStockAmount(WC_Product $product): int
    {
        return (int) wc_get_low_stock_amount($product);
    }

    public function globalLowStockThreshold(): int
    {
        return (int) get_option('woocommerce_notify_low_stock_amount', 2);
    }

    /**
     * A variable product caches its children's stock status and price range.
     * Changing a variation's stock without syncing leaves the parent claiming
     * the shop still has stock it does not.
     */
    private function syncParent(WC_Product $product): void
    {
        if (!$product instanceof WC_Product_Variation) {
            return;
        }

        $parentId = (int) $product->get_parent_id();

        if ($parentId > 0) {
            WC_Product_Variable::sync($parentId);
        }
    }

    private function categorySlug(int $termId): string
    {
        $term = get_term($termId, 'product_cat');

        return is_object($term) && isset($term->slug) ? (string) $term->slug : '';
    }
}
