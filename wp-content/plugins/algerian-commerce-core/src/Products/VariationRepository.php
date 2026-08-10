<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * WooCommerce adapter for product variations.
 *
 * Every write ends with a parent sync: WooCommerce caches the price range,
 * stock status and "from" price on the parent, and those go stale the moment
 * a child changes.
 */
final class VariationRepository
{
    /** @return list<WC_Product_Variation> */
    public function listFor(WC_Product $parent): array
    {
        $variations = [];

        foreach ($parent->get_children() as $childId) {
            $child = wc_get_product($childId);

            if ($child instanceof WC_Product_Variation) {
                $variations[] = $child;
            }
        }

        return $variations;
    }

    public function find(int $id): ?WC_Product_Variation
    {
        $variation = wc_get_product($id);

        return $variation instanceof WC_Product_Variation ? $variation : null;
    }

    public function create(WC_Product $parent, VariationInput $input): WC_Product_Variation
    {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());

        $this->apply($variation, $input);
        $variation->save();

        $this->sync($parent->get_id());

        // Reload: an unsaved WC_Product_Variation reports defaults that the
        // database then fills in (status is false until the post exists), so
        // returning the in-memory object would present a lie.
        return $this->find($variation->get_id()) ?? $variation;
    }

    public function update(WC_Product_Variation $variation, VariationInput $input): WC_Product_Variation
    {
        $this->apply($variation, $input);
        $variation->save();

        $this->sync($variation->get_parent_id());

        return $variation;
    }

    public function delete(WC_Product_Variation $variation, bool $force): bool
    {
        $parentId = $variation->get_parent_id();

        $deleted = (bool) $variation->delete($force);

        if ($deleted) {
            $this->sync($parentId);
        }

        return $deleted;
    }

    /**
     * The attribute combinations already in use, so a duplicate can be
     * refused before WooCommerce silently accepts a second identical one.
     *
     * @return array<int, array<string, string>> variation id => attribute map
     */
    public function existingCombinations(WC_Product $parent): array
    {
        $combinations = [];

        foreach ($this->listFor($parent) as $variation) {
            $combinations[$variation->get_id()] = self::normalizeCombination($variation->get_attributes());
        }

        return $combinations;
    }

    /**
     * WooCommerce stores variation attributes with inconsistent casing and
     * an optional `attribute_` prefix depending on how they were written.
     * Comparisons have to happen on a normalized form.
     *
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    public static function normalizeCombination(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $key = strtolower((string) $key);

            if (str_starts_with($key, 'attribute_')) {
                $key = substr($key, strlen('attribute_'));
            }

            $normalized[$key] = strtolower(trim((string) $value));
        }

        ksort($normalized);

        return $normalized;
    }

    private function apply(WC_Product_Variation $variation, VariationInput $input): void
    {
        $setters = [
            'sku' => 'set_sku',
            'description' => 'set_description',
            'regular_price' => 'set_regular_price',
            'sale_price' => 'set_sale_price',
            'status' => 'set_status',
            'manage_stock' => 'set_manage_stock',
            'stock_quantity' => 'set_stock_quantity',
            'stock_status' => 'set_stock_status',
            'weight' => 'set_weight',
        ];

        foreach ($setters as $field => $setter) {
            if ($input->has($field)) {
                $variation->{$setter}($input->get($field));
            }
        }

        if ($input->attributes !== null) {
            $variation->set_attributes($input->attributes);
        }
    }

    private function sync(int $parentId): void
    {
        if ($parentId > 0 && class_exists(WC_Product_Variable::class)) {
            WC_Product_Variable::sync($parentId);
        }
    }
}
