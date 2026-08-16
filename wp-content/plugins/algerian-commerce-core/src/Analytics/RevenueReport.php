<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

use AlgerianCommerce\Orders\OrderStatus;

/**
 * Financial reporting — docs/PLAN.md §28's list, and only the parts of it this
 * shop can answer honestly.
 *
 * Pure — no WordPress, no database — so the definitions a shop will run on are
 * testable without one.
 *
 * **Which orders count as revenue.** `pending`, `failed` and `cancelled` are
 * excluded: nothing was paid, nothing was committed, or it was called off.
 * That is WooCommerce Analytics' own default exclusion set, and matching the
 * convention a shop's other tools already use is worth more than a private
 * definition nobody else can reproduce.
 *
 * `refunded` is **included**, and it is the subtle one. A fully refunded order
 * made a sale and then gave it back, so it belongs in gross with its refund
 * subtracted, netting to zero. Excluding the order while still counting the
 * refund — the obvious-looking alternative — nets to *minus* the sale, and a
 * shop that refunded everything would report negative revenue it never took.
 *
 * `Customers\CustomerStatistics` counts `completed` alone and says why: for a
 * cash-on-delivery shop the money arrives when the parcel does. The two do not
 * contradict each other, they answer different questions — that one is what one
 * customer has *paid*, this one is what the shop has *booked* — and `collected`
 * below reports the narrower figure beside the wider one so nobody has to guess
 * which is on their screen.
 *
 * **Three of PLAN §28's lines are not reported at all**, and are named in
 * `unavailable()` rather than emitted as zero. A dashboard that renders
 * "Margin: 0.00 DZD" has told the shop something false; one that renders
 * nothing, with a reason, has not.
 */
final class RevenueReport
{
    /**
     * The statuses whose orders are revenue.
     *
     * @var list<string>
     */
    public const COUNTED_STATUSES = [
        OrderStatus::PROCESSING,
        OrderStatus::ON_HOLD,
        OrderStatus::COMPLETED,
        OrderStatus::REFUNDED,
    ];

    /**
     * The narrower figure: money a cash-on-delivery shop has actually taken.
     *
     * @var list<string>
     */
    public const COLLECTED_STATUSES = [OrderStatus::COMPLETED];

    /**
     * Every figure here describes **orders priced in `$currency`**, counts
     * included: this is the money report, and an order count beside a total it
     * did not contribute to is the kind of small lie a shop discovers a quarter
     * later. `/analytics/orders` reports the shop's whole activity, and
     * `excluded_currencies` is what explains any difference between the two.
     *
     * @param array{
     *     order_total: string,
     *     orders_placed: int,
     *     gross: string,
     *     orders_counted: int,
     *     collected: string,
     *     tax: string,
     *     shipping_revenue: string,
     *     discounts: string,
     *     refunds: string
     * } $sums exact decimal strings straight from SQL, and the counts beside them
     * @param array<string, int> $excludedCurrencies currency => order count
     * @return array<string, mixed>
     */
    public static function compute(
        array $sums,
        string $currency,
        int $decimals = 2,
        array $excludedCurrencies = []
    ): array {
        $scale = Metrics::scale($decimals);

        $gross = Metrics::toMinor($sums['gross'], $scale);
        $refunds = Metrics::toMinor($sums['refunds'], $scale);
        $counted = max(0, $sums['orders_counted']);

        $report = [
            'currency' => $currency,
            // Every order in the window whatever its status — the top of the
            // funnel, and deliberately not called revenue.
            'order_total' => Metrics::format(Metrics::toMinor($sums['order_total'], $scale), $scale, $decimals),
            'orders_placed' => max(0, $sums['orders_placed']),
            'orders_counted' => $counted,
            'gross' => Metrics::format($gross, $scale, $decimals),
            'discounts' => Metrics::format(Metrics::toMinor($sums['discounts'], $scale), $scale, $decimals),
            'shipping_revenue' => Metrics::format(Metrics::toMinor($sums['shipping_revenue'], $scale), $scale, $decimals),
            'tax' => Metrics::format(Metrics::toMinor($sums['tax'], $scale), $scale, $decimals),
            'refunds' => Metrics::format($refunds, $scale, $decimals),
            'net' => Metrics::format($gross - $refunds, $scale, $decimals),
            'collected' => Metrics::format(Metrics::toMinor($sums['collected'], $scale), $scale, $decimals),
            'average_order_value' => Metrics::format(Metrics::average($gross, $counted), $scale, $decimals),
            'unavailable' => self::unavailable(),
        ];

        /*
         * Orders taken in another currency are left out of every sum above,
         * never converted and never added in. Adding 890 orders in one currency
         * to 22 in another produces a number in no currency at all, and this is
         * not hypothetical: WooCommerce records the currency per order, so a
         * shop that ran on the default `USD` before anyone set `DZD` has both
         * in its order book forever. Silence about that is what makes the total
         * look right.
         */
        if ($excludedCurrencies !== []) {
            $report['excluded_currencies'] = $excludedCurrencies;
        }

        return $report;
    }

    /**
     * The lines of PLAN §28 this shop has no data for, each with the reason.
     *
     * Kept as prose rather than a bare list because the reason is the useful
     * half: "no margin" invites someone to add a cost field to a product and
     * expect this to start working, and it says here that the gap is a
     * decision about data, not an unimplemented sum.
     *
     * @return array<string, string>
     */
    public static function unavailable(): array
    {
        return [
            'shipping_cost' => 'What a courier charges the shop is not recorded. '
                . 'ac_shipments deliberately has no cost column, and shipping_revenue above '
                . 'is the separate figure of what the customer was charged.',
            'payment_fees' => 'Gateway fees are not summable across providers. '
                . 'ac_payment_transactions has no fee column by design; Chargily reports fees in '
                . 'per-transaction metadata, and a second gateway would shape them differently.',
            'margin' => 'No cost of goods exists. WooCommerce has no cost field, and PLAN §28 '
                . 'says to calculate profit only where reliable cost data exists.',
        ];
    }
}
