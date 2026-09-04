<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use WC_Cart;
use WC_Coupon;
use WC_Discounts;

/**
 * Why WooCommerce refused a coupon — the code, not just the sentence.
 *
 * ## The message alone is not enough, and one of them is actively wrong
 *
 * `WC_Cart::apply_coupon()` returns a bare `false` and leaves its reason in the
 * notice queue, so `CartService` used to read the queue back. That gives a
 * sentence in whatever language the backend runs in, and nothing a storefront
 * can branch on or translate — an expired code, a code that does not exist and
 * a minimum-spend failure are three different recoveries reported as three
 * strings.
 *
 * Worse, one of those sentences is a lie. `WC_Discounts::is_coupon_valid()`
 * (WooCommerce 10.x, `includes/class-wc-discounts.php`) never calls
 * `validate_coupon_sale_items()`, so error 110 — *"not valid for sale items"* —
 * is unreachable from the cart. A **percent** coupon is in
 * `wc_get_product_coupon_types()`, so it goes through
 * `validate_coupon_excluded_items()` instead, which asks
 * `WC_Coupon::is_valid_for_product()` per line; that method's last rule is
 * `exclude_sale_items && $product->is_on_sale()`. Every line fails, and the
 * error thrown is **109 — "not applicable to selected products"**.
 *
 * Measured 2026-09-04 on this shop: coupon `yani12` (percent, *exclude sale
 * items* on, **no product and no category restrictions at all**) against a cart
 * holding one on-sale product. The shopper is told the coupon does not apply to
 * their products, and the coupon's own configuration says it applies to every
 * product in the shop. There is no way to act on that.
 *
 * ## How the cause is established: by asking WooCommerce, not by restating it
 *
 * `CartService`'s docblock is explicit that the rules stay WooCommerce's,
 * because a second copy of them here is a copy that can disagree with the one
 * that actually applies the discount. So `narrow()` does not re-implement a
 * single check. It takes the coupon WooCommerce just rejected, switches **one**
 * exclusion off on an in-memory copy, and asks `WC_Discounts::is_coupon_valid()`
 * again. If the coupon becomes valid, that rule is provably the cause — proved
 * by the same validator that made the original decision. If it does not, we
 * claim nothing and keep WooCommerce's own answer.
 *
 * Nothing is saved. The probes build a fresh `WC_Coupon` from the code each
 * time and the objects are discarded; the coupon in the database is untouched.
 * They run only on the failure path, only for error 109, and cost at most three
 * validations of a cart that is already in memory.
 */
final class CouponRejection
{
    /**
     * WooCommerce's `WC_Coupon::E_*` codes under names a client can branch on.
     *
     * Several codes collapse onto one slug on purpose: 115 and 116 are the same
     * refusal ("another order is holding this code") told to a guest and to a
     * signed-in shopper, and a storefront has one thing to say about both.
     */
    private const REASONS = [
        WC_Coupon::E_WC_COUPON_INVALID_FILTERED => 'not_valid',
        WC_Coupon::E_WC_COUPON_INVALID_REMOVED => 'not_valid',
        WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED => 'email_required',
        WC_Coupon::E_WC_COUPON_ALREADY_APPLIED => 'already_applied',
        WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY => 'individual_use_only',
        WC_Coupon::E_WC_COUPON_NOT_EXIST => 'not_found',
        WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED => 'usage_limit_reached',
        WC_Coupon::E_WC_COUPON_EXPIRED => 'expired',
        WC_Coupon::E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET => 'minimum_spend_not_met',
        WC_Coupon::E_WC_COUPON_NOT_APPLICABLE => 'not_applicable',
        WC_Coupon::E_WC_COUPON_NOT_VALID_SALE_ITEMS => 'sale_items_excluded',
        WC_Coupon::E_WC_COUPON_PLEASE_ENTER => 'code_required',
        WC_Coupon::E_WC_COUPON_MAX_SPEND_LIMIT_MET => 'maximum_spend_exceeded',
        WC_Coupon::E_WC_COUPON_EXCLUDED_PRODUCTS => 'excluded_products',
        WC_Coupon::E_WC_COUPON_EXCLUDED_CATEGORIES => 'excluded_categories',
        WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK => 'usage_limit_reached',
        WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST => 'usage_limit_reached',
    ];

