<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Analytics\AnalyticsRange;
use AlgerianCommerce\API\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AnalyticsRangeTest extends TestCase
{
    /** Algiers: UTC+1 all year, no daylight saving — the shop this is built for. */
    private static function algiers(): DateTimeZone
    {
        return new DateTimeZone('Africa/Algiers');
    }

    /** 2026-08-16 00:30 UTC, which is already 01:30 on the 16th in Algiers. */
    private static function now(string $utc = '2026-08-16 00:30:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /** @param array<string, string> $params */
    private static function range(array $params, ?DateTimeZone $zone = null, ?DateTimeImmutable $now = null): AnalyticsRange
    {
        return AnalyticsRange::fromParams($params, $zone ?? self::algiers(), $now ?? self::now());
    }

    public function testTheDefaultRangeIsThirtyDaysEndingToday(): void
    {
        $range = self::range([]);

        self::assertSame(AnalyticsRange::LAST_30, $range->preset);
        self::assertSame('2026-08-16', $range->to);
        self::assertSame('2026-07-18', $range->from);
        self::assertSame(30, $range->days);
    }

    /**
     * "The last 7 days" includes today. The other reading hides every order
     * taken this morning, which is the one users report as a bug.
     */
    public function testSevenDaysIncludesToday(): void
    {
        $range = self::range(['range' => '7d']);

        self::assertSame('2026-08-16', $range->to);
        self::assertSame('2026-08-10', $range->from);
        self::assertSame(7, $range->days);
    }

    public function testTodayIsASingleDay(): void
    {
        $range = self::range(['range' => 'today']);

        self::assertSame('2026-08-16', $range->from);
        self::assertSame('2026-08-16', $range->to);
        self::assertSame(1, $range->days);
    }

    public function testYesterdayIsTheSingleDayBefore(): void
    {
        $range = self::range(['range' => 'yesterday']);

        self::assertSame('2026-08-15', $range->from);
        self::assertSame('2026-08-15', $range->to);
        self::assertSame(1, $range->days);
    }

    /**
     * The shop's midnight, not the server's. An Algiers day starts at 23:00 the
     * previous day in UTC, which is where `date_created_gmt` is compared.
     */
    public function testTheWindowIsTheShopsDayExpressedInUtc(): void
    {
        $range = self::range(['range' => 'today']);

        self::assertSame('2026-08-15 23:00:00', $range->startUtc);
        self::assertSame('2026-08-16 23:00:00', $range->endUtc);
        self::assertSame('Africa/Algiers', $range->timezone);
    }

    /**
     * The same instant is a different "today" in two timezones, and an order
     * taken at 00:30 UTC belongs to the 16th in Algiers and the 16th in UTC —
     * but the *bounds* differ by the offset, which is the part that must not be
     * hard-coded to the server.
     */
    public function testAUtcShopGetsUtcMidnights(): void
    {
        $range = self::range(['range' => 'today'], new DateTimeZone('UTC'));

        self::assertSame('2026-08-16 00:00:00', $range->startUtc);
        self::assertSame('2026-08-17 00:00:00', $range->endUtc);
    }

    /**
     * Just before midnight in Algiers is already the next day in UTC. The shop's
     * clock decides, or the last hour of every day would fall into tomorrow.
     */
    public function testTheShopsClockDecidesWhichDayALateEveningBelongsTo(): void
    {
        $range = self::range(['range' => 'today'], self::algiers(), self::now('2026-08-16 22:30:00'));

        // 22:30 UTC is 23:30 on the 16th in Algiers — still the 16th.
        self::assertSame('2026-08-16', $range->to);
    }

    /** The end bound is exclusive, so two adjacent windows cannot both claim an order. */
    public function testAdjacentWindowsDoNotOverlap(): void
    {
        $yesterday = self::range(['range' => 'yesterday']);
        $today = self::range(['range' => 'today']);

        self::assertSame($yesterday->endUtc, $today->startUtc);
    }

    public function testACustomRangeCoversBothEndsWholly(): void
    {
        $range = self::range([
            'range' => 'custom',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        self::assertSame(31, $range->days);
        self::assertSame('2025-12-31 23:00:00', $range->startUtc);
        self::assertSame('2026-01-31 23:00:00', $range->endUtc);
    }

    /** from == to is one whole day, not an empty window. */
    public function testASingleDayCustomRangeIsThatDay(): void
    {
        $range = self::range([
            'range' => 'custom',
            'date_from' => '2026-03-04',
            'date_to' => '2026-03-04',
        ]);

        self::assertSame(1, $range->days);
    }

    public function testACustomRangeNeedsBothEnds(): void
    {
        $this->expectException(ApiException::class);

        self::range(['range' => 'custom', 'date_from' => '2026-01-01']);
    }

    public function testAnOpenEndedCustomRangeNamesTheMissingField(): void
    {
        try {
            self::range(['range' => 'custom']);
            self::fail('an empty custom range must be refused');
        } catch (ApiException $exception) {
            $fields = $exception->details()['fields'] ?? [];

            self::assertArrayHasKey('date_from', $fields);
            self::assertArrayHasKey('date_to', $fields);
            self::assertSame(400, $exception->statusCode());
        }
    }

    public function testABackwardsCustomRangeIsRefused(): void
    {
        try {
            self::range(['range' => 'custom', 'date_from' => '2026-05-02', 'date_to' => '2026-05-01']);
            self::fail('date_from after date_to must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('date_from', $exception->details()['fields'] ?? []);
        }
    }

    /**
     * createFromFormat accepts 2026-02-31 and rolls it into March, so the
     * round-trip is what actually rejects it.
     */
    public function testADateThatDoesNotExistIsRefused(): void
    {
        try {
            self::range(['range' => 'custom', 'date_from' => '2026-02-31', 'date_to' => '2026-03-01']);
            self::fail('the 31st of February must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('date_from', $exception->details()['fields'] ?? []);
        }
    }

    /** An unbounded window is the order-book scan §63 exists to prevent. */
    public function testAWindowWiderThanTheCapIsRefused(): void
    {
        try {
            self::range(['range' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2026-01-01']);
            self::fail('a six-year window must be refused');
        } catch (ApiException $exception) {
            self::assertStringContainsString(
                (string) AnalyticsRange::MAX_DAYS,
                (string) ($exception->details()['fields']['date_from'] ?? '')
            );
        }
    }

    public function testTheWidestAllowedWindowIsAccepted(): void
    {
        $range = self::range([
            'range' => 'custom',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);

        self::assertSame(365, $range->days);
        self::assertLessThanOrEqual(AnalyticsRange::MAX_DAYS, $range->days);
    }

    public function testAnUnknownPresetIsRefused(): void
    {
        try {
            self::range(['range' => 'all-time']);
            self::fail('an invented preset must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('range', $exception->details()['fields'] ?? []);
        }
    }

    /**
     * `today` names a different window every day, so the cache key must come
     * from the resolved bounds and not from the preset.
     */
    public function testTheFingerprintFollowsTheWindowRatherThanThePreset(): void
    {
        $today = self::range(['range' => 'today']);
        $tomorrow = self::range(['range' => 'today'], self::algiers(), self::now('2026-08-17 00:30:00'));

        self::assertNotSame($today->fingerprint(), $tomorrow->fingerprint());
    }

    public function testTheSameWindowAskedForTwoWaysSharesAFingerprint(): void
    {
        $preset = self::range(['range' => 'today']);
        $custom = self::range(['range' => 'custom', 'date_from' => '2026-08-16', 'date_to' => '2026-08-16']);

        self::assertSame($preset->fingerprint(), $custom->fingerprint());
    }

    public function testTheResponseEchoesTheWindowItActuallyUsed(): void
    {
        $range = self::range(['range' => '90d'])->toArray();

        self::assertSame('90d', $range['preset']);
        self::assertSame(90, $range['days']);
        self::assertSame('Africa/Algiers', $range['timezone']);
        self::assertSame(['preset', 'from', 'to', 'days', 'timezone'], array_keys($range));
    }
}
