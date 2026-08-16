<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

/**
 * A short-lived cache in front of the aggregate queries — roadmap §63's "do not
 * repeatedly scan every order on every dashboard request".
 *
 * A dashboard polls. Seven endpoints on one screen, refreshed while somebody
 * watches it, is the shape of traffic this exists for, and a minute of staleness
 * costs a shop nothing while a hundred repeated aggregations cost the database
 * real work.
 *
 * **It is deliberately a cache and not a rollup.** `ac_analytics_aggregates` is
 * still unbuilt, and §63 recorded why: a pre-computed number is a number that
 * can be wrong, and it stays wrong until something re-computes it. A response
 * cache cannot drift further than its TTL, expires on its own, and needs no
 * scheduler — which matters here more than it looks, because this install's
 * scheduler has never run (see the README, §63).
 *
 * **The capability the caller holds is part of the key**, and that is not a
 * detail: the same window produces one payload with revenue in it and one
 * without, and a key that ignored the difference would serve an administrator's
 * money figures to a support agent out of the cache. `key()` is pure so that
 * property is a unit test rather than a hope.
 *
 * Every cached payload carries `generated_at`, so a client can always see how
 * old the number on the screen is.
 */
final class AnalyticsCache
{
    /** Long enough to absorb a dashboard's polling, short enough to be honest. */
    public const DEFAULT_TTL = 60;

    /** WordPress refuses transient names over 172 characters. */
    private const PREFIX = 'ac_analytics_';

    public function __construct(private readonly int $ttl = self::DEFAULT_TTL)
    {
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function isEnabled(): bool
    {
        return $this->ttl > 0;
    }

    /**
     * A transient name for one endpoint, one window and one privilege level.
     *
     * Pure and static: everything that can change the payload goes in, and the
     * result is hashed so a name is always the same length whatever the filters
     * were.
     *
     * `$version` is the plugin version, so a deploy that changes the shape of a
     * payload cannot serve yesterday's shape out of a transient that outlived
     * it.
     *
     * @param array<string, scalar> $extra any further filter that changes the answer
     */
    public static function key(
        string $endpoint,
        string $windowFingerprint,
        bool $moneyVisible,
        string $version,
        array $extra = []
    ): string {
        ksort($extra);

        $parts = [
            $endpoint,
            $windowFingerprint,
            $moneyVisible ? 'money' : 'nomoney',
            $version,
        ];

        foreach ($extra as $name => $value) {
            $parts[] = $name . '=' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return self::PREFIX . substr(hash('sha256', implode('|', $parts)), 0, 40);
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $cached = get_transient($key);

        return is_array($cached) ? $cached : null;
    }

    /** @param array<string, mixed> $payload */
    public function put(string $key, array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        set_transient($key, $payload, $this->ttl);
    }
}
