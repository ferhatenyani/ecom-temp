<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a change to a product's stock *settings*.
 *
 * Settings are configuration — whether stock is tracked, whether backorders
 * are allowed, when to call the level low. The quantity itself is deliberately
 * not settable here: it moves only through an adjustment, which is what
 * guarantees every change to a number of units leaves a ledger row behind. A
 * payload that tries anyway gets an error naming the endpoint that does it,
 * rather than a bare "Unknown field."
 *
 * Pure — no WordPress.
 */
final class InventorySettingsInput
{
    public const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];
    public const BACKORDERS = ['no', 'notify', 'yes'];

    private const FIELDS = ['manage_stock', 'stock_status', 'backorders', 'low_stock_amount'];

    /**
     * Emitted on read, ignored on write, so a client can GET an inventory item,
     * change one setting and PATCH the whole object back.
     */
    private const READ_ONLY = [
        'id', 'parent_id', 'type', 'name', 'sku',
        'stock_managed_by_id', 'low_stock', 'managing_stock',
    ];

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        if (array_key_exists('stock_quantity', $payload)) {
            $errors['stock_quantity'] = 'Stock quantity changes through POST /inventory/{id}/adjust, '
                . 'so that every change is recorded in the movement ledger.';
            unset($payload['stock_quantity']);
        }

        foreach (array_diff(array_keys($payload), self::FIELDS) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        if (array_key_exists('manage_stock', $payload)) {
            $manage = filter_var($payload['manage_stock'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if ($manage === null) {
                $errors['manage_stock'] = 'Must be a boolean.';
            } else {
                $clean['manage_stock'] = $manage;
            }
        }

        self::enum($payload, $clean, $errors, 'stock_status', self::STOCK_STATUSES);
        self::enum($payload, $clean, $errors, 'backorders', self::BACKORDERS);

        if (array_key_exists('low_stock_amount', $payload)) {
            $raw = $payload['low_stock_amount'];

            // null or '' clears the per-product threshold and falls back to the
            // store-wide setting. WooCommerce stores that as an empty string.
            if ($raw === null || $raw === '') {
                $clean['low_stock_amount'] = '';
            } elseif (!is_numeric($raw) || (float) $raw !== floor((float) $raw) || (int) $raw < 0) {
                $errors['low_stock_amount'] = 'Must be a whole number of zero or more, or null to clear.';
            } else {
                $clean['low_stock_amount'] = (int) $raw;
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The inventory settings are invalid.', ['fields' => $errors]);
        }

        return new self($clean);
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     * @param list<string> $allowed
     */
    private static function enum(
        array $payload,
        array &$clean,
        array &$errors,
        string $field,
        array $allowed
    ): void {
        if (!array_key_exists($field, $payload)) {
            return;
        }

        $value = is_scalar($payload[$field]) ? (string) $payload[$field] : '';

        if (!in_array($value, $allowed, true)) {
            $errors[$field] = 'Must be one of: ' . implode(', ', $allowed) . '.';

            return;
        }

        $clean[$field] = $value;
    }
}
