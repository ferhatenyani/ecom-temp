<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\COD\CodStatistics;
use AlgerianCommerce\COD\CodStatus;
use PHPUnit\Framework\TestCase;

final class CodStatisticsTest extends TestCase
{
    /** @param array<string, int> $byStatus */
    private static function counts(
        int $total,
        array $byStatus = [],
        int $everConfirmed = 0,
        int $delivered = 0,
        int $returned = 0
    ): array {
        return [
            'total' => $total,
            'by_status' => $byStatus,
            'ever_confirmed' => $everConfirmed,
            'delivered' => $delivered,
            'returned' => $returned,
        ];
    }

    public function testTheFunnelOfATypicalWeek(): void
    {
        $stats = CodStatistics::compute(self::counts(
            200,
            ['pending' => 20, 'confirmed' => 120, 'rejected' => 30, 'unreachable' => 10, 'cancelled' => 20],
            140,
            110,
            10
        ));

        self::assertSame(200, $stats['total_orders']);
        self::assertSame(140, $stats['confirmed_orders']);
        self::assertSame('0.7000', $stats['rates']['confirmation']);
        self::assertSame('0.1500', $stats['rates']['rejection']);
        self::assertSame('0.1000', $stats['rates']['cancellation']);
        self::assertSame('0.5500', $stats['rates']['delivery']);
        self::assertSame('0.0500', $stats['rates']['return']);
    }

    /**
     * Confirmation is counted from the confirmed_at stamp, so an order that was
     * confirmed and later cancelled appears in both rates. Both things happened.
     */
    public function testAnOrderConfirmedAndThenCancelledCountsInBoth(): void
    {
        $stats = CodStatistics::compute(self::counts(1, ['cancelled' => 1], 1));

        self::assertSame('1.0000', $stats['rates']['confirmation']);
        self::assertSame('1.0000', $stats['rates']['cancellation']);
        self::assertSame(0, $stats['by_status'][CodStatus::CONFIRMED]);
    }

    /** No orders is not a division; it is an empty shop. */
    public function testAnEmptyScopeProducesZeroesRatherThanAnError(): void
    {
        $stats = CodStatistics::compute(self::counts(0));

        self::assertSame(0, $stats['total_orders']);

        foreach ($stats['rates'] as $name => $rate) {
            self::assertSame('0.0000', $rate, "{$name} must be zero for an empty scope");
        }
    }

    /**
     * A client charting the funnel should not have to handle a bucket that
     * vanishes when it empties.
     */
    public function testEveryStatusBucketIsPresentAndInAFixedOrder(): void
    {
        $stats = CodStatistics::compute(self::counts(1, ['confirmed' => 1], 1));

        self::assertSame(CodStatus::ALL, array_keys($stats['by_status']));
        self::assertSame(0, $stats['by_status'][CodStatus::PENDING]);
    }

    /** A status the matrix does not know must not appear in the funnel. */
    public function testUnknownStatusBucketsAreIgnored(): void
    {
        $stats = CodStatistics::compute(self::counts(1, ['confirmed' => 1, 'delivered' => 7], 1));

        self::assertSame(CodStatus::ALL, array_keys($stats['by_status']));
    }

    public function testRatesAreFixedPointStringsSoNoFloatNoiseReachesAClient(): void
    {
        $stats = CodStatistics::compute(self::counts(3, ['confirmed' => 1], 1));

        self::assertIsString($stats['rates']['confirmation']);
        self::assertSame('0.3333', $stats['rates']['confirmation']);
    }

    /** The pending queue stays in the denominator, and the rate says so. */
    public function testPendingOrdersDepressEveryRate(): void
    {
        $stats = CodStatistics::compute(self::counts(10, ['pending' => 5, 'confirmed' => 5], 5));

        self::assertSame('0.5000', $stats['rates']['confirmation']);
        self::assertSame(5, $stats['by_status'][CodStatus::PENDING]);
    }

    /** A negative count is a broken query, not a negative rate. */
    public function testNegativeCountsAreFlooredRatherThanPropagated(): void
    {
        $stats = CodStatistics::compute(self::counts(-5, ['confirmed' => -1], -2, -3, -4));

        self::assertSame(0, $stats['total_orders']);
        self::assertSame(0, $stats['confirmed_orders']);
        self::assertSame(0, $stats['by_status'][CodStatus::CONFIRMED]);
        self::assertSame('0.0000', $stats['rates']['confirmation']);
    }
}
