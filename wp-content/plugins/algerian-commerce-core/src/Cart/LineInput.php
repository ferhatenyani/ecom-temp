<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\API\ApiException;

/**
 * The only thing a caller may say about a cart line — roadmap §59b.
 *
 * Pure, like every other `*Input` in this plugin, so the rule it enforces is a
 * unit test rather than something discovered against a live cart.
 *
 * **The list of accepted fields is the security property.** A cart line has
 * exactly three writable facts — which product, which variation, how many — and
 * every other number a line carries (`price`, `line_total`, `line_subtotal`,
 * `discount`) is derived on the server from the catalogue. Accepting any of
 * them, even to ignore them, invites a client to send them and a later
 * refactor to honour them. `REJECTED` therefore names the money fields
 * explicitly and says why, the way `Customers\CustomerInput` names `roles` and
 * `user_pass`: an unknown-field error reading "unknown field: price" is a
 * developer wondering whether they spelled it wrong, while this one tells them
 * the shop decides.
 */
final class LineInput
{
    /** @var array<string, string> */
    private const REJECTED = [
        'price' => 'The catalogue decides the price; a cart cannot set one.',
        'line_total' => 'Totals are calculated from the catalogue, never sent.',
        'line_subtotal' => 'Totals are calculated from the catalogue, never sent.',
        'subtotal' => 'Totals are calculated from the catalogue, never sent.',
        'total' => 'Totals are calculated from the catalogue, never sent.',
        'discount' => 'Discounts come from coupons, not from the request.',
        'currency' => 'The store currency applies to every cart.',
    ];

    private function __construct(
        public readonly int $productId,
        public readonly int $variationId,
        public readonly int $quantity
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException 400 listing every bad field, not just the first
     */
    public static function fromArray(array $payload, bool $requireProduct = true): self
    {
        $errors = [];

        foreach (self::REJECTED as $field => $why) {
            if (array_key_exists($field, $payload)) {
                $errors[$field] = $why;
            }
        }

        $known = ['product_id', 'variation_id', 'quantity'];

        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $known, true) && !isset(self::REJECTED[$field])) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $productId = self::int($payload['product_id'] ?? 0);
        $variationId = self::int($payload['variation_id'] ?? 0);
        $quantity = array_key_exists('quantity', $payload) ? self::int($payload['quantity']) : 1;

        if ($requireProduct && $productId <= 0) {
            $errors['product_id'] = 'A positive product id is required.';
        }

        if ($variationId < 0) {
            $errors['variation_id'] = 'Must not be negative.';
        }

        if ($quantity < 1) {
            $errors['quantity'] = 'Must be at least 1.';
        }

        if ($quantity > CartService::MAX_QUANTITY) {
            $errors['quantity'] = 'At most ' . CartService::MAX_QUANTITY . ' of one line.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The cart line is invalid.', ['fields' => $errors]);
        }

        return new self($productId, $variationId, $quantity);
    }

    /** @return array{product_id: int, variation_id: int, quantity: int} */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'variation_id' => $this->variationId,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * `"3"` is a 3 and `"3 apples"` is not.
     *
     * A JSON client that sends numbers as strings is normal; one that sends a
     * float quantity, an array or a boolean is not, and each of those becomes a
     * 0 here so the range checks above refuse it by name.
     */
    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return is_float($value) && $value === floor($value) ? (int) $value : 0;
    }
}