    /**
     * The exclusions a 109 can really be, each with the setter that switches it
     * off and the code whose message says so in WooCommerce's own words.
     *
     * Ordered by how often a shop hits them. Sale items first: it is the only
     * one of the three that a shop can switch on without naming anything, which
     * is exactly why it is the one that surprises people.
     *
     * @var list<array{0: string, 1: string, 2: mixed, 3: int}>
     */
    private const PROBES = [
        ['sale_items_excluded', 'set_exclude_sale_items', false, WC_Coupon::E_WC_COUPON_NOT_VALID_SALE_ITEMS],
        ['excluded_products', 'set_excluded_product_ids', [], WC_Coupon::E_WC_COUPON_EXCLUDED_PRODUCTS],
        ['excluded_categories', 'set_excluded_product_categories', [], WC_Coupon::E_WC_COUPON_EXCLUDED_CATEGORIES],
    ];

    private function __construct(
        private readonly string $reason,
        private readonly string $message
    ) {
    }

    /** A stable slug from `self::REASONS`; safe to branch on and to translate. */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * WooCommerce's sentence, still carrying its HTML and entities.
     *
     * Deliberately not cleaned here — `CartService::plainText()` is the one
     * place this API turns WooCommerce's markup into text, and two normalisers
     * are how two endpoints start answering differently.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * Run `$apply` and report the rejection, or `null` when it succeeded.
     *
     * `woocommerce_coupon_error` is the hook every refusal passes through:
     * `WC_Coupon::get_coupon_error()` ends with it, and
     * `WC_Discounts::is_coupon_valid()` applies it to the message it is about to
     * return. It carries the numeric code, which the notice queue does not.
     *
     * The listener runs at the lowest priority so it observes the final message
     * rather than one an earlier filter was still going to change, and it is
     * removed in a `finally` so a throw inside WooCommerce cannot leave a
     * closure attached to a global hook for the rest of the request.
     *
     * @param callable():bool $apply
     */
    public static function of(WC_Cart $cart, string $code, callable $apply): ?self
    {
        $seen = null;

        $listener = static function (mixed $message, mixed $wooCode, mixed $coupon) use (&$seen): mixed {
            $seen = ['code' => (int) $wooCode, 'message' => (string) $message];

            return $message;
        };

        add_filter('woocommerce_coupon_error', $listener, PHP_INT_MAX, 3);

        try {
            $applied = (bool) $apply();
        } finally {
            remove_filter('woocommerce_coupon_error', $listener, PHP_INT_MAX);
        }

        if ($applied) {
            return null;
        }

        /*
         * No code at all is reachable: `apply_coupon()` returns false without a
         * notice when coupons are switched off shop-wide. Saying so beats
         * reporting a rule the shopper could try to satisfy.
         */
        if ($seen === null) {
            return new self(
                function_exists('wc_coupons_enabled') && !wc_coupons_enabled() ? 'coupons_disabled' : 'not_valid',
                ''
            );
        }

        $wooCode = (int) $seen['code'];
        $reason = self::REASONS[$wooCode] ?? 'not_valid';
        $message = (string) $seen['message'];

        if ($wooCode === WC_Coupon::E_WC_COUPON_NOT_APPLICABLE) {
            return self::narrow($cart, $code, $reason, $message);
        }

        return new self($reason, $message);
    }

    /**
     * Which exclusion turned a 109 into a refusal — decided by re-validating.
     *
     * A 109 raised by `validate_coupon_product_ids()` or
     * `validate_coupon_product_categories()` is accurate as it stands: the
     * coupon names products or categories and the cart holds none of them. Those
     * coupons fail every probe below (switching an *exclusion* off cannot
     * satisfy an *inclusion* list) and keep WooCommerce's message, which is the
     * conservative outcome and the one this method is built to fall back to.
     *
     * A cart that satisfies two exclusions at once also falls through. Half a
     * cause is worse than WooCommerce's vague answer, because it sends a shop
     * to change a setting that will not fix anything.
     */
    private static function narrow(WC_Cart $cart, string $code, string $reason, string $message): self
    {
        foreach (self::PROBES as [$slug, $setter, $off, $errorCode]) {
            $probe = new WC_Coupon($code);

            if (!method_exists($probe, $setter)) {
                continue;
            }

            $probe->{$setter}($off);

            if (is_wp_error((new WC_Discounts($cart))->is_coupon_valid($probe))) {
                continue;
            }

            /*
             * The message comes off an unmodified coupon, not off `$probe`:
             * WooCommerce builds the excluded-products and excluded-categories
             * sentences by naming what is in the cart, and `$probe` no longer
             * remembers what to name.
             */
            return new self($slug, (string) (new WC_Coupon($code))->get_coupon_error($errorCode));
        }

        return new self($reason, $message);
    }
}
