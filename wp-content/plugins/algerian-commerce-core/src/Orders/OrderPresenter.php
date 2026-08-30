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
 * `shipping`, `line_items` and `shipping_amount` come back in exactly the form
 * OrderInput accepts, so GET → edit → PATCH round trips without translation.
 *
 * `shipping_amount` beside `shipping_total` is the one place this shape carries
 * two keys for one kind of money, and it is not redundancy. One is what a
 * caller may state, the other is what WooCommerce derives from the shipping
 * lines; `OrderInput`'s class docblock argues why the derived one cannot also
 * be the settable one. See `shippingAmount()` below for why the two disagree on
 * an order the storefront placed.
 *
 * That mirror is a requirement rather than a nicety, and a line's `price` is
 * where it was most recently broken. `LineItemInput` gained a settable price
 * before this file emitted one, which made the round trip *lossy in money*: a
 * client that read an order and PATCHed it back re-priced every hand-priced
 * line from the catalogue, with no error and nothing in the payload to hint at
 * it. Anything added to the write shape has to arrive here in the same change.
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
            // Directly above shipping_total, and that adjacency is deliberate:
            // the pair is the one place this shape has two keys for one kind of
            // money, and a reader comparing them is exactly who needs to see
            // them together. shippingAmount() is what you send, get_shipping_total()
            // is what the order works out.
            'shipping_amount' => self::shippingAmount($order),
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
                'price' => self::manualPrice($item),
                'subtotal' => self::money($item->get_subtotal()),
                'total' => self::money($item->get_total()),
            ];
        }

        return $items;
    }

    /**
     * The unit price a person typed for this line, or null when the catalogue
     * priced it.
     *
     * ## Why null, and not the price the line was actually charged at
     *
     * This field mirrors the write field, and on the write side `price` means
     * *override* — `LineItemInput` treats `null` and `""` as "no manual price,
     * let the catalogue price this line" and `0` as the real amount zero. So
     * `null` here is not "unknown", it is the same statement the caller would
     * make to hand the line back to the catalogue, and the round trip that
     * motivated this field works by construction: GET an order, change the
     * shipping address, PATCH the whole object, and every line that was priced
     * by hand keeps its price while every line that was not is re-priced from
     * the catalogue — each because of what it said, not by luck.
     *
     * **On an order that is holding stock it no longer works, and the field is
     * why.** `OrderService::guardManualPricesWritable()` refuses a stated price
     * there, and this echo is a statement — nothing downstream can tell it from
     * an amount somebody just typed. So a whole-body PATCH of a stock-holding
     * order carrying a hand-priced line is a 409 naming the stock, and the
     * client has to omit `line_items` from it, exactly as it already must on a
     * committed order. A catalogue-priced line is unaffected in either case,
     * because `null` states nothing. That is a cost of emitting the field, and
     * it is still the right trade: the alternative was losing the agreed amount
     * silently, which is the data-loss path this field exists to close.
     *
     * The alternative worth naming, because it is the obvious one: emit the
     * *effective* unit price, `total / quantity`, on every line. It would also
     * round trip, and it would be wrong twice. A catalogue-priced line read
     * today and PATCHed tomorrow would come back as a manual price, silently
     * freezing yesterday's catalogue amount into an order as though somebody
     * had chosen it — and every line would then look manually priced, which
     * would leave `OrderService::manualPrices()` unable to say which prices a
     * human actually set: it lists exactly the lines carrying this meta, so
     * emitting an effective price here would put every line in every audit row
     * and make the one line that *is* a decision indistinguishable. A panel
     * that wants the effective unit price already has it: `total / quantity`,
     * both emitted above.
     *
     * That leaves the case the item asked about directly — a line overridden
     * *to* the catalogue amount. It reads back as its price, not as null,
     * because the meta records the decision rather than the difference. "No
     * override" and "overridden to 1 500 when the catalogue also says 1 500"
     * are distinguishable here, which they would not be under any rule derived
     * from the numbers on the line.
     *
     * ## The amount itself
     *
     * Through `money()` like every other amount in this shape, so a client
     * never has to handle two decimal conventions in one object — `price` is
     * `"1200.50"` beside a `total` of `"2401.00"`. That does normalize: the
     * stored string is what the caller typed (`LineItemInput` is pure and
     * cannot ask WooCommerce what the store's precision is), so a price typed
     * with more decimals than `wc_get_price_decimals()` reads back rounded, and
     * PATCHing it back changes the line by that fraction. The line's own
     * `subtotal` and `total` were computed from the unrounded amount and are
     * presented under the same rounding, so the read shape is at least
     * self-consistent. Storing the typed string rather than a rounded one is
     * deliberate: the audit has to record what the person actually wrote.
     *
     * One `is_numeric()` answers both questions this needs answered, which is
     * why there is no separate emptiness test in front of it:
     *
     *  - **Absent.** `get_meta()` returns `''` for a key that was never written
     *    rather than null — the WooCommerce behaviour `COD\CodRepository`
     *    documents — and `''` is not numeric.
     *  - **Unreadable.** Item meta is a public store; another plugin, a WP-CLI
     *    script or a hand edit can put a word under this key, and `money()`
     *    would turn one into `"0.00"` — publishing a free line nobody sold.
     *
     * Both are reported as no manual price, which is the reading the order's
     * own totals support. `'0'` passes, and must: a free line is a price, and
     * the whole argument for reversing the old refusal was that a price of
     * nothing is witnessed rather than prevented.
     */
    private static function manualPrice(WC_Order_Item_Product $item): ?string
    {
        $stored = $item->get_meta(OrderRepository::MANUAL_PRICE_META, true);

        return is_numeric($stored) ? self::money($stored) : null;
    }

    /**
     * The delivery fee a person stated on this order, or null when nobody did.
     *
     * ## Null is a statement, not a gap — and it is not "no delivery charge"
     *
     * This mirrors the write field, and on the write side `shipping_amount`
     * means *state the order's delivery charge*: `OrderInput` drops `null` and
     * `""` as "this request says nothing about delivery" and treats `0` as the
     * real amount zero. So `null` here is the same thing the caller would send
     * to leave the shipping line alone, and the round trip works by
     * construction — GET an order the checkout quoted, change the address,
     * PATCH the whole object back, and the quoted fee is left exactly where it
     * was rather than deleted by a field the client never touched.
     *
     * **An order that reads `shipping_amount: null` may still be charging for
     * delivery**, and any client that shows one number must show
     * `shipping_total`, not this. The pair says two different things: what this
     * API was told, and what the order is actually charging. They agree on
     * every order this API wrote, because `OrderRepository::replaceShippingLine()`
     * collapses the statement to one line and `calculate_totals():2163` sums
     * that one line. They disagree on an order the checkout placed, where the
     * fee came from §14's tariff and nobody typed anything.
     *
     * The alternative worth naming, because it is the obvious one: emit
     * `shipping_total` under both names. It would round trip, and it would be
     * wrong the same way `manualPrice()` describes for a line — a quoted fee
     * read today and PATCHed tomorrow would come back marked as somebody's
     * decision, and `OrderService::manualPrices()` could no longer say which
     * delivery charges a human actually set, because it reads the same meta
     * this method reads.
     *
     * ## Read off the shipping line, and off the first one that carries it
     *
     * `MANUAL_PRICE_META` is written onto the shipping item, which is the only
     * thing that distinguishes a typed fee from a quoted one — see that
     * constant, and note that `method_title` is `'Delivery'` on both.
     *
     * The loop does not assume there is at most one shipping line. Our writes
     * produce exactly one, but WooCommerce allows several and an order created
     * by its admin screens or another plugin can have them; a hard `[0]` would
     * fatal or silently read the wrong line. Taking the first that carries the
     * meta is the reading that matches what the field means — the amount *this
     * API* last stated — and on an order with several lines the honest number
     * is `shipping_total` anyway.
     *
     * `is_numeric()` answers both of the questions this needs answered, as in
     * `manualPrice()`: `get_meta()` returns `''` for a key never written, and
     * item meta is a public store where another plugin could leave a word that
     * `money()` would publish as `"0.00"` — a delivery charge of nothing that
     * nobody granted. `'0'` passes, and must, because a stated zero is how a
     * fee is cancelled.
     */
    private static function shippingAmount(WC_Order $order): ?string
    {
        foreach ($order->get_items('shipping') as $item) {
            $stored = $item->get_meta(OrderRepository::MANUAL_PRICE_META, true);

            if (is_numeric($stored)) {
                return self::money($stored);
            }
        }

        return null;
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
