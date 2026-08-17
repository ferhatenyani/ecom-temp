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

    /**
     * One row, or nothing — roadmap §90.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * The queue, filtered — roadmap §90.
     *
     * Newest first, which is the opposite of `pending()`. A drain sends in the
     * order things happened; an operator opens this because something went
     * wrong a minute ago.
     *
     * `dedupe_key` is an exact match and it is the filter that answers §90's
     * question — "did the customer get their confirmation?" is
     * `?dedupe_key=order.placed:1234`, because the key is `event:subject_id`
     * by construction (`Notification::dedupeKey()`).
     *
     * @param array{channel?: string, status?: string, dedupe_key?: string, date_from?: string, date_to?: string, page?: int, per_page?: int} $criteria
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $criteria): array
    {
        [$where, $params] = $this->buildWhere($criteria);

        $page = max(1, (int) ($criteria['page'] ?? 1));
        $perPage = max(1, (int) ($criteria['per_page'] ?? 20));

        /*
         * `payload` is deliberately not selected. §90: the list omits the
         * message body, so a support agent scanning a queue does not pull five
         * hundred customers' order contents into one response — and omitting it
         * from the *query* rather than from the presenter means the rows never
         * exist in this process at all.
         */
        $sql = "SELECT id, channel, event, dedupe_key, audience, recipient, subject_type, subject_id,
                       status, attempts, last_error, created_at, sent_at
                FROM {$this->table()}
                {$where}
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        // The table name is built from $wpdb->prefix, never from input; every
        // value goes through prepare().
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        [$countWhere, $countParams] = $this->buildWhere($criteria);
        $countSql = "SELECT COUNT(*) FROM {$this->table()} {$countWhere}";

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => (int) $this->wpdb->get_var(
                $countParams === [] ? $countSql : $this->wpdb->prepare($countSql, $countParams)
            ),
        ];
    }

    /**
     * Put a row back in the queue — roadmap §90.
     *
     * **One conditional `UPDATE`, and the condition is the guarantee.** A row
     * that is already `sent` must not be re-queued: it is a record of something
     * that left the building, and re-sending replays a body frozen weeks ago at
     * whoever it was addressed to. Reading the status and then writing would
     * leave a window in which a drain sends the row between the two — §85's
     * campaign claim is the same statement for the same reason, and this is the
     * smaller version of it.
     *
     * `attempts` goes back to zero because the point of a retry is a fresh set
     * of tries; `last_error` is cleared because it describes an attempt that is
     * no longer the latest one.
     *
     * **The affected-row count alone cannot answer this, and reading it as if
     * it could is a bug this shipped once.** MySQL reports rows it *changed*,
     * not rows it *matched* — measured 2026-08-17 on this stack: the statement
     * below returned 0 against a row that was already `pending` with zero
     * attempts and no error, and 1 against the same row when one value
     * differed. So retrying an already-queued row answered **409 "already
     * sent"** about a row that had never been sent. (`CLIENT_FOUND_ROWS` would
     * change the semantics, and it is a connection flag WordPress owns, not a
     * per-statement one.)
     *
     * So zero affected rows is ambiguous and is resolved by re-reading. The
     * guarantee is unaffected: a `sent` row is never written, because that is
     * the `WHERE`, and the re-read only decides which of the two reasons for
     * zero applies.
     *
     * @return bool false when the row is already sent, or is not there
     */
    public function requeue(int $id): bool
    {
        $affected = (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table()}
                    SET status = %s, attempts = 0, last_error = NULL
                  WHERE id = %d AND status <> %s",
                self::STATUS_PENDING,
                $id,
                self::STATUS_SENT
            )
        );

        if ($affected > 0) {
            return true;
        }

        $row = $this->find($id);

        return $row !== null && (string) $row['status'] !== self::STATUS_SENT;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $criteria): array
    {
        $clauses = [];
        $params = [];

        foreach (['channel', 'status', 'dedupe_key'] as $field) {
            if (($criteria[$field] ?? '') !== '') {
                $clauses[] = "{$field} = %s";
                $params[] = (string) $criteria[$field];
            }
        }

        /*
         * Both ends cover the whole day, matching `/orders`. `created_at` is
         * UTC — every writer here uses `current_time('mysql', true)` — so the
         * bounds are UTC too, and the response says so rather than quietly
         * shifting a shop's day by an hour.
         */
        if (($criteria['date_from'] ?? '') !== '') {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $criteria['date_from'] . ' 00:00:00';
        }

        if (($criteria['date_to'] ?? '') !== '') {
            $clauses[] = 'created_at <= %s';
            $params[] = (string) $criteria['date_to'] . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
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
