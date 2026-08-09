<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function sensitiveKeyProvider(): array
    {
        return [
            'password' => ['password'],
            'api secret' => ['api_secret'],
            'token' => ['access_token'],
            'authorization header' => ['Authorization'],
            'api key' => ['api_key'],
            'webhook signature' => ['signature'],
            'card number' => ['card_number'],
            'cvv' => ['cvv'],
            'cookie' => ['cookie'],
            'mixed case' => ['Chargily_Secret_Key'],
        ];
    }

    /** @dataProvider sensitiveKeyProvider */
    public function testSensitiveKeysAreMasked(string $key): void
    {
        $redacted = Logger::redact([$key => 'super-secret-value']);

        self::assertSame(Logger::MASK, $redacted[$key]);
    }

    public function testNonSensitiveValuesSurvive(): void
    {
        $redacted = Logger::redact(['order_id' => 42, 'wilaya' => 'Alger', 'status' => 'ok']);

        self::assertSame(['order_id' => 42, 'wilaya' => 'Alger', 'status' => 'ok'], $redacted);
    }

    public function testRedactionRecursesIntoNestedContext(): void
    {
        $redacted = Logger::redact([
            'provider' => 'chargily',
            'request' => [
                'amount' => 5000,
                'headers' => ['Authorization' => 'Bearer abc123'],
            ],
        ]);

        self::assertSame(5000, $redacted['request']['amount']);
        self::assertSame(Logger::MASK, $redacted['request']['headers']['Authorization']);
    }

    public function testFormattedLineNeverContainsASecret(): void
    {
        $logger = new Logger('payments', Logger::DEBUG);

        $line = $logger->format(Logger::ERROR, 'Payment verification failed', [
            'order_id' => 12,
            'chargily_secret_key' => 'sk_live_do_not_leak',
        ]);

        self::assertStringNotContainsString('sk_live_do_not_leak', $line);
        self::assertStringContainsString('[algerian-commerce][payments][error]', $line);
        self::assertStringContainsString('Payment verification failed', $line);
        self::assertStringContainsString('"order_id":12', $line);
    }

    public function testLevelFilteringHonoursTheFloor(): void
    {
        $logger = new Logger('core', Logger::WARNING);

        self::assertFalse($logger->shouldLog(Logger::DEBUG));
        self::assertFalse($logger->shouldLog(Logger::INFO));
        self::assertTrue($logger->shouldLog(Logger::WARNING));
        self::assertTrue($logger->shouldLog(Logger::ERROR));
    }

    public function testDebugLoggerPassesEverything(): void
    {
        $logger = new Logger('core', Logger::DEBUG);

        self::assertTrue($logger->shouldLog(Logger::DEBUG));
        self::assertTrue($logger->shouldLog(Logger::ERROR));
    }

    public function testContextIsOmittedFromTheLineWhenEmpty(): void
    {
        $logger = new Logger('core', Logger::DEBUG);

        self::assertSame(
            '[algerian-commerce][core][info] Plugin booted',
            $logger->format(Logger::INFO, 'Plugin booted')
        );
    }
}
