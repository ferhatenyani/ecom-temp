<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Inventory\StockLedger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Product;
use WC_Product_Variation;

/**
 * Variation business rules — docs/PLAN.md §6.
 *
 * The rules that matter are all relational: a variation only makes sense
 * against its parent's variation attributes, and two variations must not
 * claim the same combination. Neither can be checked by payload validation
 * alone, so both live here.
 */
final class VariationService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly VariationRepository $variations,
        private readonly AuditLogger $audit,
        /** Variations hold their own stock, so they write to the ledger too. */
        private readonly StockLedger $ledger
    ) {
    }

    /** @return list<WC_Product_Variation> */
    public function list(int $productId): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        return $this->variations->listFor($this->requireVariableParent($productId));
    }

    /** @param array<string, mixed> $payload */
    public function create(int $productId, array $payload): WC_Product_Variation
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $parent = $this->requireVariableParent($productId);
        $input = VariationInput::forCreate($payload);

        $this->guardAttributes($parent, (array) $input->attributes);
        $this->guardDuplicateCombination($parent, (array) $input->attributes);
        $this->guardSku((string) ($input->get('sku') ?? ''));

        $variation = $this->variations->create($parent, $input);

        $this->ledger->recordProductEdit($variation, 0, $variation->get_stock_quantity());

        $this->audit->record('product.variation_created', 'product_variation', $variation->get_id(), [
            'parent_id' => $productId,
            'attributes' => $input->attributes,
            'sku' => $variation->get_sku(),
        ]);

        return $variation;
    }

    /** @param array<string, mixed> $payload */
    public function update(int $productId, int $variationId, array $payload): WC_Product_Variation
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $parent = $this->requireVariableParent($productId);
        $variation = $this->requireVariation($parent, $variationId);
        $input = VariationInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        if ($input->attributes !== null) {
            $this->guardAttributes($parent, $input->attributes);
            $this->guardDuplicateCombination($parent, $input->attributes, $variationId);
        }

        if ($input->has('sku')) {
            $this->guardSku((string) $input->get('sku'), $variationId);
        }

        $this->guardSalePriceAgainstStored($variation, $input);

        // Read before the write: the repository mutates this object in place.
        $quantityBefore = $variation->get_stock_quantity();

        $updated = $this->variations->update($variation, $input);

        $this->ledger->recordProductEdit($updated, $quantityBefore, $updated->get_stock_quantity());

        $this->audit->record('product.variation_updated', 'product_variation', $variationId, [
            'parent_id' => $productId,
            'fields' => array_keys($input->fields),
        ]);

        return $updated;
    }

    public function delete(int $productId, int $variationId, bool $force): void
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $parent = $this->requireVariableParent($productId);
        $variation = $this->requireVariation($parent, $variationId);

        $sku = $variation->get_sku();

        if (!$this->variations->delete($variation, $force)) {
            throw ApiException::internal('The variation could not be deleted.');
        }

        $this->audit->record('product.variation_deleted', 'product_variation', $variationId, [
            'parent_id' => $productId,
            'sku' => $sku,
            'permanent' => $force,
        ]);
    }

    private function requireVariableParent(int $productId): WC_Product
    {
        $parent = $this->products->find($productId);

        if ($parent === null) {
            throw ApiException::notFound('No product with that id.');
        }

        if ($parent->get_type() !== 'variable') {
            throw ApiException::conflict(
                'Only variable products have variations. Set the product type to "variable" first.',
                ['type' => $parent->get_type()]
            );
        }

        return $parent;
    }

    /**
     * Guards against reading or editing a variation through the wrong parent
     * — an IDOR that would otherwise let /products/5/variations/99 touch a
     * variation belonging to product 7.
     */
    private function requireVariation(WC_Product $parent, int $variationId): WC_Product_Variation
    {
        $variation = $this->variations->find($variationId);

        if ($variation === null || $variation->get_parent_id() !== $parent->get_id()) {
            throw ApiException::notFound('No variation with that id for this product.');
        }

        return $variation;
    }

    /**
     * Every key must be an attribute the parent marked `variation: true`, and
     * every value must be one of that attribute's options. An empty value is
     * "any", which WooCommerce supports.
     *
     * @param array<string, string> $attributes
     */
    private function guardAttributes(WC_Product $parent, array $attributes): void
    {
        $allowed = $this->variationAttributes($parent);

        if ($allowed === []) {
            throw ApiException::conflict(
                'The parent product has no attributes marked for variations.'
            );
        }

        $errors = [];

        foreach ($attributes as $name => $value) {
            if (!array_key_exists($name, $allowed)) {
                $errors["attributes.{$name}"] = 'Not a variation attribute of this product. Allowed: '
                    . implode(', ', array_keys($allowed)) . '.';
                continue;
            }

            if ($value === '') {
                continue;
            }

            if (!in_array(strtolower($value), $allowed[$name], true)) {
                $errors["attributes.{$name}"] = "\"{$value}\" is not an option of this attribute. Allowed: "
                    . implode(', ', $allowed[$name]) . '.';
            }
        }

        // Missing keys are allowed on update (partial) but a create without
        // every attribute produces a variation that cannot be selected.
        if ($errors !== []) {
            throw ApiException::invalidRequest('The variation data is invalid.', ['fields' => $errors]);
        }
    }

    /**
     * Parent's variation attributes as name => list of lowercase options.
     *
     * @return array<string, list<string>>
     */
    private function variationAttributes(WC_Product $parent): array
    {
        $allowed = [];

        foreach ($parent->get_attributes() as $attribute) {
            if (!$attribute->get_variation()) {
                continue;
            }

            $name = strtolower($attribute->get_name());
            $options = [];

            if ($attribute->is_taxonomy()) {
                foreach ($attribute->get_terms() ?? [] as $term) {
                    $options[] = strtolower($term->slug);
                }
            } else {
                foreach ($attribute->get_options() as $option) {
                    $options[] = strtolower((string) $option);
                }
            }

            $allowed[$name] = $options;
        }

        return $allowed;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function guardDuplicateCombination(WC_Product $parent, array $attributes, int $ignoreId = 0): void
    {
        $candidate = VariationRepository::normalizeCombination($attributes);

        foreach ($this->variations->existingCombinations($parent) as $id => $existing) {
            if ($id !== $ignoreId && $existing === $candidate) {
                throw ApiException::conflict('A variation with that attribute combination already exists.', [
                    'variation_id' => $id,
                ]);
            }
        }
    }

    private function guardSku(string $sku, int $ignoreId = 0): void
    {
        if ($sku !== '' && $this->products->skuExists($sku, $ignoreId)) {
            throw ApiException::conflict('That SKU is already in use.', ['sku' => $sku]);
        }
    }

    private function guardSalePriceAgainstStored(WC_Product_Variation $variation, VariationInput $input): void
    {
        if (!$input->has('sale_price') || $input->has('regular_price')) {
            return;
        }

        $sale = (string) $input->get('sale_price');
        $regular = (string) $variation->get_regular_price();

        if ($sale !== '' && $regular !== '' && (float) $sale > (float) $regular) {
            throw ApiException::invalidRequest('The variation data is invalid.', [
                'fields' => ['sale_price' => 'Cannot be higher than the regular price.'],
            ]);
        }
    }
}
