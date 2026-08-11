<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Security\RateLimit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private function limit(int $max = 5, int $window = 60): RateLimit
    {
        return new RateLimit('read', $max, $window);
    }

    public function testRejectsANonsenseLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimit('read', 0, 60);
    }

    public function testRejectsANonsenseWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimit('read', 5, 0);
    }

    /**
     * The boundary that decides whether the limit is off by one: `limit`
     * requests must pass, and the one after it must not.
     */
    public function testAllowsExactlyTheStatedNumberOfRequests(): void
    {
        $limit = $this->limit(5);

        self::assertFalse($limit->isExceeded(1));
        self::assertFalse($limit->isExceeded(5), 'the fifth request is within a limit of 5');
        self::assertTrue($limit->isExceeded(6), 'the sixth is not');
    }

    public function testRemainingCountsDownAndFloorsAtZero(): void
    {
        $limit = $this->limit(5);

        self::assertSame(4, $limit->remaining(1));
        self::assertSame(0, $limit->remaining(5));
        self::assertSame(0, $limit->remaining(99));
    }

    /**
     * Base timestamps here are exact multiples of the window, so "+59" really
     * is the last second of the same window. An unaligned base straddles a
     * boundary and tests nothing it claims to.
     */
    private const ALIGNED = 1_000_020;   // 1_000_020 % 60 === 0

    public function testWindowNumberChangesOnlyOnTheBoundary(): void
    {
        $limit = $this->limit(5, 60);

        self::assertSame(0, self::ALIGNED % 60, 'base must sit on a window boundary');
        self::assertSame($limit->windowNumber(self::ALIGNED), $limit->windowNumber(self::ALIGNED + 59));
        self::assertNotSame($limit->windowNumber(self::ALIGNED), $limit->windowNumber(self::ALIGNED + 60));
    }

    /**
     * The window number is part of the key, so a new window is a different
     * key. That is what makes expiry self-healing.
     */
    public function testKeyChangesWhenTheWindowRollsOver(): void
    {
        $limit = $this->limit(5, 60);

        $first = $limit->keyFor('ip:10.0.0.1', self::ALIGNED);
        $same = $limit->keyFor('ip:10.0.0.1', self::ALIGNED + 30);
        $next = $limit->keyFor('ip:10.0.0.1', self::ALIGNED + 60);

        self::assertSame($first, $same);
        self::assertNotSame($first, $next);
    }

    public function testDifferentCallersGetDifferentKeys(): void
    {
        $limit = $this->limit();

        self::assertNotSame(
            $limit->keyFor('ip:10.0.0.1', 1_000_000),
            $limit->keyFor('ip:10.0.0.2', 1_000_000)
        );
        self::assertNotSame(
            $limit->keyFor('user:1', 1_000_000),
            $limit->keyFor('ip:10.0.0.1', 1_000_000)
        );
    }

    public function testDifferentLimitsDoNotShareACounter(): void
    {
        $read = new RateLimit('read', 5, 60);
        $write = new RateLimit('write', 5, 60);

        self::assertNotSame(
            $read->keyFor('user:1', 1_000_000),
            $write->keyFor('user:1', 1_000_000)
        );
    }

    /**
     * Keys land in a shared cache and, on the transient fallback, in the
     * database — a readable list of who called what does not belong in either.
     */
    public function testKeyDoesNotContainTheRawIdentifier(): void
    {
        $key = $this->limit()->keyFor('ip:192.168.1.55', 1_000_000);

        self::assertStringNotContainsString('192.168.1.55', $key);
        self::assertStringStartsWith('ac_rl_read_', $key);
    }

    /** Transient keys have a length ceiling; a blown key silently stops working. */
    public function testKeyStaysWithinTransientNameLimits(): void
    {
        $key = (new RateLimit('authfail', 10, 900))->keyFor('ip:255.255.255.255', 2_000_000_000);

        self::assertLessThanOrEqual(172, strlen($key));
    }

    public function testSecondsUntilResetCountsDownWithinTheWindow(): void
    {
        $limit = $this->limit(5, 60);

        self::assertSame(60, $limit->secondsUntilReset(1_000_020));
        self::assertSame(1, $limit->secondsUntilReset(1_000_079));
        self::assertSame(60, $limit->secondsUntilReset(1_000_080));
    }

    public function testSecondsUntilResetIsAlwaysUsableAsRetryAfter(): void
    {
        $limit = $this->limit(5, 900);

        for ($offset = 0; $offset < 900; $offset += 97) {
            $retry = $limit->secondsUntilReset(1_000_000 + $offset);

            self::assertGreaterThan(0, $retry);
            self::assertLessThanOrEqual(900, $retry);
        }
    }
}
