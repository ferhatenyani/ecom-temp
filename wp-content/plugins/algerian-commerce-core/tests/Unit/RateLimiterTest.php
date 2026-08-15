<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Security\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The trusted-IP allowlist, which is what keeps the authentication lockout
 * from taking the application server down with the attacker.
 */
final class RateLimiterTest extends TestCase
{
    public function testEmptyConfigurationTrustsNobody(): void
    {
        self::assertSame([], RateLimiter::parseIpList(null));
        self::assertSame([], RateLimiter::parseIpList(''));
        self::assertSame([], RateLimiter::parseIpList('   '));
    }

    public function testParsesASingleAddress(): void
    {
        self::assertSame(['10.0.0.5'], RateLimiter::parseIpList('10.0.0.5'));
    }

    public function testParsesAListAndTrimsWhitespace(): void
    {
        self::assertSame(
            ['10.0.0.5', '192.168.1.9'],
            RateLimiter::parseIpList(' 10.0.0.5 , 192.168.1.9 ')
        );
    }

    public function testAcceptsIpv6(): void
    {
        self::assertSame(['2001:db8::1'], RateLimiter::parseIpList('2001:db8::1'));
    }

    public function testDeduplicates(): void
    {
        self::assertSame(['10.0.0.5'], RateLimiter::parseIpList('10.0.0.5,10.0.0.5'));
    }

    /**
     * A malformed entry is dropped, never honoured. A typo must not silently
     * exempt something unintended, and a CIDR range is not supported — writing
     * one would otherwise look like it worked.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidEntryProvider(): array
    {
        return [
            'hostname' => ['nextjs.example.dz'],
            'cidr range' => ['10.0.0.0/24'],
            'wildcard' => ['10.0.0.*'],
            'star' => ['*'],
            'partial' => ['10.0.0'],
            'out of range' => ['999.999.999.999'],
            'empty entry' => [','],
        ];
    }

    #[DataProvider('invalidEntryProvider')]
    public function testDropsMalformedEntries(string $entry): void
    {
        self::assertSame([], RateLimiter::parseIpList($entry));
    }

    public function testKeepsValidEntriesAlongsideInvalidOnes(): void
    {
        self::assertSame(
            ['10.0.0.5'],
            RateLimiter::parseIpList('10.0.0.0/24, 10.0.0.5, nope')
        );
    }

    public function testDefaultsAreSane(): void
    {
        // Writes are deliberately tighter than reads, and the authentication
        // budget far tighter than either.
        self::assertGreaterThan(RateLimiter::DEFAULT_WRITES, RateLimiter::DEFAULT_READS);
        self::assertLessThan(RateLimiter::DEFAULT_WRITES, RateLimiter::DEFAULT_AUTH_FAILURES);
        self::assertGreaterThan(RateLimiter::WINDOW, RateLimiter::AUTH_FAILURE_WINDOW);
    }
}
