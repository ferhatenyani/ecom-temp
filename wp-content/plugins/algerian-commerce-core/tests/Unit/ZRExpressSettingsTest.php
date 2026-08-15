<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Integrations\ZRExpress\ZRExpressSettings;
use PHPUnit\Framework\TestCase;

/**
 * The timeout ceiling §55 added, on the second courier.
 *
 * ZR Express configures far less than Yalidine — no origin, no insurance, no
 * parcel defaults — so this covers the one setting whose value can reach out of
 * the plugin and hold a PHP worker.
 */
final class ZRExpressSettingsTest extends TestCase
{
    /**
     * §55 asks for explicit timeouts. A setting that can raise one to ten
     * minutes removes the bound as surely as never setting it.
     */
    public function testAnOverlongTimeoutIsCappedAndReported(): void
    {
        $settings = ZRExpressSettings::fromArray(['timeout' => 600]);

        self::assertSame(ZRExpressSettings::MAX_TIMEOUT, $settings->timeout);
        self::assertStringContainsString('timeout', $settings->problems()[0]);
    }

    public function testATimeoutWithinTheCeilingIsHonoured(): void
    {
        $settings = ZRExpressSettings::fromArray(['timeout' => 30]);

        self::assertSame(30, $settings->timeout);
        self::assertSame([], $settings->problems());
    }
}
