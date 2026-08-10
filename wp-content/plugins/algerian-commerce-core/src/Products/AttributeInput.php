<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * One product attribute from a write payload.
 *
 * Two kinds exist in WooCommerce and they behave differently:
 *
 *  - **Global** (`id` given) — a registered attribute taxonomy such as
 *    `pa_size`. Its options are existing terms.
 *  - **Custom** (`name` given) — free text stored on the product alone.
 *
 * Pure: no WordPress. Resolving a global attribute's id to a taxonomy and its
 * options to term ids is the repository's job, because only that needs the
 * database.
 */
final class AttributeInput
{
    private function __construct(
        public readonly ?int $id,
        public readonly string $name,
        /** @var list<string> */
        public readonly array $options,
        public readonly bool $visible,
        public readonly bool $variation,
        public readonly int $position
    ) {
    }

    public function isGlobal(): bool
    {
        return $this->id !== null;
    }

    /**
     * @param mixed $raw the whole `attributes` value from the payload
     * @return list<self>
     *
     * @throws ApiException
     */
    public static function listFromPayload(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw ApiException::invalidRequest('The product data is invalid.', [
                'fields' => ['attributes' => 'Must be an array of attributes.'],
            ]);
        }

        $errors = [];
        $attributes = [];
        $seen = [];

        foreach (array_values($raw) as $index => $entry) {
            $field = "attributes[{$index}]";

            if (!is_array($entry)) {
                $errors[$field] = 'Must be an object.';
                continue;
            }

            $unknown = array_diff(array_keys($entry), ['id', 'name', 'options', 'visible', 'variation', 'position']);
            if ($unknown !== []) {
                $errors[$field] = 'Unknown keys: ' . implode(', ', $unknown) . '.';
                continue;
            }

            $id = null;
            if (isset($entry['id'])) {
                if (!is_numeric($entry['id']) || (int) $entry['id'] < 0) {
                    $errors[$field . '.id'] = 'Must be a non-negative integer.';
                    continue;
                }

                /*
                 * 0 is WooCommerce's own marker for "custom attribute", and
                 * it is what our read endpoint emits for one. Treating it as
                 * invalid would break the GET → edit → PATCH round trip.
                 */
                $id = (int) $entry['id'] > 0 ? (int) $entry['id'] : null;
            }

            $name = isset($entry['name']) && is_scalar($entry['name']) ? trim((string) $entry['name']) : '';

            if ($id === null && $name === '') {
                $errors[$field] = 'Requires either an id (global attribute) or a name (custom attribute).';
                continue;
            }

            $options = self::options($entry['options'] ?? null, $field, $errors);
            if ($options === null) {
                continue;
            }

            // Two entries for the same attribute silently overwrite each
            // other inside WooCommerce, so reject it here instead.
            $key = $id !== null ? "id:{$id}" : 'name:' . strtolower($name);
            if (isset($seen[$key])) {
                $errors[$field] = 'Duplicate attribute.';
                continue;
            }
            $seen[$key] = true;

            $attributes[] = new self(
                $id,
                $name,
                $options,
                self::toBool($entry['visible'] ?? true),
                self::toBool($entry['variation'] ?? false),
                isset($entry['position']) && is_numeric($entry['position']) ? (int) $entry['position'] : $index
            );
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The product data is invalid.', ['fields' => $errors]);
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $errors
     * @return list<string>|null null when the entry was rejected
     */
    private static function options(mixed $raw, string $field, array &$errors): ?array
    {
        if (!is_array($raw) || $raw === []) {
            $errors[$field . '.options'] = 'At least one option is required.';

            return null;
        }

        $options = [];

        foreach ($raw as $option) {
            if (!is_scalar($option) || trim((string) $option) === '') {
                $errors[$field . '.options'] = 'Options must be non-empty strings.';

                return null;
            }

            $options[] = trim((string) $option);
        }

        return array_values(array_unique($options));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
