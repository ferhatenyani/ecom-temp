<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\AddressInput;

/**
 * Validates and normalizes an order write payload.
 *
 * Pure — no WordPress, no WooCommerce — so every rule is unit-testable: which
 * fields exist, which are required on a create, and which values are nonsense.
 *
 * Unknown fields are rejected rather than ignored (docs/SECURITY.md). The
 * fields this API emits but does not accept are dropped silently instead, so a
 * client can GET an order, change one thing and PATCH the whole object back.
 * That distinction matters more here than on a product: an order body is large,
 * mostly computed, and no client wants to strip fourteen keys by hand.
 *
 * Every monetary field is read-only. Totals are what WooCommerce computes from
 * the catalogue, never what a caller sends.
 */
final class OrderInput
{
    /**
     * Computed, derived or identity fields the presenter emits.
     *
     * The money ones are here for a stronger reason than convenience: an order
     * total that a request can set is not a total, it is a suggestion.
     */
    private const READ_ONLY = [
        'id', 'number', 'order_key', 'created_via', 'currency', 'version',
        'discount_total', 'shipping_total', 'total_tax', 'total', 'subtotal',
        'prices_include_tax', 'payment_url', 'is_editable', 'needs_payment',
        'stock_reduced', 'customer', 'date_created', 'date_modified',
        'date_paid', 'date_completed',
    ];

    private const STRING_FIELDS = ['payment_method', 'payment_method_title', 'customer_note'];

    private const MAX_NOTE = 5000;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        return new self(self::normalize($payload, true));
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        return new self(self::normalize($payload, false));
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return list<LineItemInput> */
    public function lineItems(): array
    {
        return $this->fields['line_items'] ?? [];
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return [
            ...self::STRING_FIELDS,
            'status',
            'customer_id',
            'billing',
            'shipping',
            'line_items',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ApiException with a per-field breakdown in error.details.fields
     */
    private static function normalize(array $payload, bool $isCreate): array
    {
        $errors = [];
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        foreach (self::STRING_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $payload[$field]);

            if (mb_strlen($value) > self::MAX_NOTE) {
                $errors[$field] = 'Must be at most ' . self::MAX_NOTE . ' characters.';
                continue;
            }

            $clean[$field] = $value;
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? OrderStatus::normalize((string) $payload['status']) : '';

            if (!OrderStatus::isKnown($status)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', OrderStatus::ALL) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        if (array_key_exists('customer_id', $payload)) {
            // 0 is a guest order, which is the normal case for a storefront
            // that does not force registration — so it is a value, not a gap.
            if (!is_numeric($payload['customer_id']) || (int) $payload['customer_id'] < 0) {
                $errors['customer_id'] = 'Must be a user id, or 0 for a guest.';
            } else {
                $clean['customer_id'] = (int) $payload['customer_id'];
            }
        }

        if (array_key_exists('billing', $payload)) {
            $clean['billing'] = AddressInput::forBilling($payload['billing'], $errors);
        }

        if (array_key_exists('shipping', $payload)) {
            $clean['shipping'] = AddressInput::forShipping($payload['shipping'], $errors);
        }

        if (array_key_exists('line_items', $payload)) {
            $clean['line_items'] = LineItemInput::listFromPayload($payload['line_items'], $errors);
        } elseif ($isCreate) {
            $errors['line_items'] = 'An order needs at least one line item.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The order data is invalid.', ['fields' => $errors]);
        }

        return $clean;
    }
}
