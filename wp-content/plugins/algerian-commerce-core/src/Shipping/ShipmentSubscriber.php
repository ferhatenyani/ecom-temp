<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Orders\OrderRepository;
use Throwable;
use WC_Order;

/**
 * Creates the parcel when an order is confirmed — backend step 2, item 5.
 *
 * The reference shop does this inline: `OrderService.java:363-367` and `:413-417`
 * call `createShippingParcel()` from `update()` and `updateStatus()` when the
 * new status is `CONFIRMED`, the order carries a provider, and its tracking
 * number is null. Two properties of that method are the whole point of the item
 * and both are copied here deliberately — **it never throws**, and **it retries**
 * — each argued in its own place below.
 *
 * ## A hook, not a call from OrderService
 *
 * The same decision `OrderStockSubscriber` and `CodSubscriber` both made, for
 * the same reason, and it matters more here than it did for either of them.
 *
 * EL has one door: an order's status changes because its `OrderService` changed
 * it. We have at least five. `PATCH /orders/{id}` is one, through
 * `OrderRepository::applyStatus()`. `POST /orders` is a second — `processing`
 * is in `OrderStatus::CREATABLE`, so an order taken on the telephone can be
 * *born* confirmed. `OrderRepository::markPaid()` is a third, and it is the
 * interesting one: `WC_Order::payment_complete()` picks the next status itself
 * — `$this->needs_processing() ? PROCESSING : COMPLETED`, then `set_status()`
 * and `save()` (`class-wc-order.php:174-184`, verified against the 11.0.1 that
 * compose.yaml pins) — so a gateway confirming a payment confirms the order and
 * nothing in `Orders/` chose a status to hang anything off. wp-admin is a
 * fourth, WP-CLI and cron a fifth. A parcel created only by the first would mean
 * a shop whose paid-online orders never reach a courier, and the failure is
 * silent.
 *
 * Hooking the transition catches all of them, because WooCommerce fires
 * `woocommerce_order_status_processing` from `WC_Order::status_transition()`
 * regardless of who caused it. That is the identical argument
 * `OrderStockSubscriber` makes about the ledger — *"a ledger fed only by our own
 * service would be missing every movement that did not come through our API"* —
 * and `CodSubscriber` makes about cancellations.
 *
 * It also keeps the dependency running one way. `Orders/` knows nothing about
 * shipping; this module reads orders. Calling `ShippingService` from
 * `OrderService` would point the arrow back and make the two domains cyclic
 * (docs/ARCHITECTURE.md §3) — and `OrderService`'s own docblock already commits
 * to the opposite, saying of its provider registry that *"no rate is fetched and
 * no shipment is created here"*.
 *
 * **The alternatives, and why not.** Calling from `OrderService::update()` is
 * EL's shape and misses four of the five doors. Calling from
 * `OrderRepository::applyStatus()` misses the same four and puts a courier call
 * in a repository. Hooking `woocommerce_order_status_changed` instead would
 * work, and is strictly wider — it fires for every transition and would need a
 * `$to === 'processing'` test of its own — so it buys nothing and adds a
 * comparison that can be got wrong; `CodSubscriber` chose the specific hook for
 * the same reason. A cron sweep over confirmed orders with no parcel would be
 * the most robust of all against a missed hook, and is the wrong shape for the
 * first version: it delays every parcel by up to an hour on a stack where
 * WP-Cron only fires when somebody visits the site (`Plugin::registerShipmentPolling()`
 * says so at length), and a sweep is easy to add later on top of this.
 *
 * ## What the hook costs, and what is done about it
 *
 * The failure cannot be returned. `do_action()` discards return values and most
 * confirmations have no HTTP response at all, so EL's `shippingProviderError`
 * on the response DTO is not available to us. It is stored on the order instead
 * — see `recordFailure()` — which turns out to be the better half of the trade:
 * an operator who confirms an order from wp-admin on Monday can still find out
 * on Thursday why no parcel appeared, and EL's operator cannot, because their
 * string lived exactly as long as one HTTP response.
 *
 * ## Never throws
 *
 * `onOrderConfirmed()` catches `Throwable` twice. The inner catch turns a
 * failure into a record; the outer one exists because *recording* a failure is
 * itself a database write that can fail, and a guarantee with a hole in it at
 * the point of failure is not a guarantee.
 *
 * **The status is committed before this runs, and that is not what the catch is
 * for.** `WC_Order::save()` is `maybe_set_user_billing_email(); parent::save();
 * $this->status_transition();` (`class-wc-order.php:283-289`, verified against
 * the 11.0.1 that compose.yaml pins), and `parent::save()` is what writes the
 * row. So the new status is already in the database when the hook fires and no
 * exception here could roll it back. The item's stated property — "the status
 * change still commits" — is therefore free. What the catch buys is everything
 * else.
 *
 * **WooCommerce's own net is real, and is not enough**, which is worth spelling
 * out rather than relying on. `WC_Order::status_transition()` wraps its
 * `do_action` in `try { … } catch ( Exception $e )` (`:440-501`), logs, and adds
 * an order note. Three gaps remain, and each is a reason this class catches for
 * itself:
 *
 *  - **It catches `Exception`, not `Throwable`.** A `TypeError` or an
 *    `ArgumentCountError` from inside an adapter is an `Error`, sails straight
 *    past that `catch`, out of `save()`, and turns a successful `PATCH` into a
 *    500 and a wp-admin save into a white screen — on an order that is already
 *    confirmed.
 *  - **Its handler publishes the message.** The note it adds is
 *    `'Error during status transition. ' . $e->getMessage()`, and a raw
 *    exception message carries file paths, SQL and occasionally a credential
 *    (docs/SECURITY.md). `ShipmentFailure::fromThrowable()` exists precisely so
 *    that never happens.
 *  - **It abandons the rest of the transition.** The `catch` is around the whole
 *    body, so a hook that throws takes the status-transition note,
 *    `woocommerce_order_status_{from}_to_{to}`, `woocommerce_order_status_changed`
 *    and `woocommerce_order_payment_status_changed` down with it. A courier
 *    being unreachable would silently stop every *other* subscriber in the
 *    system from hearing about the confirmation.
 *
 * ## Idempotence
 *
 * Three layers, none of them new:
 *
 *  - WooCommerce fires this on a *transition*. Saving a `processing` order
 *    again changes nothing and fires nothing — the same shape as the
 *    `_reduced_stock` marker `OrderStockSubscriber` describes, one level up.
 *  - A second genuine transition into `processing` finds the live shipment the
 *    first one created and stops. That is `ShipmentRepository::liveForOrder()`,
 *    the existing "one live shipment per order" rule, reused rather than
 *    restated.
 *  - Two transitions at once are settled by `ShipmentRepository::claimOrder()`,
 *    a MySQL `GET_LOCK` taken before the read and held across the courier call.
 *    The loser is refused immediately with a 409 that this class swallows as an
 *    ordinary failure — which is the right outcome, because the parcel the
 *    winner created is the parcel the order needed.
 *
 * ## And it retries
 *
 * EL guards on `trackingNumber == null`. Ours is `liveForOrder() === null`, and
 * the mapping is exact where it matters and better where it does not:
 *
 *  - **A courier refusal leaves nothing behind.** `ShippingService::createClaimed()`
 *    calls the provider before it writes a row, so a rejected commune produces
 *    no shipment at all. The next confirmation, after the operator has fixed the
 *    address or named a different courier, finds no live shipment and tries
 *    again. This is EL's null tracking number, arriving by a different route.
 *  - **A cancelled or failed parcel does not block one.** Both are terminal, so
 *    an order whose parcel was called off or given up on can be sent again. EL
 *    cannot do this: its tracking number is still set, so the guard refuses
 *    forever and the operator has to clear a column by hand.
 *  - **A delivered or returned parcel does not block one either**, which is the
 *    same rule and is deliberate rather than incidental — `ShippingService`'s
 *    docblock calls it out as what makes *"a re-send after a failed delivery
 *    work without deleting history"*.
 *  - **Anything still in the air blocks it**, including `pending`, a parcel this
 *    system recorded that the provider has not accepted. That is the case where
 *    trying again really would put two boxes on two vans.
 */
