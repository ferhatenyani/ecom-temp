<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Customers\CustomerStatistics;
use PHPUnit\Framework\TestCase;

final class CustomerStatisticsTest extends TestCase
{
    /** @return list<array{id: int, status: string, total: string, date_created: ?string}> */
    private static function history(): array
    {
        return [
            ['id' => 1, 'status' => 'completed', 'total' => '1500.00', 'date_created' => '2026-01-05T10:00:00+00:00'],
            ['id' => 2, 'status' => 'cancelled', 'total' => '900.00', 'date_created' => '2026-02-05T10:00:00+00:00'],
            ['id' => 3, 'status' => 'completed', 'total' => '2500.50', 'date_created' => '2026-03-05T10:00:00+00:00'],
            ['id' => 4, 'status' => 'refunded', 'total' => '400.00', 'date_created' => '2026-04-05T10:00:00+00:00'],
            ['id' => 5, 'status' => 'processing', 'total' => '700.00', 'date_created' => '2026-05-05T10:00:00+00:00'],
        ];
    }

    public function testCountsByOutcome(): void
    {
        $stats = CustomerStatistics::compute(self::history());

        self::assertSame(5, $stats['total_orders']);
        self::assertSame(2, $stats['completed_orders']);
        self::assertSame(1, $stats['cancelled_orders']);
        // WooCommerce has no "returned" status; refunded is the money-back one.
        self::assertSame(1, $stats['returned_orders']);
    }

    public function testRevenueCountsCompletedOrdersOnly(): void
    {
        $stats = CustomerStatistics::compute(self::history());

        // 1500.00 + 2500.50. The processing order is stock committed, not money
        // collected, and the cancelled and refunded ones are neither.
        self::assertSame('4000.50', $stats['total_revenue']);
    }

    public function testAverageIsOverTheOrdersThatProducedTheRevenue(): void
    {
        $stats = CustomerStatistics::compute(self::history());

        // 4000.50 / 2, not / 5 — dividing by every order would understate what
        // a sale is worth.
        self::assertSame('2000.25', $stats['average_order_value']);
    }

    /**
     * The reason totals are summed in integer minor units.
     *
     * 0.1 + 0.2 is 0.30000000000000004 in binary floating point; a hundred
     * such lines drift far enough to disagree with the order list.
     */
    public function testMoneySumsExactly(): void
    {
        $orders = [];

        for ($i = 1; $i <= 100; $i++) {
            $orders[] = ['id' => $i, 'status' => 'completed', 'total' => '0.10', 'date_created' => null];
        }

        $stats = CustomerStatistics::compute($orders);

        self::assertSame('10.00', $stats['total_revenue']);
        self::assertSame('0.10', $stats['average_order_value']);
    }

    /** (int) truncates, so 12.30 * 100 = 1229.999… would become 12.29. */
    public function testAmountsAreRoundedNotTruncated(): void
    {
        $stats = CustomerStatistics::compute([
            ['id' => 1, 'status' => 'completed', 'total' => '12.30', 'date_created' => null],
        ]);

        self::assertSame('12.30', $stats['total_revenue']);
    }

    public function testFirstAndLastOrderComeFromTheEnds(): void
    {
        $stats = CustomerStatistics::compute(self::history());

        self::assertSame(1, $stats['first_order']['id']);
        self::assertSame(5, $stats['last_order']['id']);
        self::assertSame('2026-01-05T10:00:00+00:00', $stats['first_order']['date']);
    }

    public function testACustomerWithNoOrders(): void
    {
        $stats = CustomerStatistics::compute([]);

        self::assertSame(0, $stats['total_orders']);
        self::assertSame('0.00', $stats['total_revenue']);
        // Not a division by zero, and not null — a customer who has bought
        // nothing has an average of zero.
        self::assertSame('0.00', $stats['average_order_value']);
        self::assertNull($stats['first_order']);
        self::assertNull($stats['last_order']);
    }

    public function testASingleOrderIsBothFirstAndLast(): void
    {
        $stats = CustomerStatistics::compute([
            ['id' => 9, 'status' => 'completed', 'total' => '100.00', 'date_created' => null],
        ]);

        self::assertSame(9, $stats['first_order']['id']);
        self::assertSame(9, $stats['last_order']['id']);
    }

    public function testPrefixedStatusesAreNormalized(): void
    {
        $stats = CustomerStatistics::compute([
            ['id' => 1, 'status' => 'wc-completed', 'total' => '50.00', 'date_created' => null],
        ]);

        self::assertSame(1, $stats['completed_orders']);
        self::assertSame('50.00', $stats['total_revenue']);
    }

    /**
     * A status outside our vocabulary still counts toward the total, or
     * total_orders would disagree with the order list the client can see.
     */
    public function testAnUnknownStatusStillCountsAsAnOrder(): void
    {
        $stats = CustomerStatistics::compute([
            ['id' => 1, 'status' => 'checkout-draft', 'total' => '10.00', 'date_created' => null],
        ]);

        self::assertSame(1, $stats['total_orders']);
        self::assertSame(0, $stats['completed_orders']);
        self::assertArrayNotHasKey('checkout-draft', $stats['by_status']);
    }

    public function testByStatusCoversEveryKnownStatus(): void
    {
        $stats = CustomerStatistics::compute([]);

        self::assertSame(
            ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'],
            array_keys($stats['by_status'])
        );
        self::assertSame([0, 0, 0, 0, 0, 0, 0], array_values($stats['by_status']));
    }

    public function testAStoreWithNoDecimalsStillFormats(): void
    {
        $stats = CustomerStatistics::compute([
            ['id' => 1, 'status' => 'completed', 'total' => '1500', 'date_created' => null],
        ], 0);

        self::assertSame('1500', $stats['total_revenue']);
        self::assertSame('1500', $stats['average_order_value']);
    }

    public function testMoneyIsAlwaysAString(): void
    {
        $stats = CustomerStatistics::compute(self::history());

        self::assertIsString($stats['total_revenue']);
        self::assertIsString($stats['average_order_value']);
    }
}
