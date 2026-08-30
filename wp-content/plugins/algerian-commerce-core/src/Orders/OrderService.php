<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Audit\AuditRepository;
use AlgerianCommerce\Inventory\MovementRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
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
 *  - **A price somebody typed needs one thing more**, since backend step 6: the
 *    order must not already be holding stock. guardManualPricesWritable()
 *    refuses a stated `price` once the units are off the shelf, and refuses
 *    nothing else — the same order's quantities stay correctable. So the rule
 *    this class enforces now reads as *is_editable gates the order's money;
 *    stock having moved additionally gates the price of the goods*, and that
 *    method argues the whole of it, the delivery fee it deliberately does not
 *    reach included.
 *  - Every write is audited, and the stock consequences of a status change are
 *    recorded in the ledger by OrderStockSubscriber rather than here — an
 *    order-driven movement writes a movement row and no audit row (roadmap
 *    §49), because the audit entry for "someone changed this order's status"
 *    already exists and the stock followed from it.
 *
 *    **Every amount a person chose is named in that entry.** `snapshot()` below
 *    carries the delivery charge and one row per hand-priced amount, each
 *    against the catalogue price it replaced, and both `order.created` and
 *    `order.updated` publish it. That is not decoration: `LineItemInput` allows
 *    a manual price — a price of nothing included — to anyone holding
 *    `ac_manage_orders`, and the audit is the *entire* replacement for the
 *    refusal that used to stand there. A discount nobody can attribute is what
 *    the old gate existed to prevent, and it is this record that now prevents
 *    it, by witnessing rather than refusing.
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
        private readonly MovementRepository $movements
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

        if ($input->has('line_items')) {
            $this->guardLineItemsWritable($order);
            $this->guardManualPricesWritable($order, $input);
        }

        if ($input->has('shipping_amount')) {
            $this->guardShippingAmountWritable($order);
        }

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
     * Stock is *not* a second condition **here**, and that is still true after
     * backend step 6. An on-hold order is editable and may still be holding
     * units — on-hold is one of the statuses that reduces stock — and that case
     * is handled rather than refused: the repository returns the units,
     * replaces the lines and takes them again, so the ledger records the whole
     * manoeuvre. Refusing it would block the one moment an amendment is most
     * likely, an order paused awaiting confirmation.
     *
     * What step 6 added is a *narrower* guard beside this one rather than a
     * second condition inside it. `guardManualPricesWritable()` refuses a
     * stated `price` on an order that is holding stock, and refuses nothing
     * else: a quantity correction on that same order still goes through this
     * gate and succeeds. Read the pair in the order `update()` calls them —
     * whether the lines may be rewritten at all, then whether this particular
     * write may also restate what the goods cost. Anyone reaching for *this*
     * method as the stock rule is reaching for the wrong one.
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
     * A price somebody typed may not be *stated* on an order that is already
     * holding stock.
     *
     * ## What this is not: a narrowing of the gate above it
     *
     * `guardLineItemsWritable()` is unchanged and stays unchanged. Lines remain
     * writable on every editable order, stock-holding or not, and the
     * repository still unwinds and re-takes the shelf around the rewrite. What
     * is refused here is one field — the amount a person chose — on the subset
     * of editable orders whose units are already off the shelf.
     *
     * The other available reading was to add stock as a second condition on the
     * line-item gate itself, and it was not taken. It reverses an argument that
     * guard makes deliberately: an on-hold order awaiting confirmation is the
     * single moment an amendment is most likely, and refusing a quantity
     * correction there costs the operator the case the route exists for. It is
     * also a wider change than the one being made — the decision on the table
     * is about *a manual price*, and a manual price is what this refuses.
     *
     * ## Why "holding stock" is a flag and never a status
     *
     * `OrderRepository::stockReduced()`, which reads WooCommerce's own
     * `get_stock_reduced()` off the order's data store. Deriving it from the
     * status name would be wrong in both directions and that method's docblock
     * says why: an order can sit in `on-hold` — a status that *does* reduce
     * stock — holding nothing at all, because it arrived there from
     * `cancelled`. There is no set of status names that answers this question.
     *
     * It is also the *same* read `OrderRepository::rewriteLineItems()` makes to
     * decide whether to unwind the shelf, and that is what makes this guard
     * coherent rather than merely defensible: it fires on exactly the writes
     * that would otherwise have returned units, repriced them and taken them
     * again. One fact, one source, two decisions that cannot drift apart.
     *
     * ## After the editability gate, never before
     *
     * On a `processing` order the lines cannot be touched at all, price or no
     * price, and the operator has to be told *that*. Running this first would
     * answer the narrower question on an order where the broader one had
     * already refused — and hand a form a per-line list on a body no version of
     * which could have succeeded. General gate first, narrow gate second, the
     * same ordering that makes a refused transition win over both.
     *
     * ## A 409 carrying no `fields`
     *
     * The payload is well formed. `1200.50` is a good amount and
     * `LineItemInput` already accepted it; what refuses it is the state of the
     * order — the distinction `guardTransition()` draws a few methods up, where
     * "the payload is well formed and the status exists" is precisely why a
     * refused transition is a conflict rather than a validation error. `fields`
     * is this API's validation channel and a form binds it to an input meaning
     * *that value is wrong*. No value here is wrong, and there is no amount the
     * operator could retype that would be accepted.
     *
     * `lines` is the concession to the operator's mistake being per-line even
     * though the reason is not. It carries the zero-based indices of the
     * submitted `line_items` that stated a price, so a form bound per line can
     * still point at the boxes to clear, while the message and `stock_reduced`
     * say the thing that is true — the order is what refused this, not the
     * number. The indices are the payload's own: `OrderInput::normalize()`
     * throws on any line that failed validation, so by the time a service guard
     * runs the parsed list is one-for-one with what was sent.
     *
     * ## Price and fee on the same order
     *
     * Stated plainly, because the guard below earned the right to expect it: on
     * an `on-hold` order holding stock, a 1 DZD reprice on a line is refused
     * here and a 9 999 999 DZD delivery charge is granted there. That is the
     * mirror image of the hole step 4 named, and it is left open on purpose.
     *
     * The story that makes the pair coherent is not "money is gated" — that is
     * still `is_editable`'s rule and both guards still enforce it. It is one
     * clause longer now: **`is_editable` gates the order's money; stock having
     * moved additionally gates the price of the goods.** Stock is a fact about
     * goods and about nothing else. Units already off the shelf are allocated
     * to this customer at a price, and restating that price restates what is
     * charged for goods the shop has committed. Delivery moves no units,
     * allocates nothing, and its cost is characteristically settled *after* the
     * goods leave — the guard below names being unable to correct a courier's
     * fee after dispatch as the real price of gating it at all. An `on-hold`
     * order holding stock is exactly the window where the goods are fixed and
     * the delivery is not.
     *
     * How much that concedes is worth measuring rather than waving at. Step 4's
     * hole was reachable on `completed` and `refunded` — an order the customer
     * has paid for and received. This one is reachable on one status, on an
     * order nobody has confirmed and no courier has collected, and the fee is
     * still written under `OrderRepository::MANUAL_PRICE_META` and still lands
     * in the audit beside a before/after `shipping_total` pair. Whether the fee
     * should follow the price through this gate is a revision to *that* guard's
     * decision; the two were kept separate so it would have to be taken
     * deliberately rather than inherited from this one.
     *
     * ## What still gets through
     *
     * Named the way `LineItemInput` named the original hole, so the next reader
     * is not misled about where the boundary now is:
     *
     *  - **The quantity.** `line_items` is a wholesale replacement and stays
     *    writable, so four kettles at the catalogue's 1 500 become forty in one
     *    request and the order's total moves by 54 000 DZD with no manual price
     *    anywhere near it. This gate is on the *price*, not on the money.
     *  - **Dropping a hand-priced line.** Omitting it from the payload states no
     *    price, so nothing is refused, and the line's money leaves with it.
     *  - **Everything on an order holding no stock** — `pending`, and any
     *    creatable status at `create()`. A price of nothing is still permitted
     *    there and still only witnessed, exactly as `LineItemInput` describes.
     *
     * ## Not applied on create, which is not an oversight
     *
     * `create()` does not call this. There is no order yet to be holding
     * anything, and `OrderRepository::create()` writes the lines *before* it
     * sets the status precisely so the stock transition happens after they
     * exist. An order born `on-hold` at an agreed price is that order's price:
     * the rule being written here is that a manual price on an order whose
     * stock has moved is a refusal rather than a correction, and a creation
     * corrects nothing.
     *
     * ## What it costs the round trip
     *
     * A stock-holding order carrying a hand-priced line now refuses a
     * whole-body PATCH, because `OrderPresenter::lineItems()` emits `price` and
     * echoing it back *states* one. Nothing here can tell an echo from a
     * decision — `LineItemInput` lists that as the first cost of the field
     * existing at all, and `line_items` carries no line identity, so there is no
     * before/after pairing to compare against either. Restating the identical
     * price is therefore refused along with changing it, and has to be. The
     * client rule is the one the README already gives for a committed order,
     * now reaching further: omit `line_items` from a whole-body PATCH unless you
     * mean to rewrite the lines.
     */
    private function guardManualPricesWritable(WC_Order $order, OrderInput $input): void
    {
        if (!OrderRepository::stockReduced($order)) {
            return;
        }

        $lines = [];

        foreach ($input->lineItems() as $index => $line) {
            if ($line->price !== null) {
                $lines[] = $index;
            }
        }

        if ($lines === []) {
            return;
        }

        throw ApiException::conflict(
            'A manual price cannot be set on an order that is already holding stock.',
            ['status' => $order->get_status(), 'stock_reduced' => true, 'lines' => $lines]
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
     * `total`, so an ungated fee is a way to move a delivered order's total by
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
     * ## Kept separate from guardLineItemsWritable() on purpose, and it paid
     *
     * The two bodies differ only in a sentence, and merging them was the
     * obvious tidy. It was not done because they are the same *rule* and not
     * the same *decision*, and backend step 6 is where that bet settled. It
     * concluded that a manual price should be refused once stock has moved —
     * a narrower test than `is_editable` — and wrote
     * `guardManualPricesWritable()` for it. A shared helper would have dragged
     * the delivery fee through that gate as a side effect of a decision taken
     * about line prices; six duplicated lines meant the fee's rule stayed
     * exactly what this docblock argues for, unchanged and unchanged *on
     * purpose*.
     *
     * ## What that leaves, on one status
     *
     * The asymmetry is real and belongs here as well as there: on an `on-hold`
     * order that is holding stock, a 1 DZD reprice on a line is refused and any
     * delivery charge up to the ceiling is granted — the mirror image of the
     * hole this method was written to close, in the one window where both
     * guards are open and the narrow one is not.
     *
     * It is not the same size of hole. This method's case reached `completed`
     * and `refunded`, orders the customer has paid for and received; that one
     * reaches a single status, on an order nobody has confirmed and no courier
     * has collected, and every fee it lets through is still audited. The
     * argument for leaving it, in full, is in `guardManualPricesWritable()`:
     * stock is a fact about goods, and delivery — settled after dispatch, as
     * this docblock complains at length — is not goods. Anyone who concludes
     * otherwise is revising *this* guard's decision, which is the change these
     * six duplicated lines exist to keep deliberate.
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
     * What an order was, in the fields an audit row has to be able to answer
     * questions about later.
     *
     * ## Why it grew two keys
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