final class ShipmentSubscriber
{
    /**
     * Where the destination lives.
     *
     * Read here rather than owned here: `Cart\CheckoutService::createOrder()`
     * writes all three when the storefront places an order, and
     * `Orders\OrderRepository::applyProps()` writes the identical three when the
     * back office does.
     *
     * These were private literals — a third copy of keys that were already
     * spelled twice, and this class's own docblock called that *"one
     * duplication too many"*. They are now `OrderRepository`'s constants, which
     * is where the third writer put them and the side of the boundary that
     * keeps the dependency running one way: this module already imports that
     * class, and nothing in `Orders/` imports this one.
     *
     * The aliases are kept rather than inlined at the two call sites below,
     * because `destinationOf()` reads better against three names than against
     * three qualified constants, and because the indirection is now doing
     * something it was not before — it names, in one place, the fact that this
     * reader and both writers are reading and writing the same three keys.
     */
    private const WILAYA_META = OrderRepository::WILAYA_META;
    private const COMMUNE_META = OrderRepository::COMMUNE_META;
    private const DELIVERY_TYPE_META = OrderRepository::DELIVERY_TYPE_META;

    public function __construct(
        private readonly ShippingService $shipping,
        private readonly OrderRepository $orders,
        private readonly ShipmentRepository $shipments,
        private readonly AuditLogger $audit,
        private readonly ?Logger $logger = null
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_processing', [$this, 'onOrderConfirmed'], 10, 2);
    }

