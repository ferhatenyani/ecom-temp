<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * Parses a bulk operation payload.
 *
 * Pure. The batch shape is validated here; each item is then validated again
 * by the ordinary single-item path, so bulk inherits every product rule
 * rather than reimplementing a looser copy of them.
 */
final class BulkRequest
{
    public const ACTIONS = ['update', 'delete'];

    /**
     * Hard cap. A batch is one PHP request against one database connection —
     * an unbounded list is a timeout, a lock held far too long, and a partial
     * result nobody can reconstruct.
     */
    public const MAX_ITEMS = 100;

    private function __construct(
        public readonly string $action,
        /** @var list<array<string, mixed>> update: {id, ...fields}; delete: {id} */
        public readonly array $items,
        public readonly bool $force
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), ['action', 'items', 'ids', 'force']) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $action = is_scalar($payload['action'] ?? null) ? (string) $payload['action'] : '';

        if (!in_array($action, self::ACTIONS, true)) {
            $errors['action'] = 'Must be one of: ' . implode(', ', self::ACTIONS) . '.';
        }

        $items = $action === 'delete'
            ? self::deleteItems($payload, $errors)
            : self::updateItems($payload, $errors);

        if ($items === [] && !isset($errors['items']) && !isset($errors['ids'])) {
            $errors[$action === 'delete' ? 'ids' : 'items'] = 'At least one item is required.';
        }

        if (count($items) > self::MAX_ITEMS) {
            $errors[$action === 'delete' ? 'ids' : 'items'] =
                'A batch may contain at most ' . self::MAX_ITEMS . ' items.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The bulk request is invalid.', ['fields' => $errors]);
        }

        return new self(
            $action,
            $items,
            filter_var($payload['force'] ?? false, FILTER_VALIDATE_BOOL)
        );
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     * @return list<array<string, mixed>>
     */
    private static function updateItems(array $payload, array &$errors): array
    {
        if (!array_key_exists('items', $payload)) {
            return [];
        }

        if (!is_array($payload['items'])) {
            $errors['items'] = 'Must be an array of objects.';

            return [];
        }

        $items = [];
        $seen = [];

        foreach (array_values($payload['items']) as $index => $item) {
            if (!is_array($item)) {
                $errors["items[{$index}]"] = 'Must be an object.';
                continue;
            }

            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : 0;

            if ($id <= 0) {
                $errors["items[{$index}].id"] = 'A positive product id is required.';
                continue;
            }

            // The same id twice makes the outcome depend on ordering, and the
            // per-item result list would carry two conflicting entries.
            if (isset($seen[$id])) {
                $errors["items[{$index}].id"] = "Duplicate id {$id} in the batch.";
                continue;
            }

            $seen[$id] = true;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     * @return list<array<string, mixed>>
     */
    private static function deleteItems(array $payload, array &$errors): array
    {
        if (!array_key_exists('ids', $payload)) {
            return [];
        }

        if (!is_array($payload['ids'])) {
            $errors['ids'] = 'Must be an array of product ids.';

            return [];
        }

        $items = [];
        $seen = [];

        foreach (array_values($payload['ids']) as $index => $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                $errors["ids[{$index}]"] = 'Must be a positive product id.';
                continue;
            }

            $id = (int) $id;

            if (isset($seen[$id])) {
                $errors["ids[{$index}]"] = "Duplicate id {$id} in the batch.";
                continue;
            }

            $seen[$id] = true;
            $items[] = ['id' => $id];
        }

        return $items;
    }
}
