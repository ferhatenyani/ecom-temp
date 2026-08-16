<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

use AlgerianCommerce\API\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The date window every analytics endpoint is asked for — docs/PLAN.md §27.
 *
 * Pure — no WordPress, no database, no `time()` — so every edge of "what does
 * `7d` mean" is decidable in a unit test. The timezone and the current instant
 * are arguments, because a report that quietly used the server's clock would be
 * untestable and, worse, wrong: a shop in Algiers closes its day an hour before
 * a UTC server does.
 *
 * **A window is resolved twice, deliberately.** `from`/`to` are the shop's own
 * calendar days, which is what a client asked for and what the response echoes
 * back. `startUtc`/`endUtc` are the same window as an instant pair in UTC,
 * because `wc_orders.date_created_gmt` and `ac_shipments.created_at` both store
 * UTC. Resolving once and converting at the query would put the conversion in
 * as many places as there are queries, and the one that got it wrong would be
 * off by exactly the UTC offset — a discrepancy small enough to be blamed on
 * anything.
 *
 * The UTC end is **exclusive**. An inclusive `23:59:59` is exact only while the
 * columns stay `datetime` with no fractional seconds; half-open is exact
 * whatever the column becomes, and it is the form that cannot double-count an
 * order sitting on a boundary between two adjacent windows.
 *
 * `7d`, `30d` and `90d` count **back from today, inclusive of today** — seven
 * calendar days ending this evening, which is what a dashboard means by "the
 * last 7 days". The alternative reading, the seven days before today, hides
 * every order taken this morning and is the one users report as a bug.
 */
final class AnalyticsRange
{
    public const TODAY = 'today';
    public const YESTERDAY = 'yesterday';
    public const LAST_7 = '7d';
    public const LAST_30 = '30d';
    public const LAST_90 = '90d';
    public const CUSTOM = 'custom';

    /** @var list<string> */
    public const PRESETS = [
        self::TODAY,
        self::YESTERDAY,
        self::LAST_7,
        self::LAST_30,
        self::LAST_90,
        self::CUSTOM,
    ];

    /** How many calendar days each preset covers, today included. */
    private const SPAN = [
        self::TODAY => 1,
        self::YESTERDAY => 1,
        self::LAST_7 => 7,
        self::LAST_30 => 30,
        self::LAST_90 => 90,
    ];

    /**
     * The widest custom window this API will aggregate in one request.
     *
     * Not arbitrary: roadmap §63's instruction is that a dashboard must not
     * scan the whole order book, and an unbounded `date_from` is exactly that
     * request wearing a filter. A year plus a day covers every comparison a
     * shop actually makes — this month against last, this year against last —
     * and refuses "since the beginning", which is the query that has no upper
     * bound on cost.
     */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $preset,
        /** Y-m-d in the shop's timezone, inclusive. */
        public readonly string $from,
        /** Y-m-d in the shop's timezone, inclusive. */
        public readonly string $to,
        /** 'Y-m-d H:i:s' UTC, inclusive. */
        public readonly string $startUtc,
        /** 'Y-m-d H:i:s' UTC, **exclusive**. */
        public readonly string $endUtc,
        public readonly string $timezone,
        public readonly int $days
    ) {
    }

    /**
     * @param array{range?: string, date_from?: string, date_to?: string} $params
     *
     * @throws ApiException 400 when the window is unusable.
     */
    public static function fromParams(array $params, DateTimeZone $timezone, DateTimeImmutable $now): self
    {
        $preset = strtolower(trim((string) ($params['range'] ?? self::LAST_30)));

        if (!in_array($preset, self::PRESETS, true)) {
            throw ApiException::invalidRequest('The reporting range is invalid.', [
                'fields' => ['range' => 'Must be one of: ' . implode(', ', self::PRESETS) . '.'],
            ]);
        }

        $today = $now->setTimezone($timezone)->setTime(0, 0);

        if ($preset === self::CUSTOM) {
            return self::custom($params, $timezone);
        }

        $end = $preset === self::YESTERDAY ? $today->modify('-1 day') : $today;
        $start = $end->modify('-' . (self::SPAN[$preset] - 1) . ' days');

        return self::between($preset, $start, $end, $timezone);
    }

    /**
     * @param array{date_from?: string, date_to?: string} $params
     *
     * @throws ApiException
     */
    private static function custom(array $params, DateTimeZone $timezone): self
    {
        $errors = [];

        // Both ends, not one. A custom range with an open end is the unbounded
        // scan MAX_DAYS exists to refuse, arriving by omission rather than by
        // asking for it.
        $from = self::day($params['date_from'] ?? '', 'date_from', $timezone, $errors);
        $to = self::day($params['date_to'] ?? '', 'date_to', $timezone, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The reporting range is invalid.', ['fields' => $errors]);
        }

        /** @var DateTimeImmutable $from */
        /** @var DateTimeImmutable $to */
        if ($from > $to) {
            throw ApiException::invalidRequest('The reporting range is invalid.', [
                'fields' => ['date_from' => 'Must not be later than date_to.'],
            ]);
        }

        $days = (int) $from->diff($to)->format('%a') + 1;

        if ($days > self::MAX_DAYS) {
            throw ApiException::invalidRequest('The reporting range is invalid.', [
                'fields' => ['date_from' => 'A custom range covers at most ' . self::MAX_DAYS . ' days.'],
            ]);
        }

        return self::between(self::CUSTOM, $from, $to, $timezone);
    }

    /**
     * @param array<string, string> $errors
     */
    private static function day(mixed $value, string $field, DateTimeZone $timezone, array &$errors): ?DateTimeImmutable
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            $errors[$field] = 'Required when range is custom.';

            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        // createFromFormat accepts 2026-02-31 and silently rolls it into March,
        // so the round-trip is the check. The route's `pattern` already refused
        // anything that is not four-two-two digits.
        if ($day === false || $day->format('Y-m-d') !== $value) {
            $errors[$field] = 'Must be a real date in Y-m-d form.';

            return null;
        }

        return $day;
    }

    private static function between(
        string $preset,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        DateTimeZone $timezone
    ): self {
        $utc = new DateTimeZone('UTC');

        return new self(
            $preset,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            // Midnight at the *start of the day after* the last one, which is
            // the exclusive bound. `+1 day` on a local midnight also survives a
            // DST change, where adding 86400 seconds would not.
            $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            $timezone->getName(),
            (int) $start->diff($end)->format('%a') + 1
        );
    }

    /**
     * What the response echoes back, so a client can label a chart with the
     * window it actually got rather than the one it asked for.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'days' => $this->days,
            'timezone' => $this->timezone,
        ];
    }

    /**
     * A stable fingerprint of the window, for the response cache key.
     *
     * The UTC bounds rather than the preset: `today` names a different window
     * every day, and two requests a minute apart must not share a cache entry
     * across midnight.
     */
    public function fingerprint(): string
    {
        return $this->startUtc . '|' . $this->endUtc;
    }
}
