<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Notifications\MailTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mail transport's configuration rules — docs/PLAN.md §29, §30.
 *
 * Only the derivation is testable here: `configure()` needs a real PHPMailer
 * and the `from` filters need WordPress, and both are exercised by
 * `wp algerian-commerce mail-check`. What this covers is the part that decides
 * *how* the connection is attempted, which is worth having as a test because
 * every way of getting it wrong produces the same symptom — a mail server that
 * accepts the connection and then hangs, with nothing in any log to say the
 * port and the encryption disagree.
 */
final class MailTransportTest extends TestCase
{
    /** @param array<string, string> $env */
    private static function transport(array $env): MailTransport
    {
        return new MailTransport(new Config($env), new Logger('test', Logger::ERROR));
    }

    public function testAnEmptyEnvironmentIsNotConfigured(): void
    {
        // The honest default. With no host, WordPress falls back to PHP mail()
        // and the containers have no MTA — so this class does nothing at all
        // rather than half-configuring a transport.
        self::assertFalse(self::transport([])->isConfigured());
    }

    public function testAHostIsWhatMakesItConfigured(): void
    {
        self::assertTrue(self::transport(['SMTP_HOST' => 'smtp.example.test'])->isConfigured());
    }

    public function testAWhitespaceHostIsNotAHost(): void
    {
        // Config drops empty strings, so the value that reaches here from a
        // half-filled .env line is whitespace rather than nothing.
        self::assertFalse(self::transport(['SMTP_HOST' => '   '])->isConfigured());
    }

    /** @return array<string, array{0: array<string, string>, 1: int}> */
    public static function portProvider(): array
    {
        return [
            'unset defaults to STARTTLS' => [[], 587],
            'tls implies 587' => [['SMTP_ENCRYPTION' => 'tls'], 587],
            'ssl implies 465' => [['SMTP_ENCRYPTION' => 'ssl'], 465],
            'none still defaults to 587' => [['SMTP_ENCRYPTION' => 'none'], 587],
            'an explicit port wins' => [['SMTP_PORT' => '2525'], 2525],
            'an explicit port wins over ssl too' => [['SMTP_ENCRYPTION' => 'ssl', 'SMTP_PORT' => '25'], 25],
        ];
    }

    /**
     * The default port follows the encryption rather than being fixed, because
     * 465 with STARTTLS and 587 with implicit TLS both connect and then hang.
     *
     * @param array<string, string> $env
     */
    #[DataProvider('portProvider')]
    public function testThePortFollowsTheEncryption(array $env, int $expected): void
    {
        self::assertSame($expected, self::transport($env)->port());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function encryptionProvider(): array
    {
        return [
            'tls' => ['tls', 'tls'],
            'ssl' => ['ssl', 'ssl'],
            'none' => ['none', 'none'],
            'uppercase' => ['TLS', 'tls'],
            'padded' => ['  ssl  ', 'ssl'],
            // A typo must not silently disable TLS. Falling back to the secure
            // option is the direction that fails safe; falling back to "none"
            // would send credentials in the clear because somebody wrote "tsl".
            'a typo' => ['tsl', 'tls'],
            'starttls is not a value here' => ['starttls', 'tls'],
        ];
    }

    #[DataProvider('encryptionProvider')]
    public function testTheEncryptionIsAnAllowlistThatFailsSafe(string $given, string $expected): void
    {
        self::assertSame($expected, self::transport(['SMTP_ENCRYPTION' => $given])->encryption());
    }

    public function testTheHostIsTrimmed(): void
    {
        self::assertSame('smtp.example.test', self::transport(['SMTP_HOST' => ' smtp.example.test '])->host());
    }

    public function testEveryAllowedEncryptionIsAccepted(): void
    {
        // Guards the list against being narrowed without the tests noticing.
        foreach (MailTransport::ENCRYPTIONS as $value) {
            self::assertSame($value, self::transport(['SMTP_ENCRYPTION' => $value])->encryption());
        }
    }
}