    /**
     * @param mixed $orderId int
     * @param mixed $order   WC_Order, as WooCommerce passes it — deliberately
     *                       unused; see `confirm()`
     */
    public function onOrderConfirmed(mixed $orderId, mixed $order = null): void
    {
        $orderId = (int) $orderId;

        if ($orderId <= 0) {
            return;
        }

        try {
            $this->confirm($orderId);
        } catch (Throwable $exception) {
            /*
             * The outer half of "never throws". `confirm()` already catches
             * everything the courier can do; what reaches here is the recording
             * of that failure failing — a dead database connection, a meta write
             * refused, an order that vanished between two reads.
             *
             * The log is the last place left. It is not much, and it is
             * strictly better than a white screen on an order that is already
             * confirmed.
             */
            $this->logger?->error('Confirmation parcel handling failed outright', [
                'order_id' => $orderId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function confirm(int $orderId): void
    {
        /*
         * A fresh object, never the one the hook handed over — `CodSubscriber`
         * argues this and the argument is unchanged. This runs inside
         * `WC_Order::status_transition()`, called from the tail of `save()`;
         * writing meta onto that same instance and saving it again re-enters a
         * save that is still in progress. A separately loaded order has no
         * pending status change, so its `save()` writes the meta and fires no
         * further transition — which also means this method cannot re-enter
         * itself through its own writes.
         */
        $order = $this->orders->find($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $provider = self::providerOf($order);

        if ($provider === null) {
            /*
             * Nobody named a courier. **A real and expected state, not an
             * error** — an order taken by phone before anyone decided who
             * delivers it — and it is item 6's case exactly: the parcel is
             * created from the panel when somebody knows the answer. Recording
             * a failure here would put a red flag on every order of a shop that
             * assigns couriers at dispatch time.
             */
            return;
        }

        if ($this->shipments->liveForOrder($orderId) !== null) {
            // Already has a parcel in the air. Nothing to do, and any failure
            // still on the order is answered by the parcel that exists.
            $this->clearFailure($order);

            return;
        }

        $destination = self::destinationOf($order);
        $now = self::now();

        if ($destination === null) {
            $this->recordFailure($order, ShipmentFailure::noDestination($provider, $now));

            return;
        }

        try {
            $shipment = $this->shipping->createOnConfirmation($orderId, $destination + ['provider' => $provider]);
        } catch (ApiException $exception) {
            $this->recordFailure($order, ShipmentFailure::fromApiException($provider, $exception, $now));

            return;
        } catch (Throwable $exception) {
            /*
             * Everything an `ApiException` is not: a provider returning a shape
             * `ShipmentResult` refuses, a `TypeError` inside an adapter, an HTTP
             * client raising something nobody catalogued. The full message goes
             * to the log and a fixed sentence goes to the order —
             * `ShipmentFailure::fromThrowable()` says why the two differ.
             */
            $this->logger?->error('A courier failed unexpectedly on confirmation', [
                'order_id' => $orderId,
                'provider' => $provider,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->recordFailure($order, ShipmentFailure::fromThrowable($provider, $exception, $now));

            return;
        }

        $this->clearFailure($order);

        $this->logger?->info('Confirmation created a parcel', [
            'order_id' => $orderId,
            'provider' => $shipment->provider,
            'shipment_id' => $shipment->id,
        ]);
    }

    /**
     * Which courier this order names, or null when nobody has.
     *
     * The shipping line's `method_id`, and the rule is
     * `OrderPresenter::shippingProvider()`'s to the letter: the **first
     * non-empty** one wins, because an order can carry several shipping lines
     * and one that is a bare fee must not hide a courier the next line plainly
     * names. `OrderInput` has already normalized anything written through this
     * API to `strtolower(trim())`, which is the form `ProviderRegistry::has()`
     * and `get()` match on, and the value is lower-cased again here because a
     * line written by wp-admin or another plugin has been through no such thing.
     *
     * **The name is not checked against the registry**, and must not be. An
     * order outlives the registration that made it: a shop that switches a
     * courier off still has historical orders naming it, and
     * `OrderService::guardShippingProviderKnown()` deliberately lets those
     * restate their own courier for exactly that reason. What happens to a
     * de-registered name here is that `ProviderRegistry::get()` throws a 400
     * inside `createOnConfirmation()`, which becomes a recorded failure naming
     * the courier — the right outcome, and one the operator can act on by
     * naming a courier the shop does have.
     */
    private static function providerOf(WC_Order $order): ?string
    {
        foreach ($order->get_items('shipping') as $item) {
            $stored = strtolower(trim((string) $item->get_method_id()));

            if ($stored !== '') {
                return $stored;
            }
        }

        return null;
    }

    /**
     * Where this order is going, or null when it does not say.
     *
     * ## Ids, from meta, and nowhere else
     *
     * `Cart\CheckoutService::createOrder()` writes `OrderRepository::WILAYA_META`,
     * `COMMUNE_META` and `DELIVERY_TYPE_META` onto every order the checkout
     * places, and says in a comment that it does so "so a later shipment does
     * not have to guess it back out of a free-text address". This is that later
     * shipment, and the guess it refuses to make is `ShipmentInput`'s: the
     * order's `shipping.city` and `shipping.state` are free text, "Ouled Fayet"
     * is spelled several ways in two languages, and fuzzy-matching it sends a
     * parcel to a commune of the same name in another wilaya — of which Algeria
     * has several.
     *
     * So an order without the meta gets no parcel, and gets a recorded failure
     * saying so. Silently addressing it from the free text would be the one
     * failure mode this whole module is built to avoid, and defaulting the
     * commune to anything would be worse.
     *
     * ## Which orders that leaves out — and it is no longer "the back office"
     *
     * This paragraph used to read *"**Every order created through `POST /orders`.**
     * `OrderInput` has no wilaya or commune field"*, and called that a gap in
     * the write side that ought to be reported rather than papered over. It was
     * reported, and it was then closed: `OrderInput` takes `wilaya_id`,
     * `commune_id` and `delivery_type`, `OrderRepository::applyProps()` writes
     * the same three keys in the same shape as the checkout, and
     * `OrderService::guardDestinationResolves()` refuses a commune that does not
     * belong to the wilaya beside it. The old text is quoted rather than
     * silently overwritten because a reader who remembers it deserves to know it
     * was overturned on purpose — and because the reasoning it gave is the
     * reasoning that paid off.
     *
     * What is left out now is the honest remainder: **an order nobody has
     * addressed.** A phone call taken before the customer's commune is known, an
     * order written by wp-admin, an import. Those still confirm, still get no
     * parcel and still get `order_destination_missing` — which is now an
     * operator forgetting a field rather than a route that had no field to
     * forget, and the panel can put them right with a PATCH. That correction is
     * writable at any status precisely so this method's retry can act on it; see
     * `guardDestinationResolves()`, which has no `is_editable` gate for exactly
     * that reason. `POST /orders/{id}/shipments` remains item 6's answer for an
     * operator who would rather address one parcel than amend the order.
     *
     * The delivery type falls back to `home` when the meta is missing or
     * unrecognised, and only the delivery type does. It is the safe default in a
     * way a commune could never be: home delivery is what a courier does when
     * nobody asks for a desk, and getting it wrong costs a customer a trip
     * rather than a parcel a different wilaya.
     *
     * @return array{wilaya_id: int, commune_id: int, delivery_type: string}|null
     */
    private static function destinationOf(WC_Order $order): ?array
    {
        $wilayaId = (int) $order->get_meta(self::WILAYA_META, true);
        $communeId = (int) $order->get_meta(self::COMMUNE_META, true);

        if ($wilayaId < 1 || $communeId < 1) {
            return null;
        }

        $deliveryType = strtolower(trim((string) $order->get_meta(self::DELIVERY_TYPE_META, true)));

        return [
            'wilaya_id' => $wilayaId,
            'commune_id' => $communeId,
            'delivery_type' => Destination::isKnownDeliveryType($deliveryType)
                ? $deliveryType
                : Destination::HOME,
        ];
    }

    /**
     * Write the failure where an operator will find it — in three places, on
     * purpose.
     *
     *  - **On the order**, under `OrderRepository::SHIPPING_ERROR_META` and
     *    published by `OrderPresenter` as `shipping_provider_error`. This is the
     *    one an admin panel renders on the order it is already showing, and the
     *    one that answers "why is there no parcel" days later. It is a single
     *    current value and is cleared the moment a parcel exists, because a
     *    stale reason beside a real tracking number is worse than no reason.
     *  - **In the audit trail**, as `shipment.create_failed` against the order.
     *    That store is append-only and keeps every attempt, which is what
     *    "Yalidine has refused this address four times" needs and what a single
     *    meta value structurally cannot hold. It also puts the failure on the
     *    order's timeline, which already merges audit rows.
     *  - **In the log**, for the developer, and only where there is something a
     *    developer needs that an operator must not see — see the `Throwable`
     *    branch in `confirm()`.
     *
     * The audit row carries no actor by design: `AuditLogger` records the
     * current user, and on a cron or gateway confirmation there is none. That is
     * accurate rather than lossy — nobody did this, a status change did.
     */
    private function recordFailure(WC_Order $order, ShipmentFailure $failure): void
    {
        $order->update_meta_data(OrderRepository::SHIPPING_ERROR_META, $failure->toMeta());
        $order->save();

        $this->audit->record('shipment.create_failed', 'order', $order->get_id(), $failure->toMeta());

        $this->logger?->warning('Confirmation could not create a parcel', [
            'order_id' => $order->get_id(),
            'provider' => $failure->provider,
            'code' => $failure->code,
        ]);
    }

    /**
     * Take last time's failure off the order.
     *
     * Only when there is one, so an ordinary confirmation is a read and not a
     * write — most orders have never failed, and `save()` on every confirmed
     * order would be a database write per order for nothing.
     *
     * `delete_meta_data()` rather than storing an empty value, because
     * `OrderPresenter` reports the absence of the key as `null` and a client
     * tests one thing. Nothing is lost: the audit row for the failure stays
     * where it was written, and it is the store that is supposed to remember.
     */
    private function clearFailure(WC_Order $order): void
    {
        if (ShipmentFailure::fromMeta($order->get_meta(OrderRepository::SHIPPING_ERROR_META, true)) === null) {
            return;
        }

        $order->delete_meta_data(OrderRepository::SHIPPING_ERROR_META);
        $order->save();
    }

    /** UTC, in the format every other table in this plugin stores a time in. */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
