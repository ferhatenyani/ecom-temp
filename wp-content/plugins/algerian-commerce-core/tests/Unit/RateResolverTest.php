<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\RateQuote;
use AlgerianCommerce\Shipping\RateResolver;
use AlgerianCommerce\Shipping\ShippingRule;
use PHPUnit\Framework\TestCase;

final class RateResolverTest extends TestCase
{
    private const ALGER = 16;
    private const OULED_FAYET = 1234;

    private static function rule(
        int $wilaya,
        int $commune,
        string $amount,
        string $provider = '',
        string $type = '',
        ?string $freeOver = null,
        bool $active = true
    ): ShippingRule {
        return new ShippingRule($wilaya, $commune, $amount, $provider, $type, $freeOver, null, $active);
    }

    private static function to(int $wilaya = self::ALGER, int $commune = self::OULED_FAYET, string $type = Destination::HOME): Destination
    {
        return new Destination($wilaya, $commune, $type);
    }

    /** The shape a real tariff takes: a national rate and its exceptions. */
    public function testTheNarrowestRuleWins(): void
    {
        $rules = [
            self::rule(0, 0, '800'),                              // the whole country
            self::rule(self::ALGER, 0, '500'),                    // all of Alger
            self::rule(self::ALGER, self::OULED_FAYET, '300'),    // this commune
        ];

        self::assertSame('300', RateResolver::resolve($rules, self::to(), 'manual')?->amount);
    }

    public function testOrderInTheListDoesNotDecideThePrice(): void
    {
        $narrow = self::rule(self::ALGER, self::OULED_FAYET, '300');
        $wide = self::rule(0, 0, '800');

        self::assertSame('300', RateResolver::resolve([$narrow, $wide], self::to(), 'manual')?->amount);
        self::assertSame('300', RateResolver::resolve([$wide, $narrow], self::to(), 'manual')?->amount);
    }

    /**
     * A shop that has priced a commune means that price — not that price plus
     * the wilaya's, and not the wilaya's as a fallback for the parts the
     * commune rule did not mention.
     */
    public function testRulesAreNotAddedTogether(): void
    {
        $rules = [self::rule(0, 0, '800'), self::rule(self::ALGER, self::OULED_FAYET, '300')];

        $quote = RateResolver::quote(RateResolver::resolve($rules, self::to(), 'manual'));

        self::assertSame('300.00', $quote->amount);
    }

    public function testARuleForAnotherWilayaDoesNotApply(): void
    {
        $rules = [self::rule(31, 0, '500')];

        self::assertNull(RateResolver::resolve($rules, self::to(), 'manual'));
    }

    public function testAShopWithNoRulesQuotesNothing(): void
    {
        self::assertNull(RateResolver::resolve([], self::to(), 'manual'));
    }

    public function testDeskPickupCanBePricedDifferently(): void
    {
        $rules = [
            self::rule(self::ALGER, 0, '500', '', Destination::HOME),
            self::rule(self::ALGER, 0, '250', '', Destination::DESK),
        ];

        self::assertSame('500', RateResolver::resolve($rules, self::to(), 'manual')?->amount);
        self::assertSame(
            '250',
            RateResolver::resolve($rules, self::to(type: Destination::DESK), 'manual')?->amount
        );
    }

    public function testARuleCanBeScopedToOneCourier(): void
    {
        $rules = [
            self::rule(self::ALGER, 0, '500'),
            self::rule(self::ALGER, 0, '650', 'yalidine'),
        ];

        self::assertSame('500', RateResolver::resolve($rules, self::to(), 'manual')?->amount);
        self::assertSame('650', RateResolver::resolve($rules, self::to(), 'yalidine')?->amount);
    }

    /**
     * A narrower *place* outranks a courier-specific rule: where the parcel is
     * going is the stronger fact about what it costs.
     */
    public function testPlaceOutranksProvider(): void
    {
        $rules = [
            self::rule(self::ALGER, self::OULED_FAYET, '300'),
            self::rule(self::ALGER, 0, '650', 'manual'),
        ];

        self::assertSame('300', RateResolver::resolve($rules, self::to(), 'manual')?->amount);
    }

