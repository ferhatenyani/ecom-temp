<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * What a channel says about one attempt — docs/PLAN.md §29.
 *
 * A value object rather than a bool, so a failure carries the reason into
 * `ac_notifications.last_error` where an operator can read it. The shipping and
 * payment abstractions return their own results for the same reason: "it did
 * not work" is not an answer anybody can act on.
 */
final class NotificationResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly string $error,
        /** True when retrying could plausibly succeed — a timeout, not a bad address. */
        public readonly bool $retryable
    ) {
    }

    public static function sent(): self
    {
        return new self(true, '', false);
    }

    /** A transient failure: the drain will try this row again. */
    public static function failed(string $error): self
    {
        return new self(false, $error, true);
    }

    /**
     * A permanent failure — a malformed address, a channel that will never
     * accept this message. Retrying wastes a send and keeps a dead row at the
     * head of the queue, so these are marked failed and left alone.
     */
    public static function rejected(string $error): self
    {
        return new self(false, $error, false);
    }
}
