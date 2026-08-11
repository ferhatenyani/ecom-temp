<?php

declare(strict_types=1);

namespace AlgerianCommerce\Security;

use InvalidArgumentException;

/**
 * One rate limit rule, and the arithmetic that applies it.
 *
 * Pure — no WordPress, no storage — so the window maths and the decision are
 * unit-testable, which matters because an off-by-one here either locks out
 * legitimate traffic or silently lets a brute-force through.
 *
 * A **fixed window**, not a sliding one: the counter resets on a boundary, so
 * a caller can in theory spend a full allowance at the end of one window and
 * again at the start of the next. That is the accepted trade for a counter
 * that costs one cache read and one write. It bounds sustained abuse, which is
 * what these limits are for; it is not a traffic shaper.
 */
final class RateLimit
{
    public function __construct(
        public readonly string $name,
        public readonly int $limit,
        public readonly int $windowSeconds
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException("Rate limit {$name} must allow at least one request.");
        }

        if ($windowSeconds < 1) {
            throw new InvalidArgumentException("Rate limit {$name} needs a window of at least one second.");
        }
    }

    /**
     * The storage key for a caller in the current window.
     *
     * The window number is part of the key, which is what makes expiry
     * self-healing: a new window is simply a different key, so a counter that
     * outlives its TTL can never be mistaken for a current one.
     *
     * The identifier is hashed. Keys built from an IP or a user id end up in a
     * shared cache and, with the options-table fallback, in the database —
     * hashing keeps a readable list of who called what out of both.
     */
    public function keyFor(string $identifier, int $now): string
    {
        return sprintf(
            'ac_rl_%s_%s_%d',
            $this->name,
            substr(hash('sha256', $identifier), 0, 20),
            $this->windowNumber($now)
        );
    }

    public function windowNumber(int $now): int
    {
        return intdiv($now, $this->windowSeconds);
    }

    /** Seconds until the current window rolls over — the Retry-After value. */
    public function secondsUntilReset(int $now): int
    {
        $elapsed = $now % $this->windowSeconds;

        return $this->windowSeconds - $elapsed;
    }

    /** @param int $used requests already made in this window, including this one */
    public function isExceeded(int $used): bool
    {
        return $used > $this->limit;
    }

    public function remaining(int $used): int
    {
        return max(0, $this->limit - $used);
    }
}
