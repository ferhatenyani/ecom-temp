<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use wpdb;

/**
 * The only place that touches `ac_campaigns` — migration 011.
 *
 * The one method worth reading is `claimForSending()`. Everything else is CRUD.
 */
final class CampaignRepository
{
    /** @var list<string> */
    public const ORDERBY = ['created_at', 'updated_at', 'name', 'id'];

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_campaigns';
    }

    public function insert(Campaign $campaign): ?int
    {
        $inserted = $this->wpdb->insert($this->table(), $campaign->toRow(), $campaign->rowFormats());

        return $inserted === false ? null : (int) $this->wpdb->insert_id;
    }

    public function update(Campaign $campaign): bool
    {
        if ($campaign->id <= 0) {
            return false;
        }

        $row = $campaign->toRow();

        // The row's own birth is not rewritable, for the same reason
        // `ShipmentRepository::update()` refuses `created_at`.
        unset($row['created_at']);

        return $this->wpdb->update(
            $this->table(),
            $row,
            ['id' => $campaign->id],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function find(int $id): ?Campaign
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Campaign::fromRow($row) : null;
    }

    /**
     * Take the exclusive right to send this campaign.
     *
     * **This is the whole idempotency of a send, and it is one statement.** The
     * `WHERE status = 'draft'` is what makes it a claim rather than a check: two
     * `POST /campaigns/{id}/send` requests arriving together both read `draft`, both
     * would resolve the audience and both would try to write recipient rows — and
     * `UNIQUE (campaign_id, customer_id)` in migration 012 would refuse the second
     * set only after it had already resolved five thousand customers. Here, the
     * database decides: `rows_affected` is 1 for exactly one of them and 0 for the
     * other, which learns it lost rather than racing on.
     *
     * The same discipline as `WebhookEventRepository::claim()` and
     * `NotificationRepository::claim()` — a write whose failure *is* the answer,
     * never a read followed by a write — expressed as an UPDATE because the row
     * already exists.
     */
    public function claimForSending(int $id, string $now): bool
    {
        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table()}
                 SET status = %s, claimed_at = %s, updated_at = %s
                 WHERE id = %d AND status = %s",
                CampaignStatus::SENDING,
                $now,
                $now,
                $id,
                CampaignStatus::DRAFT
            )
        );

        return (int) $affected === 1;
    }

    public function setStatus(int $id, string $status, string $now, bool $complete = false): bool
    {
        $data = ['status' => $status, 'updated_at' => $now];
        $formats = ['%s', '%s'];

        if ($complete) {
            $data['completed_at'] = $now;
            $formats[] = '%s';
        }

        return $this->wpdb->update($this->table(), $data, ['id' => $id], $formats, ['%d']) !== false;
    }

    /**
     * Write the counts back after a resolve or a drain batch.
     *
     * Recomputed from `ac_campaign_recipients` by the caller rather than
     * incremented here: an increment that ran twice — a retried CLI invocation, a
     * cron overlapping a manual run — would drift, and the count is the record that
     * survives §85's purge.
     */
    public function setCounts(int $id, int $total, int $sent, int $failed, string $now): bool
    {
        return $this->wpdb->update(
            $this->table(),
            [
                'recipients_total' => $total,
                'recipients_sent' => $sent,
                'recipients_failed' => $failed,
                'updated_at' => $now,
            ],
            ['id' => $id],
            ['%d', '%d', '%d', '%s'],
            ['%d']
        ) !== false;
    }

    public function markPurged(int $id, string $now): void
    {
        $this->wpdb->update(
            $this->table(),
            ['purged_at' => $now, 'updated_at' => $now],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function delete(int $id): bool
    {
        return (bool) $this->wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<Campaign>
     */
    public function paginate(array $filters, int $page, int $perPage, string $orderby = 'created_at', string $order = 'desc'): array
    {
        [$where, $params] = $this->buildWhere($filters);

        // An ORDER BY cannot be parameterised, so both halves come from an
        // allowlist — the rule every repository here follows.
        $column = in_array($orderby, self::ORDERBY, true) ? $orderby : 'created_at';
        $direction = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$this->table()} {$where} ORDER BY {$column} {$direction}, id DESC LIMIT %d OFFSET %d";

        $params[] = max(1, $perPage);
        $params[] = max(0, ($page - 1) * $perPage);

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return array_map([Campaign::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$this->table()} {$where}";

        return (int) $this->wpdb->get_var($params === [] ? $sql : $this->wpdb->prepare($sql, $params));
    }

    /**
     * Campaigns the drain has work for, oldest claim first.
     *
     * @return list<Campaign>
     */
    public function sending(int $limit = 10): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE status = %s
                 ORDER BY claimed_at ASC, id ASC LIMIT %d",
                CampaignStatus::SENDING,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return array_map([Campaign::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * Completed campaigns whose recipient rows are older than the retention
     * window and have not been purged yet.
     *
     * @return list<int> campaign ids
     */
    public function purgeable(int $olderThan, string $now): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', (strtotime($now . ' UTC') ?: time()) - ($olderThan * 86400));

        $ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table()}
                 WHERE status IN (%s, %s)
                   AND purged_at IS NULL
                   AND completed_at IS NOT NULL
                   AND completed_at <= %s",
                CampaignStatus::SENT,
                CampaignStatus::CANCELLED,
                $cutoff
            )
        );

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        if (!empty($filters['segment_id'])) {
            $clauses[] = 'segment_id = %d';
            $params[] = (int) $filters['segment_id'];
        }

        if (!empty($filters['search'])) {
            $clauses[] = '(name LIKE %s OR subject LIKE %s)';
            $like = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
