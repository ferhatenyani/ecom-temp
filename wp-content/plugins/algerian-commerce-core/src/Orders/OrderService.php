<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Audit\AuditRepository;
use AlgerianCommerce\Geography\GeoRepository;
use AlgerianCommerce\Inventory\MovementRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use AlgerianCommerce\Shipping\ProviderRegistry;
use WC_Data_Exception;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Order business rules — roadmap §50, docs/PLAN.md §8.
 *
 * Four rules shape everything here:
 *
 *  - A status may only move where OrderStatus allows. WooCommerce will happily
 *    put a refunded order back into processing; an admin API should not.
 *  - Line items — and, since backend step 4, the delivery fee — may only be
 *    written while the order is *editable*, which is WooCommerce's own rule,
 *    `pending` and `on-hold`, and is **not** the same as "holds no stock". This
 *    line used to say the latter and it was wrong: `on-hold` is editable and
 *    does reduce stock, and guardLineItemsWritable() below spells out that the
 *    stock case is reconciled rather than refused. Worth correcting rather than
 *    leaving, because the wrong version is what a reader takes away about the
 *    gate a manual line price rides behind. The guards are separate methods on
 *    purpose; guardShippingAmountWritable() says why, and says why the rule is
 *    best read as *money is gated, metadata is not*.
 *  - **A price somebody typed needs nothing more**, and that sentence is a
 *    reversal. Backend step 6 added guardManualPricesWritable(), which refused a
 *    stated `price` once the units were off the shelf; the fix round's decision
 *    1 removed it. The rule this class enforces is back to one clause —
 *    *is_editable gates the order's money, and nothing narrows that for the
 *    price of the goods* — because the three things a payload can do to a
 *    stock-holding order's total had grown two answers between them: a quantity
 *    passed silently, a delivery fee passed silently, and a price alone was a
 *    409. An operator cannot hold a rule that arbitrary, and an order paused
 *    awaiting confirmation is the moment an amendment is most likely. The
 *    replacement is *warn, allow, record*: the panel warns from `stock_reduced`
 *    on the read shape, the write proceeds, and snapshot() below records both
 *    the reprice and the fact that stock had moved. The argument the guard made
 *    is kept there rather than deleted, because it was a good argument that lost
 *    to a better one.
 *  - Every write is audited, and the stock consequences of a status change are
 *    recorded in the ledger by OrderStockSubscriber rather than here — an
 *    order-driven movement writes a movement row and no audit row (roadmap
 *    §49), because the audit entry for "someone changed this order's status"
 *    already exists and the stock followed from it.
 *
 *    **Every amount a person chose is named in that entry, and so is the state
 *    the order was in.** `snapshot()` below carries the delivery charge,
 *    whether the order was holding stock at the time, and one row per
 *    hand-priced amount, each against the catalogue price it replaced; both
 *    `order.created` and `order.updated` publish it. That is not decoration:
 *    `LineItemInput` allows a manual price — a price of nothing included — to
 *    anyone holding `ac_manage_orders`, and the audit is the *entire*
 *    replacement for the two refusals that used to stand there. A discount
 *    nobody can attribute is what those gates existed to prevent, and it is
 *    this record that now prevents it, by witnessing rather than refusing —
 *    which is why the fix round's decision could remove the second gate at all.
 *
 * Authorization is asserted here as well as on the route. The route guard stops
 * an unauthenticated caller; this one holds when a WP-CLI command, cron job or
 * another service calls in without passing through REST (docs/SECURITY.md,
 * "Authorization").
 *
 * Every route carries `ac_manage_orders`, so there is no object-level check
 * yet: this API is administrative, and a caller who can read one order can read
 * them all. Customer-facing access — a shopper reading their own order — needs
 * the customer session strategy deferred in roadmap §44, and will use
 * Permissions::assertOwnsOr() when it arrives.
 */
final class OrderService
{
    /** Long enough for an explanation, short enough not to bloat an audit row. */
    public const MAX_CANCEL_REASON = 500;

    /**
     * How many hand-chosen amounts one audit row lists in full.
     *
     * The same worry as `MAX_CANCEL_REASON` above, arriving from the other
     * direction: that constant bounds one caller-supplied string, and this one
     * bounds a list whose length a caller also controls. `line_items` has no
     * cap on how many lines it may carry, every one of them may state a price,
     * and `update()` writes the list **twice** — once as `before`, once as
     * `after`. A 200-line order repriced in a loop would push six figures of
     * JSON per request into an append-only table that has no per-row prune.
     * `metadata` is `longtext`, so nothing would refuse it; it would simply
     * become a trail nobody can read and nobody can shrink.
     *
     * Twenty, because the record's job is to let a person read a discount and
     * attribute it. A back-office order is a negotiation on the telephone —
     * "the damaged copy at half, and I'll absorb the courier" — and twenty
     * hand-typed amounts is already far past any such conversation. Past that
     * it is an import, and an import is answered by the count rather than by
     * the list.
     *
     * **Past the bound the record says so.** `manual_prices_omitted` carries how
     * many rows were left out, and its *absence* is the assertion that the list
     * is complete — a truncation that does not announce itself is a record that
     * lies, and this one is read by somebody trying to establish what an
     * operator did. It is not there to be tidy.
     *
     * What truncation actually costs is worth stating rather than glossing.
     * On the `after` side: almost nothing, because
     * `OrderRepository::CATALOGUE_PRICE_META` is frozen onto each line, so every
     * omitted row is still recoverable from the order itself for as long as the
     * line stands. On the `before` side: the real thing, because the write that
     * triggered the record has already destroyed those lines. That asymmetry is
     * the price of a bounded row, and the bound is still right — an unbounded
     * audit row is a worse failure than a truncated one, and it fails silently.
     *
     * The one row that is never dropped is the delivery fee: `manualPrices()`
     * asks for the shipping item first, so the bound bites on product lines,
     * which are many and homogeneous, and never on the single stated fee, which
     * is a decision of its own.
     */
    public const MAX_AUDITED_PRICES = 20;

    /** How far back a note or timeline read may reach in one request. */
    public const MAX_NOTES = 200;
    public const DEFAULT_NOTES = 50;

