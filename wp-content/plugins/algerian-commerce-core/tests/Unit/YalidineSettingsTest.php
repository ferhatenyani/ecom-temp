<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use PHPUnit\Framework\TestCase;

/**
 * Per-client configuration — roadmap §56's line between `.env` and settings.
 *
 * The reference implementation compiles one client's origin wilaya into a
 * 58-case `switch` with a default. This plugin is cloned per client, so nothing
 * courier-specific may be hard-coded, and an unset origin has to refuse rather
 * than pick somebody else's warehouse.
 */
final class YalidineSettingsTest extends TestCase
{
    public function testAnEmptyOptionIsUsableAndSaysWhatIsMissing(): void
    {
        $settings = YalidineSettings::fromArray([]);

        self::assertFalse($settings->hasOrigin());
        self::assertSame([], $settings->problems());
        self::assertSame('https://api.yalidine.app/v1/', $settings->baseUrl);
    }

    public function testAnUnusableValueFallsBackAndIsReported(): void
    {
        $settings = YalidineSettings::fromArray([
            'origin_wilaya_id' => 'Béjaïa',
            'do_insurance' => 'yes',
        ]);

        // Never an exception: a bad option must not fatal the plugin on boot.
        self::assertSame(0, $settings->originWilayaId);
        self::assertFalse($settings->doInsurance);
        self::assertCount(2, $settings->problems());
    }

    /** A typo that looks configured and behaves as if it were never set. */
    public function testAnUnknownSettingIsNamed(): void
    {
        $settings = YalidineSettings::fromArray(['origin_wilaya' => 16]);

        self::assertFalse($settings->hasOrigin());
        self::assertStringContainsString('origin_wilaya', $settings->problems()[0]);
    }

    /** docs/SECURITY.md: every provider call goes over TLS. */
    public function testAPlainHttpBaseUrlIsRefused(): void
    {
        $settings = YalidineSettings::fromArray(['base_url' => 'http://api.yalidine.app/v1/']);

        self::assertSame('https://api.yalidine.app/v1/', $settings->baseUrl);
        self::assertNotSame([], $settings->problems());
    }

    public function testTheBaseUrlAlwaysEndsInASlash(): void
    {
        self::assertSame(
            'https://api.yalidine.app/v1/',
            YalidineSettings::fromArray(['base_url' => 'https://api.yalidine.app/v1'])->baseUrl
        );
    }

    /**
     * §55 asks for explicit timeouts. A setting that can raise one to ten
     * minutes removes the bound as surely as never setting it — a hung courier
     * would hold a PHP worker that long on every request, checkout included.
     */
    public function testAnOverlongTimeoutIsCappedAndReported(): void
    {
        $settings = YalidineSettings::fromArray(['timeout' => 600]);

        self::assertSame(YalidineSettings::MAX_TIMEOUT, $settings->timeout);
        self::assertStringContainsString('timeout', $settings->problems()[0]);
    }

    public function testATimeoutWithinTheCeilingIsHonoured(): void
    {
        $settings = YalidineSettings::fromArray(['timeout' => 30]);

        self::assertSame(30, $settings->timeout);
        self::assertSame([], $settings->problems());
    }

    /** Zero means "do not send it", not "a parcel of no size". */
    public function testUnsetDimensionsAreOmittedRatherThanSentAsZero(): void
    {
        self::assertSame([], YalidineSettings::fromArray([])->parcelDimensions());
        self::assertSame(
            ['weight' => 3],
            YalidineSettings::fromArray(['weight' => 3])->parcelDimensions()
        );
    }

    /**
     * The shop's own tariff is already inside the order total, so the driver
     * must not collect a delivery fee a second time at the door.
     */
    public function testDeliveryIsDeclaredAlreadyPaidByDefault(): void
    {
        self::assertTrue(YalidineSettings::fromArray([])->freeshipping);
        self::assertFalse(YalidineSettings::fromArray(['freeshipping' => false])->freeshipping);
    }
}
