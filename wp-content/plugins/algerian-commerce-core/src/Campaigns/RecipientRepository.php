<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use wpdb;

/**
 * The only place that touches `ac_campaign_recipients` — migration 012.
 *
 * **This table is why the campaign queue could not be `ac_notifications`**, and the
 * two methods that matter are `freeze()` and `pending()`: the first writes the
 * audience once, the second is what a resumed drain reads. Everything else counts.
 *
 * **Every row here holds a real email address**, which §85 states plainly rather
 * than pretending otherwise: an SMTP server needs one and this row outlives the
 * request. `purge()` is the other half of that admission.
 */
final class RecipientRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const TERMINAL = [self::STATUS_SENT, self::STATUS_FAILED];

    /**
     * Attempts before a recipient is parked.
     *
     * Three rather than `NotificationRepository`'s five. A transactional message
     * is one customer waiting for one receipt and is worth retrying; a campaign is
     * thousands of rows, and a mail server that has refused the same address three
     * times is telling the shop something about that address.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Rows written per `INSERT`.
     *
     * Batched because a five-thousand-recipient audience is five thousand rows and
     * one statement per row is five thousand round trips on a request path.
     * `$wpdb` has no bulk insert, so the VALUES list is built here — every value
     * still goes through `prepare()`, and the batch is bounded so the statement
     * cannot outgrow `max_allowed_packet`.
     */
    private const INSERT_BATCH = 200;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_campaign_recipients';
    }

    /**
     * Freeze a resolved audience into rows.
     *
     * **`INSERT IGNORE`, and the `UNIQUE (campaign_id, customer_id)` behind it, are
     * what make this idempotent.** A retried request or a resolve that ran twice
     * inserts nothing the second time, so nobody is queued twice for one campaign —
     * a database guarantee rather than a comparison somebody has to remember.
     *
     * @param list<array{customer_id: int, email: string, name: string, context: array<string, string>}> $recipients
     * @return int rows actually inserted
     */
    public function freeze(int $campaignId, array $recipients, string $now): int
    {
        if ($campaignId <= 0 || $recipients === []) {
            return 0;
        }

        $inserted = 0;

        foreach (array_chunk($recipients, self::INSERT_BATCH) as $chunk) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $recipient) {
                $context = json_encode($recipient['context'] ?? []);

                $placeholders[] = '(%d, %d, %s, %s, %s, %s, 0, %s)';
                $values[] = $campaignId;
                $values[] = (int) $recipient['customer_id'];
                $values[] = mb_substr((string) $recipient['email'], 0, 191);
                $values[] = mb_substr((string) $recipient['name'], 0, 191);
                $values[] = $context === false ? '{}' : $context;
                $values[] = self::STATUS_PENDING;
                $values[] = $now;
            }

            $sql = "INSERT IGNORE INTO {$this->table()}
                    (campaign_id, customer_id, email, name, context, status, attempts, created_at)
                    VALUES " . implode(', ', $placeholders);

            $affected = $this->wpdb->query($this->wpdb->prepare($sql, $values));

            if ($affected !== false) {
                $inserted += (int) $affected;
            }
        }

        return $inserted;
    }

    /**
     * The next rows to send for one campaign, oldest first.
     *
     * **This single query is what makes a resume correct.** A drain interrupted at
     * recipient 3,000 leaves rows 1–3,000 marked `sent`, so the next invocation
     * reads 3,001 onward and nobody is mailed twice. That is what the per-recipient
     * row earns its table for.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $campaignId, int $limit = 50): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE campaign_id = %d AND status = %s AND attempts < %d
                 ORDER BY id ASC LIMIT %d",
                $campaignId,
                self::STATUS_PENDING,
                self::MAX_ATTEMPTS,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(int $campaignId, array $filters, int $page, int $perPage): array
    {
        $clauses = ['campaign_id = %d'];
        $params = [$campaignId];

        if (!empty($filters['status'])) {
            $clauses[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        $params[] = max(1, $perPage);
        $params[] = max(0, ($page - 1) * $perPage);

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $clauses)
                . ' ORDER BY id ASC LIMIT %d OFFSET %d',
                $params
            ),
            ARRAY_A
        );

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
     * A retryable failure leaves the row pending so the next drain picks it up; a
     * permanent one — a malformed address — is parked immediately, because
     * retrying it three times holds up nothing but wastes three sends' worth of
     * the rate cap.
     */
    public function markFailed(int $id, string $error, bool $retryable): void
    {
        $attempts = 1 + (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT attempts FROM {$this->table()} WHERE id = %d", $id)
        );

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

    /** @return array{total: int, pending: int, sent: int, failed: int} */
    public function counts(int $campaignId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT status, COUNT(*) AS total FROM {$this->table()} WHERE campaign_id = %d GROUP BY status",
                $campaignId
            ),
            ARRAY_A
        );

        $out = ['total' => 0, self::STATUS_PENDING => 0, self::STATUS_SENT => 0, self::STATUS_FAILED => 0];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $status = (string) $row['status'];
            $count = (int) $row['total'];
            $out['total'] += $count;

            if (array_key_exists($status, $out)) {
                $out[$status] = $count;
            }
        }

        return $out;
    }

    /**
     * Whether a campaign still has anything to send.
     *
     * Counts rows that are pending *and* still have attempts left, which is not the
     * same as "pending": a row parked at `MAX_ATTEMPTS` while still marked pending
     * would otherwise keep a campaign in `sending` forever.
     */
    public function remaining(int $campaignId): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table()}
                 WHERE campaign_id = %d AND status = %s AND attempts < %d",
                $campaignId,
                self::STATUS_PENDING,
                self::MAX_ATTEMPTS
            )
        );
    }

    /**
     * Drop the addresses, keep the counts — §85's PII rule.
     *
     * The counts live on `ac_campaigns` precisely so this is possible: after a
     * purge the shop can still say a campaign reached 4,812 people and failed for
     * 19, and can no longer say who they were. That is the trade §85 asks for, and
     * it is the reason the counts are columns rather than a `COUNT(*)` over this
     * table.
     *
     * @return int rows removed
     */
    public function purge(int $campaignId): int
    {
        return (int) $this->wpdb->query(
            $this->wpdb->prepare("DELETE FROM {$this->table()} WHERE campaign_id = %d", $campaignId)
        );
    }

    /**
     * Which of these customers already has a row for this campaign.
     *
     * Used by the test-send path so a preview does not consume a real recipient's
     * row, and by `tests/Api/campaigns.php` to prove the unique key is doing the
     * work rather than a comparison in PHP.
     *
     * @param list<int> $customerIds
     * @return list<int>
     */
    public function existingCustomerIds(int $campaignId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($customerIds), '%d'));

        $ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT customer_id FROM {$this->table()}
                 WHERE campaign_id = %d AND customer_id IN ({$placeholders})",
                [$campaignId, ...array_map('intval', $customerIds)]
            )
        );

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }
}
