<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Analytics\Metrics;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    public function testARateIsAFixedPointStringSoNoFloatNoiseReachesAClient(): void
    {
        self::assertSame('0.3333', Metrics::rate(1, 3));
        self::assertSame('1.0000', Metrics::rate(5, 5));
        self::assertSame('0.0000', Metrics::rate(0, 12));
    }

    /** No denominator is an empty shop, not a division by zero. */
    public function testAnEmptyDenominatorIsZeroRatherThanAnError(): void
    {
        self::assertSame('0.0000', Metrics::rate(3, 0));
        self::assertSame('0.0000', Metrics::rate(0, -1));
    }

    /**
     * The reason money is never added as a float: 12.3 * 100 is 1229.9999… in
     * binary floating point, and truncating turns 12.30 into 12.29.
     */
    public function testAmountsScaleToMinorUnitsWithoutLosingACentime(): void
    {
        self::assertSame(1230, Metrics::toMinor('12.30', 100));
        self::assertSame(1230, Metrics::toMinor('12.3', 100));
        self::assertSame(-450, Metrics::toMinor('-4.50', 100));
        self::assertSame(0, Metrics::toMinor('', 100));
    }

    public function testSummingInMinorUnitsDoesNotDriftOverManyOrders(): void
    {
        $scale = Metrics::scale(2);
        $total = 0;

        for ($i = 0; $i < 1000; $i++) {
            $total += Metrics::toMinor('1234.56', $scale);
        }

        self::assertSame('1234560.00', Metrics::format($total, $scale, 2));
    }

    public function testAScaleFollowsTheStoresDecimalPlaces(): void
    {
        self::assertSame(100, Metrics::scale(2));
        self::assertSame(1, Metrics::scale(0));
        self::assertSame(1, Metrics::scale(-3));
    }

    public function testAmountsAreFormattedWithoutThousandSeparators(): void
    {
        self::assertSame('1234560.00', Metrics::format(123456000, 100, 2));
        self::assertSame('0.00', Metrics::format(0, 100, 2));
    }

    /**
     * An average that rounds up multiplies back to more money than the shop
     * took, so it truncates.
     */
    public function testAnAverageTruncatesRatherThanRoundsUp(): void
    {
        self::assertSame(333, Metrics::average(1000, 3));
        self::assertSame(1000, Metrics::average(3000, 3));
    }

    public function testAnAverageOfNothingIsZeroRatherThanADivisionByZero(): void
    {
        self::assertSame(0, Metrics::average(1000, 0));
        self::assertSame(0, Metrics::average(1000, -2));
    }
}
