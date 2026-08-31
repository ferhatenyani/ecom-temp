<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * What this shop charges to reach a destination — docs/PLAN.md §14.
 *
 * Pure — no WordPress, no database — so the two decisions a customer feels are
 * testable without a shop: which rule applies, and whether their basket is big
 * enough for free delivery.
 *
 * **The most specific matching rule wins, and only that one.** Rules are not
 * added together and they do not fall back to each other: a shop that has
 * priced a commune means that price, not that price plus the wilaya's. See
 * ShippingRule::specificity() for why every combination scores uniquely — a tie
 * would make the answer depend on row order and change when an unrelated rule
 * was edited.
 *
 * **The threshold is compared in integer minor units**, for the reason
 * CustomerStatistics sums money that way: `4999.99 >= 5000.00` is a comparison
 * of two floats that are not the numbers they were written as, and the customer
 * who is one centime short of free delivery is exactly the one who will notice.
 *
 * No threshold is applied when the caller supplies no subtotal. A quote for
 * "what does it cost to deliver here" is a different question from "what does
 * it cost to deliver *this basket* here", and silently answering the second
 * with an empty basket would quote full price to a customer who qualifies for
 * free delivery.
 */
final class RateResolver
{
    /**
     * Pick the rule that prices this destination, if the shop has one.
     *
     * @param list<ShippingRule> $rules
     */
    public static function resolve(array $rules, Destination $destination, string $provider): ?ShippingRule
    {
        $best = null;

        foreach ($rules as $rule) {
            if (!$rule->matches($destination, $provider)) {
                continue;
            }

            if ($best === null || $rule->specificity() > $best->specificity()) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * Turn the winning rule into a quote, applying the free-shipping threshold.
     *
     * `$subtotal` is the goods total before delivery and before tax — the
     * figure a shop means by "free delivery over 5000 DZD". Passing the order
     * total instead would let the delivery fee push a basket over its own
     * threshold, which is a loop with a customer inside it.
     *
     * `$deliveryType` is the journey the caller resolved this rule *for*, and
     * is not the same thing as the rule's own: a rule with an empty delivery
     * type prices every journey, and the quote it produces still priced exactly
     * one of them. Optional because a caller holding a rule and no destination
     * — an admin screen previewing a tariff row — has nothing true to put here,
     * and `null` is how `RateQuote` spells that.
     */
    public static function quote(
        ShippingRule $rule,
        string $subtotal = '',
        int $decimals = 2,
        string $currency = 'DZD',
        ?string $deliveryType = null
    ): RateQuote {
        $free = self::qualifiesForFreeShipping($rule, $subtotal, $decimals);

        return new RateQuote(
            $rule->deliveryType === '' ? 'standard' : $rule->deliveryType,
            $free ? 'Free delivery' : 'Delivery',
            $free ? self::zero($decimals) : self::normalize($rule->amount, $decimals),
            $currency,
            $rule->estimatedDays,
            RateQuote::SOURCE_RULES,
            $free,
            $deliveryType
        );
    }

    /**
     * A basket at exactly the threshold qualifies.
     *
     * "Free over 5000" is written on a banner and read by a customer as "spend
     * 5000 and delivery is free"; charging the one whose basket is exactly 5000
     * is the reading nobody expects and everybody complains about.
     */
    public static function qualifiesForFreeShipping(ShippingRule $rule, string $subtotal, int $decimals = 2): bool
    {
        if ($rule->freeOver === null || trim($subtotal) === '') {
            return false;
        }

        return self::toMinor($subtotal, $decimals) >= self::toMinor($rule->freeOver, $decimals);
    }

    /**
     * A decimal string to whole minor units.
     *
     * round() before the cast, because (int) truncates: 12.3 * 100 is
     * 1229.9999… in binary floating point, and truncating turns 12.30 into
     * 12.29 — the centime that decides a threshold.
     */
    private static function toMinor(string $amount, int $decimals): int
    {
        return (int) round(((float) $amount) * (10 ** max(0, $decimals)));
    }

    private static function normalize(string $amount, int $decimals): string
    {
        return number_format((float) $amount, max(0, $decimals), '.', '');
    }

    private static function zero(int $decimals): string
    {
        return number_format(0, max(0, $decimals), '.', '');
    }
}
