<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Inventory\BulkStockRequest;
use PHPUnit\Framework\TestCase;

final class BulkStockRequestTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function reject(array $payload): ApiException
    {
        try {
            BulkStockRequest::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception;
        }

        self::fail('Expected the bulk request to be rejected.');
    }

    public function testParsesABatch(): void
    {
        $request = BulkStockRequest::fromPayload([
            'items' => [
                ['id' => 1, 'mode' => 'set', 'quantity' => 10, 'reason' => 'correction'],
                ['id' => 2, 'mode' => 'increase', 'quantity' => 5, 'reason' => 'restock'],
            ],
        ]);

        self::assertSame(2, $request->count());
        self::assertSame(1, $request->items[0]['id']);
        self::assertSame(['mode' => 'set', 'quantity' => 10, 'reason' => 'correction'], $request->items[0]['payload']);
    }

    public function testTheIdIsNotPassedThroughAsAnAdjustmentField(): void
    {
        // StockAdjustment rejects unknown fields, so leaving `id` in the item
        // payload would fail every single line of every batch.
        $request = BulkStockRequest::fromPayload([
            'items' => [['id' => 1, 'mode' => 'set', 'quantity' => 10, 'reason' => 'correction']],
        ]);

        self::assertArrayNotHasKey('id', $request->items[0]['payload']);
    }

    public function testBatchReasonAndNoteBecomeItemDefaults(): void
    {
        $request = BulkStockRequest::fromPayload([
            'reason' => 'correction',
            'note' => 'stocktake',
            'items' => [['id' => 1, 'mode' => 'set', 'quantity' => 10]],
        ]);

        self::assertSame(
            ['reason' => 'correction', 'note' => 'stocktake', 'mode' => 'set', 'quantity' => 10],
            $request->items[0]['payload']
        );
    }

    public function testAnItemOverridesTheBatchDefaults(): void
    {
        $request = BulkStockRequest::fromPayload([
            'reason' => 'correction',
            'items' => [['id' => 1, 'mode' => 'decrease', 'quantity' => 2, 'reason' => 'damage']],
        ]);

        self::assertSame('damage', $request->items[0]['payload']['reason']);
    }

    public function testRejectsUnknownTopLevelFields(): void
    {
        $exception = $this->reject([
            'action' => 'update',
            'items' => [['id' => 1, 'mode' => 'set', 'quantity' => 1]],
        ]);

        self::assertSame('Unknown field.', $exception->details()['fields']['action']);
    }

    public function testRejectsAnEmptyBatch(): void
    {
        self::assertArrayHasKey('items', $this->reject(['items' => []])->details()['fields']);
        self::assertArrayHasKey('items', $this->reject([])->details()['fields']);
    }

    public function testRejectsItemsThatAreNotAList(): void
    {
        self::assertArrayHasKey('items', $this->reject(['items' => 'nope'])->details()['fields']);
    }

    public function testRejectsAnItemWithoutAPositiveId(): void
    {
        $fields = $this->reject(['items' => [['mode' => 'set', 'quantity' => 1]]])->details()['fields'];

        self::assertArrayHasKey('items[0].id', $fields);
    }

    /**
     * Two adjustments for one product make the outcome depend on ordering, and
     * the per-item result list would carry two conflicting entries for one id.
     */
    public function testRejectsDuplicateIdsInOneBatch(): void
    {
        $fields = $this->reject([
            'items' => [
                ['id' => 4, 'mode' => 'set', 'quantity' => 1],
                ['id' => 4, 'mode' => 'set', 'quantity' => 9],
            ],
        ])->details()['fields'];

        self::assertSame('Duplicate id 4 in the batch.', $fields['items[1].id']);
    }

    public function testRejectsABatchOverTheCap(): void
    {
        $items = [];

        for ($id = 1; $id <= BulkStockRequest::MAX_ITEMS + 1; $id++) {
            $items[] = ['id' => $id, 'mode' => 'set', 'quantity' => 1];
        }

        $fields = $this->reject(['items' => $items])->details()['fields'];

        self::assertSame(
            'A batch may contain at most ' . BulkStockRequest::MAX_ITEMS . ' items.',
            $fields['items']
        );
    }

    public function testAcceptsABatchAtExactlyTheCap(): void
    {
        $items = [];

        for ($id = 1; $id <= BulkStockRequest::MAX_ITEMS; $id++) {
            $items[] = ['id' => $id, 'mode' => 'set', 'quantity' => 1];
        }

        self::assertSame(BulkStockRequest::MAX_ITEMS, BulkStockRequest::fromPayload(['items' => $items])->count());
    }

    /**
     * The adjustment itself is not validated here — that happens on the
     * single-item path, so bulk inherits every rule instead of a looser copy.
     */
    public function testDoesNotValidateTheAdjustmentShape(): void
    {
        $request = BulkStockRequest::fromPayload(['items' => [['id' => 1, 'mode' => 'nonsense']]]);

        self::assertSame(['mode' => 'nonsense'], $request->items[0]['payload']);
    }
}
