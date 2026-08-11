<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\Inventory\MovementReason;
use AlgerianCommerce\Inventory\StockLedger;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Records order-driven stock movements in the ledger — roadmap §49 reserved
 * `order_reduced` and `order_restored` for exactly this.
 *
 * Hooks rather than calls from OrderService, and that is the point: WooCommerce
 * moves an order's stock on a status *transition*, from a dozen places we do
 * not own — a payment gateway completing a payment, a scheduled task expiring a
 * held order, WP-CLI, wp-admin, a future storefront checkout. A ledger fed only
 * by our own service would be missing every movement that did not come through
 * our API, and a stock history with holes cannot be reconciled.
 *
 * Both hooks fire once per line item, and only after WooCommerce has already
 * written the new quantity, so the figures are the real ones. WooCommerce
 * marks each item with `_reduced_stock` and skips it next time, so a second
 * transition into `processing` produces no rows rather than a double count.
 */
final class OrderStockSubscriber
{
    public function __construct(private readonly StockLedger $ledger)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_reduce_order_item_stock', [$this, 'onReduced'], 10, 3);
        add_action('woocommerce_restore_order_item_stock', [$this, 'onRestored'], 10, 4);
    }

    /**
     * @param mixed $item   WC_Order_Item_Product
     * @param mixed $change array{product: WC_Product, from: int|float, to: int|float}
     * @param mixed $order  WC_Order
     */
    public function onReduced(mixed $item, mixed $change, mixed $order): void
    {
        if (!is_array($change) || !($change['product'] ?? null) instanceof WC_Product || !$order instanceof WC_Order) {
            return;
        }

        $this->ledger->recordOrderMovement(
            $change['product'],
            (int) $change['from'],
            (int) $change['to'],
            MovementReason::ORDER_REDUCED,
            $order->get_id()
        );
    }

    /**
     * The restore hook passes the quantities but not the product, so it is
     * read back off the item — which is the same object WooCommerce itself
     * just used to apply the change.
     *
     * @param mixed $item     WC_Order_Item_Product
     * @param mixed $newStock int|float
     * @param mixed $oldStock int|float
     * @param mixed $order    WC_Order
     */
    public function onRestored(mixed $item, mixed $newStock, mixed $oldStock, mixed $order): void
    {
        if (!$item instanceof WC_Order_Item_Product || !$order instanceof WC_Order) {
            return;
        }

        $product = $item->get_product();

        if (!$product instanceof WC_Product) {
            return;
        }

        $this->ledger->recordOrderMovement(
            $product,
            (int) $oldStock,
            (int) $newStock,
            MovementReason::ORDER_RESTORED,
            $order->get_id()
        );
    }
}
