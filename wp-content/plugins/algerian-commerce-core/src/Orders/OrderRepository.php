<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\AddressInput;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Product_Variation;

/**
 * The WooCommerce adapter for orders.
 *
 * The only place that knows WC_Order exists. Everything above it works with
 * validated input objects and plain arrays, so the domain never depends on
 * WooCommerce's data model (docs/ARCHITECTURE.md §2).
 *
 * No direct SQL and no `get_post()`: orders are reached through wc_get_order()
 * and the CRUD layer, which is what makes the plugin's HPOS compatibility
 * declaration true. Reading wp_posts here would silently break every install
 * that has switched to the orders tables — and every install created after
 * WooCommerce 8.2, where HPOS is the default.
 *
 * ## The order total is computed here and never stated
 *
 * `OrderInput::READ_ONLY` drops `total`, `subtotal`, `shipping_total`,
 * `discount_total` and `total_tax` from every write, so this file is the only
 * thing that decides what an order is worth. It decides it in one place and in
 * one line: `calculate_totals()`, called immediately after the lines and the
 * delivery fee are written — in `create()`, in `rewriteLineItems()`, and in the
 * fee-only branch of `update()`.
 *
 * A caller does state amounts, on a line's `price` and on the order's
 * `shipping_amount`, and neither is a counter-example: both are *inputs to* the
 * sum, written onto the items the sum reads, never the sum itself.
 *
 * **There is deliberately no hand-rolled sum anywhere in this class**, and that
 * is worth arguing rather than assuming, because the order-edit item words the
 * requirement as an arithmetic formula — `sum(price × quantity) + shipping_total`
 * — that reads like something to go and write. WooCommerce already computes
 * exactly it, verified in `woocommerce/includes/abstracts/abstract-wc-order.php`
 * (11.0.1, the version compose.yaml pins):
 *
 *  - `calculate_totals():2146` sums every shipping line into
 *    `set_shipping_total()` at `:2163`, so `shipping_total` is a derived value
 *    too and not a number anyone stores by hand;
 *  - `:2199` calls `set_total(round(cart_total + fees + shipping_total +
 *    cart_tax + shipping_tax))`, where `cart_total` is
 *    `get_cart_total_for_order():2131` — the sum of each line's own `total`;
 *  - `get_subtotal():596` is the same sum over each line's `subtotal`.
 *
 * So `sum(price × quantity)` is exactly "the line totals", and the whole of
 * step 3's recompute is: *put `price × quantity` on the line's `subtotal` and
 * `total`, then let `calculate_totals()` run as it already did*. A second sum
 * written here would have to be kept in step with fees, taxes, coupons and
 * rounding — five things WooCommerce's version already handles and ours would
 * quietly get wrong the first time an order carried any of them.
 *
 * **Why recompute at all, when our panel never sends a total?** Because it is
 * not a defence against a client, it is what makes the total a derivation. The
 * reference shop recomputes for the other reason and it shows: EL's
 * `OrderService.update()` recalculates `itemsTotal.add(shippingCost)` — but only
 * inside `if (orderDTO.getShippingCost() != null)`, with the comment "The admin
 * app sends the old totalAmount from the DB (via ...fullOrder spread), so we
 * must recompute it here"
 * (`EL/api/src/main/java/com/oussamabenberkane/espritlivre/service/OrderService.java:344-351`).
 * That is a patch for one client's round trip, not a rule: an EL update that
 * changes a line and no shipping cost leaves the stale total standing, and
 * `OrderService.create():196-197` stores whatever `unitPrice` *and*
 * `totalPrice` the client sent. Ours recomputes on every write that touches the
 * lines, whatever the payload contained, because the total is derived — which
 * is the property EL's conditional does not have.
 *
 * ## A stated delivery fee is a line, not a property
 *
 * The `shipping_amount` a caller states arrives here and is written as a
 * `WC_Order_Item_Shipping`, exactly the way `Cart\CheckoutService::createOrder()`
 * writes the checkout quote. It is **not** written with `set_shipping_total()`,
 * and that is forced rather than chosen: `calculate_totals():2163` derives
 * `shipping_total` by summing the shipping *lines*, so a prop set by hand
 * survives only until the next recompute — which this class runs on the very
 * next statement. Put the amount where WooCommerce looks and the order total
 * follows with no arithmetic of ours, which is the same move step 3 made for a
 * line's price and the reason `OrderInput::READ_ONLY` can keep `shipping_total`.
 *
 * Two consequences, stated here so nobody has to rediscover them:
 *
 *  - **`replaceLineItems()` does not touch shipping.** It removes only
 *    `line_item`-type items, deliberately, because shipping, fee and tax lines
 *    belong to other phases. So a shipping line *survives* a line edit, and a
 *    second statement left beside the first would double `shipping_total` and
 *    the order total with it. `replaceShippingLine()` clears before it adds for
 *    that reason alone; it is the whole of what makes stating a fee twice mean
 *    the second number rather than their sum.
 *  - **A payload with no `line_items` used to skip the recompute.** `update()`
 *    called a plain `save()` in that case, which was right while nothing but
 *    the lines could move the money and is wrong the moment a delivery fee can.
 *    That branch reaches `calculate_totals()` now — see `update()`.
 */
final class OrderRepository
{
    /** Sortable columns WooCommerce's order query actually understands. */
    public const ORDERBY = ['date', 'id', 'modified', 'total'];

    /**
     * Item meta recording that an amount on this item was stated by a person.
     *
     * Public because three files need the same key and a second literal is a
     * key that will eventually disagree with itself: this class writes it,
     * `OrderPresenter::lineItems()` reads it back as the line's `price`, and
     * `OrderService::manualPrices()` names it to find every amount a person
     * chose on the order, which is what `order.created` and `order.updated`
     * record against the catalogue price each one replaced.
     *
     * ## It is written on the shipping line too, under the same key
     *
     * Step 4 made a delivery fee settable, and the fact it has to record is
     * word for word the fact a manual line price records: *a person typed this
     * number*. One key across both item types, rather than a second
     * `_ac_manual_shipping`, because the audit's question is "which amounts on
     * this order did somebody choose" and one key answers it with one pass over
     * `get_items(['line_item', 'shipping'])`. Two keys would mean an audit that
     * knows about the second only if somebody remembered to teach it.
     *
     * It is also **the only** thing that distinguishes a typed fee from a
     * quoted one, and that is worth being explicit about because the obvious
     * alternatives do not work. `method_title` is `'Delivery'` on both — see
     * `replaceShippingLine()` for why the customer-facing label must not carry
     * back-office bookkeeping. `method_id` is `''` on both today, and will stop
     * being a signal at all once item 2 writes a courier into it. And the
     * amount itself says nothing: 450 DZD looks identical whether §14's tariff
     * quoted it or an operator agreed it on the phone — the same argument this
     * key already makes about a line at the catalogue price.
     *
     * It exists because the fact is not otherwise recoverable. A line's
     * `subtotal` and `total` are money, not a decision — a line at 1 500 DZD
     * looks identical whether the catalogue said 1 500 or somebody typed it,
     * and comparing against the catalogue later answers a different question,
     * since the catalogue moves and an order line deliberately does not (see
     * `OrderPresenter::sku()` for the same argument about a SKU). Deriving the
     * flag from `subtotal !== total` would be worse still: that difference is
     * what a *coupon* means to every WooCommerce surface, and it would report a
     * price manually set to the catalogue amount as no override at all.
     *
     * Underscore-prefixed, which is WooCommerce's own convention for meta it
     * does not render. A manual price must not turn up as a line of item meta
     * on a packing slip or in a customer email beside the engraving
     * instructions `Cart\CheckoutService::attachOptions()` deliberately *does*
     * show there. What the customer sees is the line total, which is the number
     * that was agreed with them.
     *
     * Nothing ever has to clear it: `replaceLineItems()` deletes every line and
     * re-adds the payload's, and `replaceShippingLine()` does the same for the
     * one shipping line, so an item that arrives without a stated amount is a
     * new row with no meta on it. That falls out of the wholesale replacement
     * rather than being maintained, which is the only reason there is no
     * "unset the manual price" path in this file.
     */
    public const MANUAL_PRICE_META = '_ac_manual_price';

