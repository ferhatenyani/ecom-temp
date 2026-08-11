<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Orders\OrderTimeline;
use PHPUnit\Framework\TestCase;

final class OrderTimelineTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function notes(): array
    {
        return [
            ['id' => 7, 'content' => 'Customer called back', 'customer_note' => false, 'added_by' => 'amina', 'created_at' => '2026-08-11 10:00:00'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function audit(): array
    {
        return [
            ['id' => 3, 'action' => 'order.status_changed', 'actor_login' => 'amina', 'created_at' => '2026-08-11 09:00:00', 'metadata' => ['from' => 'pending', 'to' => 'processing']],
            ['id' => 1, 'action' => 'order.created', 'actor_login' => 'amina', 'created_at' => '2026-08-11 08:00:00', 'metadata' => []],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function movements(): array
    {
        return [
            ['id' => 12, 'product_id' => 55, 'delta' => -2, 'quantity_before' => 50, 'quantity_after' => 48, 'reason' => 'order_reduced', 'created_at' => '2026-08-11 09:00:00'],
        ];
    }

    public function testMergesAllThreeSourcesNewestFirst(): void
    {
        $entries = OrderTimeline::merge(self::notes(), self::audit(), self::movements());

        self::assertCount(4, $entries);
        self::assertSame(
            ['note', 'stock', 'audit', 'audit'],
            array_column($entries, 'type')
        );
    }

    /**
     * A status change and the stock it moves land in the same second. Without a
     * tie-break the feed reorders itself between requests.
     */
    public function testEntriesInTheSameSecondHaveAStableOrder(): void
    {
        $first = OrderTimeline::merge(self::notes(), self::audit(), self::movements());
        $second = OrderTimeline::merge(self::notes(), array_reverse(self::audit()), self::movements());

        self::assertSame(array_column($first, 'summary'), array_column($second, 'summary'));
    }

    public function testTimesAreEmittedAsIso8601(): void
    {
        $entries = OrderTimeline::merge([], self::audit(), []);

        self::assertSame('2026-08-11T09:00:00+00:00', $entries[0]['at']);
    }

    /**
     * The three tables store 'Y-m-d H:i:s' in UTC. Sorting the formatted
     * strings instead of timestamps would interleave them wrongly.
     */
    public function testOrderingIsByRealTimeNotStringShape(): void
    {
        $entries = OrderTimeline::merge(
            [['id' => 1, 'content' => 'later note', 'customer_note' => false, 'added_by' => 'x', 'created_at' => '2026-08-11 23:00:00']],
            [['id' => 1, 'action' => 'order.created', 'actor_login' => 'x', 'created_at' => '2026-08-12 01:00:00', 'metadata' => []]],
            []
        );

        self::assertSame('audit', $entries[0]['type']);
        self::assertSame('note', $entries[1]['type']);
    }

    public function testNoteAddedAuditEntriesAreDroppedSoNotesAreNotShownTwice(): void
    {
        $entries = OrderTimeline::merge(
            self::notes(),
            [['id' => 9, 'action' => 'order.note_added', 'actor_login' => 'amina', 'created_at' => '2026-08-11 10:00:00', 'metadata' => ['note_id' => 7]]],
            []
        );

        self::assertCount(1, $entries);
        self::assertSame('note', $entries[0]['type']);
    }

    public function testStatusChangeReadsAsASentence(): void
    {
        $entries = OrderTimeline::merge([], self::audit(), []);

        self::assertSame('Status changed from pending to processing', $entries[0]['summary']);
        self::assertSame('Order created', $entries[1]['summary']);
    }

    public function testCancellationCarriesItsReason(): void
    {
        $entries = OrderTimeline::merge([], [
            ['id' => 4, 'action' => 'order.cancelled', 'actor_login' => 'x', 'created_at' => '2026-08-11 11:00:00', 'metadata' => ['reason' => 'Customer unreachable']],
        ], []);

        self::assertSame('Order cancelled — Customer unreachable', $entries[0]['summary']);
    }

    public function testCancellationWithoutAReasonStillReads(): void
    {
        $entries = OrderTimeline::merge([], [
            ['id' => 4, 'action' => 'order.cancelled', 'actor_login' => 'x', 'created_at' => '2026-08-11 11:00:00', 'metadata' => []],
        ], []);

        self::assertSame('Order cancelled', $entries[0]['summary']);
    }

    public function testUpdateNamesTheFieldsThatChanged(): void
    {
        $entries = OrderTimeline::merge([], [
            ['id' => 5, 'action' => 'order.updated', 'actor_login' => 'x', 'created_at' => '2026-08-11 11:00:00', 'metadata' => ['fields' => ['billing', 'customer_note']]],
        ], []);

        self::assertSame('Updated billing, customer_note', $entries[0]['summary']);
    }

    /**
     * An action nobody wrote a sentence for still appears, named. Dropping it
     * would hide exactly the unusual event a timeline is read to find.
     */
    public function testAnUnknownActionSurvivesUnderItsOwnName(): void
    {
        $entries = OrderTimeline::merge([], [
            ['id' => 6, 'action' => 'order.exported', 'actor_login' => 'x', 'created_at' => '2026-08-11 11:00:00', 'metadata' => []],
        ], []);

        self::assertCount(1, $entries);
        self::assertSame('order.exported', $entries[0]['summary']);
    }

    public function testStockEntriesReadInBothDirections(): void
    {
        $entries = OrderTimeline::merge([], [], [
            ['id' => 12, 'product_id' => 55, 'delta' => -2, 'quantity_before' => 50, 'quantity_after' => 48, 'reason' => 'order_reduced', 'created_at' => '2026-08-11 09:00:00'],
            ['id' => 13, 'product_id' => 55, 'delta' => 2, 'quantity_before' => 48, 'quantity_after' => 50, 'reason' => 'order_restored', 'created_at' => '2026-08-11 12:00:00'],
        ]);

        self::assertSame('Stock restored by 2 on product 55 (48 → 50)', $entries[0]['summary']);
        self::assertSame('Stock reduced by 2 on product 55 (50 → 48)', $entries[1]['summary']);
    }

    public function testTheLimitKeepsTheNewest(): void
    {
        $entries = OrderTimeline::merge(self::notes(), self::audit(), self::movements(), 2);

        self::assertCount(2, $entries);
        self::assertSame(['note', 'stock'], array_column($entries, 'type'));
    }

    public function testSortKeysDoNotLeakIntoTheWireFormat(): void
    {
        foreach (OrderTimeline::merge(self::notes(), self::audit(), self::movements()) as $entry) {
            self::assertSame(['type', 'at', 'actor', 'summary', 'data'], array_keys($entry));
        }
    }

    public function testAnEmptyOrderHasAnEmptyTimeline(): void
    {
        self::assertSame([], OrderTimeline::merge([], [], []));
    }

    /** A row with a broken date is still a thing that happened. */
    public function testAnUnparseableDateSortsLastRatherThanVanishing(): void
    {
        $entries = OrderTimeline::merge([], [
            ['id' => 1, 'action' => 'order.created', 'actor_login' => 'x', 'created_at' => 'not a date', 'metadata' => []],
            ['id' => 2, 'action' => 'order.updated', 'actor_login' => 'x', 'created_at' => '2026-08-11 09:00:00', 'metadata' => []],
        ], []);

        self::assertCount(2, $entries);
        self::assertSame('order.updated', $entries[0]['data']['action']);
    }

    public function testCustomerVisibilityIsCarriedThrough(): void
    {
        $entries = OrderTimeline::merge([
            ['id' => 8, 'content' => 'Your parcel is on its way', 'customer_note' => true, 'added_by' => 'amina', 'created_at' => '2026-08-11 10:00:00'],
        ], [], []);

        self::assertTrue($entries[0]['data']['customer_note']);
    }
}
