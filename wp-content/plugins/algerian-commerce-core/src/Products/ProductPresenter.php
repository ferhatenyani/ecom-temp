<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WC_Product;
use WC_Product_Variation;

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
            'attributes' => self::attributes($product),
            'variations' => array_map('intval', $product->get_children()),
            // Writable ids plus a read-only enriched form, so a client has
            // the URLs without a second request and can still PATCH the
            // object straight back.
            'image_id' => (int) $product->get_image_id(),
            'gallery_image_ids' => array_map('intval', $product->get_gallery_image_ids()),
            'image' => self::image((int) $product->get_image_id()),
            'gallery' => array_values(array_filter(array_map(
                [self::class, 'image'],
                array_map('intval', $product->get_gallery_image_ids())
            ))),
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

    /**
     * Attributes are flattened to the same shape the write endpoints accept,
     * so a client can GET, edit and PATCH without translating.
     *
     * @return list<array<string, mixed>>
     */
    private static function attributes(WC_Product $product): array
    {
        $attributes = [];

        foreach ($product->get_attributes() as $attribute) {
            $options = $attribute->is_taxonomy()
                ? array_map(static fn ($term): string => (string) $term->slug, $attribute->get_terms() ?? [])
                : array_map('strval', $attribute->get_options());

            $attributes[] = [
                'id' => $attribute->get_id(),
                'name' => $attribute->get_name(),
                'options' => array_values($options),
                'visible' => $attribute->get_visible(),
                'variation' => $attribute->get_variation(),
                'position' => $attribute->get_position(),
            ];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>|null null when there is no image, or when
     *         the attachment has since been deleted
     */
    private static function image(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_url($id, 'full');

        if ($src === false) {
            return null;
        }

        return [
            'id' => $id,
            'src' => $src,
            'thumbnail' => wp_get_attachment_image_url($id, 'woocommerce_thumbnail') ?: $src,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }

    /** @return array<string, mixed> */
    public static function variation(WC_Product_Variation $variation): array
    {
        return [
            'id' => $variation->get_id(),
            'parent_id' => $variation->get_parent_id(),
            'sku' => $variation->get_sku(),
            'status' => $variation->get_status(),
            'description' => $variation->get_description(),
            'price' => $variation->get_price(),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price(),
            'on_sale' => $variation->is_on_sale(),
            'manage_stock' => $variation->get_manage_stock(),
            'stock_quantity' => $variation->get_stock_quantity(),
            'stock_status' => $variation->get_stock_status(),
            'weight' => $variation->get_weight(),
            'attributes' => VariationRepository::normalizeCombination($variation->get_attributes()),
            'image_id' => (int) $variation->get_image_id(),
            'image' => self::image((int) $variation->get_image_id()),
            'date_created' => self::date($variation->get_date_created()),
            'date_modified' => self::date($variation->get_date_modified()),
        ];
    }

    /** @param list<WC_Product_Variation> $variations */
    public static function variationList(array $variations): array
    {
        return array_values(array_map([self::class, 'variation'], $variations));
    }

    private static function date(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date')
            ? $date->date('c')
            : null;
    }
}