    /**
     * What the catalogue was charging for a hand-priced line, frozen onto that
     * line at the moment it was written.
     *
     * ## It has to be captured at write time, and nothing else will do
     *
     * The audit exists so that a discount is attributable — `LineItemInput`
     * argues at length that a manual price is no longer *prevented*, it is
     * *witnessed*. A witness that can only say "somebody charged 1 200.50"
     * witnesses nothing; the number that makes it a discount is the 1 500 the
     * catalogue was asking when the operator agreed 1 200.50 on the phone.
     *
     * That number is not on the order and cannot be got back afterwards. A
     * product's price is a *current* property of the product and it moves —
     * sales start and end, `get_price()` follows `get_sale_price()`, a supplier
     * puts the kettle up. So reading the catalogue when the audit row is *read*
     * answers "what does this cost today", and reading it on the order's next
     * write answers "what did it cost the next time somebody edited this order".
     * Neither is the question. There is exactly one instant at which "the
     * catalogue price this manual price replaced" is a fact rather than a
     * reconstruction, and it is the instant `replaceLineItems()` adds the line.
     *
     * ## Why it is stored on the line rather than handed to the service
     *
     * The obvious alternative is to collect the pairs while writing and return
     * them up to `OrderService`, which is what writes the audit row. It is not
     * enough, for a reason that only shows up on the *second* edit: the `before`
     * half of an `order.updated` record describes lines written by an **earlier
     * request**, and no value passed up during *this* one can say what the
     * catalogue was asking back then. A before-snapshot without this meta could
     * report today's catalogue price beside a price agreed last month, which is
     * precisely the worthless comparison the paragraph above rejects. Persisting
     * it is what lets both halves of a before/after pair be read the same way
     * and both be true.
     *
     * It also keeps this class stateless. Returning the pairs would mean either
     * changing what `create()` and `update()` return — they return `WC_Order`,
     * and every caller wants the order — or a `lastWrite` property, which is a
     * repository that remembers, and this one deliberately does not.
     *
     * **The meta is the carrier, not the record.** Item meta is a public,
     * mutable store: another plugin, WP-CLI or a hand edit can rewrite this key,
     * and `rememberManualPrice()` already says nothing downstream treats the
     * neighbouring key's presence as proof of anything. The proof is the row in
     * `ac_audit_logs`, which is append-only and is written from the same read in
     * the same request. This key's job is only to keep the fact readable until
     * the *next* request needs it for a before-snapshot.
     *
     * ## What is stored
     *
     * `WC_Product::get_price()` verbatim — the product's active price, sale
     * price included, as the string WooCommerce stores it. Not a near relative
     * of the catalogue price but the exact value the manual price displaced:
     * `wc_get_price_excluding_tax()` picks `$args['price']` when one is passed
     * and `(float) $product->get_price()` when one is not
     * (`wc-product-functions.php:1604`, 11.0.1), and `lineTotals()` passes the
     * typed price into precisely that argument. So the two are the same quantity
     * in the same terms — a **unit** price, comparable to the typed string
     * without arithmetic and without either side being converted first. The
     * line's own `subtotal`/`total` carry the money, and `quantity` is on the
     * item, so the audit can multiply if it wants to; it records the two unit
     * prices because those are the two numbers a person actually chose between.
     *
     * Nothing is written when `get_price()` returns `''` — a product with no
     * price at all, which WooCommerce permits. An absent key reads back as
     * "there is no catalogue price to compare against", which is the truth, and
     * is the same thing a line written before this key existed says. Inventing a
     * `0` there would report a full-price sale as a total giveaway.
     *
     * ## Only on lines that were hand-priced
     *
     * Written by `rememberManualPrice()` and therefore only where
     * `MANUAL_PRICE_META` is written. A catalogue-priced line does not get one,
     * and that is the same decision `OrderPresenter::manualPrice()` makes about
     * emitting `null` rather than the effective unit price: an audit in which
     * every line carries a catalogue comparison is an audit in which every line
     * looks like somebody's decision, and then none of them do.
     *
     * Underscore-prefixed and never cleared, for `MANUAL_PRICE_META`'s two
     * reasons: WooCommerce does not render meta keys that begin with one, so
     * this bookkeeping stays off the packing slip and out of the customer's
     * email; and the wholesale replacement in `replaceLineItems()` destroys
     * every line and re-adds the payload's, so a line that arrives without a
     * stated price is a new row with neither key on it.
     */
    public const CATALOGUE_PRICE_META = '_ac_catalogue_price';

    /**
     * What the order's shipping line is called, on a back-office order.
     *
     * A `WC_Order_Item_Shipping` carries a `method_title` and a `method_id` as
     * well as an amount, and neither has an obvious value when the amount came
     * from a person rather than from a quote. Both were decided rather than
     * defaulted.
     *
     * **`method_title` is customer-facing, so it says what the line is and not
     * where the number came from.** WooCommerce renders it as the shipping row
     * on the order screen, the packing slip and the customer's email. `'Delivery'`
     * is the exact string `Shipping\RateResolver::quote()` labels a quoted line
     * with, so a back-office order and a storefront order print the identical
     * row — which is the point: the customer agreed a delivery charge, and how
     * the shop arrived at it is bookkeeping they neither need nor should be
     * shown. That the label therefore fails to distinguish a typed fee from a
     * quoted one is the reason `MANUAL_PRICE_META` is written on the line, and
     * not a gap.
     *
     * It is deliberately **not** `RateResolver`'s other label, `'Free delivery'`,
     * when the amount is zero. That label means *the basket crossed a
     * free-shipping threshold*, a §14 fact a back-office order has no
     * equivalent of; reusing it for "somebody typed 0" would put a claim about
     * a rule that does not exist in front of a customer.
     *
     * English, like `RateResolver`'s labels, and for the same reason: the string
     * is frozen onto the order at the moment of sale. Localising it would store
     * whichever language the operator's panel happened to be in and leave the
     * order book speaking two, which is the argument `Cart\CheckoutService::attachOptions()`
     * makes about freezing an option's label at the time of sale.
     *
     * **`method_id` now holds the courier, and item 2 is what filled it.** Two
     * earlier revisions of this paragraph said the slot was left empty and
     * explained at length why nothing should squat in it — first because that
     * was all a back-office order could say, then because a storefront order
     * had begun naming a courier there while this path still could not. Both
     * are kept in the history rather than quietly overwritten, because the
     * reasoning they gave is the reasoning that paid off: the slot was held
     * open for `shipping_provider`, and `shipping_provider` arrived and took it
     * without evicting anything.
     *
     * So the value written here is whatever the payload's `shipping_provider`
     * named, and empty when it named nobody — a fee stated in the back office
     * before anybody decided who delivers it, which remains a legitimate state
     * and remains spelled `''`. Still not a marker like `ac_manual`: `manual`
     * is a real registered courier (`Shipping\ManualProvider`), so a flag with
     * that shape would be indistinguishable from an operator choosing in-house
     * delivery, and "nobody has decided" and "we are driving it ourselves" are
     * different facts about an order.
     *
     * The facts that are not about the courier still live in meta —
     * `MANUAL_PRICE_META` and `RATE_SOURCE_META` — which is what left this slot
     * free to be occupied by the one fact it is named for.
     */
    private const SHIPPING_LINE_TITLE = 'Delivery';

    /**
     * Which of the two sources priced a shipping line — `rules` or `provider`.
     *
     * ## The values are `RateQuote`'s, and are not new vocabulary
     *
     * `Shipping\RateQuote::SOURCE_RULES` and `::SOURCE_PROVIDER` are what
     * `GET /shipping/rates` and `GET /checkout/shipping-rates` have always
     * labelled a quote with. This stores the winning quote's own label
     * unchanged, so "where did this number come from" has one answer spelled
     * one way from the quote a shopper was shown through to the order they
     * placed. Inventing a second pair of words for the order — the reference
     * shop's `AUTOMATIC` / `FIXED` — would have meant a client translating
     * between two vocabularies for one fact, and translating is where a
     * mismatch hides.
     *
     * ## Why a rules quote is not "fixed"
     *
     * Worth saying because `FIXED` is the obvious import and it is wrong here.
     * In the reference shop a fixed fee is a number on the *product*
     * (`EL/api/…/service/DeliveryFeeCalculationService.java:169`, read from
     * source). §14's tariff is not that: `Shipping\RateResolver` picks the
     * narrowest rule of commune, wilaya, delivery type and courier, and the
     * amount can be waived entirely by a free-shipping threshold. Calling that
     * "fixed" describes almost nothing about it. `rules` says where to go and
     * look, which is what an operator actually needs.
     *
     * ## Written by whoever prices the line, and only then
     *
     * A line with no such meta is one this key predates or one nobody priced
     * through a quote — `OrderPresenter` reads that as `null`, the same way it
     * reads an unstated `shipping_amount`, rather than guessing a source. A
     * guess here would be worse than a silence: the whole reason the field
     * exists is that the amount cannot tell you where it came from.
     *
     * Underscore-prefixed, for `MANUAL_PRICE_META`'s reason — WooCommerce does
     * not render meta keys that begin with one, so this stays off the packing
     * slip and out of the customer's email. It is bookkeeping for the shop, and
     * a customer told their delivery fee came from `provider` learns nothing
     * and wonders what the alternative was.
     */
    public const RATE_SOURCE_META = '_ac_rate_source';