    /** Deactivating a rule is how a shop suspends a price without losing it. */
    public function testAnInactiveRuleMatchesNothing(): void
    {
        $rules = [
            self::rule(0, 0, '800'),
            self::rule(self::ALGER, self::OULED_FAYET, '300', active: false),
        ];

        self::assertSame('800', RateResolver::resolve($rules, self::to(), 'manual')?->amount);
    }

    /**
     * Every combination of dimensions scores uniquely, so two rules can never
     * tie and the price cannot change when an unrelated rule is edited.
     */
    public function testNoTwoScopesShareASpecificity(): void
    {
        $seen = [];

        foreach ([0, self::ALGER] as $wilaya) {
            foreach ([0, self::OULED_FAYET] as $commune) {
                foreach (['', Destination::HOME] as $type) {
                    foreach (['', 'manual'] as $provider) {
                        if ($commune > 0 && $wilaya === 0) {
                            continue; // refused at construction
                        }

                        $score = self::rule($wilaya, $commune, '1', $provider, $type)->specificity();
                        self::assertNotContains($score, $seen, 'two scopes score the same');
                        $seen[] = $score;
                    }
                }
            }
        }
    }

    public function testFreeShippingAboveTheThreshold(): void
    {
        $rule = self::rule(self::ALGER, 0, '500', freeOver: '5000');

        $quote = RateResolver::quote($rule, '6000.00');

        self::assertSame('0.00', $quote->amount);
        self::assertTrue($quote->isFree);
        self::assertSame(RateQuote::SOURCE_RULES, $quote->source);
    }

    /**
     * "Free over 5000" is read by a customer as "spend 5000 and delivery is
     * free". Charging the basket that is exactly 5000 is the reading nobody
     * expects.
     */
    public function testABasketExactlyAtTheThresholdQualifies(): void
    {
        $rule = self::rule(self::ALGER, 0, '500', freeOver: '5000');

        self::assertTrue(RateResolver::quote($rule, '5000.00')->isFree);
    }

    public function testJustBelowTheThresholdPaysInFull(): void
    {
        $rule = self::rule(self::ALGER, 0, '500', freeOver: '5000');
        $quote = RateResolver::quote($rule, '4999.99');

        self::assertSame('500.00', $quote->amount);
        self::assertFalse($quote->isFree);
    }

    /**
     * The centime that decides a threshold: comparing these as floats, or
     * truncating instead of rounding on the way to minor units, gets it wrong.
     */
    public function testThresholdsAreComparedExactly(): void
    {
        $rule = self::rule(self::ALGER, 0, '500', freeOver: '12.30');

        self::assertTrue(RateResolver::quote($rule, '12.30')->isFree);
        self::assertFalse(RateResolver::quote($rule, '12.29')->isFree);
    }

    /**
     * "What does delivery here cost" is a different question from "what does
     * delivering this basket here cost", and answering the second with an empty
     * basket quotes full price to a customer who qualifies for free delivery.
     */
    public function testNoSubtotalMeansNoThresholdIsApplied(): void
    {
        $rule = self::rule(self::ALGER, 0, '500', freeOver: '5000');

        self::assertSame('500.00', RateResolver::quote($rule)->amount);
        self::assertFalse(RateResolver::quote($rule, '')->isFree);
    }

    public function testARuleWithoutAThresholdIsNeverFree(): void
    {
        $rule = self::rule(self::ALGER, 0, '500');

        self::assertFalse(RateResolver::quote($rule, '999999.00')->isFree);
    }

    public function testTheQuoteCarriesTheRulesDeliveryTypeAndEstimate(): void
    {
        $rule = new ShippingRule(self::ALGER, 0, '500', '', Destination::DESK, null, 3);
        $quote = RateResolver::quote($rule);

        self::assertSame('desk', $quote->service);
        self::assertSame(3, $quote->estimatedDays);
        self::assertSame('DZD', $quote->currency);
    }

    /** A rule for any delivery type is a plain standard service. */
    public function testARuleForAnyDeliveryTypeQuotesAsStandard(): void
    {
        self::assertSame('standard', RateResolver::quote(self::rule(self::ALGER, 0, '500'))->service);
    }

    public function testAmountsAreNormalisedToTheStoresDecimals(): void
    {
        self::assertSame('500.00', RateResolver::quote(self::rule(self::ALGER, 0, '500'))->amount);
        self::assertSame('500', RateResolver::quote(self::rule(self::ALGER, 0, '500'), '', 0)->amount);
    }
}
