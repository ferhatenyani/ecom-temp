<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use WC_Product;
use WC_Product_Variation;

/**
 * Shapes stock for the API.
 *
 * Narrower than the product representation on purpose: an inventory screen
 * wants counts and thresholds, not descriptions and galleries, and a stock
 * list that carried every product field would be far heavier than the job
 * needs.
 */
final class InventoryPresenter
{
    /** @return array<string, mixed> */
    public static function item(WC_Product $product): array
    {
        $quantity = $product->get_stock_quantity();
        $managing = (bool) $product->managing_stock();
        $threshold = (int) wc_get_low_stock_amount($product);

        return [
            'id' => $product->get_id(),
            'parent_id' => (int) $product->get_parent_id(),
            'type' => $product->get_type(),
            'name' => self::name($product),
            'sku' => $product->get_sku(),
            /*
             * WooCommerce reports a variation that inherits its parent's stock
             * as the string "parent", not a boolean, and the distinction is
             * the whole reason stock_managed_by_id exists. Passing it through
             * unflattened lets a client tell the two apart; managing_stock is
             * the plain yes/no, and it also reflects the store-wide switch.
             */
            'manage_stock' => $product->get_manage_stock(),
            'managing_stock' => $managing,
            'stock_managed_by_id' => (int) $product->get_stock_managed_by_id(),
            'stock_quantity' => $quantity === null ? null : (int) $quantity,
            'stock_status' => $product->get_stock_status(),
            'backorders' => $product->get_backorders(),
            'low_stock_amount' => $threshold,
            'low_stock' => $managing && $quantity !== null && (int) $quantity <= $threshold,
        ];
    }

    /**
     * @param list<WC_Product> $products
     * @return list<array<string, mixed>>
     */
    public static function itemList(array $products): array
    {
        return array_values(array_map([self::class, 'item'], $products));
    }

    /**
     * A variation's own name is its parent's title, which makes a stock list
     * of a variable product read as the same row repeated. Appending the
     * combination is what tells "Djellaba / red / L" from "Djellaba / blue / S".
     */
    private static function name(WC_Product $product): string
    {
        $name = $product->get_name();

        if (!$product instanceof WC_Product_Variation) {
            return $name;
        }

        // Empty values mean "any" and say nothing about which variation it is.
        $combination = array_filter(array_map('strval', $product->get_attributes()));

        return $combination === [] ? $name : $name . ' — ' . implode(', ', $combination);
    }
}
