<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

/**
 * One product line on an order write.
 *
 * Pure — no WordPress — so the shape rules are unit-testable. Whether the
 * product exists is a question for the repository, which is the only layer
 * allowed to ask WooCommerce.
 *
 * ## A line may carry a price now, and that is a reversal
 *
 * This file used to say, in as many words, that there is no price field and
 * there will not be one. Keep the argument it made, because it was not wrong:
 * an amount accepted from the caller lets anyone holding `ac_manage_orders` —
 * or anyone riding a compromised admin session — write an order at a price of
 * their choosing, *including a price of nothing*. Nothing about that threat has
 * expired.
 *
 * The old docblock cited docs/SECURITY.md for it, and that citation was
 * broader than the document. SECURITY.md's money rule is "A configured line
 * prices on the server — §83", and it is scoped to the **cart**: an anonymous
 * shopper's payload, where the threat is anybody at all and the answer is that
 * `Cart\LineInput` refuses `price`, `line_total`, `subtotal`, `total`,
 * `discount` and `currency` by name. That rule is untouched here and must stay
 * untouched. Nothing in SECURITY.md says an authenticated back-office operator
 * may not state an amount, and the two cases are not the same case.
 *
 * What also changed is the realisation that refusing the field never actually
 * stopped the threat it named: the same session can already invent a 100%
 * coupon, rewrite the order's lines, or move an unpaid order to `completed`.
 * The gate was cheap to walk around and expensive to work with.
 *
 * And it was expensive. A back-office order is placed by a person on the phone
 * who has already agreed a number with the customer — a damaged copy sold at
 * half, a courier fee absorbed, a hand-negotiated bulk rate. With no price
 * field the only ways to record that were a coupon invented per order, or an
 * order whose stated total is a lie. The reference shop this one is replacing
 * does not even pretend to guard it: `OrderService.create()` there stores
 * whatever `unitPrice` the client sends, with no capability check on the amount
 * at all (`EL/api/src/main/java/com/oussamabenberkane/espritlivre/service/OrderService.java:196`,
 * `orderItem.setUnitPrice(itemDTO.getUnitPrice())`).
 *
 * ## What guards it instead
 *
 * Three things, none of which is a refusal:
 *
 *  - **The capability.** `ac_manage_orders`, asserted on every order route and
 *    again in `OrderService::create()` / `OrderService::update()`. A manual
 *    price is reachable by exactly the people who could already do worse.
 *  - **The audit.** Every manual price is recorded against the catalogue price
 *    it replaced, so a discount is always attributable to a person and an
 *    order. That is the *whole* answer to "a price of nothing": it is no longer
 *    prevented, it is witnessed.
 *
 *    This paragraph used to end "auditable in principle and unaudited in fact",
 *    and that is no longer true. `OrderService::snapshot()` carries a
 *    `manual_prices` list — one row per amount somebody chose, each naming the
 *    product, the quantity, the string that was typed and the catalogue price
 *    it displaced — and both `order.created` and `order.updated` publish it,
 *    the latter on both halves of its before/after pair. The catalogue price is
 *    frozen onto the line as `OrderRepository::CATALOGUE_PRICE_META` at the
 *    instant the line is written, because a product's price moves and asking
 *    the catalogue later answers a question nobody asked.
 *
 *    **What the record does not do is stop anything.** It is a witness, so it
 *    is only as good as somebody reading it; a price of nothing still succeeds,
 *    still ships, and is still refused by no layer of this API. The bound is
 *    worth knowing too: `OrderService::MAX_AUDITED_PRICES` lists twenty amounts
 *    in full and records a count past that, and on the `before` half of an
 *    update those omitted rows are gone with the lines they described.
 *  - **The `is_editable` gate**, unchanged. `OrderService::guardLineItemsWritable()`
 *    refuses `line_items` unless `WC_Order::is_editable()` — `pending` or
 *    `on-hold` — and a price rides on `line_items`, so it inherits that reach.
 *    A `processing`, `completed`, `cancelled` or `refunded` order cannot be
 *    repriced at all.
 *  - **And, since backend step 6, a stock gate on the price alone.**
 *    `OrderService::guardManualPricesWritable()` refuses a stated `price` on an
 *    order that is already holding stock — `OrderRepository::stockReduced()`,
 *    WooCommerce's own flag, never a list of status names.
 *
 *    **This bullet used to say the opposite and the correction is the point.**
 *    It said the boundary was `is_editable` and not "the order has not moved
 *    stock", that an `on-hold` order holding units off the shelf was therefore
 *    still repriceable, and that closing that case was a later step's to
 *    decide. It was decided, and it is closed: on such an order a stated price
 *    is now a 409, and only the price is. Everything the old text said about
 *    `is_editable` reaching less far than it sounds like remains true of the
 *    *line-item* gate — quantities on a stock-holding order still go through,
 *    the repository still returns the units, replaces the lines and takes them
 *    again (`OrderRepository::rewriteLineItems()`) — so the two gates have to be
 *    read as two. What no longer rides along with that manoeuvre is an amount
 *    somebody typed.
 *
 *    Its own limits are named where it lives, and they are not small: a
 *    quantity can still move the same order's total by any amount, and the
 *    delivery fee is still writable there. Read that docblock before quoting
 *    this one as a guarantee about money.
 *
 * ## What the reversal costs
 *
 * Three things, stated so nobody has to rediscover them:
 *
 *  1. **A round trip can restate a price nobody typed.** `OrderPresenter`'s read
 *     shape mirrors the write shape on purpose, and a panel that spreads a
 *     fetched order back into a PATCH sends every field it read — the reference
 *     admin app does exactly that, and its API had to defend against the stale
 *     total it produces (`OrderService.java`, "The admin app sends the old
 *     totalAmount from the DB (via ...fullOrder spread)"). Nothing here can
 *     tell an echoed price from a typed one, so the audit, not this file, is
 *     what makes an accidental restatement visible.
 *
 *     Step 6's stock gate inherits that blindness and turns it into a refusal:
 *     on an order that is holding stock, a whole-body PATCH of an order with a
 *     hand-priced line is a 409, because the echo *states* a price and no layer
 *     can prove it was only an echo. Restating the identical amount is refused
 *     along with changing it. The client rule that answers it is the one the
 *     README already gives for a committed order — omit `line_items` unless you
 *     mean to rewrite the lines — now reaching one status further.
 *  2. **The line's price and the line's totals can now disagree.** `subtotal`
 *     and `total` are dropped on write (see READ_ONLY) and both recomputed from
 *     `price × quantity` — backend step 3, applied in
 *     `OrderRepository::lineTotals()`. A caller who sends a price and a stale
 *     total in the same object is believed about the price and ignored about
 *     the totals, which is the right way round but is not obvious from the
 *     payload.
 *  3. **A round trip normalizes the amount.** The price is stored as the string
 *     the caller typed — see the constructor property on why this class cannot
 *     round it — and `OrderPresenter` publishes every amount through
 *     `wc_format_decimal()` at the store's precision. So a price typed with
 *     more decimals than the store keeps reads back rounded, and PATCHing the
 *     read body moves the line by that fraction. The line's own totals get the
 *     same rounding on the way out, so the shape stays self-consistent; the
 *     unrounded number survives only in what the audit records.
 *
 * Everything above about the route's contract is **read from source, not
 * measured** — the live API is unauthenticable from here (see BLOCKED.md), so
 * no refusal in this file has been observed being returned over the wire.
 */