    public function __construct(
        private readonly OrderRepository $repository,
        private readonly AuditLogger $audit,
        /**
         * Read-only, and only for the timeline: an order's history is spread
         * across the notes, the audit trail and the stock ledger, and merging
         * them on read is what stops a fourth copy existing that can disagree
         * with all three.
         */
        private readonly AuditRepository $auditLog,
        private readonly MovementRepository $movements,
        /*
         * The couriers, and the only thing this class asks them: whether a name
         * an operator typed is one of them. No rate is fetched and no shipment
         * is created here — `Shipping\ShippingService` owns both — so the
         * dependency is a membership test and nothing more.
         *
         * Optional for the reason `Cart\CheckoutService`'s registry is, with
         * the same consequence stated rather than left to be discovered: a
         * service built without one accepts any well-formed courier name,
         * because there is nothing to check it against. `Plugin::orderService()`
         * always passes one, so that is a test seam and not a configuration a
         * shop can reach.
         */
        private readonly ?ProviderRegistry $providers = null,
        /*
         * The §51 geography, and the only thing this class asks it: whether the
         * commune an operator picked is a real row of the wilaya beside it.
         *
         * A read-only reference dataset, so the new arrow this adds to the
         * module map is the cheap kind — `Geography/` depends on nothing and
         * has no opinion about orders, which is why `Shipping\ShippingService`
         * already holds the identical dependency for the identical check
         * (`validatedDestination()`). It is a lookup, not an orchestration:
         * nothing here creates a parcel, quotes a rate or reads a courier's
         * destination map.
         *
         * Optional and last, on `$providers`' rule and with the same
         * consequence spelled out rather than discovered: a service built
         * without one accepts any well-formed pair of ids, because there is
         * nothing to check them against. `Plugin::orderService()` always passes
         * one. The pair *coherence* check below runs either way, because that
         * one needs no tables.
         */
        private readonly ?GeoRepository $geography = null
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Order>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        return $this->repository->paginate($criteria);
    }

