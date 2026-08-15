<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Integrations\Chargily\ChargilyCredentials;
use AlgerianCommerce\Integrations\Chargily\ChargilySettings;
use PHPUnit\Framework\TestCase;

/**
 * Per-client Chargily configuration — roadmap §59.
 *
 * A bad option value must never fatal the plugin on boot, so every case here is
 * "falls back and says so" rather than "throws".
 */
final class ChargilySettingsTest extends TestCase
{
    public function testDefaultsAreUsable(): void
    {
        $settings = ChargilySettings::fromArray([]);

        self::assertSame('https://pay.chargily.net/test/api/v2/', $settings->testBaseUrl);
        self::assertSame('https://pay.chargily.net/api/v2/', $settings->liveBaseUrl);
        self::assertSame(30, $settings->checkoutLifetime);
        self::assertSame([], $settings->problems());
    }

    /**
     * The key picks the environment, so a live key can never be pointed at the
     * test endpoint by a setting somebody forgot to change.
     */
    public function testTheKeyChoosesTheBaseUrl(): void
    {
        $settings = ChargilySettings::fromArray([]);

        self::assertSame(
            'https://pay.chargily.net/test/api/v2/',
            $settings->baseUrl(new ChargilyCredentials('test_sk_abc'))
        );
        self::assertSame(
            'https://pay.chargily.net/api/v2/',
            $settings->baseUrl(new ChargilyCredentials('live_sk_abc'))
        );
    }

    /** Anything that is not explicitly a test key is treated as live. */
    public function testAnUnrecognisedPrefixIsTreatedAsLive(): void
    {
        self::assertFalse((new ChargilyCredentials('sk_whatever'))->isTestMode());
        self::assertTrue((new ChargilyCredentials('test_sk_abc'))->isTestMode());
        self::assertFalse((new ChargilyCredentials(''))->isComplete());
    }

    public function testAnUnknownSettingIsReportedRatherThanApplied(): void
    {
        $settings = ChargilySettings::fromArray(['pass_fees_to_customer' => true]);

        self::assertNotSame([], $settings->problems());
    }

    /** docs/SECURITY.md: a return URL is somewhere a customer's browser is sent. */
    public function testAPlaintextReturnUrlIsRefused(): void
    {
        $settings = ChargilySettings::fromArray(['success_url' => 'http://shop.example/thanks']);

        self::assertSame('', $settings->successUrl);
        self::assertNotSame([], $settings->problems());
    }

    public function testAnInvalidEnumFallsBackToTheDefault(): void
    {
        $settings = ChargilySettings::fromArray([
            'locale' => 'de',
            'fees_allocation' => 'nobody',
            'payment_method' => 'bitcoin',
        ]);

        self::assertSame('fr', $settings->locale);
        self::assertSame('merchant', $settings->feesAllocation);
        self::assertSame('', $settings->paymentMethod);
        self::assertCount(3, $settings->problems());
    }

    /** Empty means "let the shopper choose at the checkout page", which is valid. */
    public function testAnEmptyPaymentMethodIsAllowed(): void
    {
        $settings = ChargilySettings::fromArray(['payment_method' => '']);

        self::assertSame('', $settings->paymentMethod);
        self::assertSame([], $settings->problems());
    }

    public function testTheTimeoutIsCappedRatherThanRefused(): void
    {
        $settings = ChargilySettings::fromArray(['timeout' => 600]);

        self::assertSame(ChargilySettings::MAX_TIMEOUT, $settings->timeout);
        self::assertNotSame([], $settings->problems());
    }

    public function testItRoundTripsThroughToArray(): void
    {
        $settings = ChargilySettings::fromArray([
            'success_url' => 'https://shop.example/ok',
            'locale' => 'ar',
            'timeout' => 20,
        ]);

        self::assertSame($settings->toArray(), ChargilySettings::fromArray($settings->toArray())->toArray());
    }
}
