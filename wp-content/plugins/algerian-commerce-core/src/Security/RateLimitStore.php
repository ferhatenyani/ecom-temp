<?php

declare(strict_types=1);

namespace AlgerianCommerce\Security;

/**
 * The counter behind a rate limit.
 *
 * Prefers a persistent object cache, because `wp_cache_incr()` is atomic there
 * — two simultaneous requests each get a distinct number. The transient
 * fallback is a read-then-write, so under real concurrency it can undercount
 * and let a few extra requests through.
 *
 * That fallback is a deliberate, bounded compromise rather than an oversight:
 * a limiter that is approximately right on a single-server dev box is worth
 * far more than none, and the counter can never *over*count and lock out a
 * legitimate caller. **Production should run a persistent object cache** —
 * without one these counters also land in the options table, which is slower
 * and noisier than it looks. The health endpoint reports which backend is live.
 */
final class RateLimitStore
{
    private const GROUP = 'algerian_commerce_rate_limit';

    /**
     * Count this request and return the running total for the window.
     */
    public function hit(string $key, int $ttlSeconds): int
    {
        if ($this->hasPersistentCache()) {
            // add() only succeeds when the key is absent, which starts the
            // window; incr() is atomic from there.
            if (wp_cache_add($key, 1, self::GROUP, $ttlSeconds)) {
                return 1;
            }

            $count = wp_cache_incr($key, 1, self::GROUP);

            // A key can expire between add() and incr(); restart rather than
            // treat the miss as "no requests yet".
            if ($count === false) {
                wp_cache_set($key, 1, self::GROUP, $ttlSeconds);

                return 1;
            }

            return (int) $count;
        }

        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, $ttlSeconds);

        return $count;
    }

    /** Current count without recording a request. */
    public function peek(string $key): int
    {
        if ($this->hasPersistentCache()) {
            $value = wp_cache_get($key, self::GROUP);

            return $value === false ? 0 : (int) $value;
        }

        return (int) get_transient($key);
    }

    public function reset(string $key): void
    {
        if ($this->hasPersistentCache()) {
            wp_cache_delete($key, self::GROUP);

            return;
        }

        delete_transient($key);
    }

    public function backend(): string
    {
        return $this->hasPersistentCache() ? 'object_cache' : 'transient';
    }

    private function hasPersistentCache(): bool
    {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }
}
