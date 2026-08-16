<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Analytics\AnalyticsCache;
use PHPUnit\Framework\TestCase;

final class AnalyticsCacheTest extends TestCase
{
    private const WINDOW = '2026-07-31 23:00:00|2026-08-16 23:00:00';

    public function testTheSameRequestReusesTheSameEntry(): void
    {
        self::assertSame(
            AnalyticsCache::key('overview', self::WINDOW, true, '0.1.0'),
            AnalyticsCache::key('overview', self::WINDOW, true, '0.1.0')
        );
    }

    /**
     * The security property this class exists to keep. The same window produces
     * one payload with revenue in it and one without; a key that ignored the
     * difference would serve an administrator's money figures to a support
     * agent out of the cache.
     */
    public function testAPayloadWithMoneyInItNeverSharesAKeyWithOneWithout(): void
    {
        self::assertNotSame(
            AnalyticsCache::key('overview', self::WINDOW, true, '0.1.0'),
            AnalyticsCache::key('overview', self::WINDOW, false, '0.1.0')
        );
    }

    public function testEachEndpointHasItsOwnEntry(): void
    {
        self::assertNotSame(
            AnalyticsCache::key('overview', self::WINDOW, true, '0.1.0'),
            AnalyticsCache::key('revenue', self::WINDOW, true, '0.1.0')
        );
    }

    public function testADifferentWindowIsADifferentEntry(): void
    {
        self::assertNotSame(
            AnalyticsCache::key('overview', self::WINDOW, true, '0.1.0'),
            AnalyticsCache::key('overview', '2026-08-15 23:00:00|2026-08-16 23:00:00', true, '0.1.0')
        );
    }

    /** A deploy that changes a payload's shape must not serve the old shape. */
    public function testAnUpgradeInvalidatesEveryEntry(): void
    {
        self::assertNotSame(
            AnalyticsCache::key('overview', self::WINDOW, true, '0.1.0'),
            AnalyticsCache::key('overview', self::WINDOW, true, '0.2.0')
        );
    }

    public function testExtraFiltersChangeTheKeyRegardlessOfTheOrderTheyArriveIn(): void
    {
        $one = AnalyticsCache::key('products', self::WINDOW, true, '0.1.0', ['limit' => 10, 'sort' => 'units']);
        $other = AnalyticsCache::key('products', self::WINDOW, true, '0.1.0', ['sort' => 'units', 'limit' => 10]);

        self::assertSame($one, $other);
        self::assertNotSame(
            $one,
            AnalyticsCache::key('products', self::WINDOW, true, '0.1.0', ['limit' => 20, 'sort' => 'units'])
        );
    }

    /** WordPress refuses a transient name over 172 characters. */
    public function testAKeyIsAlwaysShortEnoughToBeATransientName(): void
    {
        $key = AnalyticsCache::key(
            'shipping',
            self::WINDOW,
            true,
            '0.1.0',
            ['a' => str_repeat('x', 500), 'b' => str_repeat('y', 500)]
        );

        self::assertLessThanOrEqual(172, strlen($key));
        self::assertStringStartsWith('ac_analytics_', $key);
    }

    public function testAZeroTtlTurnsTheCacheOffEntirely(): void
    {
        self::assertFalse((new AnalyticsCache(0))->isEnabled());
        self::assertTrue((new AnalyticsCache(60))->isEnabled());
        self::assertSame(60, (new AnalyticsCache(60))->ttl());
    }

    /** With the cache off, nothing is read and nothing is written. */
    public function testADisabledCacheNeverReadsAStore(): void
    {
        // get() would call get_transient(), which does not exist in the unit
        // bootstrap — reaching WordPress at all would fatal here, which is the
        // assertion.
        self::assertNull((new AnalyticsCache(0))->get('ac_analytics_anything'));
    }
}
