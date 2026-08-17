<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Inventory\MovementReason;
use AlgerianCommerce\Inventory\StockLedger;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Bundles — roadmap §83. "An inventory feature wearing a catalogue costume."
 *
 * A bundle is an ordinary product whose `OptionSet` carries a `bundle` group.
 * It is not a new product type and not a second mechanism: §83 is explicit that
 * bundle contents are the same document with a different group type.
 *
 * ## Two rules §83 states, and this class is both of them
 *
 * **A bundle's purchasability is the minimum of its components', recomputed on
 * the server** — never a stock field of its own. A bundle showing "in stock"
 * because nobody refreshed it is an oversell, and the only number that cannot
 * go stale is the one derived at the moment of asking.
 *
 * **Every component movement goes through the ledger.** That is §64's rule —
 * "an import must not be a back door around `ac_inventory_movements`" — applied
 * to the case that sounds even more harmless. A bundle that adjusted stock
 * directly would produce a ledger whose numbers do not reconcile and no
 * movement explains why.
 *
 * ### Why the ledger and not `InventoryService`
 *
 * §83 says the movements go through `InventoryService`. They go through
 * `StockLedger`, which is the half of it that matters, and the reason is the
 * one `OrderStockSubscriber` already documents: `InventoryService::adjust()`
 * asserts `ac_manage_inventory`, and stock moves here on an order *status
 * transition* — fired by a Chargily webhook with no user at all, by the hourly
 * poller, by wp-admin, by WP-CLI. A path that required a staff capability would
 * either fail on every webhook or have to fake an identity, and §67's seeder
 * shows what the second costs. The rule §64 actually protects is that nothing
 * moves stock without writing a movement, and that is what this does — through
 * `wc_update_product_stock()`, WooCommerce's own API, with a ledger row per
 * component.
 *
 * ## Bounded on purpose
 *
 * **It does not recurse.** A component that is itself a bundle has its own
 * stock reduced and its components left alone. `OptionSetRepository` refuses
 * the direct self-reference; a longer cycle would need a graph walk on every
 * write and every order transition, and nothing has asked for nested bundles.
 *
 * **A partially-refundable bundle is out of scope for this step**, named here
 * rather than half-built: refunding two of a bundle's three components means
 * deciding what fraction of one price each component was sold at, which is a
 * pricing question §83 does not answer. A whole-order restock puts every
 * component back, which is correct and is what `onRestored()` does.
 */
final class BundleStock
{
    /**
     * Written on the order item once its components have been drawn down, in
     * the manner of WooCommerce's own `_reduced_stock`. Stock reduction fires
     * on more than one transition — `processing` then `completed` — and without
     * a marker the second one decrements a warehouse that never moved.
     */
    private const REDUCED_META = '_ac_bundle_stock_reduced';

    public function __construct(
        private readonly OptionSetRepository $optionSets,
        private readonly StockLedger $ledger,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_reduce_order_stock', [$this, 'onReduced']);
        add_action('woocommerce_restore_order_stock', [$this, 'onRestored']);
    }

    /**
     * How many of this bundle the shop can actually sell — `BundleAvailability`.
     *
     * Thin wrappers so a caller that already holds this collaborator does not
     * also have to hold a repository to answer the question.
     */
    public function available(WC_Product $product): ?int
    {
        return BundleAvailability::forSet($this->optionSets->forPurchase($product));
    }

    public function canSell(WC_Product $product, int $quantity): bool
    {
        return BundleAvailability::canSell($this->optionSets->forPurchase($product), $quantity);
    }

    public function shortfallReason(WC_Product $product, int $quantity): string
    {
        return BundleAvailability::reason($this->optionSets->forPurchase($product), $quantity);
    }

    /** @param mixed $order WC_Order */
    public function onReduced(mixed $order): void
    {
        $this->walk($order, true);
    }

    /** @param mixed $order WC_Order */
    public function onRestored(mixed $order): void
    {
        $this->walk($order, false);
    }

    private function walk(mixed $order, bool $reduce): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();

            if (!$product instanceof WC_Product) {
                continue;
            }

            $items = $this->optionSets->forPurchase($product)->bundleItems();

            if ($items === []) {
                continue;
            }

            $alreadyReduced = (bool) $item->get_meta(self::REDUCED_META);

            if ($reduce === $alreadyReduced) {
                // Reducing what is already reduced, or restoring what was never
                // reduced. Both are the second firing of a transition, and both
                // are no-ops rather than errors.
                continue;
            }

            $this->move($order, $item, $items, (int) $item->get_quantity(), $reduce);

            $item->update_meta_data(self::REDUCED_META, $reduce ? 1 : '');
            $item->save();
        }
    }

    /**
     * @param list<array{product_id: int, quantity: int}> $items
     */
    private function move(WC_Order $order, WC_Order_Item_Product $item, array $items, int $sold, bool $reduce): void
    {
        foreach ($items as $component) {
            $product = wc_get_product($component['product_id']);

            if (!$product instanceof WC_Product || !$product->managing_stock()) {
                continue;
            }

            $change = $component['quantity'] * max(1, $sold);
            $before = (int) $product->get_stock_quantity();

            $after = wc_update_product_stock($product, $change, $reduce ? 'decrease' : 'increase');

            if ($after === null || $after === false) {
                $this->logger->error('A bundle component could not be restocked', [
                    'order_id' => $order->get_id(),
                    'bundle_id' => $item->get_product_id(),
                    'component_id' => $component['product_id'],
                    'change' => $change,
                ]);

                continue;
            }

            /*
             * The ledger row is the point of this class. It names the order, so
             * "why did we lose eight of these" answers itself, and it uses the
             * same two reasons an ordinary order line does — a warehouse does
             * not care that the thing sold was a bundle, only that stock moved
             * because of an order.
             */
            $this->ledger->recordOrderMovement(
                wc_get_product($component['product_id']) ?: $product,
                $before,
                (int) $after,
                $reduce ? MovementReason::ORDER_REDUCED : MovementReason::ORDER_RESTORED,
                $order->get_id()
            );
        }
    }
}
