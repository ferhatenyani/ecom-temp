<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Settings\SettingsInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Roadmap §71, docs/PLAN.md §48.
 *
 * **The refusals are the subject.** This is the endpoint whose stated purpose is
 * "configure the template without forking it", which makes it where somebody
 * will try to configure the things that are deliberately not configurable. Each
 * of those is refused *by name with its reason*, and each of those refusals is
 * a rule that would otherwise live only in a comment.
 */
final class SettingsInputTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fields(ApiException $e): array
    {
        return $e->details()['fields'] ?? [];
    }

    // ------------------------------------------------------------- accepted

    public function testAcceptsEveryWritableBlock(): void
    {
        $input = SettingsInput::fromPayload([
            'store' => ['name' => 'Boutique', 'storefront_url' => 'https://shop.dz'],
            'contact' => ['email' => 'hi@shop.dz', 'phone' => '0551020304'],
            'legal' => ['rc' => '16/00-1234567B25'],
            'social' => ['facebook' => 'https://facebook.com/shop'],
        ]);

        self::assertSame('Boutique', $input->block('store')['name']);
        self::assertSame('hi@shop.dz', $input->block('contact')['email']);
        self::assertSame('16/00-1234567B25', $input->block('legal')['rc']);
    }

    public function testAPartialWriteCarriesOnlyWhatItNames(): void
    {
        // A settings screen that saves one section must not blank the others;
        // the repository merges, so the input must not invent empty blocks.
        $input = SettingsInput::fromPayload(['contact' => ['phone' => '0770112233']]);

        self::assertNull($input->block('store'));
        self::assertSame(['phone' => '0770112233'], $input->block('contact'));
    }

    public function testAnEmptyPayloadIsEmptyRatherThanAnError(): void
    {
        self::assertTrue(SettingsInput::fromPayload([])->isEmpty());
    }

    public function testNullAndEmptyStringBothClearAField(): void
    {
        $input = SettingsInput::fromPayload([
            'social' => ['tiktok' => null, 'youtube' => ''],
        ]);

        // A client with no TikTok has to be able to say so.
        self::assertSame(['tiktok' => '', 'youtube' => ''], $input->block('social'));
    }

    public function testValuesAreTrimmed(): void
    {
        $input = SettingsInput::fromPayload(['contact' => ['phone' => '  0551020304  ']]);

        self::assertSame('0551020304', $input->block('contact')['phone']);
    }

    /** JSON has no comments and client.json is filled in by hand — §73. */
    public function testAnUnderscoredKeyIsIgnoredRatherThanRejected(): void
    {
        $input = SettingsInput::fromPayload([
            '_comment' => ['anything at all'],
            'contact' => ['phone' => '0551020304'],
        ]);

        self::assertSame(['phone' => '0551020304'], $input->block('contact'));
    }

    // ------------------------------------------------------------- refusals

    /** @return array<string, array{0: string}> */
    public static function refusedProvider(): array
    {
        return [
            'currency' => ['currency'],
            'features' => ['features'],
            'providers' => ['providers'],
            'secrets' => ['secrets'],
            'an api key' => ['api_key'],
            'a webhook secret' => ['webhook_secret'],
            'the Meta token' => ['meta_capi_access_token'],
            'locale' => ['locale'],
            'a bare url' => ['url'],
        ];
    }

    /**
     * Refused **by name, with a reason**, never ignored. A caller who sets
     * `currency` and receives a 200 will believe the currency changed.
     */
    #[DataProvider('refusedProvider')]
    public function testRefusesByNameWithAReason(string $field): void
    {
        try {
            SettingsInput::fromPayload([$field => 'anything']);
            self::fail("{$field} was accepted");
        } catch (ApiException $e) {
            $fields = self::fields($e);

            self::assertArrayHasKey($field, $fields);
            self::assertNotSame('', (string) $fields[$field], 'the refusal must say why');
        }
    }

    public function testTheCurrencyRefusalExplainsTheOrderBook(): void
    {
        // The reason is the point: WooCommerce records the currency per order,
        // so a change splits the order book instead of converting it.
        try {
            SettingsInput::fromPayload(['currency' => 'USD']);
            self::fail('currency was accepted');
        } catch (ApiException $e) {
            self::assertStringContainsString('per order', self::fields($e)['currency']);
        }
    }

    public function testTheFeatureRefusalPointsAtTheEnvironment(): void
    {
        try {
            SettingsInput::fromPayload(['features' => ['cod' => false]]);
            self::fail('features was accepted');
        } catch (ApiException $e) {
            self::assertStringContainsString('.env', self::fields($e)['features']);
        }
    }

    public function testRefusesAnUnknownBlock(): void
    {
        try {
            SettingsInput::fromPayload(['shipping' => ['free_over' => 5000]]);
            self::fail('an unknown block was accepted');
        } catch (ApiException $e) {
            self::assertStringContainsString('Unknown block', self::fields($e)['shipping']);
        }
    }

    public function testRefusesAnUnknownFieldInAKnownBlock(): void
    {
        try {
            SettingsInput::fromPayload(['store' => ['name' => 'X', 'currency' => 'USD']]);
            self::fail('an unknown field was accepted');
        } catch (ApiException $e) {
            self::assertStringContainsString('currency', self::fields($e)['store']);
        }
    }

    public function testEveryProblemIsReportedNotJustTheFirst(): void
    {
        try {
            SettingsInput::fromPayload([
                'currency' => 'USD',
                'nonsense' => [],
                'contact' => ['email' => 'not-an-email'],
            ]);
            self::fail('the payload was accepted');
        } catch (ApiException $e) {
            self::assertCount(3, self::fields($e));
        }
    }

    // ------------------------------------------------------------ validation

    public function testRejectsAnInvalidEmail(): void
    {
        try {
            SettingsInput::fromPayload(['contact' => ['email' => 'contact at shop']]);
            self::fail('a bad email was accepted');
        } catch (ApiException $e) {
            self::assertArrayHasKey('contact.email', self::fields($e));
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function urlProvider(): array
    {
        return [
            'https' => ['https://shop.dz', true],
            'http' => ['http://shop.dz', true],
            'no scheme' => ['shop.dz', false],
            // Valid URLs to filter_var, and cross-site scripting the moment a
            // storefront renders one as a link.
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,<script>alert(1)</script>', false],
            'ftp' => ['ftp://shop.dz', false],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testTheUrlSchemeIsAnAllowlist(string $url, bool $allowed): void
    {
        try {
            $input = SettingsInput::fromPayload(['store' => ['storefront_url' => $url]]);

            self::assertTrue($allowed, "{$url} should have been refused");
            self::assertSame($url, $input->block('store')['storefront_url']);
        } catch (ApiException $e) {
            self::assertFalse($allowed, "{$url} should have been accepted");
            self::assertArrayHasKey('store.storefront_url', self::fields($e));
        }
    }

    #[DataProvider('urlProvider')]
    public function testSocialLinksGetTheSameSchemeCheck(string $url, bool $allowed): void
    {
        try {
            SettingsInput::fromPayload(['social' => ['facebook' => $url]]);
            self::assertTrue($allowed, "{$url} should have been refused");
        } catch (ApiException $e) {
            self::assertFalse($allowed, "{$url} should have been accepted");
        }
    }

    public function testRejectsAnOverlongValue(): void
    {
        try {
            SettingsInput::fromPayload(['legal' => ['registered_name' => str_repeat('a', 201)]]);
            self::fail('an overlong value was accepted');
        } catch (ApiException $e) {
            self::assertArrayHasKey('legal.registered_name', self::fields($e));
        }
    }

    public function testAnAddressMayBeLongerThanOtherFields(): void
    {
        $input = SettingsInput::fromPayload(['contact' => ['address' => str_repeat('a', 400)]]);

        self::assertSame(400, mb_strlen($input->block('contact')['address']));
    }

    public function testLogoIdMustBeAnId(): void
    {
        try {
            SettingsInput::fromPayload(['store' => ['logo_id' => 'logo.png']]);
            self::fail('a filename was accepted as an id');
        } catch (ApiException $e) {
            self::assertArrayHasKey('store.logo_id', self::fields($e));
        }
    }

    public function testLogoIdClearsToZero(): void
    {
        $input = SettingsInput::fromPayload(['store' => ['logo_id' => null]]);

        self::assertSame(0, $input->block('store')['logo_id']);
    }

    public function testABlockMustBeAnObject(): void
    {
        try {
            SettingsInput::fromPayload(['contact' => 'hi@shop.dz']);
            self::fail('a string block was accepted');
        } catch (ApiException $e) {
            self::assertArrayHasKey('contact', self::fields($e));
        }
    }
}
