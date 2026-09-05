<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use WC_Cart;
use WC_Product;

/**
 * Shapes a `WC_Cart` for the API — roadmap §59b.
 *
 * The same job `Orders\OrderPresenter` does for an order, and the same rules:
 * one place decides the wire format, and **money is emitted as decimal strings
 * in the store currency**, every one of them through `wc_format_decimal()` so
 * nothing picks up binary-floating-point rounding on the way out. WooCommerce
 * returns some cart totals as floats and others as strings; a client should not
 * have to know which.
 *
 * **Every price here is read from the cart, never echoed from a request.** That
 * is §59b's rule made visible: the only numbers a caller sends are a product id
 * and a quantity, and nothing else in this payload has a client-supplied
 * ancestor. A `price` field that could be written would be a shop selling at
 * whatever the customer types.
 *
 * Two fields exist so a storefront does not have to guess, and both are facts
 * about the cart rather than about the shop:
 *
 *  - `needs_shipping` — whether anything in the basket is a shippable product.
 *    A cart of downloads does not get an address form. It is `WC_Cart`'s own
 *    answer, so a virtual product added later cannot make it wrong.
 *  - `shipping` is deliberately **absent**. §14 decides what this shop charges
 *    for delivery and it needs a wilaya and a commune, which a cart does not
 *    have; quoting it here would mean inventing a destination. `POST /checkout`
 *    is where an address exists and where `RateResolver` is asked.
 */
final class CartPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(WC_Cart $cart, ?OptionPriceSubscriber $optionPrices = null): array
    {
        return [
            'items' => self::items($cart, $optionPrices),
            'items_count' => $cart->get_cart_contents_count(),
            'coupons' => self::coupons($cart),
            'currency' => get_woocommerce_currency(),
            // CartService::needsShipping(), not WC_Cart::needs_shipping(): the
            // WooCommerce one counts shipping-zone methods, and §14 replaced
            // zones, so it answers false for a cart full of rugs.
            'needs_shipping' => CartService::needsShipping($cart),
            'totals' => self::totals($cart),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function items(WC_Cart $cart, ?OptionPriceSubscriber $optionPrices): array
    {
        $items = [];

        foreach ($cart->get_cart() as $key => $line) {
            $product = $line['data'] ?? null;

            if (!$product instanceof WC_Product) {
                continue;
            }

            $items[] = [
                // WooCommerce's own line key — a 32-char hash of the product,
                // variation and any add-on data. It addresses *this line*, which
                // is why PATCH and DELETE take it rather than a product id: the
                // same product can legitimately sit in a cart twice.
                'key' => (string) $key,
                'product_id' => (int) ($line['product_id'] ?? 0),
                'variation_id' => (int) ($line['variation_id'] ?? 0),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'quantity' => (int) ($line['quantity'] ?? 0),
                // Unit price as the catalogue states it today, not as it stood
                // when the line was added. A cart is a quote, and re-reading is
                // what makes it an honest one.
                'price' => self::money($product->get_price()),
                'line_subtotal' => self::money($line['line_subtotal'] ?? 0),
                'line_total' => self::money($line['line_total'] ?? 0),
                'image' => self::image($product),
                'stock_status' => $product->get_stock_status(),
                // Whether stock is tracked and, when it is, the current
                // ceiling. Published on every cart read so a storefront's
                // stepper can clamp against the same number the backend
                // enforces in `CartService::assertStockAvailable()` —
                // avoiding a round-trip refusal for a shopper trying to
                // raise a legitimate cart line past what the shop holds.
                // Null when stock is unmanaged (no numeric ceiling exists)
                // or backorders are allowed (the shop opted in to selling
                // past zero).
                'manage_stock' => (bool) $product->managing_stock(),
                'stock_quantity' => $product->managing_stock() && !$product->backorders_allowed()
                    ? (int) $product->get_stock_quantity()
                    : null,
            ] + self::options((string) $key, $optionPrices);
        }

        return $items;
    }

    /**
     * The chosen options and what the server decided they cost — roadmap §83.
     *
     * Both come from `OptionPriceSubscriber`, which recomputed them from the
     * product's own definition during `calculate_totals()`. Neither is echoed
     * from the request: `price` above already includes the surcharge, and
     * `options_surcharge` is published beside it so a storefront can show
     * "Tapis 24,000 + gravure 500" without doing the arithmetic itself — and
     * without being trusted to.
     *
     * Absent, not empty, on a line with no options: a storefront rendering an
     * options block for every mug is a storefront that has to check anyway.
     *
     * @return array<string, mixed>
     */
    private static function options(string $key, ?OptionPriceSubscriber $optionPrices): array
    {
        $priced = $optionPrices?->pricedLine($key);

        if ($priced === null || ($priced['options'] ?? []) === []) {
            return [];
        }

        return [
            'options' => $priced['options'],
            'options_surcharge' => self::money($priced['surcharge']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function coupons(WC_Cart $cart): array
    {
        $coupons = [];

        foreach ($cart->get_coupons() as $code => $coupon) {
            $coupons[] = [
                'code' => (string) $code,
                'discount' => self::money($cart->get_coupon_discount_amount((string) $code)),
            ];
        }

        return $coupons;
    }

    /** @return array<string, string> */
    private static function totals(WC_Cart $cart): array
    {
        return [
            'subtotal' => self::money($cart->get_subtotal()),
            'discount' => self::money($cart->get_discount_total()),
            'tax' => self::money($cart->get_total_tax()),
            // 'edit' context, or WooCommerce returns the formatted HTML string
            // it would print in a template — currency symbol, span tags and all.
            'total' => self::money($cart->get_total('edit')),
        ];
    }

    private static function image(WC_Product $product): ?string
    {
        $id = (int) $product->get_image_id();

        if ($id <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_url($id, 'woocommerce_thumbnail');

        return is_string($src) && $src !== '' ? $src : null;
    }

    private static function money(mixed $value): string
    {
        return (string) wc_format_decimal((string) $value, wc_get_price_decimals());
    }
}
