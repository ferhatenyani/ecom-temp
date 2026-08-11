<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

/**
 * One product line on an order write.
 *
 * Pure — no WordPress — so the shape rules are unit-testable. Whether the
 * product exists is a question for the repository, which is the only layer
 * allowed to ask WooCommerce.
 *
 * **There is no price field, and there will not be one.** Prices come from the
 * catalogue when the line is added; accepting one from the caller would let
 * anyone holding `ac_manage_orders` — or anyone who reaches the endpoint
 * through a compromised admin session — write an order at a price of their
 * choosing (docs/SECURITY.md: never trust a client-supplied amount). Discounts
 * belong to coupons, which have their own capability.
 */
final class LineItemInput
{
    /**
     * Emitted on read, ignored on write.
     *
     * The presenter returns a line's name, SKU and computed totals so a client
     * can display an order without a second request. Dropping them here is what
     * makes the natural round trip — GET an order, change a quantity, PATCH it
     * back — work, while a genuine typo is still an error.
     *
     * Exactly the keys the presenter emits, and no more. `price` is not one of
     * them, so it falls through to the unknown-field check below and is
     * refused by name: nobody arrives at `price` by round-tripping a response,
     * they arrive at it by trying to set one.
     */
    private const READ_ONLY = ['id', 'name', 'sku', 'subtotal', 'total'];

    private const ALLOWED = ['product_id', 'variation_id', 'quantity'];

    public function __construct(
        public readonly int $productId,
        public readonly int $variationId,
        public readonly int $quantity
    ) {
    }

    /**
     * @param array<string, string> $errors keyed `line_items.0.quantity`
     * @return list<self>
     */
    public static function listFromPayload(mixed $payload, array &$errors): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            $errors['line_items'] = 'Must be an array of line items.';

            return [];
        }

        if ($payload === []) {
            $errors['line_items'] = 'An order needs at least one line item.';

            return [];
        }

        $items = [];

        foreach ($payload as $index => $raw) {
            $item = self::one($raw, "line_items.{$index}", $errors);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @param array<string, string> $errors */
    private static function one(mixed $raw, string $prefix, array &$errors): ?self
    {
        if (!is_array($raw)) {
            $errors[$prefix] = 'Must be an object.';

            return null;
        }

        $raw = array_diff_key($raw, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($raw), self::ALLOWED) as $field) {
            $errors["{$prefix}." . (string) $field] = $field === 'price'
                ? 'Line prices come from the catalogue and cannot be set.'
                : 'Unknown field.';
        }

        $productId = self::positiveInt($raw['product_id'] ?? null);

        if ($productId === null) {
            $errors["{$prefix}.product_id"] = 'A product id is required.';
        }

        $quantity = self::positiveInt($raw['quantity'] ?? null);

        if ($quantity === null) {
            $errors["{$prefix}.quantity"] = 'Must be a whole number of one or more.';
        }

        // A variation is optional, but 0 has to mean "none" rather than an
        // error — it is what the presenter emits for a simple product, and a
        // round-tripped order would otherwise fail on a field nobody touched.
        $variationId = 0;

        if (array_key_exists('variation_id', $raw) && !in_array($raw['variation_id'], [null, 0, '0', ''], true)) {
            $variationId = self::positiveInt($raw['variation_id']) ?? -1;

            if ($variationId === -1) {
                $errors["{$prefix}.variation_id"] = 'Must be a variation id, or 0 for none.';
            }
        }

        if ($productId === null || $quantity === null || $variationId === -1) {
            return null;
        }

        return new self($productId, $variationId, $quantity);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        // Rejects 1.5 rather than silently truncating it to 1: a fractional
        // quantity is a caller mistake, and half a unit is not something a
        // ledger of whole units can represent.
        if ((float) $value !== floor((float) $value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
