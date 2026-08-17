<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WC_Product;

/**
 * How many of a bundle the shop can actually make up — roadmap §83.
 *
 * **Derived, never stored.** §83: "A bundle's purchasability is the minimum of
 * its components', recomputed on the server, not a stock field of its own. A
 * bundle showing 'in stock' because nobody refreshed it is an oversell." The
 * only number that cannot go stale is the one computed at the moment of asking,
 * so this is a function rather than a column.
 *
 * Separate from `BundleStock` because the two need different things.
 * *Moving* stock needs the ledger and a logger; *asking* about it needs neither,
 * and a presenter that had to construct a `StockLedger` to render a product page
 * would be carrying a write dependency into a read.
 */
final class BundleAvailability
{
    /**
     * `null` means unbounded — not a bundle, or every component sells without
     * managing stock. A caller must treat that as "no ceiling", not as zero.
     */
    public static function forSet(OptionSet $set): ?int
    {
        $items = $set->bundleItems();

        if ($items === []) {
            return null;
        }

        $available = null;

        foreach ($items as $item) {
            $component = wc_get_product($item['product_id']);

            /*
             * A component that has been deleted cannot be shipped, so the
             * bundle cannot be sold — zero, not "unbounded". Getting this
             * backwards is the oversell §83 warns about, arriving through the
             * one path nobody tests: somebody trashing a product that another
             * product quietly depends on.
             */
            if (!$component instanceof WC_Product || !$component->is_in_stock()) {
                return 0;
            }

            if (!$component->managing_stock()) {
                continue;
            }

            $units = (int) floor(((int) $component->get_stock_quantity()) / max(1, (int) $item['quantity']));
            $available = $available === null ? $units : min($available, $units);
        }

        return $available;
    }

    public static function canSell(OptionSet $set, int $quantity): bool
    {
        $available = self::forSet($set);

        return $available === null || $available >= $quantity;
    }

    /**
     * Why not, in the shopper's terms.
     *
     * Deliberately never names the component or its stock figure. Which product
     * is running low, and by how much, is the shop's business — a public cart
     * route that reported it would turn `POST /cart/items` into an inventory
     * read for anybody willing to guess bundle quantities.
     */
    public static function reason(OptionSet $set, int $quantity): string
    {
        $available = self::forSet($set);

        if ($available === null || $available >= $quantity) {
            return '';
        }

        return $available < 1
            ? 'One of the items in this bundle is out of stock.'
            : sprintf('Only %d of this bundle can be made up right now.', $available);
    }
}
