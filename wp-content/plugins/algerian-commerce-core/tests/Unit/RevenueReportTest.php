<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Analytics\RevenueReport;
use AlgerianCommerce\Orders\OrderStatus;
use PHPUnit\Framework\TestCase;

final class RevenueReportTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function sums(array $overrides = []): array
    {
        return $overrides + [
            'order_total' => '0',
            'orders_placed' => 0,
            'gross' => '0',
            'orders_counted' => 0,
            'collected' => '0',
            'tax' => '0',
            'shipping_revenue' => '0',
            'discounts' => '0',
            'refunds' => '0',
        ];
    }

    public function testATypicalMonthReportsEveryLinePlanTwentyEightAsksFor(): void
    {
        $report = RevenueReport::compute(self::sums([
            'order_total' => '520000.00',
            'orders_placed' => 260,
            'gross' => '400000.00',
            'orders_counted' => 200,
            'collected' => '310000.00',
            'tax' => '0.00',
            'shipping_revenue' => '60000.00',
            'discounts' => '15000.00',
            'refunds' => '25000.00',
        ]), 'DZD');

        self::assertSame('DZD', $report['currency']);
        self::assertSame('400000.00', $report['gross']);
        self::assertSame('25000.00', $report['refunds']);
        self::assertSame('375000.00', $report['net']);
        self::assertSame('310000.00', $report['collected']);
        self::assertSame('60000.00', $report['shipping_revenue']);
        self::assertSame('15000.00', $report['discounts']);
        self::assertSame('2000.00', $report['average_order_value']);
    }

    /**
     * The whole reason `refunded` is a counted status. A fully refunded order
     * belongs in gross *with* its refund subtracted, netting to zero; leaving
     * the order out while still counting the refund nets to minus the sale, and
     * a shop that refunded everything would report revenue it never took, in
     * the negative.
     */
    public function testAFullyRefundedOrderNetsToZeroRatherThanToMinusTheSale(): void
    {
        $report = RevenueReport::compute(self::sums([
            'order_total' => '1000.00',
            'orders_placed' => 1,
            'gross' => '1000.00',
            'orders_counted' => 1,
            'refunds' => '1000.00',
        ]), 'DZD');

        self::assertSame('1000.00', $report['gross']);
        self::assertSame('0.00', $report['net']);
    }

    public function testRefundedIsCountedAsRevenueAndCancelledIsNot(): void
    {
        self::assertContains(OrderStatus::REFUNDED, RevenueReport::COUNTED_STATUSES);
        self::assertContains(OrderStatus::PROCESSING, RevenueReport::COUNTED_STATUSES);
        self::assertContains(OrderStatus::ON_HOLD, RevenueReport::COUNTED_STATUSES);
        self::assertContains(OrderStatus::COMPLETED, RevenueReport::COUNTED_STATUSES);

        self::assertNotContains(OrderStatus::CANCELLED, RevenueReport::COUNTED_STATUSES);
        self::assertNotContains(OrderStatus::PENDING, RevenueReport::COUNTED_STATUSES);
        self::assertNotContains(OrderStatus::FAILED, RevenueReport::COUNTED_STATUSES);
    }

    /**
     * `collected` is the cash-on-delivery figure — money in hand — and agrees
     * with what CustomerStatistics counts for one customer.
     */
    public function testCollectedCountsCompletedOrdersOnly(): void
    {
        self::assertSame([OrderStatus::COMPLETED], RevenueReport::COLLECTED_STATUSES);
    }

    public function testTheTopLineIsEveryOrderAndIsNotCalledRevenue(): void
    {
        $report = RevenueReport::compute(self::sums([
            'order_total' => '900.00',
            'orders_placed' => 9,
            'gross' => '400.00',
            'orders_counted' => 4,
        ]), 'DZD');

        self::assertSame('900.00', $report['order_total']);
        self::assertSame(9, $report['orders_placed']);
        self::assertSame(4, $report['orders_counted']);
        self::assertArrayNotHasKey('revenue', $report);
    }

    public function testAnEmptyWindowIsZeroesRatherThanADivisionByZero(): void
    {
        $report = RevenueReport::compute(self::sums(), 'DZD');

        self::assertSame('0.00', $report['gross']);
        self::assertSame('0.00', $report['net']);
        self::assertSame('0.00', $report['average_order_value']);
    }

    /**
     * PLAN §28 lists ten figures and this shop has data for seven. The other
     * three are named with a reason rather than emitted as zero — a dashboard
     * rendering "Margin: 0.00 DZD" has told the shop something false.
     */
    public function testTheThreeFiguresThisShopCannotComputeAreNamedNotZeroed(): void
    {
        $report = RevenueReport::compute(self::sums(), 'DZD');

        self::assertSame(
            ['shipping_cost', 'payment_fees', 'margin'],
            array_keys($report['unavailable'])
        );

        foreach ($report['unavailable'] as $field => $reason) {
            self::assertNotSame('', $reason, "{$field} must carry a reason");
            self::assertArrayNotHasKey($field, $report, "{$field} must not also be reported as a number");
        }
    }

    public function testMarginSaysThereIsNoCostOfGoods(): void
    {
        self::assertStringContainsString('cost', RevenueReport::unavailable()['margin']);
    }

    /**
     * Not hypothetical: WooCommerce records the currency per order, so a shop
     * that traded on the default USD before anyone set DZD has both in its
     * order book forever. Silence about that is what makes the total look right.
     */
    public function testOrdersInAnotherCurrencyAreReportedRatherThanAddedIn(): void
    {
        $report = RevenueReport::compute(
            self::sums(['gross' => '48200.00', 'orders_counted' => 22]),
            'DZD',
            2,
            ['USD' => 890]
        );

        self::assertSame('48200.00', $report['gross']);
        self::assertSame(['USD' => 890], $report['excluded_currencies']);
    }

    public function testASingleCurrencyShopIsNotToldAboutAnExclusionThatDidNotHappen(): void
    {
        $report = RevenueReport::compute(self::sums(), 'DZD');

        self::assertArrayNotHasKey('excluded_currencies', $report);
    }

    public function testAZeroDecimalCurrencyIsFormattedWithoutADecimalPoint(): void
    {
        $report = RevenueReport::compute(self::sums(['gross' => '4200', 'orders_counted' => 2]), 'DZD', 0);

        self::assertSame('4200', $report['gross']);
        self::assertSame('2100', $report['average_order_value']);
    }
}
