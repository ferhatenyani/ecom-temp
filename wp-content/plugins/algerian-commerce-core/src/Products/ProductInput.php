<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * Validates and normalizes a product write payload.
 *
 * Pure — no WordPress, no WooCommerce — so every rule here is unit-testable:
 * which fields exist, which are required, and which combinations are
 * nonsense (a sale price above the regular price).
 *
 * Unknown fields are rejected rather than ignored (docs/SECURITY.md). Silently
 * dropping `stock_quantiy` leaves a caller convinced they set stock.
 */
final class ProductInput
{
    public const STATUSES = ['draft', 'pending', 'private', 'publish'];
    public const VISIBILITIES = ['visible', 'catalog', 'search', 'hidden'];
    public const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    private const STRING_FIELDS = ['name', 'slug', 'description', 'short_description', 'sku'];
    private const PRICE_FIELDS = ['regular_price', 'sale_price'];
    private const BOOL_FIELDS = ['featured', 'manage_stock'];
    private const INT_LIST_FIELDS = ['category_ids', 'tag_ids'];

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        $fields = self::normalize($payload, true);

        return new self($fields);
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

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return [
            ...self::STRING_FIELDS,
            ...self::PRICE_FIELDS,
            ...self::BOOL_FIELDS,
            ...self::INT_LIST_FIELDS,
            'status',
            'catalog_visibility',
            'stock_status',
            'stock_quantity',
            'weight',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ApiException with a per-field breakdown in error.details.fields
     */
    private static function normalize(array $payload, bool $requireName): array
    {
        $errors = [];
        $clean = [];

        $unknown = array_diff(array_keys($payload), self::allowedFields());
        foreach ($unknown as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        foreach (self::STRING_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            $clean[$field] = trim((string) $payload[$field]);
        }

        if ($requireName && ($clean['name'] ?? '') === '') {
            $errors['name'] = 'A product name is required.';
        } elseif (array_key_exists('name', $clean) && $clean['name'] === '') {
            $errors['name'] = 'A product name cannot be emptied.';
        }

        foreach (self::PRICE_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            // '' clears a price in WooCommerce, so it is a valid value.
            if ($payload[$field] === '' || $payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_numeric($payload[$field])) {
                $errors[$field] = 'Must be a number.';
                continue;
            }

            if ((float) $payload[$field] < 0) {
                $errors[$field] = 'Cannot be negative.';
                continue;
            }

            $clean[$field] = (string) $payload[$field];
        }

        self::validateSalePrice($clean, $errors);

        foreach (self::BOOL_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $clean[$field] = self::toBool($payload[$field]);
            }
        }

        self::validateEnum($payload, $clean, $errors, 'status', self::STATUSES);
        self::validateEnum($payload, $clean, $errors, 'catalog_visibility', self::VISIBILITIES);
        self::validateEnum($payload, $clean, $errors, 'stock_status', self::STOCK_STATUSES);

        if (array_key_exists('stock_quantity', $payload)) {
            if ($payload['stock_quantity'] === null) {
                $clean['stock_quantity'] = null;
            } elseif (!is_numeric($payload['stock_quantity']) || (int) $payload['stock_quantity'] < 0) {
                $errors['stock_quantity'] = 'Must be a whole number of zero or more.';
            } else {
                $clean['stock_quantity'] = (int) $payload['stock_quantity'];
            }
        }

        if (array_key_exists('weight', $payload)) {
            if ($payload['weight'] === '' || $payload['weight'] === null) {
                $clean['weight'] = '';
            } elseif (!is_numeric($payload['weight']) || (float) $payload['weight'] < 0) {
                $errors['weight'] = 'Must be a non-negative number.';
            } else {
                $clean['weight'] = (string) $payload['weight'];
            }
        }

        foreach (self::INT_LIST_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if (!is_array($payload[$field])) {
                $errors[$field] = 'Must be an array of ids.';
                continue;
            }

            $ids = [];
            foreach ($payload[$field] as $id) {
                if (!is_numeric($id) || (int) $id <= 0) {
                    $errors[$field] = 'Ids must be positive integers.';
                    continue 2;
                }

                $ids[] = (int) $id;
            }

            $clean[$field] = array_values(array_unique($ids));
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The product data is invalid.', ['fields' => $errors]);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function validateSalePrice(array $clean, array &$errors): void
    {
        $sale = $clean['sale_price'] ?? '';
        $regular = $clean['regular_price'] ?? null;

        if ($sale === '' || $regular === null || $regular === '') {
            // Nothing to compare. A sale price without a regular price in the
            // same payload is checked against the stored value in the service.
            return;
        }

        if ((float) $sale > (float) $regular) {
            $errors['sale_price'] = 'Cannot be higher than the regular price.';
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     * @param list<string> $allowed
     */
    private static function validateEnum(
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

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
