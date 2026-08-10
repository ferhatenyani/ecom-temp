<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a variation write payload.
 *
 * Same rules as ProductInput where they overlap — prices stay strings, stock
 * cannot be negative, unknown fields are rejected — plus the attribute map
 * that identifies which combination this variation is.
 *
 * Pure. Whether the attribute values actually exist on the parent is checked
 * by the service, since only it can load the parent.
 */
final class VariationInput
{
    public const STATUSES = ['publish', 'private'];

    private const STRING_FIELDS = ['sku', 'description'];
    private const PRICE_FIELDS = ['regular_price', 'sale_price'];
    private const BOOL_FIELDS = ['manage_stock'];

    /** @param array<string, mixed> $fields */
    private function __construct(
        public readonly array $fields,
        /** @var array<string, string>|null name => option, null when not supplied */
        public readonly ?array $attributes
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        $input = self::normalize($payload);

        if ($input->attributes === null || $input->attributes === []) {
            throw ApiException::invalidRequest('The variation data is invalid.', [
                'fields' => ['attributes' => 'A variation must specify its attribute combination.'],
            ]);
        }

        return $input;
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        return self::normalize($payload);
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
        return $this->fields === [] && $this->attributes === null;
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return [
            ...self::STRING_FIELDS,
            ...self::PRICE_FIELDS,
            ...self::BOOL_FIELDS,
            'status',
            'stock_quantity',
            'stock_status',
            'weight',
            'attributes',
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function normalize(array $payload): self
    {
        $errors = [];
        $clean = [];

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        foreach (self::STRING_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                if (!is_scalar($payload[$field])) {
                    $errors[$field] = 'Must be a string.';
                    continue;
                }

                $clean[$field] = trim((string) $payload[$field]);
            }
        }

        foreach (self::PRICE_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] === '' || $payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_numeric($payload[$field]) || (float) $payload[$field] < 0) {
                $errors[$field] = 'Must be a non-negative number.';
                continue;
            }

            $clean[$field] = (string) $payload[$field];
        }

        if (isset($clean['regular_price'], $clean['sale_price'])
            && $clean['sale_price'] !== '' && $clean['regular_price'] !== ''
            && (float) $clean['sale_price'] > (float) $clean['regular_price']
        ) {
            $errors['sale_price'] = 'Cannot be higher than the regular price.';
        }

        foreach (self::BOOL_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $clean[$field] = in_array(
                    strtolower(trim((string) $payload[$field])),
                    ['1', 'true', 'yes', 'on'],
                    true
                ) || $payload[$field] === true;
            }
        }

        if (array_key_exists('status', $payload)) {
            if (!in_array($payload['status'], self::STATUSES, true)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', self::STATUSES) . '.';
            } else {
                $clean['status'] = (string) $payload['status'];
            }
        }

        if (array_key_exists('stock_status', $payload)) {
            if (!in_array($payload['stock_status'], ProductInput::STOCK_STATUSES, true)) {
                $errors['stock_status'] = 'Must be one of: ' . implode(', ', ProductInput::STOCK_STATUSES) . '.';
            } else {
                $clean['stock_status'] = (string) $payload['stock_status'];
            }
        }

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

        $attributes = null;

        if (array_key_exists('attributes', $payload)) {
            $attributes = self::attributeMap($payload['attributes'], $errors);
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The variation data is invalid.', ['fields' => $errors]);
        }

        return new self($clean, $attributes);
    }

    /**
     * Accepts `{"size": "M"}`. An empty value means "any", which is how
     * WooCommerce models a variation that matches every option.
     *
     * @param array<string, string> $errors
     * @return array<string, string>|null
     */
    private static function attributeMap(mixed $raw, array &$errors): ?array
    {
        if (!is_array($raw)) {
            $errors['attributes'] = 'Must be an object of attribute name to option.';

            return null;
        }

        $map = [];

        foreach ($raw as $name => $option) {
            if (!is_string($name) || trim($name) === '') {
                $errors['attributes'] = 'Attribute names must be non-empty strings.';

                return null;
            }

            if ($option !== '' && !is_scalar($option)) {
                $errors['attributes'] = 'Attribute options must be strings.';

                return null;
            }

            $map[strtolower(trim($name))] = trim((string) $option);
        }

        return $map;
    }
}
