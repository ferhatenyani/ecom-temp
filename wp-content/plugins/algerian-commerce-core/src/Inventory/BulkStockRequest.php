<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\API\ApiException;

/**
 * Parses a bulk stock update payload.
 *
 * Pure, and deliberately shallow: it checks the shape of the *batch* — ids
 * present, no duplicates, within the cap — and leaves each adjustment to
 * StockAdjustment on the ordinary single-item path. Bulk therefore inherits
 * every rule rather than reimplementing a looser copy of them.
 *
 * A batch-level `reason` and `note` act as defaults so a stocktake does not
 * repeat itself on all hundred lines; an item may still override either.
 */
final class BulkStockRequest
{
    /**
     * Hard cap. A batch is one PHP request against one database connection —
     * an unbounded list is a timeout, a lock held far too long, and a partial
     * result nobody can reconstruct.
     */
    public const MAX_ITEMS = 100;

    private const FIELDS = ['reason', 'note', 'items'];

    private function __construct(
        /** @var list<array{id: int, payload: array<string, mixed>}> */
        public readonly array $items
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), self::FIELDS) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $defaults = array_filter(
            [
                'reason' => $payload['reason'] ?? null,
                'note' => $payload['note'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null
        );

        $items = self::items($payload, $defaults, $errors);

        if ($items === [] && !isset($errors['items'])) {
            $errors['items'] = 'At least one item is required.';
        }

        if (count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'A batch may contain at most ' . self::MAX_ITEMS . ' items.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The bulk stock request is invalid.', ['fields' => $errors]);
        }

        return new self($items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $defaults
     * @param array<string, string> $errors
     * @return list<array{id: int, payload: array<string, mixed>}>
     */
    private static function items(array $payload, array $defaults, array &$errors): array
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

            // Two adjustments for one product in a single batch make the
            // outcome depend on ordering, and the per-item result list would
            // carry two conflicting entries for the same id.
            if (isset($seen[$id])) {
                $errors["items[{$index}].id"] = "Duplicate id {$id} in the batch.";
                continue;
            }

            $seen[$id] = true;

            unset($item['id']);

            $items[] = ['id' => $id, 'payload' => [...$defaults, ...$item]];
        }

        return $items;
    }
}