    /**
     * Why the last confirmation of this order created no parcel — backend
     * step 2, item 5.
     *
     * ## Defined here, written by `Shipping/`, and that is the settled shape
     *
     * `Shipping\ShipmentSubscriber` writes it and `Shipping\ShipmentFailure`
     * decides what goes in it; this file owns nothing but the key and
     * `OrderPresenter` reads it back. That is `RATE_SOURCE_META`'s arrangement
     * exactly — declared in `Orders/`, written by `Cart\CheckoutService::createOrder()`
     * — and it is the arrangement that keeps the dependency running one way.
     * `Shipping/` already imports this class; nothing in `Orders/` imports
     * `Shipping/`, and putting the constant on the other side of the boundary
     * would be the change that made the two cyclic (docs/ARCHITECTURE.md §3).
     *
     * ## An order-level key, unlike its two neighbours
     *
     * `MANUAL_PRICE_META` and `RATE_SOURCE_META` both go on a *line item*,
     * because both are facts about one shipping line. This is a fact about the
     * order: it records that a confirmation happened and produced no parcel, and
     * a confirmation is not a line. It would also be lost by the line rewrite
     * that `replaceShippingLine()` performs on every restated fee, which is
     * precisely the moment an operator is most likely to be correcting the thing
     * that failed.
     *
     * ## One current value, not a history
     *
     * The key holds the most recent failure and is deleted the moment a parcel
     * exists. That is deliberate and it is only defensible because the history
     * is kept somewhere better: every attempt writes a `shipment.create_failed`
     * audit row, and `ac_audit_logs` is the append-only store this plugin uses
     * for "what has been done to this" — the same division of labour
     * `Shipping\ShipmentRepository` describes for a parcel, where the row says
     * where it is and the trail says what happened to it.
     *
     * ## Read-only on the wire
     *
     * `OrderPresenter` publishes it as `shipping_provider_error` and
     * `OrderInput::READ_ONLY` drops it, for `shipping_source`'s reason: a caller
     * who could state it could claim a courier had refused when none was asked.
     * It is also a statement about the past, which the write side of this API
     * never lets anybody make.
     *
     * Underscore-prefixed for `MANUAL_PRICE_META`'s reason, and here the
     * argument is at its strongest: WooCommerce renders unprefixed meta on the
     * packing slip and in the customer's email, and a customer must never be
     * shown the sentence a courier used to refuse their address.
     */
    public const SHIPPING_ERROR_META = '_ac_shipping_error';

    /**
     * Where this order is going, by the ids of the §51 dataset — backend
     * step 2.
     *
     * ## Three writers, one shape, and that is the whole reason these are
     * constants
     *
     * `Cart\CheckoutService::createOrder()` wrote these three keys first, as
     * string literals, with a comment saying why: *"kept with the order so a
     * later shipment does not have to guess it back out of a free-text
     * address"*. `Shipping\ShipmentSubscriber` then read them back, and
     * declared its own private copies of the same three literals — its docblock
     * called that *"one duplication too many"* out loud rather than pretending
     * otherwise. This change adds the third writer, `applyProps()` below, on
     * behalf of `POST /orders`.
     *
     * Three sites spelling one fact was the point at which the literals had to
     * stop. Two writers producing two shapes for one fact is the failure this
     * key exists to make impossible: `ShipmentSubscriber::destinationOf()` and
     * `Shipping\ShipmentInput` must not need to know which door an order came
     * through, and they only avoid needing to know while a storefront order and
     * a back-office order are written **identically** — same keys, same casts,
     * `(int)` for the ids and `(string)` for the journey.
     *
     * ## Declared in `Orders/`, written from `Cart/` and read from `Shipping/`
     *
     * `RATE_SOURCE_META`'s arrangement exactly, and `SHIPPING_ERROR_META`'s, and
     * for their reason: both of those modules already import this class and
     * nothing in `Orders/` imports either of them, so the constant lives on the
     * side of the boundary that keeps the dependency running one way
     * (docs/ARCHITECTURE.md §3). Putting it in `Shipping/` — where `Destination`
     * lives and where it superficially belongs — is the change that would make
     * the two cyclic.
     *
     * ## Order-level, like `SHIPPING_ERROR_META` and unlike the other two
     *
     * A destination is a fact about the order, not about a shipping line. It
     * therefore survives `replaceShippingLine()`, which destroys and rebuilds
     * the line on every restated fee — and that is load-bearing rather than
     * incidental: *"the courier came back at 600, not 450"* is the most ordinary
     * PATCH this route takes, and an address that evaporated when a price was
     * corrected would break the confirmation dispatch in exactly the way
     * `replaceShippingLine()` had to grow a `$provider` argument to stop.
     *
     * ## Not underscore-prefixed for a *new* reason
     *
     * The prefix is `MANUAL_PRICE_META`'s — WooCommerce does not render meta
     * keys that begin with one, so these stay off the packing slip and out of
     * the customer's email. Here that is close to a formality: the keys were
     * spelled this way by `CheckoutService` before anything else existed, and
     * they could not be renamed now without orphaning the destination of every
     * order the storefront has ever placed. They are what they are because they
     * are already in the database.
     */
    public const WILAYA_META = '_ac_wilaya_id';
    public const COMMUNE_META = '_ac_commune_id';
    public const DELIVERY_TYPE_META = '_ac_delivery_type';

