<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Security\RateLimiter;
use AlgerianCommerce\Security\RateLimitStore;
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

    /**
     * An upload is a write that costs megabytes and a CPU-bound decode rather
     * than a database row, so its budget has to be the smaller one — roadmap
     * §61. If these two are ever equal, the upload limit has stopped doing
     * anything the write limit was not already doing.
     */
    public function testUploadsAreTighterThanOrdinaryWrites(): void
    {
        self::assertLessThan(RateLimiter::DEFAULT_WRITES, RateLimiter::DEFAULT_UPLOADS);
        self::assertGreaterThan(0, RateLimiter::DEFAULT_UPLOADS);
    }

    public function testTheUploadLimitIsConfigurableAndNamedForItsOwnBucket(): void
    {
        $limiter = new RateLimiter(
            new RateLimitStore(),
            new Logger('test'),
            new Config(['AC_RATE_LIMIT_UPLOADS' => '5'])
        );

        $limit = $limiter->uploadLimit();

        self::assertSame(5, $limit->limit);
        self::assertSame(RateLimiter::WINDOW, $limit->windowSeconds);
        // Its own name, so an upload burst cannot spend the write allowance's
        // counter or be spent by one.
        self::assertSame('upload', $limit->name);
    }

    public function testAnUnusableUploadLimitFallsBackToTheDefault(): void
    {
        $limiter = new RateLimiter(
            new RateLimitStore(),
            new Logger('test'),
            new Config(['AC_RATE_LIMIT_UPLOADS' => 'lots'])
        );

        self::assertSame(RateLimiter::DEFAULT_UPLOADS, $limiter->uploadLimit()->limit);
    }
}
