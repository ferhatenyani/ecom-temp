<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\BulkRequest;
use PHPUnit\Framework\TestCase;

final class BulkRequestTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function errors(array $payload): array
    {
        try {
            BulkRequest::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testParsesABulkUpdate(): void
    {
        $request = BulkRequest::fromPayload([
            'action' => 'update',
            'items' => [
                ['id' => 1, 'status' => 'draft'],
                ['id' => 2, 'regular_price' => '100'],
            ],
        ]);

        self::assertSame('update', $request->action);
        self::assertSame(2, $request->count());
        self::assertFalse($request->force);
    }

    public function testParsesABulkDelete(): void
    {
        $request = BulkRequest::fromPayload(['action' => 'delete', 'ids' => [4, '5'], 'force' => true]);

        self::assertSame('delete', $request->action);
        self::assertSame([['id' => 4], ['id' => 5]], $request->items);
        self::assertTrue($request->force);
    }

    public function testActionIsRequiredAndConstrained(): void
    {
        self::assertArrayHasKey('action', $this->errors(['items' => [['id' => 1]]]));
        self::assertArrayHasKey('action', $this->errors(['action' => 'destroy', 'ids' => [1]]));
    }

    public function testAnEmptyBatchIsRejected(): void
    {
        self::assertArrayHasKey('items', $this->errors(['action' => 'update', 'items' => []]));
        self::assertArrayHasKey('ids', $this->errors(['action' => 'delete', 'ids' => []]));
        self::assertArrayHasKey('items', $this->errors(['action' => 'update']));
    }

    public function testBatchSizeIsCapped(): void
    {
        // An unbounded batch is a timeout and a partial result nobody can
        // reconstruct.
        $ids = range(1, BulkRequest::MAX_ITEMS + 1);

        self::assertArrayHasKey('ids', $this->errors(['action' => 'delete', 'ids' => $ids]));

        $atLimit = BulkRequest::fromPayload([
            'action' => 'delete',
            'ids' => range(1, BulkRequest::MAX_ITEMS),
        ]);
        self::assertSame(BulkRequest::MAX_ITEMS, $atLimit->count());
    }

    public function testEveryItemNeedsAPositiveId(): void
    {
        self::assertArrayHasKey('items[0].id', $this->errors(['action' => 'update', 'items' => [['status' => 'draft']]]));
        self::assertArrayHasKey('items[0].id', $this->errors(['action' => 'update', 'items' => [['id' => 0]]]));
        self::assertArrayHasKey('ids[1]', $this->errors(['action' => 'delete', 'ids' => [1, -2]]));
    }

    public function testDuplicateIdsAreRejected(): void
    {
        // Two entries for one product make the outcome depend on ordering and
        // produce two conflicting per-item results.
        self::assertArrayHasKey(
            'items[1].id',
            $this->errors(['action' => 'update', 'items' => [['id' => 3], ['id' => 3]]])
        );

        self::assertArrayHasKey('ids[2]', $this->errors(['action' => 'delete', 'ids' => [1, 2, 1]]));
    }

    public function testRejectsUnknownFields(): void
    {
        self::assertArrayHasKey(
            'dry_run',
            $this->errors(['action' => 'delete', 'ids' => [1], 'dry_run' => true])
        );
    }

    public function testRejectsMalformedContainers(): void
    {
        self::assertArrayHasKey('items', $this->errors(['action' => 'update', 'items' => 'all']));
        self::assertArrayHasKey('ids', $this->errors(['action' => 'delete', 'ids' => 5]));
        self::assertArrayHasKey('items[0]', $this->errors(['action' => 'update', 'items' => ['nope']]));
    }

    public function testForceIsCoercedAndDefaultsToFalse(): void
    {
        self::assertFalse(BulkRequest::fromPayload(['action' => 'delete', 'ids' => [1]])->force);
        self::assertTrue(BulkRequest::fromPayload(['action' => 'delete', 'ids' => [1], 'force' => 'true'])->force);
        self::assertFalse(BulkRequest::fromPayload(['action' => 'delete', 'ids' => [1], 'force' => '0'])->force);
    }

    public function testItemFieldsAreLeftForTheSingleItemValidator(): void
    {
        // Bulk deliberately does not validate product fields itself; each
        // item goes through ProductInput so the rules cannot drift apart.
        $request = BulkRequest::fromPayload([
            'action' => 'update',
            'items' => [['id' => 1, 'regular_price' => 'not-a-number']],
        ]);

        self::assertSame('not-a-number', $request->items[0]['regular_price']);
    }
}
