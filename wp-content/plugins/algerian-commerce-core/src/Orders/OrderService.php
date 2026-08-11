<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Data_Exception;
use WC_Order;

/**
 * Order business rules — roadmap §50, docs/PLAN.md §8.
 *
 * Three rules shape everything here:
 *
 *  - A status may only move where OrderStatus allows. WooCommerce will happily
 *    put a refunded order back into processing; an admin API should not.
 *  - Line items may only be rewritten while the order still holds no stock.
 *  - Every write is audited, and the stock consequences of a status change are
 *    recorded in the ledger by OrderStockSubscriber rather than here — an
 *    order-driven movement writes a movement row and no audit row (roadmap
 *    §49), because the audit entry for "someone changed this order's status"
 *    already exists and the stock followed from it.
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

    public function __construct(
        private readonly OrderRepository $repository,
        private readonly AuditLogger $audit
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

        $this->audit->record('order.created', 'order', $order->get_id(), [
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'items' => count($order->get_items()),
            'total' => $order->get_total(),
        ]);

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
        }

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
     * Line items may be rewritten only while the order holds no stock.
     *
     * Two conditions, and they are not the same one twice:
     *
     *  - `is_editable()` is WooCommerce's own rule — pending and on-hold only.
     *    Rewriting a completed order's lines would change what was already
     *    invoiced and delivered.
     *  - Stock must not have been reduced. An on-hold order passes the first
     *    test and can still be holding units, because on-hold is one of the
     *    statuses that reduces stock. Replacing its lines drops the
     *    `_reduced_stock` marker WooCommerce writes on each item, and those
     *    units are then never returned to the shelf by anything — the ledger
     *    would show a reduction with no matching restoration and no way to
     *    reconcile it.
     *
     * Adjusting a line on an order that has already taken stock needs a
     * per-line reconciliation. WooCommerce's own helper for that,
     * wc_maybe_adjust_line_item_product_stock(), lives in an admin-only file
     * that is not loaded during a REST request, so doing it properly means
     * writing it here — a later refinement, not a silent half-measure now.
     */
    private function guardLineItemsWritable(WC_Order $order): void
    {
        if (!$order->is_editable()) {
            throw ApiException::conflict(
                'The line items of an order in this status cannot be changed.',
                ['status' => $order->get_status(), 'editable_in' => [OrderStatus::PENDING, OrderStatus::ON_HOLD]]
            );
        }

        if (OrderRepository::stockReduced($order)) {
            throw ApiException::conflict(
                'This order has already reduced stock; cancel it and place a new one to change what was ordered.',
                ['status' => $order->get_status(), 'stock_reduced' => true]
            );
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(WC_Order $order): array
    {
        return [
            'status' => $order->get_status(),
            'customer_id' => $order->get_customer_id(),
            'total' => $order->get_total(),
            'items' => count($order->get_items()),
        ];
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
