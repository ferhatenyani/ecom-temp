<?php

declare(strict_types=1);

namespace AlgerianCommerce\Coupons;

use WC_Coupon;

/**
 * Shapes a `WC_Coupon` for the API — docs/PLAN.md §21.
 *
 * The read shape mirrors the write shape for everything writable, so GET → edit
 * → PATCH round trips without translation, exactly as `Orders\OrderPresenter`
 * does it. Money is decimal strings; `null` means "no limit" rather than zero,
 * because a usage limit of 0 and no usage limit are different coupons.
 *
 * `maximum_amount` is emitted under its real meaning — the largest cart the
 * coupon may be used against — and there is deliberately no `maximum_discount`
 * beside it. See `CouponInput` for why: WooCommerce has no discount cap, and a
 * field that looked like one would be read as one.
 */
final class CouponPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(WC_Coupon $coupon): array
    {
        return [
            'id' => $coupon->get_id(),
            'code' => $coupon->get_code(),
            'status' => get_post_status($coupon->get_id()) ?: 'publish',
            'discount_type' => $coupon->get_discount_type(),
            'amount' => self::money($coupon->get_amount()),
            'description' => $coupon->get_description(),

            'date_expires' => self::date($coupon->get_date_expires()),
            // `null`, not `"0.00"`. WooCommerce stores an absent threshold as
            // `'0'`, which reads as "a minimum spend of nothing" — and worse,
            // round-tripped back through a PATCH it made every coupon with a
            // minimum and no maximum fail the min ≤ max check against a
            // maximum that did not exist. Same treatment as `usage_limit`.
            'minimum_amount' => self::threshold($coupon->get_minimum_amount()),
            'maximum_amount' => self::threshold($coupon->get_maximum_amount()),

            'usage_limit' => self::limit($coupon->get_usage_limit()),
            'usage_limit_per_user' => self::limit($coupon->get_usage_limit_per_user()),
            'limit_usage_to_x_items' => self::limit($coupon->get_limit_usage_to_x_items()),
            // Read-only: how many times it has actually been redeemed.
            'usage_count' => (int) $coupon->get_usage_count(),

            'individual_use' => (bool) $coupon->get_individual_use(),
            'free_shipping' => (bool) $coupon->get_free_shipping(),
            'exclude_sale_items' => (bool) $coupon->get_exclude_sale_items(),

            'product_ids' => self::ids($coupon->get_product_ids()),
            'excluded_product_ids' => self::ids($coupon->get_excluded_product_ids()),
            'product_categories' => self::ids($coupon->get_product_categories()),
            'excluded_product_categories' => self::ids($coupon->get_excluded_product_categories()),
            'email_restrictions' => array_values((array) $coupon->get_email_restrictions()),

            'date_created' => self::date($coupon->get_date_created()),
            'date_modified' => self::date($coupon->get_date_modified()),
        ];
    }

    /** @param array<int, mixed> $ids @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_map('intval', $ids));
    }

    /**
     * `null` rather than `0` for "no limit".
     *
     * WooCommerce stores an absent usage limit as 0, which a client would
     * reasonably read as "may be used zero times". The distinction matters
     * enough to translate it here rather than leave every consumer to know it.
     */
    private static function limit(mixed $value): ?int
    {
        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    private static function money(mixed $value): string
    {
        $value = (string) $value;

        return $value === '' ? '' : (string) wc_format_decimal($value, wc_get_price_decimals());
    }

    /**
     * A spend threshold, or `null` when there is none.
     *
     * WooCommerce writes an unset minimum or maximum as the string `'0'`, which
     * is indistinguishable from a real threshold of zero — and a real threshold
     * of zero is meaningless, so the ambiguity only ever resolves one way.
     */
    private static function threshold(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || (float) $value <= 0.0 ? null : self::money($value);
    }

    private static function date(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date') ? $date->date('c') : null;
    }
}
