<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;

/**
 * Shapes a WC_Order for the API.
 *
 * One place decides the wire format, so the Next.js clients see a stable
 * contract that does not shift when WooCommerce changes its internals — or
 * when the store moves between HPOS and legacy post storage, which this shape
 * is deliberately blind to.
 *
 * Money is emitted as decimal strings in the store currency. WooCommerce
 * returns some totals as floats and others as strings; every one of them goes
 * through wc_format_decimal() here so a client never has to guess, and so no
 * amount picks up binary-floating-point rounding on the way out.
 *
 * The read shape mirrors the write shape for everything writable: `billing`,
 * `shipping` and `line_items` come back in exactly the form OrderInput accepts,
 * so GET → edit → PATCH round trips without translation.
 */
final class OrderPresenter
{
    /** @return array<string, mixed> */
    public static function toArray(WC_Order $order): array
    {
        return [
            'id' => $order->get_id(),
            // Distinct from the id: a plugin may format order numbers, and the
            // storefront shows the number while the API addresses the id.
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'customer_id' => $order->get_customer_id(),
            'customer_note' => $order->get_customer_note(),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'billing' => self::billing($order),
            'shipping' => self::shipping($order),
            'line_items' => self::lineItems($order),
            'discount_total' => self::money($order->get_discount_total()),
            'shipping_total' => self::money($order->get_shipping_total()),
            'total_tax' => self::money($order->get_total_tax()),
            'subtotal' => self::money($order->get_subtotal()),
            'total' => self::money($order->get_total()),
            // Operational state a client needs before offering an action.
            // is_editable is WooCommerce's own rule — line items may only be
            // rewritten while an order is pending or on hold.
            'is_editable' => $order->is_editable(),
            'needs_payment' => $order->needs_payment(),
            // Read through the repository: it is the only file that talks to
            // an order data store, and the service needs the same answer.
            'stock_reduced' => OrderRepository::stockReduced($order),
            'date_created' => self::date($order->get_date_created()),
            'date_modified' => self::date($order->get_date_modified()),
            'date_paid' => self::date($order->get_date_paid()),
            'date_completed' => self::date($order->get_date_completed()),
        ];
    }

    /**
     * @param list<WC_Order> $orders
     * @return list<array<string, mixed>>
     */
    public static function toArrayList(array $orders): array
    {
        return array_values(array_map([self::class, 'toArray'], $orders));
    }

    /** @return array<string, string> */
    private static function billing(WC_Order $order): array
    {
        return [
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'company' => $order->get_billing_company(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];
    }

    /** @return array<string, string> */
    private static function shipping(WC_Order $order): array
    {
        return [
            'first_name' => $order->get_shipping_first_name(),
            'last_name' => $order->get_shipping_last_name(),
            'company' => $order->get_shipping_company(),
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
            'phone' => $order->get_shipping_phone(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function lineItems(WC_Order $order): array
    {
        $items = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $items[] = [
                'id' => $item->get_id(),
                'name' => $item->get_name(),
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity' => (int) $item->get_quantity(),
                'sku' => self::sku($item),
                'subtotal' => self::money($item->get_subtotal()),
                'total' => self::money($item->get_total()),
            ];
        }

        return $items;
    }

    /**
     * The SKU as it is *now*, or '' when the product has since been deleted.
     *
     * Deliberately not stored on the line: an order line keeps the name and
     * price it was placed at, but a SKU is a lookup key, and showing a stale
     * one would send a picker to the wrong shelf.
     */
    private static function sku(WC_Order_Item $item): string
    {
        $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;

        return is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
    }

    private static function money(mixed $value): string
    {
        return (string) wc_format_decimal((string) $value, wc_get_price_decimals());
    }

    private static function date(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date')
            ? $date->date('c')
            : null;
    }
}
