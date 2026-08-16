<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

/**
 * What a provider answers when asked to send an event — roadmap §62b.
 *
 * Three outcomes rather than a boolean, because they lead to different actions
 * and merging them is how a queue either gives up on a recoverable failure or
 * retries a permanent one forever:
 *
 * ```
 * sent        it is done; mark it and stop
 * retryable   the network, a 5xx, a rate limit — leave it queued
 * rejected    the payload is wrong and will be wrong next time; stop trying
 * ```
 *
 * A malformed event retried hourly for a week is a real cost: it burns the
 * account's rate limit and buries the events that would have worked.
 */
final class MarketingResult
{
    private function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly string $reference = ''
    ) {
    }

    public const SENT = 'sent';
    public const RETRYABLE = 'retryable';
    public const REJECTED = 'rejected';

    /** @param string $reference the provider's own id for the delivery, where it gives one */
    public static function sent(string $reference = ''): self
    {
        return new self(self::SENT, '', $reference);
    }

    public static function retryable(string $message): self
    {
        return new self(self::RETRYABLE, $message);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isRetryable(): bool
    {
        return $this->status === self::RETRYABLE;
    }
}
