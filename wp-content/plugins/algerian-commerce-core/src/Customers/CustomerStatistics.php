<?php

declare(strict_types=1);

namespace AlgerianCommerce\Customers;

use AlgerianCommerce\Orders\OrderStatus;

/**
 * Customer statistics — docs/PLAN.md §9.
 *
 * Pure — no WordPress, no database — so the arithmetic that decides what a
 * customer is worth is testable without a shop.
 *
 * **Money is summed in integer minor units**, not as floats. Adding `1234.56`
 * to itself a few hundred times in binary floating point drifts, and a lifetime
 * value that disagrees with the sum of its own orders is worse than no figure
 * at all. Totals arrive as decimal strings, are scaled to whole units, added as
 * integers, and formatted back at the end.
 *
 * `total_revenue` counts **completed orders only**. For a cash-on-delivery shop
 * the money arrives when the parcel does; an order sitting in `processing` is
 * stock committed and a courier booked, not revenue. §60's analytics may want a
 * second, wider definition for forecasting — this one is what has actually been
 * collected.
 */
final class CustomerStatistics
{
    /**
     * WooCommerce has no `returned` status. `refunded` is the state where the
     * money went back, which is what PLAN.md §9's "returned orders" is counting.
     */
    private const RETURNED = OrderStatus::REFUNDED;

    /**
     * @param list<array{id: int, status: string, total: string, date_created: ?string}> $orders
     *        every order the customer has placed, oldest first
     * @return array<string, mixed>
     */
    public static function compute(array $orders, int $decimals = 2): array
    {
        $scale = 10 ** max(0, $decimals);

        $byStatus = array_fill_keys(OrderStatus::ALL, 0);
        $revenueMinor = 0;
        $completed = 0;

        foreach ($orders as $order) {
            $status = OrderStatus::normalize($order['status']);

            // A status outside our vocabulary — a plugin's own, or a
            // checkout draft — still counts toward the total. Dropping it
            // would make total_orders disagree with the order list.
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status]++;
            }

            if ($status === OrderStatus::COMPLETED) {
                $completed++;
                $revenueMinor += self::toMinor($order['total'], $scale);
            }
        }

        return [
            'total_orders' => count($orders),
            'completed_orders' => $completed,
            'cancelled_orders' => $byStatus[OrderStatus::CANCELLED],
            'returned_orders' => $byStatus[self::RETURNED],
            'total_revenue' => self::format($revenueMinor, $scale, $decimals),
            // Averaged over the orders that produced the revenue, not over
            // every order — dividing collected money by a count that includes
            // cancellations understates what a sale is worth.
            'average_order_value' => self::format(
                $completed > 0 ? intdiv($revenueMinor, $completed) : 0,
                $scale,
                $decimals
            ),
            'first_order' => self::edge($orders, false),
            'last_order' => self::edge($orders, true),
            'by_status' => $byStatus,
        ];
    }

    /**
     * A decimal string to whole minor units.
     *
     * round() before the cast, because (int) truncates: 12.3 * 100 is
     * 1229.9999… in binary floating point, and truncating turns 12.30 into
     * 12.29.
     */
    private static function toMinor(string $total, int $scale): int
    {
        return (int) round(((float) $total) * $scale);
    }

    private static function format(int $minor, int $scale, int $decimals): string
    {
        return number_format($minor / $scale, max(0, $decimals), '.', '');
    }

    /**
     * @param list<array{id: int, status: string, total: string, date_created: ?string}> $orders
     * @return array<string, mixed>|null
     */
    private static function edge(array $orders, bool $last): ?array
    {
        if ($orders === []) {
            return null;
        }

        // The caller supplies them oldest first, so the ends are the answer —
        // no second sort, and no chance of disagreeing with the order list.
        $order = $last ? $orders[count($orders) - 1] : $orders[0];

        return [
            'id' => $order['id'],
            'date' => $order['date_created'],
            'status' => OrderStatus::normalize($order['status']),
            'total' => $order['total'],
        ];
    }
}
