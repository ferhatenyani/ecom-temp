<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;

/**
 * Validates an FAQ category write payload — roadmap §89.
 *
 * Pure, and small because a term is small. The one decision worth stating is
 * that **`slug` is not refused and not incidental** — §88 settled that for
 * attribute terms, where a slug is what a saved filter matches. Here it is what
 * `GET /cms/faqs?category=…` matches and what a storefront's own FAQ anchors
 * are built from, so the same treatment applies: accepted, audited by value,
 * and reported back in `meta.slug_changed`.
 */
final class FaqCategoryInput
{
    /** Emitted on read, dropped on write. */
    private const READ_ONLY = ['id', 'count'];

    /** @var array<string, string> refused by name, with the reason */
    private const REFUSED = [
        'parent' => 'FAQ categories are flat — the taxonomy is registered non-hierarchical.',
        'taxonomy' => 'There is one FAQ taxonomy and this route is it.',
        'term_id' => 'The id is in the URL.',
    ];

    private const MAX_NAME = 200;
    private const MAX_SLUG = 64;
    private const MAX_DESCRIPTION = 2000;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['name', 'slug', 'description'];
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
            throw ApiException::invalidRequest('The FAQ category data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The FAQ category data is invalid.', ['fields' => $errors]);
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

        if (array_key_exists('name', $payload)) {
            $name = is_scalar($payload['name']) ? ContentHtml::sanitizeText(trim((string) $payload['name'])) : '';

            if ($name === '') {
                $errors['name'] = 'Must be a non-empty string.';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors['name'] = 'Must be at most ' . self::MAX_NAME . ' characters.';
            } else {
                $clean['name'] = $name;
            }
        }

        if (array_key_exists('slug', $payload)) {
            $slug = is_scalar($payload['slug']) ? strtolower(trim((string) $payload['slug'])) : '';

            if ($slug === '') {
                $errors['slug'] = 'Must be a non-empty string, or omitted to derive it from the name.';
            } elseif (preg_match('/^[a-z0-9\-_]+$/', $slug) !== 1) {
                $errors['slug'] = 'May contain a–z, 0–9, hyphens and underscores only.';
            } elseif (strlen($slug) > self::MAX_SLUG) {
                $errors['slug'] = 'Must be at most ' . self::MAX_SLUG . ' bytes.';
            } else {
                $clean['slug'] = $slug;
            }
        }

        if (array_key_exists('description', $payload)) {
            $description = $payload['description'];

            if ($description === null) {
                $clean['description'] = '';
            } elseif (!is_string($description)) {
                $errors['description'] = 'Must be a string.';
            } elseif (strlen($description) > self::MAX_DESCRIPTION) {
                $errors['description'] = 'Must be at most ' . self::MAX_DESCRIPTION . ' bytes.';
            } else {
                // A term description is rendered wherever the category is
                // listed, so it goes through the same allowlist as an answer.
                $clean['description'] = ContentHtml::sanitize($description);
            }
        }

        return $clean;
    }
}