    public function find(int $id): ?WC_Order
    {
        $order = wc_get_order($id);

        // wc_get_order() also returns refunds, which are a different object
        // type sharing the id space. WC_Order_Refund extends the abstract, not
        // WC_Order, so this check excludes them.
        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * @param array{page: int, per_page: int, search: string, status: string, customer_id: int, date_from: string, date_to: string, orderby: string, order: string} $criteria
     * @return array{items: list<WC_Order>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $args = [
            'limit' => $criteria['per_page'],
            'page' => $criteria['page'],
            'paginate' => true,
            /*
             * Orders only. WooCommerce's default here is every type registered
             * for order views, which includes `shop_order_refund` — refunds
             * share the id space and are a different object that this endpoint
             * neither presents nor promises.
             */
            'type' => 'shop_order',
            'orderby' => in_array($criteria['orderby'], self::ORDERBY, true) ? $criteria['orderby'] : 'date',
            'order' => strtoupper($criteria['order']) === 'ASC' ? 'ASC' : 'DESC',
        ];

        // Left unset when no status filter is given: WooCommerce's default is
        // every registered order status, which is what "all orders" means and
        // already excludes the storefront's internal checkout drafts.
        if ($criteria['status'] !== '') {
            $args['status'] = [$criteria['status']];
        }

        if ($criteria['customer_id'] > 0) {
            $args['customer_id'] = $criteria['customer_id'];
        }

        if ($criteria['search'] !== '') {
            /*
             * Free-text search across order id, customer and product names.
             * This is an HPOS capability — OrdersTableSearchQuery joins the
             * address and items tables to do it. Under legacy post storage `s`
             * degrades to a post-content search and finds almost nothing,
             * which is one more reason this install runs on HPOS.
             */
            $args['s'] = $criteria['search'];
            $args['search_filter'] = 'all';
        }

        $range = self::dateRange($criteria['date_from'], $criteria['date_to']);

        if ($range !== '') {
            $args['date_created'] = $range;
        }

        $results = wc_get_orders($args);

        return [
            'items' => is_object($results) ? $results->orders : [],
            'total' => is_object($results) ? (int) $results->total : 0,
        ];
    }

    /**
     * Create an order, then bring it to the requested status.
     *
     * The sequence is deliberate and the comments are the reason it is not
     * shorter:
     *
     *  1. Resolve every line to a real product *before* anything is written.
     *     There is no transaction across a WooCommerce save, so a line naming
     *     a product that does not exist has to fail while there is still
     *     nothing to undo — otherwise a 400 leaves an empty order behind in
     *     the order book.
     *  2. Save the bare order. `add_product()` stamps the item with
     *     `$order->get_id()` and saves it immediately, so adding a line to an
     *     unsaved order orphans that line against order 0.
     *  3. Add the lines and the delivery fee, then recompute the order's money
     *     from them. Step 3 used to read "calculate totals from the catalogue",
     *     which stopped being the whole truth when a line gained a manual
     *     price: the lines are priced from the catalogue *or* from what a
     *     person typed, and `calculate_totals()` derives the order from
     *     whichever it finds — see the class docblock on why there is no sum of
     *     our own here. The shipping line joins the same recompute, before it
     *     rather than after, because `calculate_totals()` reads the lines that
     *     exist when it runs and nothing re-reads them afterwards.
     *  4. Only then set the requested status. `WC_Order::save()` fires the
     *     status transition *after* the items are persisted, and the
     *     transition into `processing`, `on-hold` or `completed` is what makes
     *     WooCommerce reduce stock — so the lines have to exist first or the
     *     order takes stock for nothing.
     */
    public function create(OrderInput $input): WC_Order
    {
        $lines = $this->resolveLines($input->lineItems());

        $order = new WC_Order();

        $this->applyProps($order, $input);
        $order->save();

        $this->replaceLineItems($order, $lines);
        $this->applyShippingLine($order, $input);
        $order->calculate_totals();

        return $this->applyStatus($order, $input);
    }

    /**
     * The caller has already checked that the status change is a legal
     * transition and that line items and the delivery fee may be written —
     * those are policy, and policy lives in the service.
     *
     * ## Three branches, because there are three ways to move the money
     *
     * This used to be two: rewrite the lines and recompute, or save. That was
     * correct while `line_items` was the only writable thing that could change
     * what the order is worth. A settable delivery fee breaks the assumption,
     * and the branch it breaks is the quiet one — a PATCH carrying only
     * `shipping_amount` would have taken the plain `save()` path, written a
     * shipping line, and left `total` and `shipping_total` at their old values
     * with no error anywhere. The order would read back with a delivery charge
     * it did not charge for.
     *
     * So the rule is now stated as a rule rather than as a side effect of the
     * line-items branch: **any write that touches the lines or the fee ends at
     * `calculate_totals()`**, and only a write that touches neither may take
     * the bare `save()`.
     *
     * The shipping-only branch does not go through `rewriteLineItems()`, and
     * that is the point of it being its own branch. That method exists to
     * unwind and re-take stock around a line replacement — see its docblock —
     * and a delivery fee moves no units. Routing a fee change through it would
     * return every held unit to the shelf and take it again, writing two ledger
     * rows for a shelf that never moved.
     */
    public function update(WC_Order $order, OrderInput $input): WC_Order
    {
        // Resolved first for the same reason as on create, and one more:
        // replaceLineItems() deletes the existing lines before adding the new
        // ones, so a bad product id partway through would leave the order
        // stripped of the items it had.
        $lines = $input->has('line_items') ? $this->resolveLines($input->lineItems()) : null;

        $this->applyProps($order, $input);

        if ($lines !== null) {
            $this->rewriteLineItems($order, $lines, $input);
        } elseif ($input->has('shipping_amount')) {
            $this->applyShippingLine($order, $input);
            // Saves, like every other call to it in this class.
            $order->calculate_totals();
        } else {
            /*
             * The bare branch, and it stayed bare on purpose when
             * `shipping_provider` arrived.
             *
             * A courier's name is written here rather than in the branch above
             * because it is not money: it changes no line total, so there is
             * nothing for `calculate_totals()` to re-derive and every reason not
             * to run it — see `applyShippingLine()`, which argues the whole
             * case. `save()` persists the item through `save_items()`
             * (`abstract-wc-order.php:255`), which is the same call that has
             * always persisted the props `applyProps()` set just above.
             *
             * So the rule the three branches state together is now sharper than
             * the two-branch version it grew from: **any write that touches the
             * lines or the fee ends at `calculate_totals()`, and everything else
             * ends at `save()`** — with `shipping_provider` on the second side,
             * beside the address and the customer note.
             *
             * `applyShippingLine()` is called unconditionally and returns
             * immediately when the payload states neither field, so this branch
             * is still the plain save it reads as for every payload that has
             * nothing to do with delivery.
             */
            $this->applyShippingLine($order, $input);
            $order->save();
        }

        return $this->applyStatus($order, $input);
    }

    /**
     * Replace an order's lines, returning and re-taking stock if it held any.
     *
     * An order that has already reduced stock carries a `_reduced_stock` marker
     * on each item recording how many units that line took. Replacing the items
     * destroys the markers, and nothing afterwards can return those units — the
     * ledger is left with a reduction and no matching restoration.
     *
     * So the stock is unwound first and re-taken after, through the same two
     * functions WooCommerce uses itself. The `maybe_` wrappers are the right
     * entry points because they also maintain the order's `stock_reduced` flag;
     * calling wc_increase_stock_levels() directly would move the units and
     * leave the flag saying they were still held.
     *
     * Both fire the hooks OrderStockSubscriber listens to, so the ledger records
     * the whole manoeuvre — a restoration of the old lines, then a reduction for
     * the new ones — instead of a quantity that changes with nothing to explain
     * it. That verbosity is the point: the shelf really did move twice.
     *
     * WooCommerce's own helper for adjusting a single line in place,
     * wc_maybe_adjust_line_item_product_stock(), is not usable here — it lives
     * in an admin-only file that is not loaded during a REST request.
     *
     * A `shipping_amount` in the same payload is written here rather than in a
     * pass of its own, because one `calculate_totals()` has to see both: the
     * lines and the fee are two terms of one sum, and recomputing twice would
     * publish an order total that was briefly the goods without the delivery.
     *
     * @param list<array{product: WC_Product, quantity: int, price: ?string}> $lines
     */
    private function rewriteLineItems(WC_Order $order, array $lines, OrderInput $input): void
    {
        $orderId = $order->get_id();
        $heldStock = self::stockReduced($order);

        if ($heldStock) {
            wc_maybe_increase_stock_levels($orderId);
        }

        $this->replaceLineItems($order, $lines);
        $this->applyShippingLine($order, $input);
        // Saves, so the new lines are in the database before the re-reduction
        // below — and before any status transition can act on them.
        $order->calculate_totals();

        if ($heldStock) {
            /*
             * Guarded on $heldStock rather than run unconditionally: reducing
             * here for an order that was never holding stock — a pending one —
             * would take units off the shelf for an order nobody has confirmed.
             */
            wc_maybe_reduce_stock_levels($orderId);
        }
    }

    private function applyStatus(WC_Order $order, OrderInput $input): WC_Order
    {
        $status = (string) ($input->get('status') ?? '');

        if ($status !== '' && $status !== $order->get_status()) {
            $order->set_status($status);
            $order->save();
        }

        return $this->find($order->get_id()) ?? $order;
    }

    /**
     * Every order a customer has placed, oldest first, projected to the few
     * fields the statistics need.
     *
     * One query rather than a COUNT per status: the counts, the revenue and the
     * first and last order all fall out of a single pass, and they cannot
     * disagree with each other the way five separate queries can.
     *
     * `limit => -1` is bounded by *one customer's* order count, not the shop's,
     * which is a few dozen rows for a retail buyer. Store-wide reporting is a
     * different problem with a different answer — §60's `ac_analytics_aggregates`,
     * which exists precisely so a dashboard never scans the order book.
     *
     * @return list<array{id: int, status: string, total: string, date_created: ?string}>
     */
    public function customerOrderSummaries(int $customerId): array
    {
        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'type' => 'shop_order',
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $summaries = [];

        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $summaries[] = [
                'id' => $order->get_id(),
                'status' => $order->get_status(),
                'total' => (string) $order->get_total(),
                'date_created' => self::iso($order->get_date_created()),
            ];
        }

        return $summaries;
    }

    private static function iso(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date') ? $date->date('c') : null;
    }

    /**
     * Write a note against an order.
     *
     * `$isCustomerNote` is passed straight through because WooCommerce acts on
     * it: a customer note is emailed to the buyer as it is saved. The third
     * argument marks the note as added by the signed-in user rather than by
     * "WooCommerce", so the timeline can attribute it.
     *
     * @return int the new note id
     */
    public function addNote(WC_Order $order, OrderNoteInput $note): int
    {
        return (int) $order->add_order_note($note->note, $note->customerNote ? 1 : 0, true);
    }

