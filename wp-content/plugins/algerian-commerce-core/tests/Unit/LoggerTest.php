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

    /**
     * §55: a Yalidine `label` is a URL carrying an access token, and anyone
     * holding it can fetch the customer's name, phone and address. It belongs
     * in the shipment record and never in a log.
     */
    public function testAParcelLabelUrlIsMasked(): void
    {
        $redacted = Logger::redact([
            'tracking' => 'yal-123',
            'label' => 'https://api.yalidine.app/labels/abc?token=live-token',
            'labels' => ['https://api.yalidine.app/labels/abc?token=live-token'],
        ]);

        self::assertSame('yal-123', $redacted['tracking']);
        self::assertSame(Logger::MASK, $redacted['label']);
        self::assertSame(Logger::MASK, $redacted['labels']);
    }

    /**
     * The exact-match list must not become another over-matching substring
     * rule: `provider_status_label` is ZR Express's wording for a parcel state
     * and carries nothing sensitive.
     */
    public function testAKeyMerelyContainingLabelSurvives(): void
    {
        $redacted = Logger::redact(['provider_status_label' => 'Sorti en livraison']);

        self::assertSame('Sorti en livraison', $redacted['provider_status_label']);
    }

    /**
     * The diagnostics §55 found were being redacted into uselessness: a hashed
     * rate-limit bucket and the references a Yalidine response was keyed by,
     * both masked because their names contained "key". `response_keys` would
     * have been masked too — the substring does not care where it sits — so
     * they are `bucket` and `answered_for`, and this is what keeps them so.
     */
    public function testTheRenamedDiagnosticsAreNotMasked(): void
    {
        $redacted = Logger::redact([
            'bucket' => 'ac_rl_read_9f86d081884c7d65_29245',
            'answered_for' => ['order-12-1'],
        ]);

        self::assertSame('ac_rl_read_9f86d081884c7d65_29245', $redacted['bucket']);
        self::assertSame(['order-12-1'], $redacted['answered_for']);
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