    public function get(int $id): WC_Order
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        return $this->requireOrder($id);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): WC_Order
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $input = OrderInput::forCreate($payload);

        $requested = (string) ($input->get('status') ?? OrderStatus::PENDING);

        /*
         * Not a transition check. `pending → cancelled` is a legal move, but an
         * order that is born cancelled records the calling-off of something
         * that was never placed — see OrderStatus::CREATABLE.
         */
        if (!OrderStatus::canCreateAs($requested)) {
            throw ApiException::conflict("A new order cannot be created as \"{$requested}\".", [
                'status' => $requested,
                'allowed' => OrderStatus::CREATABLE,
            ]);
        }

        $this->guardShippingProviderKnown($input, null);
        // Null for the same reason: a create has no stored destination to
        // resolve a half-stated pair against, and nothing to restate.
        $this->guardDestinationResolves($input, null);

        $order = $this->save(fn (): WC_Order => $this->repository->create($input));

        /*
         * The same snapshot `update()` records, and it used to be four fields
         * spelled out here that happened to match `snapshot()`'s four. They no
         * longer would: a `POST /orders` can hand-price every line it creates
         * and state a delivery fee, and an order that is *born* at a price
         * somebody chose is exactly as much a discount to attribute as one
         * repriced afterwards — arguably more, since there is no earlier version
         * of the order to compare it against.
         *
         * Flat rather than under an `after` key, because a creation has no
         * before to pair it with and the four keys already published here are a
         * shape somebody may be reading. One definition of the shape, two
         * events, so a reader parses `order.created` and either half of an
         * `order.updated` with the same code.
         *
         * That one definition is why `stock_reduced` reaches this event for
         * free, and it needed to: `OrderStatus::CREATABLE` includes `on-hold`
         * and `processing`, both of which reduce stock, so an order can be
         * *born* holding units at a price somebody chose. The old
         * `guardManualPricesWritable()` never applied here — creation corrects
         * nothing, so there was no order yet to be holding anything — which
         * means `POST /orders` was already the reversal's own policy before the
         * reversal, and this record is what made that defensible.
         *
         * Recorded after `save()` deliberately: the repository writes the lines
         * before it applies the status, precisely so the stock transition
         * happens once they exist, so this snapshot reads the flag as it stands
         * after that transition rather than before it.
         */
        $this->audit->record('order.created', 'order', $order->get_id(), $this->snapshot($order));

        return $order;
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): WC_Order
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $order = $this->requireOrder($id);
        $input = OrderInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $statusBefore = $order->get_status();
        $statusAfter = (string) ($input->get('status') ?? $statusBefore);

        if ($input->has('status')) {
            $this->guardTransition($statusBefore, $statusAfter);
        }

        /*
         * One gate, and there used to be two.
         *
         * `guardManualPricesWritable()` stood on the next line from backend step
         * 6 until the fix round's decision 1, and refused a stated `price` on an
         * order that was already holding stock — a 409 carrying `status`,
         * `stock_reduced` and the zero-based `lines` that named an amount. It
         * was removed rather than relaxed, and the whole of its argument, with
         * what beat it, is kept in `snapshot()`'s "Why the record says whether
         * stock had moved". Anybody arriving here looking for the stock rule
         * should read that before concluding it was dropped by accident.
         *
         * What is left is `is_editable`, which is unchanged and stays unchanged:
         * a `processing` or `completed` order's lines cannot be rewritten at
         * all, price or no price. Stock is not a second condition on it, and the
         * method below spells out why the stock-holding case is reconciled
         * rather than refused.
         */
        if ($input->has('line_items')) {
            $this->guardLineItemsWritable($order);
        }

        if ($input->has('shipping_amount')) {
            $this->guardShippingAmountWritable($order);
        }

        /*
         * Not beside the guards above, and not conditional on `has()` either —
         * the method makes both decisions itself, because for this field
         * "should I run?" and "what do I check?" are the same question. See its
         * docblock for why it is a *known-name* check and not a writability
         * one: `shipping_provider` moves no money, so there is no `is_editable`
         * gate here to sit under.
         */
        $this->guardShippingProviderKnown($input, $order);

        /*
         * Beside it and not among the guards above, for its neighbour's exact
         * reason: the method decides for itself whether it should run, because
         * a destination arrives in up to three keys and "is this payload
         * talking about where the parcel goes" is not a question `has()` on any
         * one of them answers. Like `shipping_provider` and unlike
         * `shipping_amount`, there is no `is_editable` gate for it to sit
         * under — see its docblock.
         */
        $this->guardDestinationResolves($input, $order);

        /*
         * Taken before the write, and now that the snapshot reads item meta
         * that is a hard requirement rather than an ordering preference. Both
         * `OrderRepository::replaceLineItems()` and `replaceShippingLine()`
         * *destroy* the items they replace — wholesale replacement is what the
         * whole write path is built on — so the prices a person chose last time
         * exist only until this next statement runs. There is nowhere to read
         * them afterwards.
         */
        $before = $this->snapshot($order);

        $updated = $this->save(fn (): WC_Order => $this->repository->update($order, $input));

        $fields = array_keys($input->fields);

        /*
         * A status change is logged as its own event, not folded into
         * order.updated. It is the operationally interesting thing that
         * happens to an order — it moves money, stock and couriers — and it
         * has to be filterable on its own in the audit trail.
         */
        if ($statusAfter !== $statusBefore) {
            $this->audit->record('order.status_changed', 'order', $id, [
                'from' => $statusBefore,
                'to' => $updated->get_status(),
                'stock_reduced' => OrderRepository::stockReduced($updated),
            ]);
        }

        $otherFields = array_values(array_diff($fields, ['status']));

        if ($otherFields !== []) {
            $this->audit->record('order.updated', 'order', $id, [
                'fields' => $otherFields,
                'before' => $before,
                'after' => $this->snapshot($updated),
            ]);
        }

        return $updated;
    }

    /**
     * Cancel an order.
     *
     * Separate from a PATCH to `cancelled` because a cancellation carries a
     * reason, and because it is the endpoint an admin UI binds a button to.
     * Cancelling an already-cancelled order succeeds and changes nothing: a
     * retried request must not fail, and there is no state to corrupt.
     */
    public function cancel(int $id, string $reason): WC_Order
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        if (mb_strlen($reason) > self::MAX_CANCEL_REASON) {
            throw ApiException::invalidRequest('The request is invalid.', [
                'fields' => ['reason' => 'Must be at most ' . self::MAX_CANCEL_REASON . ' characters.'],
            ]);
        }

        $order = $this->requireOrder($id);
        $from = $order->get_status();

        if ($from === OrderStatus::CANCELLED) {
            return $order;
        }

        $this->guardTransition($from, OrderStatus::CANCELLED);

        // Read before the change. Afterwards the flag is false either way —
        // because the stock was returned, or because there never was any — and
        // the audit entry could not tell the two apart.
        $heldStock = OrderRepository::stockReduced($order);

        $cancelled = $this->save(fn (): WC_Order => $this->repository->changeStatus($order, OrderStatus::CANCELLED));

        $this->audit->record('order.cancelled', 'order', $id, [
            'from' => $from,
            // The reason lives in the audit trail for now. The customer- and
            // staff-visible note surface is POST /orders/{id}/notes, which
            // arrives with the rest of §50.
            'reason' => $reason,
            'stock_restored' => $heldStock,
        ]);

        return $cancelled;
    }

    /**
     * Add a note to an order.
     *
     * Allowed at any status, deliberately. A note is a record of something that
     * happened, and the orders people most need to annotate — delivered,
     * cancelled, refunded — are exactly the ones no longer editable.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the stored note
     */
    public function addNote(int $id, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $order = $this->requireOrder($id);
        $input = OrderNoteInput::fromPayload($payload);

        $noteId = $this->repository->addNote($order, $input);

        if ($noteId === 0) {
            throw ApiException::internal('The note could not be saved.');
        }

        /*
         * Audited even though the note is itself a record, because the two
         * stores have different guarantees: ac_audit_logs is append-only,
         * while WooCommerce notes are comments and an administrator can delete
         * one. The timeline drops this entry so notes are not shown twice.
         */
        $this->audit->record('order.note_added', 'order', $id, [
            'note_id' => $noteId,
            'customer_note' => $input->customerNote,
            'length' => mb_strlen($input->note),
        ]);

        foreach ($this->repository->notes($id, self::MAX_NOTES) as $note) {
            if ($note['id'] === $noteId) {
                return $note;
            }
        }

        // Saved, but not readable back — report the save rather than invent a
        // response body.
        throw ApiException::internal('The note was saved but could not be read back.');
    }

    /** @return list<array<string, mixed>> newest first */
    public function notes(int $id, int $limit): array
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $this->requireOrder($id);

        return $this->repository->notes($id, $limit);
    }

    /**
     * Everything that ever happened to this order, newest first.
     *
     * Each source is asked for its newest `$limit` and the merge keeps the
     * newest `$limit` of the union — which is exactly right, since no source
     * can contribute an entry to the top N that is not in its own top N.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(int $id, int $limit): array
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $this->requireOrder($id);

        return OrderTimeline::merge(
            $this->repository->notes($id, $limit),
            $this->auditLog->paginate(['resource_type' => 'order', 'resource_id' => (string) $id], 1, $limit),
            $this->movements->paginate(['order_id' => $id], 1, $limit),
            $limit
        );
    }

    private function requireOrder(int $id): WC_Order
    {
        $order = $this->repository->find($id);

        if ($order === null) {
            throw ApiException::notFound('No order with that id.');
        }

        return $order;
    }

    /**
     * A refused transition is a conflict, not a validation error: the payload
     * is well formed and the status exists — the order is simply not in a state
     * that can reach it. The response names what is reachable, so a client can
     * render the buttons that will work instead of guessing.
     */
    private function guardTransition(string $from, string $to): void
    {
        if (OrderStatus::canTransition($from, $to)) {
            return;
        }

        throw ApiException::conflict("An order cannot move from \"{$from}\" to \"{$to}\".", [
            'from' => $from,
            'to' => $to,
            'allowed' => OrderStatus::allowedFrom($from),
        ]);
    }

    /**
     * Line items may be rewritten only while WooCommerce considers the order
     * editable — `pending` and `on-hold`.
     *
     * That is WooCommerce's own rule and it is the right one: a `completed` or
     * `refunded` order's lines are what was invoiced and delivered, and editing
     * them rewrites history rather than correcting it.
     *
     * Stock is *not* a second condition **here**, and after the fix round's
     * decision 1 it is not a condition anywhere on this route. An on-hold order
     * is editable and may still be holding units — on-hold is one of the
     * statuses that reduces stock — and that case is handled rather than
     * refused: the repository returns the units, replaces the lines and takes
     * them again, so the ledger records the whole manoeuvre. Refusing it would
     * block the one moment an amendment is most likely, an order paused
     * awaiting confirmation.
     *
     * **There is no longer a narrower guard beside this one.** Backend step 6
     * added `guardManualPricesWritable()`, which let a quantity through this
     * gate and then refused a stated `price` on the same stock-holding order;
     * the fix round removed it, so this method is once again the only thing
     * standing between a payload and `line_items`. The consequence to carry
     * away is that **this gate is now the whole of the rule** — anyone
     * reaching for something narrower will not find it, and the argument for
     * why it stopped existing is in `snapshot()`, which is where the
     * replacement lives.
     *
     * What did not change is the ordering intuition that guard depended on: a
     * `processing` order is refused here, before anything looks at what the
     * lines say, so an operator is told the order is closed rather than told
     * something about a number.
     */
    private function guardLineItemsWritable(WC_Order $order): void
    {
        if ($order->is_editable()) {
            return;
        }

        throw ApiException::conflict(
            'The line items of an order in this status cannot be changed.',
            ['status' => $order->get_status(), 'editable_in' => [OrderStatus::PENDING, OrderStatus::ON_HOLD]]
        );
    }

    /**
     * The delivery fee may be written only while the order is editable, on the
     * same rule and for the same reason as the line items.
     *
     * ## Why the gate is on money, not on line items
     *
     * Read the two guards together and the rule this class actually enforces
     * becomes visible: **everything that moves the order total is gated by
     * `is_editable`, and everything that does not is free at any status.** An
     * address, a phone number, the payment method and the customer note stay
     * editable on a delivered COD order, because a phone typo has to be
     * fixable. The lines do not, because a `completed` order's lines are what
     * was invoiced.
     *
     * `shipping_amount` is on the money side of that line, and leaving it
     * ungated would not have been a smaller decision than gating it — it would
     * have been a hole straight through the guard beside it. `OrderRepository`
     * writes the fee as a shipping line and `calculate_totals()` folds it into
     * `total`, so an ungated fee is a way to move a `completed` order's total by
     * any amount up to the ceiling: refused a 1 DZD reprice on a line, granted a
     * 9 999 999 DZD delivery charge on the same order in the same request.
     *
     * **WooCommerce's own admin agrees, and it is the source this gate quotes.**
     * `is_editable` is WooCommerce's rule, not ours, and its order screen hides
     * *Add item(s)* — the control that reveals *Add shipping* — behind
     * `$order->is_editable()`, showing "This order is no longer editable."
     * instead (`includes/admin/meta-boxes/views/html-order-items.php:315-321`,
     * 11.0.1). So the shipping line was never editable on a committed order
     * through WooCommerce either; this API is not adding a restriction, it is
     * declining to invent an exemption.
     *
     * The cost is real and is worth naming rather than hiding: the courier's
     * fee is often settled *after* dispatch, which is exactly when the order is
     * no longer editable, so "the delivery came back at 600, not 450" cannot be
     * corrected in place. The correction for a committed order is a refund or
     * an order note, both of which leave the original invoice standing — which
     * is the same answer this API already gives for a line priced wrongly, and
     * the reference shop's alternative is instructive: EL applies a shipping
     * cost at any status and recomputes `totalAmount` under it
     * (`OrderService.java:342-351`), so a delivered order's total can move
     * after the customer has paid it.
     *
     * ## Kept separate from guardLineItemsWritable() on purpose, and the bet
     * has now settled twice
     *
     * The two bodies differ only in a sentence, and merging them was the
     * obvious tidy. It was not done because they are the same *rule* and not
     * the same *decision*, and the history is the argument for the duplication
     * rather than against it:
     *
     *  - **Backend step 6** concluded that a manual price should be refused once
     *    stock had moved — a narrower test than `is_editable` — and wrote
     *    `guardManualPricesWritable()` for it. A shared helper would have
     *    dragged the delivery fee through that gate as a side effect of a
     *    decision taken about line prices. Six duplicated lines are what stopped
     *    that.
     *  - **The fix round's decision 1** deleted that guard again. A shared
     *    helper would now have carried the deletion back the other way, and
     *    "the fee became writable on a `completed` order" would have been a side
     *    effect of a decision taken about a stock-holding `on-hold` one. Six
     *    duplicated lines stopped that too.
     *
     * A rule that moved twice in opposite directions without this method
     * noticing either time is the case for keeping it written out.
     *
     * ## What that leaves — and the asymmetry this section used to name is gone
     *
     * This section used to record a live inconsistency: on an `on-hold` order
     * holding stock, a 1 DZD reprice on a line was refused and any delivery
     * charge up to the ceiling was granted. It existed only because
     * `guardManualPricesWritable()` existed, and it is what the fix round's
     * decision 1 settled — by removing the narrow guard rather than by
     * extending it to the fee. On that order all three things a payload can do
     * to the total now behave the same way: the quantity lands, the price lands,
     * the fee lands, and `snapshot()` records all three.
     *
     * So the boundary this method draws is once again a single line, in one
     * place, with nothing on the other side of it: **`is_editable` gates the
     * order's money**, and past `pending`/`on-hold` neither a line nor a fee
     * moves. The direction the old asymmetry pointed is worth keeping even so,
     * because it is the argument that would have to be beaten to gate the fee
     * more tightly: delivery moves no units, allocates nothing, and is
     * characteristically settled *after* dispatch — which is the cost this
     * docblock complains about at length two paragraphs up.
     */
    private function guardShippingAmountWritable(WC_Order $order): void
    {
        if ($order->is_editable()) {
            return;
        }

        throw ApiException::conflict(
            'The shipping amount of an order in this status cannot be changed.',
            ['status' => $order->get_status(), 'editable_in' => [OrderStatus::PENDING, OrderStatus::ON_HOLD]]
        );
    }

    /**
     * The stated courier has to be one this shop has — and that is the only
     * thing checked, at any status.
     *
     * ## There is no `is_editable` gate here, and that is a decision
     *
     * The method above states the rule this class enforces: **everything that
     * moves the order total is gated by `is_editable`, and everything that does
     * not is free at any status.** `shipping_provider` is unambiguously on the
     * free side. It writes `method_id` on the shipping line, and
     * `calculate_totals()` sums shipping *line totals*
     * (`abstract-wc-order.php:2158-2163`, the 11.0.1 compose.yaml pins) — a
     * `method_id` is not a term in that sum. Naming a courier cannot move a
     * dinar, so gating it would be inventing a restriction rather than
     * declining to invent an exemption, which is the distinction
     * `guardShippingAmountWritable()` is careful to draw about its own gate.
     *
     * **And gating it would break the feature it exists for.** Backend step 2
     * confirms an order and creates a parcel with the courier the order names;
     * confirmation moves the order to `processing`, which is not editable. An
     * `is_editable` gate would therefore make the courier unchangeable from the
     * exact moment it starts to matter — and *"Yalidine refused this commune,
     * send it with ZR Express"* is not a hypothetical, it is the retry path
     * `BLOCKED.md` names as the thing that cannot be exercised until a courier
     * can reject. That retry is a later sub-task's to build, and building it
     * against a field it cannot write would be building it against nothing.
     *
     * The cost, named rather than hidden: on a `completed` order an operator
     * can re-point the courier on a parcel that has already been delivered.
     * That writes a label on a historical line; it does not re-route anything,
     * because a shipment records the provider it was created with and
     * `ProviderRegistry`'s docblock makes exactly that promise — *"A shipment
     * always records the provider it was created with, so changing the default
     * later never re-routes a parcel that already exists."* The `ac_shipments`
     * row is untouched by this write, and the write is audited like every
     * other.
     *
     * ## Membership, checked here because a pure validator cannot
     *
     * `OrderInput` normalizes the name and stops there, on
     * `Shipping\ShipmentInput::fromPayload()`'s stated rule that a pure
     * validator must not need a container. The registry check has to happen
     * somewhere, and it has to happen at *write* time: backend step 2's fifth
     * item hands this string to `ProviderRegistry::get()` to create the parcel,
     * and an unregistered name discovered there is a 400 at confirmation — the
     * worst possible moment, on a request the operator did not associate with
     * the typo they made days earlier.
     *
     * ## Restating what the order already says can never fail
     *
     * The second branch is not a convenience. `OrderPresenter` emits
     * `shipping_provider`, and `OrderInput`'s docblock promises the round trip
     * that shape exists for: GET an order, change one thing, PATCH the whole
     * object back. A shop that switches `ENABLE_YALIDINE` off has not made its
     * historical Yalidine orders wrong — but under a registration check alone,
     * every one of them would 400 on a PATCH that only touched the customer
     * note, on a key the client echoed without meaning to. That is precisely
     * the trap `shipping_source` avoided by being read-only, and a writable
     * field has to avoid it another way.
     *
     * So the rule is: **you may name any courier this shop has, and you may
     * always restate the one this order already names.** A no-op is not a
     * change and must not be refused for one. It is genuinely a no-op —
     * `OrderRepository::assignShippingProvider()` writes the same value back —
     * so nothing is smuggled through the exemption; what it cannot do is name a
     * *different* de-registered courier, which would be a change and is
     * refused.
     *
     * On a create there is no order to restate, so registration is the whole
     * test.
     */
    private function guardShippingProviderKnown(OrderInput $input, ?WC_Order $order): void
    {
        if (!$input->has('shipping_provider') || $this->providers === null) {
            return;
        }

        $stated = (string) $input->get('shipping_provider');

        if ($this->providers->has($stated)) {
            return;
        }

        foreach ($order?->get_items('shipping') ?? [] as $item) {
            if (strtolower(trim((string) $item->get_method_id())) === $stated) {
                return;
            }
        }

        throw ApiException::invalidRequest('The order data is invalid.', [
            /*
             * `fields`, keyed by the field name, because that is what every
             * other refusal on this route hands a form — `OrderInput` builds
             * its whole breakdown that way and a panel binds to it. The
             * available list is inlined into the sentence rather than published
             * beside it, which is `Cart\CheckoutService::requireProvider()`'s
             * shape for the identical problem on `payment_method`.
             *
             * Naming every registered courier is safe *here* and is not on the
             * checkout: this route asserts `Capabilities::MANAGE_ORDERS`, and
             * `GET /shipping/providers` publishes the same list to
             * `MANAGE_SHIPPING`. An operator who may enter orders may know
             * which couriers the shop has — indeed they cannot choose one
             * otherwise.
             */
            'fields' => [
                'shipping_provider' => 'Available: ' . implode(', ', $this->providers->names()) . '.',
            ],
        ]);
    }

    /**
     * The order must end up naming a wilaya and a commune that go together —
     * and, like the courier above, at any status.
     *
     * ## What "the order must end up naming" means, and why it is not "the
     * payload"
     *
     * The pair is resolved against the order before it is judged: a stated id
     * wins, and an unstated one falls back to what the order already stores.
     * That is not leniency, it is this API's own rule — **a payload changes
     * what it mentions** — arriving at the one field where it has teeth.
     * `OrderRepository::applyProps()` writes only the keys the caller sent, so
     * a PATCH carrying `commune_id` alone leaves `_ac_wilaya_id` standing, and
     * a guard that judged the payload in isolation would refuse the single most
     * useful correction this route can make: *"same wilaya, wrong commune."*
     *
     * That correction is not hypothetical. `Shipping\ShipmentSubscriber`'s
     * whole retry design rests on it — *"the next confirmation, after the
     * operator has fixed the address or named a different courier, finds no
     * live shipment and tries again"* — and a courier refusing a commune is one
     * of the two ways to reach that state.
     *
     * ## Half a destination is refused, because half of one silently does
     * nothing
     *
     * `ShipmentSubscriber::destinationOf()` returns null unless **both** ids are
     * at least 1, so an order carrying a wilaya and no commune is addressed
     * exactly as well as an order carrying neither: it confirms, creates no
     * parcel, and records `order_destination_missing`. Accepting that write
     * would ship a field that appears to work and does nothing — the failure
     * `OrderInput`'s docblock refuses for `shipping_total` in almost the same
     * words — and the operator would find out days later from an error naming a
     * thing they thought they had entered.
     *
     * The two sentences are `Shipping\ShippingRuleInput`'s, which has faced
     * this on the tariff side and answers *"Required when the rule names a
     * commune."* Here it is the order that names one.
     *
     * ## The commune is looked up and the wilaya is not
     *
     * One query answers both questions, because a commune row carries its
     * `wilaya_id`: if the commune exists and points at the wilaya the order
     * names, that wilaya exists by construction. `ShippingService::validatedDestination()`
     * makes exactly this trade for exactly this pair, and its docblock gives
     * the reason the pair is checked rather than each half — *"a commune id
     * from the right dropdown and a wilaya id left over from the previous
     * selection is the mistake an address form actually makes, and it routes a
     * parcel to a commune of the same name in another wilaya — of which Algeria
     * has several."*
     *
     * Both refusals are copied from that method verbatim, `commune_wilaya_id`
     * included. A panel that renders one sentence when a parcel is refused and
     * a different sentence when the order that would have carried it is refused
     * is a panel the operator has to read twice, and the envelope message
     * differs only because it is this route's — `The order data is invalid.`,
     * which is what `OrderInput` and every other refusal here already say.
     *
     * ## Restating what the order already names can never fail
     *
     * `guardShippingProviderKnown()`'s second branch, for its reason, and the
     * two are best read as one rule: **you may name any destination that
     * resolves, and you may always restate the one this order already names.**
     *
     * The trap it avoids is the same trap. `OrderPresenter` emits all three
     * keys, so a client that GETs an order, changes the customer note and
     * PATCHes the whole body back sends this pair without meaning to. Almost
     * always it resolves and nothing happens. The exception is an order that
     * arrived carrying a pair that does not resolve — which this API cannot
     * write, but `POST /checkout` can, because `Cart\CheckoutService::destination()`
     * validates the delivery type and takes the two ids on trust. Under a check
     * with no exemption, every such order would 400 on a PATCH that only
     * touched a note, on keys the client echoed.
     *
     * Nothing is smuggled through it: the exemption is granted only when the
     * resolved pair is *identical* to the stored pair, so it is a genuine
     * no-op, and changing either half re-validates both. The order stays
     * unshippable and says so where it should — `ShippingService::validatedDestination()`
     * refuses it at confirmation and `ShipmentSubscriber` records the reason on
     * the order.
     *
     * ## No `is_editable` gate, and that is the same decision as the courier's
     *
     * `guardShippingAmountWritable()` states the rule this class enforces:
     * everything that moves the order total is gated by `is_editable`,
     * everything else is free at any status. A wilaya id and a commune id are
     * order meta; `calculate_totals()` sums shipping *line totals*
     * (`abstract-wc-order.php:2158-2163`, the 11.0.1 compose.yaml pins), and
     * meta has never been a term in that sum. Saying where a parcel goes cannot
     * move a dinar.
     *
     * **And a gate here would make the retry unreachable**, which is the
     * stronger half. Both ways an order earns a `shipping_provider_error` — the
     * missing destination and the commune a courier refused — are recorded at
     * `processing`, which is not editable. Gating the destination would freeze
     * it at the exact moment it starts to matter, and the field would be
     * writable only while nothing had yet gone wrong. That is the argument
     * `guardShippingProviderKnown()` makes for the courier, and the destination
     * is the other half of the same fix: *"Yalidine refused this commune"* is
     * answered either by a different courier or by the right commune.
     *
     * The cost is the courier's cost, named rather than hidden: on a `completed`
     * order an operator can rewrite the destination of a parcel that has already
     * been delivered. It re-routes nothing — the `ac_shipments` row records the
     * destination it was created with and is untouched by this write — and the
     * write is audited like every other.
     */
    private function guardDestinationResolves(OrderInput $input, ?WC_Order $order): void
    {
        if (!$input->has('wilaya_id') && !$input->has('commune_id')) {
            /*
             * `delivery_type` is deliberately not a reason to run. It is a
             * journey rather than a place, it needs no pair and no lookup, and
             * `destinationOf()` never reads it unless both ids are present — so
             * an order that states only a desk collection has said something
             * harmless and true about an address it may not have yet.
             */
            return;
        }

        $storedWilaya = self::storedId($order, OrderRepository::WILAYA_META);
        $storedCommune = self::storedId($order, OrderRepository::COMMUNE_META);

        $wilayaId = $input->has('wilaya_id') ? (int) $input->get('wilaya_id') : $storedWilaya;
        $communeId = $input->has('commune_id') ? (int) $input->get('commune_id') : $storedCommune;

        if ($wilayaId < 1) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ['wilaya_id' => 'Required when the order names a commune.'],
            ]);
        }

        if ($communeId < 1) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ['commune_id' => 'Required when the order names a wilaya.'],
            ]);
        }

        // The restatement exemption, and the completeness check above runs
        // ahead of it on purpose: a half destination is refused even when it is
        // what the order already stores, because this API never wrote that
        // state and cannot address it either.
        if ($wilayaId === $storedWilaya && $communeId === $storedCommune) {
            return;
        }

        if ($this->geography === null) {
            return;
        }

        $commune = $this->geography->findCommune($communeId);

        if ($commune === null) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ['commune_id' => "No commune with id {$communeId}."],
            ]);
        }

        if ((int) $commune['wilaya_id'] !== $wilayaId) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ['wilaya_id' => 'That commune belongs to a different wilaya.'],
                // Beside the field rather than inside the sentence, exactly as
                // `ShippingService::validatedDestination()` publishes it: a
                // panel that knows which wilaya the commune *does* belong to
                // can offer to move the selection instead of clearing it.
                'commune_wilaya_id' => (int) $commune['wilaya_id'],
            ]);
        }
    }

    /**
     * One of the two destination ids as the order currently stores it, or 0.
     *
     * `get_meta()` returns `''` for a key never written, and order meta is a
     * public store where another plugin may have left a word — the care
     * `OrderPresenter::manualPrice()` takes for the same reason. Anything that
     * is not a positive integer is 0 here, which is the same reading
     * `ShipmentSubscriber::destinationOf()` gives it: no destination.
     */
    private static function storedId(?WC_Order $order, string $key): int
    {
        if (!$order instanceof WC_Order) {
            return 0;
        }

        $stored = (int) $order->get_meta($key, true);

        return $stored > 0 ? $stored : 0;
    }

    /**
     * What an order was, in the fields an audit row has to be able to answer
     * questions about later.
     *
     * ## Why it grew three keys
     *
     * It used to record a status, a customer, an order total and a *count* of
     * lines, which was enough while every amount on an order was derived from
     * the catalogue. Two steps changed that: a line may carry a price a person
     * typed, and the order may carry a delivery fee a person typed. Against the
     * old shape both are invisible — a total moving from 3 900 to 3 301 looks
     * the same whether a line was removed, a quantity dropped or 599 DZD was
     * given away, and `order.updated`'s `fields` list naming `line_items` says
     * only that *something* about the lines changed.
     *
     *  - **`shipping_total`**, because the delivery charge is money a person now
     *    states and the before/after pair is the only comparison it has.
     *    `OrderRepository::replaceShippingLine()` argues why there is no
     *    catalogue price for delivery to compare against and why inventing one
     *    would be worse than having none; what exists instead is what the order
     *    was charging before this request and what it charges after. It is
     *    `shipping_total` rather than the stated `shipping_amount` deliberately:
     *    the derived number is the one that is true for a fee the checkout
     *    quoted as well as one somebody typed, so a stated fee replacing a
     *    quoted one is legible in the pair — which it would not be if the
     *    `before` side could only report `null` for it.
     *  - **`manual_prices`**, the list below: every amount on this order that a
     *    person chose, each against the catalogue price it replaced.
     *  - **`stock_reduced`**, added by the fix round's decision 1 and argued in
     *    its own section below, because it is the key that turns this record
     *    from an accounting note into the replacement for a refusal.
     *
     * ## Why the record says whether stock had moved
     *
     * **This section is the argument `guardManualPricesWritable()` used to
     * make, kept because the reversal has to answer it rather than delete it.**
     * That guard stood in `update()` from backend step 6 until the fix round,
     * and turned a stated `price` on an order already holding stock into a 409
     * carrying `status`, `stock_reduced` and the zero-based `lines` that named
     * an amount. What it was defending is worth restating in full, because it
     * is still true:
     *
     *  - Units already off the shelf are **allocated to this customer at a
     *    price**. Restating that price restates what is charged for goods the
     *    shop has committed, and it is the one write on this route that cannot
     *    be read as a correction of something not yet agreed.
     *  - It fired on exactly the writes that `OrderRepository::rewriteLineItems()`
     *    has to unwind and re-take the shelf around — one fact, one source, two
     *    decisions that could not drift apart.
     *  - A price of nothing on a committed order is the discount nobody can
     *    attribute, which is the threat `LineItemInput` names as the reason its
     *    own refusal ever existed.
     *
     * ### What beat it
     *
     * Not one of those points. What beat it is that **it was alone**. A
     * stock-holding `on-hold` order took a quantity of forty where four had been
     * agreed — 54 000 DZD on this shop's own kettle — and took a delivery charge
     * up to the ceiling, and refused a 1 DZD reprice. Its own docblock listed
     * those as *"what still gets through"*, and `guardShippingAmountWritable()`
     * carried a matching section naming the fee asymmetry as a hole left open on
     * purpose. Two guards each documenting the hole in the other is not a
     * boundary; it is a boundary that was never drawn.
     *
     * There were two ways to make the three consistent. Extending the stock test
     * to the quantity and the fee was the one backend step 6 explicitly declined
     * — `guardLineItemsWritable()` argues that refusing an amendment on an
     * `on-hold` order costs the operator the single case the route exists for,
     * an order paused awaiting confirmation while somebody is on the telephone.
     * So the reversal went the other way: **warn, allow, record**, applied to
     * all three.
     *
     * ### Which makes this key load-bearing rather than informative
     *
     * With the refusal gone, the audit is the *entire* remaining answer, and it
     * has to be able to answer the question the guard used to make unaskable —
     * *what was repriced, on an order that was holding stock, and away from what
     * catalogue price*. `manual_prices` answers the first and third halves and
     * always did; without `stock_reduced` the second half was unanswerable from
     * the row, because a status name does not carry it:
     * `OrderRepository::stockReduced()` reads WooCommerce's own
     * `get_stock_reduced()` flag precisely because an order can sit in
     * `on-hold` — a status that *does* reduce stock — holding nothing at all,
     * having arrived there from `cancelled`. There is no set of status names
     * that answers this, so a reader reconstructing it from the `status` key
     * already in this snapshot would be guessing.
     *
     * It is on **both halves** of an `order.updated` pair rather than at the top
     * of the row, and that is not symmetry for its own sake: a PATCH that
     * reprices a line *and* moves `pending → on-hold` in one request writes
     * `before.stock_reduced = false` and `after.stock_reduced = true`, and the
     * pair is the only place that transition is visible next to the money it
     * happened beside. `order.status_changed` records the same flag for the
     * status half; this records it for the money half, and they are separate
     * events on purpose.
     *
     * ### What the panel does with it, which is the other half of the decision
     *
     * Nothing here warns anybody. `stock_reduced` has been on the read shape
     * since `OrderPresenter::toArray()` and the panel draws its warning from
     * that — naming what is reserved *before* the operator types, which is the
     * part a 409 after the fact never did. This key is the record, not the
     * warning, and the two read the same flag through the same repository
     * method so they cannot disagree.
     *
     * ## Always present, including when it is empty
     *
     * `manual_prices` is emitted as `[]` on an order nobody hand-priced rather
     * than omitted. The distinction it buys is in an append-only table: rows
     * written before this key existed have no key, and "this order had no manual
     * prices" must not be indistinguishable from "nobody was looking". An empty
     * list is a statement; a missing key is a version.
     *
     * `manual_prices_omitted` is the opposite — present only when the bound bit,
     * so its absence asserts the list is whole. See `MAX_AUDITED_PRICES`.
     *
     * Amounts are recorded exactly as they are stored, unrounded and
     * unformatted. `OrderPresenter` publishes money through `wc_format_decimal()`
     * and the audit deliberately does not: a price typed with more decimals than
     * the store keeps is *what the person actually wrote*, and the presenter's
     * rounding is the one thing a record of what somebody did must not adopt.
     *
     * @return array<string, mixed>
     */
    private function snapshot(WC_Order $order): array
    {
        $prices = $this->manualPrices($order);

        $snapshot = [
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'total' => $order->get_total(),
            'items' => count($order->get_items()),
            'shipping_total' => $order->get_shipping_total(),
            /*
             * Through the repository, which is the only file allowed to ask an
             * order data store for this — the same call `OrderPresenter` makes
             * for the read shape the panel warns from, so the record and the
             * warning cannot disagree about what "holding stock" means.
             */
            'stock_reduced' => OrderRepository::stockReduced($order),
            'manual_prices' => array_slice($prices, 0, self::MAX_AUDITED_PRICES),
        ];

        if (count($prices) > self::MAX_AUDITED_PRICES) {
            $snapshot['manual_prices_omitted'] = count($prices) - self::MAX_AUDITED_PRICES;
        }

        return $snapshot;
    }

    /**
     * Every amount on this order that somebody chose, with what it replaced.
     *
     * ## One pass, one key, both item types
     *
     * `OrderRepository::MANUAL_PRICE_META` is written on a hand-priced product
     * line *and* on a stated delivery fee, under the same key and on purpose —
     * see that constant. So the question "which amounts on this order did a
     * person choose" is one loop over `get_items(['shipping', 'line_item'])` and
     * needs no second key and no per-type rule. Nothing else would answer it:
     * the shipping line's `method_title` is `'Delivery'` whether it was quoted
     * or typed, its `method_id` is empty on both and stops being a signal at all
     * once item 2 writes a courier into it, and the amount says nothing — 450
     * DZD looks identical either way.
     *
     * **Shipping is asked for first**, which is the only reason the argument
     * order matters: `get_items()` walks the types in the order it is given
     * (`abstract-wc-order.php:1066-1070`, 11.0.1), so the one fee sorts ahead of
     * the lines and `MAX_AUDITED_PRICES` can only ever drop product lines.
     *
     * ## What a row says, and what a missing catalogue price means
     *
     * A product row carries the pair the audit exists for: `charged`, the string
     * the operator typed, and `catalogue`, what the product was asking at the
     * moment the line was written — read from
     * `OrderRepository::CATALOGUE_PRICE_META`, which is frozen onto the line
     * precisely because a product's price moves and asking the catalogue *now*
     * would answer a different question. Both are unit prices in the same terms,
     * so the discount is `(catalogue − charged) × quantity` and the record does
     * not have to pre-chew it.
     *
     * `catalogue` is `null` in two cases and the row's `type` tells them apart,
     * which is why `type` is on every row rather than inferred from the presence
     * of `product_id`:
     *
     *  - on a **`shipping`** row, always, and structurally: delivery has no
     *    catalogue price and never will, because §14's tariff is quoted from a
     *    cart and an order is not one. The comparison for a fee is
     *    `shipping_total` on the two halves of the pair, not a baseline invented
     *    here.
     *  - on a **`line`** row, when the catalogue price could not be captured —
     *    the product carried no price at all, or the line was written before
     *    that key existed. It means "there is nothing to compare against", not
     *    "the catalogue said zero", and it is left null rather than filled in
     *    from today's catalogue for the reason above.
     *
     * ## Only what a person chose
     *
     * A line without the meta contributes no row. That is the same call
     * `OrderPresenter::manualPrice()` makes about emitting `null` instead of the
     * effective unit price, and it is the load-bearing one: an audit in which
     * every line carries a price and a comparison is an audit in which nothing
     * stands out, and the thing this record exists to make visible is exactly
     * the line that is not like the others. `is_numeric()` on the stored value
     * for the presenter's two reasons — `get_meta()` returns `''` for a key
     * never written, and item meta is a public store where another plugin could
     * leave a word.
     *
     * @return list<array<string, mixed>>
     */
    private function manualPrices(WC_Order $order): array
    {
        $rows = [];

        foreach ($order->get_items(['shipping', 'line_item']) as $item) {
            $charged = $item->get_meta(OrderRepository::MANUAL_PRICE_META, true);

            if (!is_numeric($charged)) {
                continue;
            }

            if (!$item instanceof WC_Order_Item_Product) {
                // The loop asked for two groups, so anything that is not a
                // product line is the shipping line.
                $rows[] = ['type' => 'shipping', 'charged' => (string) $charged, 'catalogue' => null];

                continue;
            }

            $catalogue = $item->get_meta(OrderRepository::CATALOGUE_PRICE_META, true);

            $rows[] = [
                'type' => 'line',
                // The ids, not the name: a name is what the line was called at
                // the time of sale and two products can share one, while the
                // pair below is what somebody re-checking the catalogue price
                // needs to look the product up.
                'product_id' => (int) $item->get_product_id(),
                'variation_id' => (int) $item->get_variation_id(),
                'quantity' => (int) $item->get_quantity(),
                'charged' => (string) $charged,
                'catalogue' => is_numeric($catalogue) ? (string) $catalogue : null,
            ];
        }

        return $rows;
    }

    /**
     * WooCommerce throws WC_Data_Exception for what it validates itself.
     * Translating it keeps the error envelope consistent instead of surfacing
     * a raw exception as a 500.
     *
     * @param callable(): WC_Order $operation
     */
    private function save(callable $operation): WC_Order
    {
        try {
            return $operation();
        } catch (WC_Data_Exception $exception) {
            throw ApiException::invalidRequest($exception->getMessage());
        }
    }
}