    /**
     * @return list<array<string, mixed>> newest first, in the shape
     *         OrderTimeline and OrderPresenter both consume
     */
    public function notes(int $orderId, int $limit): array
    {
        $notes = wc_get_order_notes([
            'order_id' => $orderId,
            'limit' => $limit,
            'orderby' => 'date_created',
            'order' => 'DESC',
        ]);

        $rows = [];

        foreach (is_array($notes) ? $notes : [] as $note) {
            $rows[] = [
                'id' => (int) $note->id,
                'content' => (string) $note->content,
                'customer_note' => (bool) $note->customer_note,
                // WooCommerce reports its own automatic notes as "system".
                'added_by' => (string) $note->added_by,
                // Normalized to the 'Y-m-d H:i:s' UTC that the audit and
                // ledger tables store, so the timeline sorts one kind of value.
                'created_at' => self::utc($note->date_created),
            ];
        }

        return $rows;
    }

    /**
     * A WC_DateTime to 'Y-m-d H:i:s' in UTC.
     *
     * WooCommerce hands back a WC_DateTime carrying the *site* timezone. Taking
     * its ->date('Y-m-d H:i:s') would mix local wall-clock times into a feed
     * sorted against two tables that store UTC, and the timeline would
     * interleave wrongly by exactly the UTC offset.
     */
    private static function utc(mixed $date): string
    {
        if (!is_object($date) || !method_exists($date, 'getTimestamp')) {
            return '';
        }

        return gmdate('Y-m-d H:i:s', $date->getTimestamp());
    }

    /** The caller has already checked that the transition is legal. */
    public function changeStatus(WC_Order $order, string $status): WC_Order
    {
        if ($status !== $order->get_status()) {
            $order->set_status($status);
            $order->save();
        }

        return $this->find($order->get_id()) ?? $order;
    }

    /**
     * Record that this order has been paid for — roadmap §59.
     *
     * `payment_complete()` rather than a status write, because it is
     * WooCommerce's own supported API for exactly this and does several things a
     * `set_status()` would silently skip: it stamps the date paid and the
     * transaction id, reduces stock if that has not happened, empties held
     * stock, and chooses between `processing` and `completed` according to what
     * the order contains — a virtual, downloadable basket completes, a physical
     * one goes to processing to be packed. Reimplementing that here would be
     * forking a data model, which CLAUDE.md forbids outright.
     *
     * It is idempotent on WooCommerce's side: an order that is already paid
     * keeps its original paid date. The caller has already established that this
     * payment is confirmed *server-side* — never from a client callback.
     */
    public function markPaid(WC_Order $order, string $transactionId = ''): WC_Order
    {
        $order->payment_complete($transactionId);

        return $this->find($order->get_id()) ?? $order;
    }

    /**
     * Whether WooCommerce has already taken this order's stock.
     *
     * A flag on the order, not a derivation from its status: WooCommerce
     * reduces stock once and records that it did, so an order can be `on-hold`
     * — a status that reduces stock — and still be holding none, because it
     * arrived there from `cancelled`.
     *
     * Static because the presenter needs the same answer, and this is the only
     * file allowed to ask a data store for it.
     *
     * `has_callable()` rather than `method_exists()`, and the difference is not
     * cosmetic: WC_Data_Store is a decorator that forwards to the real store
     * through `__call()`, so `method_exists()` answers "no" for every method
     * the store actually has. Using it here silently pinned this to false —
     * every order reported holding no stock, and the guard that stops line
     * items being rewritten on a committed order never fired.
     */
    public static function stockReduced(WC_Order $order): bool
    {
        $store = $order->get_data_store();

        if (!is_object($store) || !$store->has_callable('get_stock_reduced')) {
            return false;
        }

        return (bool) $store->get_stock_reduced($order->get_id());
    }

    /**
     * Everything except status and line items, both of which are ordered.
     *
     * ## The destination joins this method and not `applyShippingLine()`
     *
     * It is order meta, so it belongs with the props rather than with the one
     * shipping line — and the placement pays twice. `applyProps()` runs on
     * **every** path: before the save in `create()`, and ahead of all three
     * branches in `update()`, whichever way that method then decides to
     * persist. So a payload carrying nothing but a corrected commune takes the
     * bare `save()` and writes exactly one thing, while the same payload with a
     * fee beside it lands in the same `calculate_totals()` as the fee. Neither
     * needed a branch of its own, because a destination is not a term in any
     * sum — `calculate_totals()` adds up shipping *line totals*
     * (`abstract-wc-order.php:2158-2163`, the 11.0.1 compose.yaml pins) and
     * order meta has never been in that arithmetic.
     *
     * Written exactly as `Cart\CheckoutService::createOrder()` writes it —
     * same three keys, `(int)` for the two ids and `(string)` for the journey —
     * which is the entire contract of `WILAYA_META` above. A storefront order
     * and a phone order have to be indistinguishable to
     * `Shipping\ShipmentSubscriber::destinationOf()`, and they are only
     * indistinguishable while these two writers agree character for character.
     *
     * Each key is written only when the payload states it, which is this API's
     * rule everywhere else — **a payload changes what it mentions**, the same
     * sentence `replaceShippingLine()` argues from and the same reason the
     * address loop above walks only the fields `AddressInput` kept. It is what
     * lets an operator correct a commune without restating the wilaya, and
     * `OrderService::guardDestinationResolves()` is what makes sure the pair
     * they leave behind still names one place.
     */
    private function applyProps(WC_Order $order, OrderInput $input): void
    {
        foreach (['payment_method' => 'set_payment_method',
            'payment_method_title' => 'set_payment_method_title',
            'customer_note' => 'set_customer_note'] as $field => $setter) {
            if ($input->has($field)) {
                $order->{$setter}((string) $input->get($field));
            }
        }

        if ($input->has('customer_id')) {
            $order->set_customer_id($this->assertCustomer((int) $input->get('customer_id')));
        }

        foreach (['billing', 'shipping'] as $type) {
            if (!$input->has($type)) {
                continue;
            }

            /** @var AddressInput $address */
            $address = $input->get($type);

            foreach ($address->fields as $field => $value) {
                $order->{"set_{$type}_{$field}"}($value);
            }
        }

        foreach ([
            'wilaya_id' => self::WILAYA_META,
            'commune_id' => self::COMMUNE_META,
        ] as $field => $key) {
            if ($input->has($field)) {
                $order->update_meta_data($key, (int) $input->get($field));
            }
        }

        if ($input->has('delivery_type')) {
            $order->update_meta_data(self::DELIVERY_TYPE_META, (string) $input->get('delivery_type'));
        }
    }

    /**
     * A customer id must belong to a real user.
     *
     * WooCommerce stores whatever it is given, so an unchecked id produces an
     * order attributed to a user that does not exist — invisible in the
     * customer's order history and impossible to reconcile later. 0 is the
     * documented value for a guest order.
     */
    private function assertCustomer(int $customerId): int
    {
        if ($customerId === 0 || get_userdata($customerId) !== false) {
            return $customerId;
        }

        throw ApiException::invalidRequest('The order data is invalid.', [
            'fields' => ['customer_id' => "No user with id {$customerId}."],
        ]);
    }

