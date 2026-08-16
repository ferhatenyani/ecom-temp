<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use wpdb;

/**
 * The queue, and the claim — migration 010, docs/PLAN.md §29.
 *
 * `Marketing\MarketingEventRepository` is the model this follows, including the
 * one thing worth restating: **the claim is a write-once insert whose
 * duplicate-key failure is the answer**, never a read followed by a write. Two
 * requests saving the same order at the same moment both read "not queued" and
 * both insert; only one survives `UNIQUE (channel, dedupe_key)`, and the other
 * learns it lost from the database rather than from a race it did not notice.
 */
final class NotificationRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * How many times a transient failure is retried before the row is parked.
     *
     * Five, because the common transient cause is a mail server that is down
     * for minutes rather than seconds, and a row that has failed five drains is
     * not going to succeed on the sixth without someone looking at it.
     */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_notifications';
    }

    /**
     * Claim this notification for this channel, or report that it is already
     * claimed.
     *
     * @return int the new row id, or 0 when another writer got there first
     */
    public function claim(string $channel, Notification $notification): int
    {
        /*
         * Suppressed for this one statement: a duplicate key is the expected
         * outcome of a re-saved order, not a fault, and without this WordPress
         * prints a database error into the response on an ordinary double-save.
         */
        $suppressed = $this->wpdb->suppress_errors(true);

        $inserted = $this->wpdb->insert(
            $this->table(),
            [
                'channel' => $channel,
                'event' => $notification->event,
                'dedupe_key' => $notification->dedupeKey(),
                'audience' => $notification->audience,
                'recipient' => $notification->recipient,
                'subject_type' => $notification->subjectType,
                'subject_id' => $notification->subjectId,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'payload' => (string) wp_json_encode($notification->toArray()),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s']
        );

        $this->wpdb->suppress_errors($suppressed);

        return $inserted ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * The next rows to try, oldest first.
     *
     * Ordered by `created_at` so a backlog drains in the order it happened —
     * a customer should not receive "delivered" before "shipped".
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 50, string $channel = ''): array
    {
        $sql = "SELECT * FROM {$this->table()}
                 WHERE status = %s AND attempts < %d";
        $params = [self::STATUS_PENDING, self::MAX_ATTEMPTS];

        if ($channel !== '') {
            $sql .= ' AND channel = %s';
            $params[] = $channel;
        }

        $sql .= ' ORDER BY created_at ASC, id ASC LIMIT %d';
        $params[] = max(1, $limit);

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function markSent(int $id): void
    {
        $this->wpdb->update(
            $this->table(),
            ['status' => self::STATUS_SENT, 'sent_at' => current_time('mysql', true), 'last_error' => null],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Record a failed attempt.
     *
     * A retryable failure increments `attempts` and leaves the row pending, so
     * the next drain picks it up; a permanent one is parked immediately.
     * Without that distinction a malformed address is retried five times and
     * holds up everything behind it.
     */
    public function markFailed(int $id, string $error, bool $retryable): void
    {
        $attempts = (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT attempts FROM {$this->table()} WHERE id = %d", $id)
        );

        $attempts++;
        $exhausted = !$retryable || $attempts >= self::MAX_ATTEMPTS;

        $this->wpdb->update(
            $this->table(),
            [
                'status' => $exhausted ? self::STATUS_FAILED : self::STATUS_PENDING,
                'attempts' => $attempts,
                'last_error' => substr($error, 0, 500),
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    /** @return array<string, int> status => count */
    public function summary(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$this->table()} GROUP BY status",
            ARRAY_A
        );

        $out = [self::STATUS_PENDING => 0, self::STATUS_SENT => 0, self::STATUS_FAILED => 0];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Forget a `stock.low` claim so the shop can be told again.
     *
     * Deduplication is what stops an hourly email about a line that has been
     * low all week; it is also what would stop a *second* warning after the
     * line was restocked and fell low again. Clearing the claim when stock
     * recovers is what closes that, and `StockSubscriber` is where it is called.
     */
    public function forget(string $event, int $subjectId): int
    {
        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE event = %s AND subject_id = %d",
                $event,
                $subjectId
            )
        );
    }

    /**
     * The highest id in the queue, or 0 when it is empty.
     *
     * A watermark for a caller that is about to cause queueing and wants to
     * know afterwards what it caused — see `Seed\Seeder` (roadmap §67).
     */
    public function maxId(): int
    {
        return (int) $this->wpdb->get_var("SELECT MAX(id) FROM {$this->table()}");
    }

    /**
     * Drop every *pending* row above a watermark.
     *
     * This exists for the seeder and is deliberately narrow. Seeding eleven
     * orders queues a customer confirmation and an admin alert for each, and a
     * fictional order must not tell anybody it happened — the customer
     * addresses are on reserved domains that reach nobody (`SeedDataset`), but
     * the admin alert goes to a real inbox.
     *
     * **Pending only, and above a watermark only.** A row that has already been
     * sent is a record of something that left the building and is never
     * deleted, and rows queued before the caller started are not the caller's
     * to remove.
     */
    public function discardPendingAbove(int $id): int
    {
        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE id > %d AND status = %s",
                $id,
                self::STATUS_PENDING
            )
        );
    }
}
