<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WC_Product;

/**
 * Shapes a WC_Product for the API.
 *
 * One place decides the wire format, so the Next.js clients see a stable
 * contract that does not shift when WooCommerce changes its internals. Prices
 * stay strings — they are decimal amounts in DZD, and a float would introduce
 * rounding where money is concerned.
 */
final class ProductPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(WC_Product $product): array
    {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'featured' => $product->get_featured(),
            'catalog_visibility' => $product->get_catalog_visibility(),
            'sku' => $product->get_sku(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'on_sale' => $product->is_on_sale(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'weight' => $product->get_weight(),
            'category_ids' => array_map('intval', $product->get_category_ids()),
            'tag_ids' => array_map('intval', $product->get_tag_ids()),
            'permalink' => $product->get_permalink(),
            'date_created' => self::date($product->get_date_created()),
            'date_modified' => self::date($product->get_date_modified()),
        ];
    }

    /** @param list<WC_Product> $products */
    public static function toArrayList(array $products): array
    {
        return array_values(array_map([self::class, 'toArray'], $products));
    }

    private static function date(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date')
            ? $date->date('c')
            : null;
    }
}