    /**
     * Replace the product lines wholesale.
     *
     * A PATCH that sends `line_items` states what the order now contains, so
     * anything absent is removed. Partial line edits would need a stable line
     * id in the payload and a per-line stock reconciliation, and neither
     * exists.
     *
     * This used to add "the service only allows this path on an order that has
     * not yet taken stock, which is what makes wholesale replacement safe", and
     * that was never true — `OrderService::guardLineItemsWritable()` gates on
     * `WC_Order::is_editable()`, which includes `on-hold`, and `on-hold`
     * reduces stock. What actually makes the replacement safe on a stock-holding
     * order is `rewriteLineItems()` directly above, which unwinds the shelf
     * before this runs and re-takes it after. Left standing, the old sentence
     * would have read as a licence to delete that reconciliation.
     *
     * The service refuses nothing else on a stock-holding order either, and
     * that sentence has moved once. Backend step 6 added
     * `guardManualPricesWritable()`, which turned a stated price on such an
     * order into a 409, so this method was reached with a `price` of null on
     * every line of one; the fix round's decision 1 removed that guard, so a
     * hand-priced line now arrives here on an order holding units exactly as it
     * does on a `pending` one. **Nothing in this method had to change for
     * either move**, which is the point worth recording: it prices from the
     * catalogue when `price` is null and honours the amount when it is not, and
     * the reconciliation above is indifferent to which. The rule about who may
     * state an amount lives in the service, and it has never lived here.
     *
     * @param list<array{product: WC_Product, quantity: int, price: ?string}> $lines
     */
    private function replaceLineItems(WC_Order $order, array $lines): void
    {
        /*
         * remove_item(), not remove_order_items(). The plural form deletes the
         * rows immediately and unsets the in-memory group; the next
         * add_product() then re-reads that group from the database, and the
         * line it just saved is dropped on the following save — an order that
         * carries the right total and no items at all. remove_item() instead
         * queues the item, and save_items() processes the deletions before it
         * writes the new lines, which is the order that actually works.
         *
         * Only line items are touched. Shipping, fee and tax lines belong to
         * other phases and must survive a line edit.
         */
        foreach ($order->get_items('line_item') as $existing) {
            $order->remove_item($existing->get_id());
        }

        foreach ($lines as $line) {
            /*
             * With no $args, add_product() prices the line from the catalogue —
             * that is still what happens for every line the caller did not put
             * a price on, and lineTotals() returns [] to say so.
             */
            $itemId = (int) $order->add_product($line['product'], $line['quantity'], self::lineTotals($line));

            if ($line['price'] !== null) {
                /*
                 * The whole line, not just the price, because the audit needs
                 * what the catalogue was asking *right now* and this is the
                 * only moment that is knowable — see CATALOGUE_PRICE_META. The
                 * product is in hand here and is gone by the time anything
                 * reads the record back.
                 */
                self::rememberManualPrice($order, $itemId, $line);
            }
        }
    }

    /**
     * Write the stated delivery fee, if this payload stated one.
     *
     * The `has()` test rather than a null amount is the whole contract of the
     * field, and `OrderInput` argues it at length: `null` and `""` are dropped
     * before they reach here, so "absent" means *this request says nothing
     * about delivery* and the order's shipping line is left exactly as it
     * stands. A zero arrives as a stated `'0'` and does replace the line, which
     * is how a fee is cancelled.
     *
     * That asymmetry against a line's price is deliberate. An empty price hands
     * a line back to the catalogue because a line has a catalogue price to fall
     * back to; delivery has none, and re-quoting would need a destination and
     * §14's rules, which are reached from a cart and not from an order.
     * "Leave it alone" is also the only reading under which the round trip
     * works — `OrderPresenter` emits `null` for a fee the checkout quoted, and
     * PATCHing a fetched order back must not delete the delivery charge the
     * shopper already paid.
     *
     * Three callers, and all three sit immediately before a
     * `calculate_totals()`. That adjacency is the design: this method only
     * moves items, and the money is derived afterwards by the one call that
     * already derives it from the lines.
     *
     * ## Two fields, two branches, and why the second is not the first
     *
     * `shipping_provider` writes to the same shipping line and it was tempting
     * to route it through `replaceShippingLine()` as well, one writer for one
     * line. That is wrong, and the reason is worth the extra method.
     *
     * **Naming a courier moves no money.** `calculate_totals()` sums shipping
     * *line totals* (`abstract-wc-order.php:2158-2163`, the 11.0.1
     * compose.yaml pins); a `method_id` is not in that sum and never has been.
     * So a payload that states only a courier changes nothing the order is
     * worth, and it must not be made to look as if it might: routing it through
     * the replace path would destroy and re-create the line, hand it a new item
     * id, and oblige the caller to run `calculate_totals()` on an order that
     * has no reason to recompute — which on a `completed` order is exactly the
     * kind of quiet re-derivation `OrderService::guardShippingAmountWritable()`
     * exists to keep away from committed money. Setting the field in place
     * touches one column, keeps the item id, keeps every meta, and takes the
     * plain `save()`.
     *
     * The branches are ordered rather than independent because a payload may
     * state both, and then there is one line to write and the replace path
     * writes it — carrying the courier into the line it builds. Running both
     * would be a replace immediately followed by a mutation of what it built,
     * which is the same answer reached twice.
     */
    private function applyShippingLine(WC_Order $order, OrderInput $input): void
    {
        if ($input->has('shipping_amount')) {
            self::replaceShippingLine(
                $order,
                (string) $input->get('shipping_amount'),
                // Null is "this payload names no courier", which is a different
                // statement from naming none — see replaceShippingLine(), where
                // it means *keep whoever is already carrying it*.
                $input->has('shipping_provider') ? (string) $input->get('shipping_provider') : null
            );

            return;
        }

        if ($input->has('shipping_provider')) {
            self::assignShippingProvider($order, (string) $input->get('shipping_provider'));
        }
    }

    /**
     * Point the order's shipping line at a courier, without touching the money.
     *
     * ## In place, and what that buys
     *
     * `WC_Abstract_Order::save()` runs `save_items()` (`abstract-wc-order.php:255`),
     * which calls `save()` on every item in every group it has loaded
     * (`:352-361`) — both verified against the 11.0.1 compose.yaml pins. The
     * `get_items('shipping')` below is what loads the group, so mutating the
     * item here and letting the caller's `save()` run is a complete write. No
     * `calculate_totals()` is needed or wanted: nothing in the sum moved.
     *
     * Every shipping line gets the courier, not just the first. An order this
     * API wrote has exactly one — `replaceShippingLine()` collapses them, and
     * argues why — but WooCommerce permits several and another surface may have
     * left them. Naming a courier on one of three and leaving two pointing
     * somewhere else would produce an order whose answer to "who is carrying
     * this" depends on which line you read, and `OrderPresenter` reads the
     * first. Writing all of them keeps the question single-valued.
     *
     * ## When there is no line at all, one is made
     *
     * This is the `POST /orders` case the whole field exists for: an order
     * taken on the phone where the operator knows the courier and either has no
     * delivery charge to state or has not been told it yet. Refusing here would
     * make `shipping_provider` depend on `shipping_amount`, and the two are
     * independent statements — see `OrderInput`'s docblock, which argues at
     * length that neither validates the other.
     *
     * The new line's total is the order's *current* `shipping_total` rather
     * than a bare zero. On every order that reaches this branch those are the
     * same number, because an order with no shipping lines has nothing for
     * `calculate_totals()` to have summed into that prop. It is written from
     * the prop anyway so the line and the total cannot be made to disagree by
     * an order that arrived here with a shipping total and no line to explain
     * it — this method would otherwise be the thing that silently zeroed a
     * delivery charge, on a request that said nothing about money at all.
     *
     * No `MANUAL_PRICE_META` goes on it, and that is the correct silence:
     * nobody stated this amount, so `OrderPresenter::shippingAmount()` reports
     * `null` and the order says a courier was named and no fee was. Nor a
     * `RATE_SOURCE_META` — no quote produced the number, which is exactly what
     * that field's `null` means.
     */
    private static function assignShippingProvider(WC_Order $order, string $provider): void
    {
        $items = $order->get_items('shipping');

        if ($items === []) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title(self::SHIPPING_LINE_TITLE);
            $item->set_method_id($provider);
            $item->set_total((float) $order->get_shipping_total());

            $order->add_item($item);

            return;
        }

