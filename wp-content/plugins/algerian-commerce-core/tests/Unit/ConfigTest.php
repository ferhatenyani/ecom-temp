<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testGetReturnsValueOrDefault(): void
    {
        $config = new Config(['SMTP_HOST' => 'mail.example.dz']);

        self::assertSame('mail.example.dz', $config->get('SMTP_HOST'));
        self::assertNull($config->get('MISSING'));
        self::assertSame('fallback', $config->get('MISSING', 'fallback'));
    }

    public function testHasTreatsEmptyStringAsAbsent(): void
    {
        $config = new Config(['A' => 'x', 'B' => '']);

        self::assertTrue($config->has('A'));
        self::assertFalse($config->has('B'));
        self::assertFalse($config->has('C'));
    }

    public function testSecretReturnsNullWhenUnconfigured(): void
    {
        $config = new Config(['CHARGILY_SECRET_KEY' => '']);

        // Null rather than '' so callers cannot send a blank credential.
        self::assertNull($config->secret('CHARGILY_SECRET_KEY'));
        self::assertNull($config->secret('YALIDINE_API_TOKEN'));
    }

    public function testSecretReturnsConfiguredValue(): void
    {
        $config = new Config(['CHARGILY_SECRET_KEY' => 'sk_test_123']);

        self::assertSame('sk_test_123', $config->secret('CHARGILY_SECRET_KEY'));
    }

    /** @return array<string, array{0: ?string, 1: bool}> */
    public static function truthyProvider(): array
    {
        return [
            'one' => ['1', true],
            'true' => ['true', true],
            'TRUE uppercase' => ['TRUE', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            'padded' => ['  true  ', true],
            'zero' => ['0', false],
            'false' => ['false', false],
            'empty' => ['', false],
            'null' => [null, false],
            'arbitrary' => ['maybe', false],
        ];
    }

    /** @dataProvider truthyProvider */
    public function testIsTruthy(?string $value, bool $expected): void
    {
        self::assertSame($expected, Config::isTruthy($value));
    }

    public function testFeatureFlagsDefaultToDisabled(): void
    {
        $config = new Config([]);

        foreach (Config::FLAGS as $flag) {
            self::assertFalse($config->isEnabled($flag), "{$flag} must default to off");
        }
    }

    public function testFeatureFlagIsEnabledWhenEnvironmentSaysSo(): void
    {
        $config = new Config(['ENABLE_COD' => 'true', 'ENABLE_ZR_EXPRESS' => 'false']);

        self::assertTrue($config->isEnabled('ENABLE_COD'));
        self::assertFalse($config->isEnabled('ENABLE_ZR_EXPRESS'));
    }
}
