<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

use WC_Order;

/**
 * Closes an order's COD state when the order itself is cancelled.
 *
 * A hook rather than a call from OrderService, for the same reason
 * OrderStockSubscriber is one: an order is cancelled from a dozen places this
 * plugin does not own — wp-admin, WP-CLI, a scheduled task expiring a held
 * order, a payment gateway, a future storefront. A confirmation queue fed only
 * by our own service would keep phoning customers about orders that were called
 * off somewhere else, which is the single most visible way a COD workflow
 * embarrasses a shop.
 *
 * It also keeps the dependency running one way. `Orders/` knows nothing about
 * COD — an order carries meta, and meta is just meta — while this module reads
 * and writes orders. Wiring the cancellation as a call from OrderService would
 * point the arrow back the other way and make the two domains cyclic
 * (docs/ARCHITECTURE.md §3).
 *
 * No audit row is written here, deliberately. `order.cancelled` is already in
 * the trail with its actor and its reason; this is the consequence of that
 * event, not a second one, and OrderStockSubscriber makes the same choice for
 * the stock that moves at the same moment.
 */
final class CodSubscriber
{
    public function __construct(private readonly CodRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderCancelled'], 10, 2);
    }

    /**
     * @param mixed $orderId int
     * @param mixed $order   WC_Order, as WooCommerce passes it
     */
    public function onOrderCancelled(mixed $orderId, mixed $order = null): void
    {
        $orderId = (int) $orderId;

        if ($orderId <= 0) {
            return;
        }

        /*
         * A fresh object, never the one the hook handed over. This runs inside
         * WC_Order::status_transition(), which is called from the tail of
         * save(); writing meta onto that same instance and saving it again
         * re-enters a save that is still in progress. A separately loaded order
         * has no pending status change, so its save() writes the meta and
         * fires no further transition.
         */
        $fresh = $this->repository->find($orderId);

        if (!$fresh instanceof WC_Order) {
            return;
        }

        $state = $this->repository->state($fresh);

        // Not a COD order, or one whose COD state is already closed — a
        // rejection stays a rejection, and re-cancelling would overwrite the
        // outcome the confirmation call actually produced.
        if (!$state->enabled || !CodStatus::canTransition($state->status, CodStatus::CANCELLED)) {
            return;
        }

        /*
         * No reason: the hook does not carry one, and the cancellation reason
         * an operator typed is already on the `order.cancelled` audit row. The
         * empty string leaves whatever the last confirmation call recorded in
         * place rather than blanking it — see CodState::cancel().
         */
        $this->repository->save($fresh, $state->cancel('', gmdate('Y-m-d H:i:s')));
    }
}
