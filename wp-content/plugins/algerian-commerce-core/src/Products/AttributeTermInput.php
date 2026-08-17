<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * Validates one attribute term — roadmap §88.
 *
 * Pure: no WordPress.
 *
 * A term is what `GET /products?attributes[pa_size]=m` actually matches, so the
 * **slug is a public identifier** rather than an implementation detail. It is
 * writable, because a slug is sometimes genuinely wrong, and it is treated as a
 * field of its own — see `AttributeService::updateTerm()` for why a rename is
 * reported rather than applied quietly.
 */
final class AttributeTermInput
{
    /** Emitted on read, dropped on write, so a read body PATCHes back. */
    private const READ_ONLY = ['id', 'count', 'taxonomy', 'attribute_id'];

    /** @var array<string, string> */
    private const REFUSED = [
        'term_id' => 'The id is in the URL.',
        'parent' => 'An attribute taxonomy is flat. WooCommerce registers it with hierarchical => false, so a parent would be stored and never read.',
        'products' => 'A term is attached to a product by writing the product, not by writing the term.',
    ];

    private const MAX_NAME = 200;
    private const MAX_SLUG = 200;
    private const MAX_DESCRIPTION = 2000;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['name', 'slug', 'description', 'menu_order'];
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
            $errors['name'] = 'Required.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The term data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The term data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $errors
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

        foreach (['name' => self::MAX_NAME, 'slug' => self::MAX_SLUG] as $field => $max) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = is_scalar($payload[$field]) ? trim((string) $payload[$field]) : '';

            if ($value === '') {
                $errors[$field] = 'Must be a non-empty string.';
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = "Must be at most {$max} characters.";
            } else {
                $clean[$field] = $value;
            }
        }

        if (array_key_exists('description', $payload)) {
            if ($payload['description'] === null) {
                $clean['description'] = '';
            } elseif (!is_scalar($payload['description'])) {
                $errors['description'] = 'Must be a string.';
            } elseif (mb_strlen(trim((string) $payload['description'])) > self::MAX_DESCRIPTION) {
                $errors['description'] = 'Must be at most ' . self::MAX_DESCRIPTION . ' characters.';
            } else {
                $clean['description'] = trim((string) $payload['description']);
            }
        }

        if (array_key_exists('menu_order', $payload)) {
            if (!is_numeric($payload['menu_order'])) {
                $errors['menu_order'] = 'Must be an integer.';
            } else {
                $clean['menu_order'] = (int) $payload['menu_order'];
            }
        }

        return $clean;
    }
}
