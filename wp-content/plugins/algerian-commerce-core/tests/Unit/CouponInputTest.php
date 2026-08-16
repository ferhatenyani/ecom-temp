<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Coupons\CouponInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Coupon rules — docs/PLAN.md §21, roadmap step 33. */
final class CouponInputTest extends TestCase
{
    public function testAcceptsAPercentageCoupon(): void
    {
        $input = CouponInput::fromPayload(
            ['code' => 'SUMMER10', 'discount_type' => 'percent', 'amount' => '10'],
            true
        );

        self::assertSame('summer10', $input->get('code'), 'WooCommerce stores codes lower-cased');
        self::assertSame('percent', $input->get('discount_type'));
        self::assertSame('10', $input->get('amount'));
    }

    public function testAmountStaysAString(): void
    {
        // Money is strings end to end here; a float would introduce rounding.
        self::assertIsString(
            CouponInput::fromPayload(['code' => 'X', 'amount' => 1500.50], true)->get('amount')
        );
    }

    public function testCreatingNeedsACodeAndAnAmount(): void
    {
        try {
            CouponInput::fromPayload([], true);
            self::fail('accepted');
        } catch (ApiException $e) {
            $fields = $e->details()['fields'] ?? [];
            self::assertArrayHasKey('code', $fields);
            self::assertArrayHasKey('amount', $fields);
        }
    }

    public function testAPatchNeedsNeither(): void
    {
        $input = CouponInput::fromPayload(['description' => 'Spring sale'], false);

        self::assertFalse($input->has('code'));
        self::assertSame('Spring sale', $input->get('description'));
    }

    public function testDefaultTypeIsFixedCart(): void
    {
        self::assertSame('fixed_cart', CouponInput::fromPayload(['code' => 'X', 'amount' => '5'], true)->get('discount_type'));
    }

    public function testAPercentageOverOneHundredIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['code' => 'X', 'discount_type' => 'percent', 'amount' => '150'], true);
    }

    public function testAFixedAmountOverOneHundredIsFine(): void
    {
        // 150 DZD off is perfectly ordinary; only a *percentage* has a ceiling.
        self::assertSame(
            '150',
            CouponInput::fromPayload(['code' => 'X', 'discount_type' => 'fixed_cart', 'amount' => '150'], true)->get('amount')
        );
    }

    /**
     * PLAN §21 asks for "maximum discount where supported" and WooCommerce does
     * not support it — `maximum_amount` is a cap on the *cart*, not the
     * discount. Refused by name so nobody sets one believing they set the other.
     */
    #[DataProvider('discountCapProvider')]
    public function testADiscountCapIsRefusedByNameNotSilentlyDropped(string $field): void
    {
        try {
            CouponInput::fromPayload(['code' => 'X', 'amount' => '5', $field => '100'], true);
            self::fail("{$field} was accepted");
        } catch (ApiException $e) {
            $fields = $e->details()['fields'] ?? [];
            self::assertArrayHasKey($field, $fields);
            self::assertStringContainsString('maximum_amount', $fields[$field]);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function discountCapProvider(): array
    {
        return ['maximum_discount' => ['maximum_discount'], 'max_discount' => ['max_discount']];
    }

    public function testUnknownFieldsAreRejected(): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['code' => 'X', 'amount' => '5', 'nonsense' => 1], true);
    }

    public function testReadOnlyFieldsAreDroppedSoARoundTripWorks(): void
    {
        $input = CouponInput::fromPayload(
            ['code' => 'X', 'amount' => '5', 'id' => 12, 'usage_count' => 4, 'date_created' => 'x'],
            true
        );

        self::assertFalse($input->has('id'));
        self::assertFalse($input->has('usage_count'));
    }

    /** null means unlimited, and it has to be expressible or a cap can never be undone. */
    public function testAUsageLimitCanBeCleared(): void
    {
        $input = CouponInput::fromPayload(['usage_limit' => null], false);

        self::assertTrue($input->has('usage_limit'));
        self::assertNull($input->get('usage_limit'));
    }

    public function testAUsageLimitOfZeroIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['usage_limit' => 0], false);
    }

    public function testMinimumAboveMaximumIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['minimum_amount' => '500', 'maximum_amount' => '100'], false);
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['code' => 'X', 'amount' => '-5'], true);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badExpiryProvider(): array
    {
        return [
            'not a date' => ['soon'],
            'wrong shape' => ['31/01/2027'],
            'a day that does not exist' => ['2027-02-31'],
        ];
    }

    #[DataProvider('badExpiryProvider')]
    public function testExpiryMustBeARealDate(mixed $value): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['date_expires' => $value], false);
    }

    public function testExpiryCanBeCleared(): void
    {
        self::assertNull(CouponInput::fromPayload(['date_expires' => null], false)->get('date_expires'));
    }

    public function testRestrictionsMustBeIdArrays(): void
    {
        $this->expectException(ApiException::class);

        CouponInput::fromPayload(['product_ids' => 'not an array'], false);
    }

    public function testRestrictionsAreDeduplicated(): void
    {
        self::assertSame(
            [4, 7],
            CouponInput::fromPayload(['product_ids' => [4, 7, 4, '7']], false)->get('product_ids')
        );
    }

    public function testEmailRestrictionsAllowAWildcard(): void
    {
        self::assertSame(
            ['*@example.test', 'a@example.test'],
            CouponInput::fromPayload(
                ['email_restrictions' => ['*@example.test', 'A@example.test']],
                false
            )->get('email_restrictions')
        );
    }

    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        try {
            CouponInput::fromPayload(['amount' => 'free', 'usage_limit' => -2, 'nonsense' => 1], true);
            self::fail('accepted');
        } catch (ApiException $e) {
            self::assertSame(['nonsense', 'code', 'amount', 'usage_limit'], array_keys($e->details()['fields'] ?? []));
        }
    }
}