        foreach ($items as $item) {
            $item->set_method_id($provider);
        }
    }

    /**
     * Replace the order's shipping line with one line at the stated amount.
     *
     * ## Replace, never add
     *
     * The clearing loop is not defensive tidying, it is the feature. Stating a
     * fee twice — two PATCHes, or a PATCH after a checkout that already quoted
     * one — must mean the second number, not the sum of both.
     * `replaceLineItems()` removes only `line_item`-type items, on purpose, so
     * a shipping line survives every line edit and would sit there accumulating:
     * 500 stated twice would read back as `shipping_total` 1 000 and an order
     * total 500 too high, with every number internally consistent and wrong.
     * Nothing else in the write path would have caught it, because
     * `calculate_totals():2158-2163` sums *whatever shipping lines it finds*.
     *
     * It also collapses an order that arrived with several shipping lines — a
     * shape WooCommerce allows and our own writes never produce — down to the
     * one this API can describe. That is the honest consequence of offering a
     * single `shipping_amount`: a caller who states one is stating the order's
     * whole delivery charge, and the field could not mean anything else without
     * a per-line address for shipping lines that the payload has no way to
     * express (the same argument `LineItemInput` makes about repricing one line
     * in place).
     *
     * `remove_item()` rather than `remove_order_items('shipping')`, for the
     * reason `replaceLineItems()` spells out: the plural form deletes the rows
     * immediately and unsets the in-memory group, and the next read re-populates
     * that group from the database — which would resurrect the line we just
     * deleted. `remove_item()` queues the deletion and `save_items()` processes
     * it before the new line is written.
     *
     * ## Why the new item is not saved here
     *
     * `add_item()` stages the item and persists it on the order's next save
     * (`abstract-wc-order.php:1281`, which appends under a `new:` key), and the
     * next save is the `calculate_totals()` every caller runs immediately after.
     * So the meta can be written straight onto the object before it is added,
     * with no second write and no window in which the order carries a fee that
     * is not marked as stated. That is the one place this differs from
     * `rememberManualPrice()`, which has to re-read its item because
     * `add_product()` saves inside itself and hands back only an id.
     *
     * The amount goes through `(float)` because `WC_Order_Item_Shipping::set_total()`
     * takes a number, and the *string* the caller typed is what
     * `MANUAL_PRICE_META` keeps — the same split step 3 made for a line price,
     * for the same reason: the money is WooCommerce's to round at the store's
     * precision, and the audit has to record what the person actually wrote.
     *
     * ## No `CATALOGUE_PRICE_META` goes on beside it, and that is not an omission
     *
     * A hand-priced product line gets two keys: the amount typed and the
     * catalogue amount it displaced. A stated fee gets only the first, because
     * the second does not exist. Delivery has no catalogue: §14's tariff is
     * reached from a *cart* — it needs a destination and a basket weight — and
     * an order is not a cart, which is the same reason `OrderInput` cannot let
     * an empty `shipping_amount` mean "re-quote it". Writing a `0` or an echo of
     * the previous fee here would invent a baseline and make every stated fee
     * read as a discount against a number nobody ever quoted.
     *
     * The comparison that does exist is recorded instead: `OrderService::snapshot()`
     * puts `shipping_total` on both halves of an `order.updated` pair, so the
     * record says what delivery was charging before this request and what it
     * charges after — for a quoted fee as well as a stated one, which no meta on
     * this line could do.
     *
     * ## The courier survives a restated fee, and this is the bug that was not
     *
     * `$provider` is null when the payload named no courier, and then the
     * courier already on the line is carried onto the new one. Writing `''`
     * there instead — which is what this method did while `method_id` had
     * nothing to hold — is the version that looks harmless and is not.
     *
     * Correcting a delivery charge is the single most ordinary PATCH this API
     * takes: *the courier came back at 600, not 450.* Under a blind `''` that
     * request would also, silently, un-assign the courier. On a storefront
     * order that is a real loss, because `Cart\CheckoutService::createOrder()`
     * now writes a registered courier's name there from the winning quote, and
     * backend step 2's fifth item reads exactly that field to decide who to
     * hand the parcel to on confirmation. The operator would fix a price and
     * break a dispatch, with nothing in the response to say so.
     *
     * The rule that falls out is the one this API applies to every other field
     * on a PATCH: **a payload changes what it mentions.** `applyProps()` walks
     * only the keys the caller stated, one setter each; a restated fee is a
     * statement about the fee.
     *
     * ## What does *not* survive it, and why that is the opposite decision
     *
     * `RATE_SOURCE_META` is deliberately not carried forward, and the asymmetry
     * with `method_id` one line above is the point rather than an inconsistency.
     * That field records **where this number came from**, so a number a person
     * has just replaced by hand cannot keep the old one's provenance — the fee
     * is now stated, and `OrderPresenter::shippingSource()`'s docblock says in
     * terms what a stated fee reads: *"An order whose fee a person typed in the
     * back office therefore reads `null` here and carries a `shipping_amount`
     * instead."* Carrying it would let an order claim a courier quoted a figure
     * no courier has ever seen, which is precisely why `shipping_source` is not
     * settable by anyone.
     *
     * `method_id` is not about the number at all. It is about the van. Restating
     * a price says nothing about who drives.
     */
    private static function replaceShippingLine(WC_Order $order, string $amount, ?string $provider = null): void
    {
        /*
         * Read before the removal loop rather than off `$existing` afterwards.
         * `remove_item()` only queues the deletion — the object stays readable,
         * which is what makes the tempting version work today — but a value
         * this method depends on should not be read out of something it has
         * already asked to be destroyed, and the first line is the one
         * `OrderPresenter::shippingSource()` reads, so "first" has to mean the
         * same thing in both places.
         */
        $carried = '';

        foreach ($order->get_items('shipping') as $existing) {
            if ($carried === '') {
                $carried = (string) $existing->get_method_id();
            }

            $order->remove_item($existing->get_id());
        }

        $item = new WC_Order_Item_Shipping();
        $item->set_method_title(self::SHIPPING_LINE_TITLE);
        /*
         * The courier this payload named, or the one already carrying it.
         * Empty only when neither exists, which is the original back-office
         * case: a fee stated before anybody has decided who delivers it.
         */
        $item->set_method_id($provider ?? $carried);
        $item->set_total((float) $amount);
        $item->update_meta_data(self::MANUAL_PRICE_META, $amount);

        $order->add_item($item);
    }

    /**
     * `add_product()`'s `$args` for one line: nothing when the catalogue prices
     * it, a `subtotal`/`total` pair when a person stated the price.
     *
     * ## Overriding by argument rather than by writing the item afterwards
     *
     * `add_product()` builds `$default_args` with `subtotal` and `total` both
     * set to `wc_get_price_excluding_tax($product, ['qty' => $qty, 'order' => null])`
     * and then runs `wp_parse_args($args, $default_args)`, so anything passed
     * in wins (`abstract-wc-order.php:1750`, defaults at `:1761-1770`). Setting
     * the amounts on the item afterwards would work too and is worse: the item
     * is saved inside `add_product()`, so it would be two writes and a window
     * in which the order is persisted at the catalogue price.
     * `Cart\CheckoutService::createOrder()` already passes the pair this way
     * for a cart line, which is the same override for a different reason.
     *
     * Only `subtotal` and `total` are passed. `$args` is handed straight to
     * `WC_Order_Item_Product::set_props()` (`:1793`, on the item built at
     * `:1792`), so every key in it is a
     * property write — including `order`, which `add_product()` reads for tax
     * context but would then also try to set. The conversion below is done
     * here instead, which keeps this array to the two amounts it is about.
     *
     * ## Why the manual price goes through wc_get_price_excluding_tax()
     *
     * Because a line's `subtotal` and `total` are **ex-tax** amounts — that is
     * what `add_product()`'s own default puts there — and a catalogue price is
     * not necessarily quoted that way. When a store has "prices include tax"
     * on, `wc_get_price_excluding_tax()` strips the tax back out
     * (`wc-product-functions.php:1591`, the `is_taxable() && wc_prices_include_tax()`
     * branch at `:1613`). Passing `price × quantity` straight through would
     * therefore mean a manual price and a catalogue price are quoted in
     * different terms in the same order — the operator on the phone types the
     * number they agreed with the customer, and it would land inflated by the
     * tax rate. The `price` argument exists for exactly this substitution
     * (`:1604`), so the manual price is converted the way the catalogue price
     * it replaces would have been, and nothing else about the line changes.
     *
     * On this shop it is currently a no-op, and that is measured rather than
     * assumed: `wc_prices_include_tax()` is false, `woocommerce_calc_taxes` is
     * `no` and the test products report `is_taxable() === false`, so the
     * function returns `price × quantity` unchanged (`:1646`, the `else`
     * branch). It is here for the store that turns tax on later, not for this
     * one today.
     *
     * ## Why both `subtotal` and `total`, and not just `total`
     *
     * The order-edit item's formula only names the total, and setting `total`
     * alone satisfies it arithmetically. It was tried, on this stack, against a
     * kettle listed at 1 500 and a manual price on each side of it. Both
     * outcomes are wrong, and neither is the one predicted from reading
     * `calculate_totals()` alone:
     *
     * ```
     * total-only, 1200.50 x2   subtotal=3000  total=2401.00  discount_total=599
     * total-only, 2000.00 x2   subtotal=4000  total=4000.00  discount_total=0
     * ```
     *
     *  1. **Below the catalogue price it invents a discount.**
     *     `calculate_totals()` derives
     *     `set_discount_total(round(cart_subtotal - cart_total))` at
     *     `abstract-wc-order.php:2197`, so the 599 above is the gap between the
     *     list price and the agreed one, reported to `discount_total`,
     *     WooCommerce's admin screens and the customer's email as though a
     *     coupon or a sale price had granted it. Nobody granted anything: a
     *     price was agreed. This is the common direction and the whole reason
     *     the field exists.
     *  2. **Above it, WooCommerce overrules the subtotal anyway.** The second
     *     row is not a subtotal of 3 000 as passed — it is 4 000.
     *     `WC_Order_Item_Product::set_total()` ends with "Subtotal cannot be
     *     less than total" and raises the subtotal to match
     *     (`class-wc-order-item-product.php:145-148`). So "set only the total"
     *     does not even give a stable subtotal; it gives one that depends on
     *     which side of the catalogue price the agreed number fell. Worth
     *     recording that this also refutes the reason first written here — the
     *     negative `discount_total` reasoned out of `set_discount_total()`
     *     having no clamp at `:831` never happens, because the clamp is
     *     upstream on the item. The argument is the measurement, not the guess.
     *  3. **It overloads the one signal that already means something.** On a
     *     WooCommerce order `subtotal !== total` means the discount machinery
     *     ran. Keeping that true is what lets a coupon still be read off an
     *     order at a glance, and it is why `MANUAL_PRICE_META` exists rather
     *     than the flag being derived from the gap between the two.
     *
     * So a manual price is the line's price, before and after discounts alike,
     * and the catalogue price it replaced is preserved by the audit rather than
     * by hiding it in a total. The consequence to know: `subtotal` on a manually
     * priced order is the agreed money, not the list money, so **the order's
     * arithmetic cannot say what it would have cost at catalogue** — the gap is
     * deliberately not encoded in any total. What answers that question is
     * `CATALOGUE_PRICE_META`, frozen onto the line beside the manual price at
     * the moment it is written, and the `order.created` / `order.updated` rows
     * `OrderService` writes from it.
     *
     * @param array{product: WC_Product, quantity: int, price: ?string} $line
     * @return array<string, float>
     */
    private static function lineTotals(array $line): array
    {
        if ($line['price'] === null) {
            return [];
        }

        $amount = (float) wc_get_price_excluding_tax($line['product'], [
            'price' => $line['price'],
            'qty' => $line['quantity'],
        ]);

        return ['subtotal' => $amount, 'total' => $amount];
    }

    /**
     * Record that this line's price was typed by a person, not read from the
     * catalogue — and what the catalogue was asking at the time.
     *
     * Read back by `OrderPresenter::lineItems()` as the line's `price`, which
     * is what closes the round trip: without it a GET → edit → PATCH cycle
     * re-prices every line from the catalogue and loses the agreed amount
     * silently, because the write shape has a `price` and the read shape did
     * not.
     *
     * The second key is the audit's, and it is written here for the one reason
     * that matters: this is the last instant at which the catalogue price is a
     * fact. `CATALOGUE_PRICE_META` argues why it cannot be reconstructed
     * afterwards and why it is persisted rather than handed upwards. Both keys
     * go on in one pass so there is no order in which the line is saved marked
     * as hand-priced with nothing to compare it against.
     *
     * Fetched back through `get_item()` rather than kept from `add_product()`,
     * which returns only an id. That is a load from the database — `get_item()`
     * defaults to `$load_from_db = true` and goes through
     * `WC_Order_Factory::get_order_item()` (`abstract-wc-order.php:1207`) — so
     * this is a second object for the row `add_product()` has just saved. It is
     * safe for the same reason `Cart\CheckoutService::attachOptions()` is: meta
     * is written additively, and the order-level `save()` that follows in
     * `calculate_totals()` writes the in-memory item's own props and meta
     * without deleting keys it never knew about.
     *
     * Returning without recording is not a swallowed error, because it cannot
     * happen: `add_product()` builds a `WC_Order_Item_Product` and nothing else
     * (`:1792`) and has already saved it, so the row is there and it is that
     * type. The guard states the type for a reader and a static analyser.
     *
     * It is written as a return rather than a throw deliberately. By this point
     * the line is saved and the order is half-built — `calculate_totals()` has
     * not run — so raising here would abandon an order mid-write to protect a
     * record, which is the wrong trade. And the failure would degrade
     * legibly rather than dangerously: the line still carries the money that
     * was actually charged, and `OrderPresenter` would report it as a
     * catalogue-priced line, and the audit would record no manual price for it.
     * Nothing downstream treats the meta's presence as proof of anything; it is
     * the *decision*, and the money is the money.
     *
     * @param array{product: WC_Product, quantity: int, price: string} $line
     */
    private static function rememberManualPrice(WC_Order $order, int $itemId, array $line): void
    {
        $item = $itemId > 0 ? $order->get_item($itemId) : null;

        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $item->update_meta_data(self::MANUAL_PRICE_META, $line['price']);

        $catalogue = self::cataloguePrice($line['product']);

        /*
         * Absent rather than `'0'` or `''` when the product carries no price of
         * its own. The audit reads a missing key as "there was no catalogue
         * price to compare against", and a stored zero would read as a full
         * price given away — see CATALOGUE_PRICE_META.
         */
        if ($catalogue !== null) {
            $item->update_meta_data(self::CATALOGUE_PRICE_META, $catalogue);
        }

        $item->save();
    }

    /**
     * What the catalogue is asking for this product, as a unit price string, or
     * null when it is asking nothing.
     *
     * `get_price()` and not `get_regular_price()`: the audit's question is what
     * the customer would have paid had nobody typed anything, and on a product
     * that is on sale that is the sale price. It is also the value the manual
     * price literally replaces — `wc_get_price_excluding_tax():1604` falls back
     * to `(float) $product->get_price()` exactly when `lineTotals()` does not
     * pass a `price` argument — so recording the regular price instead would
     * report a discount that a sale had already granted as one this operator
     * granted.
     *
     * `is_numeric()` rather than `!== ''` because the prop is a free-text meta
     * value underneath and a product may carry anything in it; `money()` in the
     * presenter guards its own reads the same way. The string is stored as
     * WooCommerce stores it, unrounded, for the reason the typed price is: the
     * audit records what was there, and rounding for display is the presenter's
     * job and not the record's.
     */
    private static function cataloguePrice(WC_Product $product): ?string
    {
        $price = $product->get_price();

        return is_numeric($price) ? (string) $price : null;
    }

    /**
     * @param list<LineItemInput> $items
     * @return list<array{product: WC_Product, quantity: int, price: ?string}>
     */
    private function resolveLines(array $items): array
    {
        $lines = [];

        foreach ($items as $index => $item) {
            $lines[] = [
                'product' => $this->resolveProduct($item, $index),
                'quantity' => $item->quantity,
                /*
                 * Carried, not resolved. Whether a price is *allowed* was
                 * settled by LineItemInput; whether the product exists is this
                 * layer's question and is asked above. Projecting the price out
                 * here — which is what this method used to do — is what left
                 * the field validated and applied nowhere.
                 */
                'price' => $item->price,
            ];
        }

        return $lines;
    }

    /**
     * Resolve a line to the product WooCommerce should price and stock.
     *
     * A variation is addressed by its own id, and the parent must match: an
     * order line pairing product A with a variation of product B would price
     * from one and reduce stock on the other.
     */
    private function resolveProduct(LineItemInput $item, int $index): WC_Product
    {
        $field = "line_items.{$index}";

        if ($item->variationId === 0) {
            $product = wc_get_product($item->productId);

            if (!$product instanceof WC_Product) {
                throw ApiException::invalidRequest('The order data is invalid.', [
                    'fields' => ["{$field}.product_id" => "No product with id {$item->productId}."],
                ]);
            }

            if ($product->is_type('variable')) {
                throw ApiException::invalidRequest('The order data is invalid.', [
                    'fields' => ["{$field}.variation_id" => 'This is a variable product; name the variation to order.'],
                ]);
            }

            return $product;
        }

        $variation = wc_get_product($item->variationId);

        if (!$variation instanceof WC_Product_Variation) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ["{$field}.variation_id" => "No variation with id {$item->variationId}."],
            ]);
        }

        if ((int) $variation->get_parent_id() !== $item->productId) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ["{$field}.variation_id" => 'That variation belongs to a different product.'],
            ]);
        }

        return $variation;
    }

    /**
     * WooCommerce's own range syntax for a date query var.
     *
     * A bare `Y-m-d` covers the whole day at both ends, so from == to returns
     * that day rather than nothing.
     *
     * Public because COD's funnel counts orders over the same windows this
     * endpoint filters on, and two implementations of "what does date_from mean
     * at the edges" would eventually disagree by a day.
     */
    public static function dateRange(string $from, string $to): string
    {
        if ($from !== '' && $to !== '') {
            return "{$from}...{$to}";
        }

        if ($from !== '') {
            return ">={$from}";
        }

        return $to !== '' ? "<={$to}" : '';
    }
}
