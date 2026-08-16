<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

/**
 * The arithmetic every analytics figure is built from.
 *
 * Pure — no WordPress, no database — so the sums a shop will make decisions
 * about are testable without a shop, exactly as `Customers\CustomerStatistics`
 * and `COD\CodStatistics` are.
 *
 * **Money is added as integer minor units**, never as floats, for the reason
 * `CustomerStatistics` already records: adding `1234.56` to itself a few hundred
 * times in binary floating point drifts, and a revenue figure that disagrees
 * with the sum of its own orders is worse than no figure at all. The sums that
 * arrive from SQL are already exact — MySQL's `DECIMAL` arithmetic is — so the
 * only conversion is a single scale on the way in and a format on the way out,
 * and anything this class does *between* two sums (net, average) happens in
 * integers.
 *
 * bcmath would be the other way to do it and is deliberately not used: it is a
 * PHP extension, the two images in this stack are built differently, and §61
 * settled that a property which depends on which PHP process ran is not a
 * property. Integers need nothing installed.
 */
final class Metrics
{
    /** Enough to distinguish 12.34% from 12.35% and no more — CodStatistics. */
    public const RATE_DECIMALS = 4;

    private function __construct()
    {
    }

    /**
     * A fixed-point string in 0…1.
     *
     * No denominator means no rate, and 0 is the only honest answer that does
     * not force every caller to handle a null — the count it came from is
     * always reported beside it, which is what says the difference between
     * "nobody" and "none of them".
     */
    public static function rate(int $part, int $total): string
    {
        if ($total <= 0) {
            return number_format(0, self::RATE_DECIMALS, '.', '');
        }

        return number_format($part / $total, self::RATE_DECIMALS, '.', '');
    }

    /**
     * A decimal string — from SQL, from WooCommerce, from anywhere — to whole
     * minor units.
     *
     * round() before the cast, because `(int)` truncates: `12.3 * 100` is
     * 1229.9999… in binary floating point, and truncating turns 12.30 into
     * 12.29.
     */
    public static function toMinor(string $amount, int $scale): int
    {
        $amount = trim($amount);

        return $amount === '' ? 0 : (int) round(((float) $amount) * $scale);
    }

    public static function scale(int $decimals): int
    {
        return 10 ** max(0, $decimals);
    }

    public static function format(int $minor, int $scale, int $decimals): string
    {
        return number_format($minor / $scale, max(0, $decimals), '.', '');
    }

    /**
     * An average in minor units, truncated rather than rounded.
     *
     * `intdiv` so the average of three 10.00 orders reads 10.00 and not
     * 10.01: a per-order figure that rounds up multiplies back to more money
     * than the shop took.
     */
    public static function average(int $totalMinor, int $count): int
    {
        return $count > 0 ? intdiv($totalMinor, $count) : 0;
    }
}