final class LineItemInput
{
    /**
     * Emitted on read, ignored on write.
     *
     * The presenter returns a line's name, SKU and computed totals so a client
     * can display an order without a second request. Dropping them here is what
     * makes the natural round trip — GET an order, change a quantity, PATCH it
     * back — work, while a genuine typo is still an error.
     *
     * Exactly the keys the presenter emits and this class does not accept, and
     * no more — `price` is emitted too and is *not* here, because it is the one
     * computed-looking field a caller really does state. `subtotal` and `total`
     * are the interesting two now that a price is settable: they are a line's
     * money *as computed*, and a caller who sends a `price` and a stale
     * `total` in the same object is stating two different amounts. Dropping the
     * computed pair and believing the price is the only ordering that keeps a
     * total a total (`OrderInput::READ_ONLY` makes the same call for the order).
     *
     * `id` is here too, and dropping it silently is what makes a price aimed at
     * one existing line impossible to express — see the refusal in one().
     */
    private const READ_ONLY = ['id', 'name', 'sku', 'subtotal', 'total'];

    private const ALLOWED = ['product_id', 'variation_id', 'quantity', 'price'];

    /**
     * A ceiling on a manual price, matching `ShippingRuleInput::MAX_AMOUNT`.
     *
     * Not a business rule — a numeric one. The order total is recomputed as
     * `sum(price × quantity) + shipping_total` (backend step 3), and PHP turns
     * an unbounded amount into `INF` rather than an error, which would land in
     * the order book as a total nobody can read back. A price above eight
     * figures in DZD is a typo or a probe, and both deserve a 400 rather than a
     * poisoned row.
     *
     * ## The `price × quantity` product is not capped, and that is a decision
     *
     * This ceiling bounds one factor; `positiveInt()` below has no ceiling, so
     * the *product* looks unbounded. Step 3 looked at capping it and did not,
     * for two reasons, the first measured and the second structural.
     *
     * **The INF this constant exists to prevent is already unreachable.**
     * `positiveInt()` casts through `(int)`, and a float too large for an int
     * does not survive that cast as a positive number — measured on the pinned
     * PHP 8.4: `(int) 1e19` is `-8446744073709551616` and `(int) 1e301` is `0`,
     * both refused by the `> 0` test. So a quantity cannot exceed `PHP_INT_MAX`,
     * about 9.2 × 10^18, and `9999999.99 × PHP_INT_MAX` is roughly 9.2 × 10^25
     * — enormous, and finite. Reaching `INF` (about 1.8 × 10^308) would need a
     * quantity no payload can express.
     *
     * **What is reachable is a huge finite total, and it is not this field's
     * to guard.** `wp_wc_orders.total_amount` is `decimal(26,8)`, so anything
     * from about 10^18 up overflows the column. A quantity of 10^12 gets there
     * with a *catalogue* price of 1 500 just as easily as with a manual one:
     * the hazard is the unbounded quantity, it predates a settable price, and a
     * cap that fired only on manually priced lines would stop none of it while
     * reading like a guard that did. The cap belongs on `quantity`, where it
     * covers both paths — and adding one narrows a contract callers rely on
     * today, which is a change with its own argument to make and not a
     * side-effect of adding a price.
     */
    private const MAX_PRICE = 9999999.99;

