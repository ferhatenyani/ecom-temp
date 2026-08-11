<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

/**
 * The COD funnel — roadmap §52's "Track: confirmation rate, cancellation rate,
 * delivery rate, return rate", docs/PLAN.md §12.
 *
 * Pure — no WordPress, no database — so the arithmetic that a shop will use to
 * judge its customers is testable without a shop. It takes counts, not orders:
 * the repository answers each count with a `COUNT(*)`, so nothing here depends
 * on how many orders the store has, and the "never scan the order book" rule
 * that §60's analytics aggregates exist to enforce is not broken on the way.
 *
 * **Every rate shares one denominator: every COD order in scope.** Rates with
 * different denominators cannot be compared or added, and the tempting
 * alternative — dividing by "orders that reached a decision" — silently changes
 * meaning as the pending queue drains. The consequence is worth stating
 * plainly: a window that includes today's orders is depressed by the ones
 * nobody has called yet, and the honest way to read it is next to `pending`.
 *
 * **Confirmation counts orders that were ever confirmed**, from the
 * `confirmed_at` stamp rather than the current status, so an order that was
 * confirmed and later cancelled counts in both the confirmation rate and the
 * cancellation rate. That is not double-counting; both things happened, and a
 * shop that only counted the cancellation would conclude its confirmation
 * process was failing when what failed came after it.
 *
 * Rates are emitted as fixed-point strings alongside the raw counts they came
 * from. The counts are the fact; the rate is a convenience, and a client that
 * wants a different denominator can build one without asking us.
 */
final class CodStatistics
{
    /** Enough to distinguish 12.34% from 12.35% and no more. */
    private const DECIMALS = 4;

    /**
     * @param array{total: int, by_status: array<string, int>, ever_confirmed: int, delivered: int, returned: int} $counts
     * @return array<string, mixed>
     */
    public static function compute(array $counts): array
    {
        $total = max(0, $counts['total']);

        // Every status present, in a fixed order, whether or not the shop has
        // an order in it — a client charting the funnel should not have to
        // handle a bucket that vanishes when it empties.
        $byStatus = array_fill_keys(CodStatus::ALL, 0);

        foreach ($counts['by_status'] as $status => $count) {
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] = max(0, (int) $count);
            }
        }

        $confirmed = max(0, $counts['ever_confirmed']);
        $delivered = max(0, $counts['delivered']);
        $returned = max(0, $counts['returned']);

        return [
            'total_orders' => $total,
            'by_status' => $byStatus,
            'confirmed_orders' => $confirmed,
            'delivered_orders' => $delivered,
            'returned_orders' => $returned,
            'rates' => [
                'confirmation' => self::rate($confirmed, $total),
                'rejection' => self::rate($byStatus[CodStatus::REJECTED], $total),
                'cancellation' => self::rate($byStatus[CodStatus::CANCELLED], $total),
                /*
                 * Delivery and return are read from the *order* status —
                 * `completed` and `refunded` — because that is the only record
                 * of an outcome that exists today. The courier's own verdict
                 * arrives with the shipping providers in §53, and these two
                 * rates should be re-derived from it then; until then they
                 * describe what the shop recorded, not what the driver saw.
                 */
                'delivery' => self::rate($delivered, $total),
                'return' => self::rate($returned, $total),
            ],
        ];
    }

    /**
     * A fixed-point string in 0…1.
     *
     * No orders means no rate, and 0 is the only honest answer that does not
     * require the caller to handle a null — `total_orders` is right there to
     * say the difference between "nobody cancelled" and "there was nobody".
     */
    private static function rate(int $part, int $total): string
    {
        if ($total <= 0) {
            return number_format(0, self::DECIMALS, '.', '');
        }

        return number_format($part / $total, self::DECIMALS, '.', '');
    }
}
