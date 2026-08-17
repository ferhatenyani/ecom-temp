<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a **global attribute** write payload — roadmap §88.
 *
 * Pure: no WordPress, so the field list is testable on its own.
 *
 * Not to be confused with `AttributeInput`, which validates an attribute as it
 * appears *on a product*. The two describe opposite ends of the same idea and
 * the names say which: this one creates `pa_size`, that one says a product has
 * `pa_size` with the options M and L. §82's distinction is the reason both
 * exist — **only a global attribute can be filtered or counted**, because only
 * a global attribute has a shared vocabulary to count.
 */
final class GlobalAttributeInput
{
    /** WooCommerce's own list, hard-coded inside `wc_create_attribute()`. */
    public const ORDER_BY = ['menu_order', 'name', 'name_num', 'id'];

    /**
     * Emitted on read, dropped on write, so `GET` → edit → `PATCH` round trips.
     */
    private const READ_ONLY = ['id', 'taxonomy', 'term_count', 'product_count'];

    /**
     * Refused by name. Neither is emitted, so nobody arrives at one by
     * round-tripping a response.
     *
     * @var array<string, string>
     */
    private const REFUSED = [
        'terms' => 'Terms are managed at /attributes/{id}/terms, one at a time, because deleting one detaches every product using it.',
        'attribute_id' => 'The id is in the URL.',
        'attribute_name' => 'Use "slug" for the identifier and "name" for the label.',
    ];

    private const MAX_NAME = 200;

    /**
     * `wc_get_attribute_slug_max_byte_length()` reported 29 on WooCommerce
     * 11.0.1: WordPress caps a taxonomy name at 32 bytes and `pa_` takes
     * three. Checked here for the message, and again by WooCommerce, which is
     * the authority — the constant is a copy and copies drift.
     */
    private const MAX_SLUG_BYTES = 29;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['name', 'slug', 'type', 'order_by', 'has_archives'];
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

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if (!array_key_exists('name', $payload)) {
            $errors['name'] = 'Required. This is the label a shopper sees — "Taille", not "pa_taille".';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The attribute data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The attribute data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $errors collected by reference so one
     *                                      response names every bad field
     * @return array<string, mixed>
     */
    private static function common(array $payload, array &$errors): array
    {
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $field = (string) $field;
            $errors[$field] = self::REFUSED[$field] ?? 'Unknown field.';
        }

        if (array_key_exists('name', $payload)) {
            $name = is_scalar($payload['name']) ? trim((string) $payload['name']) : '';

            if ($name === '') {
                $errors['name'] = 'Must be a non-empty string.';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors['name'] = 'Must be at most ' . self::MAX_NAME . ' characters.';
            } else {
                $clean['name'] = $name;
            }
        }

        if (array_key_exists('slug', $payload)) {
            $slug = is_scalar($payload['slug']) ? trim((string) $payload['slug']) : '';

            /*
             * The `pa_` prefix is stripped rather than refused, because
             * `GET /attributes` publishes the taxonomy as `pa_size` and §82's
             * filters accept it either way. Refusing the form the API itself
             * emits would be the round-trip failure this class exists to avoid.
             */
            $slug = preg_replace('/^pa_/', '', strtolower($slug)) ?? $slug;

            if ($slug === '') {
                $errors['slug'] = 'Must be a non-empty string, or omitted to derive it from the name.';
            } elseif (strlen($slug) > self::MAX_SLUG_BYTES) {
                $errors['slug'] = sprintf(
                    'Must be at most %d bytes once the "pa_" prefix is added — WordPress caps a taxonomy name at 32.',
                    self::MAX_SLUG_BYTES
                );
            } else {
                $clean['slug'] = $slug;
            }
        }

        if (array_key_exists('type', $payload)) {
            $type = is_scalar($payload['type']) ? trim((string) $payload['type']) : '';

            /*
             * Shape only. Which types exist is `wc_get_attribute_types()`, a
             * filtered list a plugin can extend, so the vocabulary check is the
             * service's — the platform's answer, second gate. §87's username
             * split, again.
             */
            if ($type === '') {
                $errors['type'] = 'Must be a non-empty string.';
            } else {
                $clean['type'] = $type;
            }
        }

        if (array_key_exists('order_by', $payload)) {
            $orderBy = is_scalar($payload['order_by']) ? trim((string) $payload['order_by']) : '';

            if (!in_array($orderBy, self::ORDER_BY, true)) {
                $errors['order_by'] = 'Must be one of: ' . implode(', ', self::ORDER_BY) . '.';
            } else {
                $clean['order_by'] = $orderBy;
            }
        }

        if (array_key_exists('has_archives', $payload)) {
            if (!is_bool($payload['has_archives'])) {
                $errors['has_archives'] = 'Must be a boolean.';
            } else {
                $clean['has_archives'] = $payload['has_archives'];
            }
        }

        return $clean;
    }
}
