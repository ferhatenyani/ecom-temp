<?php

declare(strict_types=1);

namespace AlgerianCommerce\Coupons;

use WC_Coupon;
use WP_Term;

/**
 * Turns a coupon's four id arrays into something a person can read.
 *
 * ## Why this exists at all
 *
 * `product_categories: [16]` is a correct API response and an unusable screen. To
 * render *Tapis et Textiles* a client needs `/product-categories`, and to render a
 * product's name it needs `/products` — **and both are `ac_manage_products`, which
 * the Marketing Manager does not hold.** That is one of the three roles carrying
 * `ac_manage_coupons`, and it is the role whose job coupons are. So the one reader
 * most likely to open a coupon was the one reader who could never see what it
 * applied to, and no amount of client work could fix it.
 *
 * Resolving here costs the caller nothing extra, needs no second capability, and
 * discloses strictly less than `/products` would: a name and a SKU for ids the
 * coupon already names. Prices, stock, costs and drafts-versus-published logic
 * stay behind the capability that governs them.
 *
 * ## An id that resolves to nothing is reported, never dropped
 *
 * WooCommerce stores restriction ids without checking them, and until
 * `CouponInput` grew a validator every id this API had ever been handed was
 * stored blind — a coupon in this shop was made to carry `product_ids: [999999]`
 * and a customer id, both with a 200. Those coupons exist. So do coupons whose
 * products were deleted afterwards, which is the ordinary case and will keep
 * happening: **validation on write cannot make a read total.**
 *
 * A missing id therefore comes back as `{id, name: null, missing: true}` rather
 * than being filtered out. Dropping it would be worse than showing it: a form that
 * loaded four restrictions, rendered three and saved would silently delete the
 * fourth, and the only evidence would be a discount that stopped applying.
 *
 * ## Detail only
 *
 * `CouponPresenter` takes this as an optional argument, the way
 * `Customers\CustomerPresenter` takes `statistics`, and the list route does not
 * pass it. A list of 100 coupons would be 100 rows of resolution to populate a
 * column no list shows; the selection matters on the screen that can change it.
 */
final class CouponRestrictions
{
    /** The two product fields and the two category fields, in presentation order. */
    public const PRODUCT_FIELDS = ['product_ids', 'excluded_product_ids'];
    public const CATEGORY_FIELDS = ['product_categories', 'excluded_product_categories'];

    /**
     * Every restriction on this coupon, resolved in two queries rather than one per
     * id: the ids across *both* product fields are looked up together, and so are
     * the ids across both category fields.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function resolve(WC_Coupon $coupon): array
    {
        $ids = [];

        foreach ([...self::PRODUCT_FIELDS, ...self::CATEGORY_FIELDS] as $field) {
            $ids[$field] = array_values(array_map('intval', (array) $coupon->{'get_' . $field}()));
        }

        $products = self::products(array_merge(...array_map(
            static fn (string $field): array => $ids[$field],
            self::PRODUCT_FIELDS
        )));

        $categories = self::categories(array_merge(...array_map(
            static fn (string $field): array => $ids[$field],
            self::CATEGORY_FIELDS
        )));

        $resolved = [];

        foreach (self::PRODUCT_FIELDS as $field) {
            $resolved[$field] = self::rows($ids[$field], $products);
        }

        foreach (self::CATEGORY_FIELDS as $field) {
            $resolved[$field] = self::rows($ids[$field], $categories);
        }

        return $resolved;
    }

    /**
     * @param list<int> $ids
     * @param array<int, array<string, mixed>> $known
     * @return list<array<string, mixed>>
     */
    private static function rows(array $ids, array $known): array
    {
        $rows = [];

        foreach ($ids as $id) {
            // `missing` is emitted on every row, not only on the broken ones. A key
            // that appears solely in the failure case is a key clients forget to
            // check, and this one decides whether a row is a name or a warning.
            $rows[] = $known[$id] ?? ['id' => $id, 'name' => null, 'missing' => true];
        }

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        /*
         * `post_status: any` on purpose. A coupon restricted to a draft product is a
         * misconfiguration a shop needs to *see*, and filtering the draft out here
         * would render it as `missing` — which would send someone looking for a
         * deleted product that is sitting in their drafts. `status` is emitted so a
         * client can say which it is.
         *
         * `product_variation` is included because WooCommerce accepts a variation id
         * in `product_ids`, and a variation resolved as `missing` would be the same
         * lie in a second place.
         */
        $posts = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post__in' => $ids,
            'post_status' => 'any',
            'posts_per_page' => count($ids),
            'orderby' => 'post__in',
            'no_found_rows' => true,
            // Primes the meta cache in the same round trip, so reading each `_sku`
            // below is not a query per row.
            'update_post_meta_cache' => true,
        ]);

        $found = [];

        foreach ($posts as $post) {
            $id = (int) $post->ID;
            $sku = (string) get_post_meta($id, '_sku', true);

            $found[$id] = [
                'id' => $id,
                'name' => $post->post_title,
                // Deliberately the only two product fields besides identity. No
                // price, no stock, no cost — see the class docblock.
                'sku' => $sku === '' ? null : $sku,
                'status' => get_post_status($id) ?: 'publish',
                'missing' => false,
            ];
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function categories(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'include' => $ids,
            'hide_empty' => false,
        ]);

        $found = [];

        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }

                $found[(int) $term->term_id] = [
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'missing' => false,
                ];
            }
        }

        return $found;
    }
}
