<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ShippingRule;
use AlgerianCommerce\Shipping\ShippingRuleInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShippingRuleInputTest extends TestCase
{
    private const NOW = '2026-08-13 09:00:00';

    public function testAValidRule(): void
    {
        $rule = ShippingRuleInput::forCreate([
            'wilaya_id' => 16,
            'commune_id' => 1234,
            'delivery_type' => 'DESK',
            'provider' => 'Manual',
            'amount' => '450',
            'free_over' => 5000,
            'estimated_days' => 2,
        ])->toRule(self::NOW);

        self::assertSame(16, $rule->wilayaId);
        self::assertSame('desk', $rule->deliveryType);
        self::assertSame('manual', $rule->provider);
        self::assertSame('450.00', $rule->amount);
        self::assertSame('5000.00', $rule->freeOver);
        self::assertSame(2, $rule->estimatedDays);
        self::assertTrue($rule->isActive);
    }

    /** The national fallback: no place, no courier, one price. */
    public function testARuleForTheWholeCountryNeedsOnlyAnAmount(): void
    {
        $rule = ShippingRuleInput::forCreate(['amount' => '800'])->toRule(self::NOW);

        self::assertSame(0, $rule->wilayaId);
        self::assertSame(0, $rule->communeId);
        self::assertSame('', $rule->provider);
        self::assertSame('', $rule->deliveryType);
        self::assertNull($rule->freeOver);
    }

    public function testACreateNeedsAnAmount(): void
    {
        try {
            ShippingRuleInput::forCreate(['wilaya_id' => 16]);
            self::fail('a rule with no price is not a rule');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('amount', $exception->details()['fields']);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function badAmountProvider(): array
    {
        return [
            'negative' => ['-1'],
            'not a number' => ['gratuit'],
            'boolean' => [true],
            'array' => [[500]],
            'implausible' => ['99999999.00'],
        ];
    }

    #[DataProvider('badAmountProvider')]
    public function testBadAmountsAreRefused(mixed $amount): void
    {
        try {
            ShippingRuleInput::forCreate(['amount' => $amount]);
            self::fail(var_export($amount, true) . ' is not a price');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('amount', $exception->details()['fields']);
        }
    }

    /**
     * A commune belongs to exactly one wilaya, so a rule naming a commune and
     * no wilaya describes a place nothing can match — and it would sit in the
     * table looking as though it worked.
     */
    public function testACommuneRuleMustNameItsWilaya(): void
    {
        try {
            ShippingRuleInput::forCreate(['commune_id' => 1234, 'amount' => '300']);
            self::fail('a commune without its wilaya must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('wilaya_id', $exception->details()['fields']);
        }
    }

    public function testTheRuleItselfRefusesTheSameIncoherence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingRule(0, 1234, '300');
    }

    public function testAnUnknownDeliveryTypeIsRefused(): void
    {
        try {
            ShippingRuleInput::forCreate(['amount' => '300', 'delivery_type' => 'drone']);
            self::fail('an invented delivery type must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('delivery_type', $exception->details()['fields']);
        }
    }

    public function testUnknownFieldsAreRejected(): void
    {
        try {
            ShippingRuleInput::forCreate(['amount' => '300', 'zone' => 'north']);
            self::fail('an unknown field must be rejected');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('zone', $exception->details()['fields']);
        }
    }

    /** GET a rule, change the price, PATCH the whole object back. */
    public function testTheFieldsTheApiEmitsButDoesNotAcceptAreDropped(): void
    {
        $wire = (new ShippingRule(16, 0, '500.00', '', '', null, null, true, self::NOW, self::NOW, 7))->toArray();
        $wire['amount'] = '450';

        $input = ShippingRuleInput::forUpdate($wire);

        self::assertFalse($input->has('id'));
        self::assertFalse($input->has('specificity'));
        self::assertSame('450.00', $input->get('amount'));
    }

    /**
     * A PATCH changes what it names and nothing else — a payload that omits
     * free_over must not silently delete a threshold a shop relies on.
     */
    public function testAPatchLeavesUnmentionedFieldsAlone(): void
    {
        $existing = new ShippingRule(16, 0, '500.00', 'manual', 'home', '5000.00', 3, true, self::NOW, self::NOW, 7);

        $updated = ShippingRuleInput::forUpdate(['amount' => '450'])->applyTo($existing, '2026-08-13 10:00:00');

        self::assertSame('450.00', $updated->amount);
        self::assertSame('5000.00', $updated->freeOver);
        self::assertSame(3, $updated->estimatedDays);
        self::assertSame('manual', $updated->provider);
        self::assertSame(7, $updated->id);
        // The identity and the birth time survive an edit; only updated_at moves.
        self::assertSame(self::NOW, $updated->createdAt);
        self::assertSame('2026-08-13 10:00:00', $updated->updatedAt);
    }

    /** Explicit null is the only way to say "no more free delivery here". */
    public function testAThresholdIsClearedByAnExplicitNull(): void
    {
        $existing = new ShippingRule(16, 0, '500.00', '', '', '5000.00');

        $updated = ShippingRuleInput::forUpdate(['free_over' => null])->applyTo($existing, self::NOW);

        self::assertNull($updated->freeOver);
    }

    public function testAnEmptyPatchIsEmpty(): void
    {
        self::assertTrue(ShippingRuleInput::forUpdate([])->isEmpty());
        self::assertTrue(ShippingRuleInput::forUpdate(['id' => 7])->isEmpty());
    }

    public function testARuleCanBeDeactivated(): void
    {
        $existing = new ShippingRule(16, 0, '500.00');

        self::assertFalse(ShippingRuleInput::forUpdate(['is_active' => false])->applyTo($existing, self::NOW)->isActive);
    }

    public function testIsActiveMustBeABoolean(): void
    {
        try {
            ShippingRuleInput::forUpdate(['is_active' => 'false']);
            self::fail('a string that looks like a boolean must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('is_active', $exception->details()['fields']);
        }
    }

    public function testAnImplausibleDeliveryEstimateIsRefused(): void
    {
        try {
            ShippingRuleInput::forUpdate(['estimated_days' => 400]);
            self::fail('a year of delivery is not an estimate');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('estimated_days', $exception->details()['fields']);
        }
    }

    public function testARuleForAnyDeliveryTypeIsWrittenAsEmpty(): void
    {
        $rule = ShippingRuleInput::forCreate(['amount' => '300', 'delivery_type' => ''])->toRule(self::NOW);

        self::assertSame('', $rule->deliveryType);
        self::assertTrue($rule->matches(new Destination(16, 1234, Destination::DESK), 'manual'));
        self::assertTrue($rule->matches(new Destination(16, 1234, Destination::HOME), 'manual'));
    }
}
