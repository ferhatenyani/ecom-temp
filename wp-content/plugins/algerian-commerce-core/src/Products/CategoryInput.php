<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * One product category from a create or update payload.
 *
 * `fromPayload()` requires `name`. `fromPatch()` allows any subset — only the
 * keys the caller sent are considered present, so a client can PATCH the
 * description without accidentally clearing the parent.
 *
 * Pure: no WordPress. Slug generation and parent-exists checks are the
 * controller's job because only that layer needs the taxonomy.
 */
final class CategoryInput
{
    /** @var array<string, true> keys the caller actually sent */
    private array $present;

    private function __construct(
        public readonly string $name,
        public readonly ?string $slug,
        public readonly ?int $parent,
        public readonly string $description,
        array $present
    ) {
        $this->present = $present;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'parent' => $this->parent,
            'description' => $this->description,
        ];
    }

    public function has(string $field): bool
    {
        return isset($this->present[$field]);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        $name = isset($payload['name']) && is_scalar($payload['name']) ? trim((string) $payload['name']) : '';

        if ($name === '') {
            $errors['name'] = 'A name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'Must be 200 characters or fewer.';
        }

        $slug = self::slug($payload['slug'] ?? null, $errors);
        $parent = self::parent($payload['parent'] ?? null, $errors);
        $description = self::description($payload['description'] ?? null, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The category data is invalid.', ['fields' => $errors]);
        }

        return new self(
            $name,
            $slug,
            $parent,
            $description ?? '',
            self::mark($payload, ['name', 'slug', 'parent', 'description'])
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException
     */
    public static function fromPatch(array $payload): self
    {
        $errors = [];

        $name = '';
        if (array_key_exists('name', $payload)) {
            $name = is_scalar($payload['name']) ? trim((string) $payload['name']) : '';

            if ($name === '') {
                $errors['name'] = 'Cannot be empty.';
            } elseif (mb_strlen($name) > 200) {
                $errors['name'] = 'Must be 200 characters or fewer.';
            }
        }

        $slug = array_key_exists('slug', $payload) ? self::slug($payload['slug'], $errors) : null;
        $parent = array_key_exists('parent', $payload) ? self::parent($payload['parent'], $errors) : null;
        $description = array_key_exists('description', $payload) ? (self::description($payload['description'], $errors) ?? '') : '';

        if ($errors !== []) {
            throw ApiException::invalidRequest('The category data is invalid.', ['fields' => $errors]);
        }

        return new self(
            $name,
            $slug,
            $parent,
            $description,
            self::mark($payload, ['name', 'slug', 'parent', 'description'])
        );
    }

    /** @param array<string, string> $errors */
    private static function slug(mixed $raw, array &$errors): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_scalar($raw)) {
            $errors['slug'] = 'Must be a string.';

            return null;
        }

        $slug = trim((string) $raw);

        if ($slug === '') {
            return null;
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors['slug'] = 'Must be a-z, 0-9 and hyphens only.';

            return null;
        }

        if (mb_strlen($slug) > 200) {
            $errors['slug'] = 'Must be 200 characters or fewer.';

            return null;
        }

        return $slug;
    }

    /** @param array<string, string> $errors */
    private static function parent(mixed $raw, array &$errors): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw) || (int) $raw < 0) {
            $errors['parent'] = 'Must be a non-negative integer.';

            return null;
        }

        return (int) $raw;
    }

    /** @param array<string, string> $errors */
    private static function description(mixed $raw, array &$errors): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_scalar($raw)) {
            $errors['description'] = 'Must be a string.';

            return null;
        }

        $description = (string) $raw;

        if (mb_strlen($description) > 5000) {
            $errors['description'] = 'Must be 5000 characters or fewer.';

            return null;
        }

        return $description;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, true>
     */
    private static function mark(array $payload, array $fields): array
    {
        $present = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $present[$field] = true;
            }
        }

        return $present;
    }
}
