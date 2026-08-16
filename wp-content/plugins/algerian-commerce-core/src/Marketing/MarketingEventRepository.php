<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

use wpdb;

/**
 * The queue and the claim — roadmap §62b, migration 009.
 *
 * `claim()` is the whole idempotency mechanism and it is deliberately a
 * **write**: an insert whose duplicate-key failure *is* the answer, exactly as
 * `Commerce\WebhookEventRepository` does for inbound events. There is no `has()`
 * and there must never be one — a read-then-write races precisely when a
 * customer double-clicks the pay button, which is the case this exists for.
 */
final class MarketingEventRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Attempts before a pending event is abandoned.
     *
     * Bounded, because the alternative is an event that failed for a permanent
     * reason nobody noticed being retried hourly forever, burning the account's
     * rate limit and burying the events that would have worked.
     */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'ac_marketing_events';
    }

    /**
     * Take the event, or discover somebody already has.
     *
     * @return int the row id, or 0 when the claim was refused as a duplicate
     */
    public function claim(string $provider, MarketingEvent $event): int
    {
        /*
         * Errors are suppressed for this one statement. A duplicate key is the
         * expected outcome on a repeated purchase, not a fault, and without
         * this WordPress prints a database error to the response on a perfectly
         * ordinary double-submit.
         */
        $suppressed = $this->wpdb->suppress_errors(true);

        $inserted = $this->wpdb->insert(
            $this->table(),
            [
                'provider' => $provider,
                'event_name' => $event->name,
                'event_id' => $event->eventId,
                'order_id' => $event->orderId,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'payload' => (string) wp_json_encode($event->toArray()),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
        );

        $this->wpdb->suppress_errors($suppressed);

        return $inserted === false ? 0 : (int) $this->wpdb->insert_id;
    }

    /**
     * The next events waiting to go out, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 50, ?string $provider = null): array
    {
        $sql = "SELECT * FROM {$this->table()} WHERE status = %s AND attempts < %d";
        $args = [self::STATUS_PENDING, self::MAX_ATTEMPTS];

        if ($provider !== null && $provider !== '') {
            $sql .= ' AND provider = %s';
            $args[] = $provider;
        }

        $sql .= ' ORDER BY created_at ASC, id ASC LIMIT %d';
        $args[] = max(1, $limit);

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function markSent(int $id, string $reference = ''): void
    {
        $this->wpdb->update(
            $this->table(),
            [
                'status' => self::STATUS_SENT,
                'sent_at' => current_time('mysql', true),
                'last_error' => $reference === '' ? null : 'reference: ' . $reference,
                'attempts' => $this->attempts($id) + 1,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );
    }

    /**
     * Record a failure.
     *
     * A retryable failure stays `pending` with its attempt counter raised, so
     * the next drain picks it up; a rejection goes straight to `failed`,
     * because a payload the provider refused will be refused again.
     */
    public function markFailed(int $id, string $error, bool $retryable): void
    {
        $attempts = $this->attempts($id) + 1;

        $this->wpdb->update(
            $this->table(),
            [
                'status' => $retryable && $attempts < self::MAX_ATTEMPTS
                    ? self::STATUS_PENDING
                    : self::STATUS_FAILED,
                'attempts' => $attempts,
                // Truncated: a provider that answers with an HTML error page
                // would otherwise fill a TEXT column with a stack trace.
                'last_error' => mb_substr($error, 0, 500),
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    /** @return array<string, int> status → count, for the CLI report */
    public function summary(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$this->table()} GROUP BY status",
            ARRAY_A
        );

        $summary = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $summary[(string) $row['status']] = (int) $row['total'];
        }

        return $summary;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function attempts(int $id): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT attempts FROM {$this->table()} WHERE id = %d", $id)
        );
    }
}