    public function __construct(
        public readonly int $productId,
        public readonly int $variationId,
        public readonly int $quantity,
        /**
         * The unit price to charge, as the decimal string the caller typed, or
         * null when they stated none and the catalogue should price the line.
         *
         * A string, not a float, for the reason every other money value in this
         * API is a string: a float round trip is where a price picks up binary
         * rounding. Deliberately *not* normalized to two decimals the way
         * `ShippingRuleInput::money()` normalizes — that helper hard-codes the
         * store's price precision, and this class is pure, so it cannot ask
         * `wc_get_price_decimals()` what the precision actually is. Rounding is
         * WooCommerce's to do when the amount reaches the line.
         */
        public readonly ?string $price
    ) {
    }

    /**
     * @param array<string, string> $errors keyed `line_items.0.quantity`
     * @return list<self>
     */
    public static function listFromPayload(mixed $payload, array &$errors): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            $errors['line_items'] = 'Must be an array of line items.';

            return [];
        }

        if ($payload === []) {
            $errors['line_items'] = 'An order needs at least one line item.';

            return [];
        }

        $items = [];

        foreach ($payload as $index => $raw) {
            $item = self::one($raw, "line_items.{$index}", $errors);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @param array<string, string> $errors */
    private static function one(mixed $raw, string $prefix, array &$errors): ?self
    {
        if (!is_array($raw)) {
            $errors[$prefix] = 'Must be an object.';

            return null;
        }

        $raw = array_diff_key($raw, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($raw), self::ALLOWED) as $field) {
            $errors["{$prefix}." . (string) $field] = 'Unknown field.';
        }

        $productId = self::positiveInt($raw['product_id'] ?? null);

        if ($productId === null) {
            $errors["{$prefix}.product_id"] = 'A product id is required.';
        }

        $quantity = self::positiveInt($raw['quantity'] ?? null);

        if ($quantity === null) {
            $errors["{$prefix}.quantity"] = 'Must be a whole number of one or more.';
        }

        // A variation is optional, but 0 has to mean "none" rather than an
        // error — it is what the presenter emits for a simple product, and a
        // round-tripped order would otherwise fail on a field nobody touched.
        $variationId = 0;

        if (array_key_exists('variation_id', $raw) && !in_array($raw['variation_id'], [null, 0, '0', ''], true)) {
            $variationId = self::positiveInt($raw['variation_id']) ?? -1;

            if ($variationId === -1) {
                $errors["{$prefix}.variation_id"] = 'Must be a variation id, or 0 for none.';
            }
        }

        /*
         * A price is optional, and *absent* is not the same as *zero*.
         *
         * `null` and `""` mean "no manual price — let the catalogue price this
         * line", so a client can clear a manual price by sending the field
         * empty rather than by having to know some magic amount. `0` and `"0"`
         * are deliberately not in that list: a free line is a real thing a shop
         * does — a replacement, a promised gift — and it is exactly the case
         * the old refusal was written to prevent, so conflating it with "no
         * price stated" would reinstate the old rule by accident. Contrast
         * `variation_id` just above, where 0 *is* the empty value.
         */
        $price = null;
        $priceRefused = false;

        if (array_key_exists('price', $raw) && !in_array($raw['price'], [null, ''], true)) {
            $price = self::amount($raw['price'], "{$prefix}.price", $errors);
            $priceRefused = $price === null;

            /*
             * "A price on a line the caller did not otherwise change" — what
             * that can mean here, and what it cannot.
             *
             * It cannot mean "unchanged compared to the order as it stands".
             * `line_items` is a wholesale replacement: `OrderRepository::replaceLineItems()`
             * removes every existing `line_item` and re-adds the payload's
             * lines, and `resolveLines()` pairs them by array index alone. The
             * line `id` never reaches the write at all — READ_ONLY drops it
             * above. So there is no before/after pairing anywhere in this
             * request path, and no layer, pure or not, can name which submitted
             * line corresponds to which stored one. A comparison against the
             * stored price is a thing the *audit* does — `order.updated` records
             * the whole set before and the whole set after — and this class
             * cannot.
             *
             * What it can mean, and does mean here: a price may only ride on a
             * line that states the line. A body like `{"id": 91, "price": 0}`
             * is someone trying to reprice one existing line in place — a
             * perfectly reasonable thing to want, and not a thing this route
             * does. Today that body fails with two errors about `product_id`
             * and `quantity`, fields the caller believes they never had to
             * send, and says nothing about the one field they came for. Naming
             * the price is what makes the refusal readable.
             *
             * Checked on `array_key_exists`, not on the parsed values: a stated
             * but invalid quantity is a quantity error and gets one message,
             * not two.
             */
            if ($price !== null && (!array_key_exists('product_id', $raw) || !array_key_exists('quantity', $raw))) {
                $errors["{$prefix}.price"] =
                    'A price can only be set on a line that also states its product and quantity: '
                    . 'line_items replaces the whole set and cannot reprice one line in place.';
                $priceRefused = true;
            }
        }

        if ($productId === null || $quantity === null || $variationId === -1 || $priceRefused) {
            return null;
        }

        return new self($productId, $variationId, $quantity, $price);
    }

    /**
     * A manual price as the decimal string the caller typed.
     *
     * Null on refusal, with the reason already recorded under `$field`. Unlike
     * `positiveInt()` below, which only says yes or no and leaves the wording
     * to its caller, this one writes its own message: there are three distinct
     * ways a price is wrong and a single "invalid price" would tell the caller
     * which one none of the time.
     *
     * Zero is allowed, and that is the deliberate part: the threat the old
     * refusal named was "an order at a price of nothing", and a price of
     * nothing is now permitted and audited rather than prevented. Negative is
     * not allowed — a negative line is a refund, and a refund is a different
     * object with a different route, not an order line with a minus sign.
     *
     * @param array<string, string> $errors
     */
    private static function amount(mixed $value, string $field, array &$errors): ?string
    {
        if (!is_numeric($value)) {
            $errors[$field] = 'Must be an amount.';

            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            $errors[$field] = 'Cannot be negative.';

            return null;
        }

        // Also catches the `INF` that a JSON literal like 1e400 decodes to,
        // which no comparison against a real number would.
        if (!($amount <= self::MAX_PRICE)) {
            $errors[$field] = 'Is implausibly large.';

            return null;
        }

        // `is_numeric()` tolerates surrounding whitespace; the stored string
        // should not carry it into the order.
        return trim((string) $value);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        // Rejects 1.5 rather than silently truncating it to 1: a fractional
        // quantity is a caller mistake, and half a unit is not something a
        // ledger of whole units can represent.
        if ((float) $value !== floor((float) $value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
