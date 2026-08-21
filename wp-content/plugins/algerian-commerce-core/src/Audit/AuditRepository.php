<?php

declare(strict_types=1);

namespace AlgerianCommerce\Audit;

use wpdb;

/**
 * The only place that touches the ac_audit_logs table.
 *
 * Append and read: there is no update or delete. Audit records are
 * append-only (docs/SECURITY.md), and retention pruning is a separate
 * operational concern, not something the application performs mid-request.
 */
final class AuditRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_audit_logs';
    }

    /** @return int|null the new row id, or null when the insert failed */
    public function insert(AuditEvent $event): ?int
    {
        $inserted = $this->wpdb->insert($this->table(), $event->toRow(), $event->rowFormats());

        if ($inserted === false) {
            return null;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array{action?: string, actor_id?: int, resource_type?: string, resource_id?: string, date_from?: string, date_to?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT id, actor_id, actor_login, action, resource_type, resource_id, ip_address, metadata, created_at
                FROM {$this->table()}
                {$where}
                ORDER BY id DESC
                LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = max(0, ($page - 1) * $perPage);

        // Table name is built from $wpdb->prefix, never from input; every
        // value goes through prepare().
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'hydrate'], $rows) : [];
    }

    /** @param array{action?: string, actor_id?: int, resource_type?: string, resource_id?: string, date_from?: string, date_to?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$this->table()} {$where}";

        $prepared = $params === [] ? $sql : $this->wpdb->prepare($sql, $params);

        return (int) $this->wpdb->get_var($prepared);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['action'])) {
            $clauses[] = 'action = %s';
            $params[] = (string) $filters['action'];
        }

        if (!empty($filters['actor_id'])) {
            $clauses[] = 'actor_id = %d';
            $params[] = (int) $filters['actor_id'];
        }

        if (!empty($filters['resource_type'])) {
            $clauses[] = 'resource_type = %s';
            $params[] = (string) $filters['resource_type'];
        }

        // Only meaningful alongside resource_type — ids are unique per type,
        // not globally. The order timeline pairs the two.
        if (!empty($filters['resource_id'])) {
            $clauses[] = 'resource_id = %s';
            $params[] = (string) $filters['resource_id'];
        }

        /*
         * Both ends cover the whole day, matching `/notifications` and
         * `/orders`. `created_at` is UTC — `AuditEvent` stamps
         * `gmdate('Y-m-d H:i:s')` and nothing else writes this table — so the
         * bounds are UTC too rather than quietly shifting a shop's day.
         *
         * This is the difference between a usable screen and a scroll: the
         * trail is append-only and unpruned, so a shop a few months old
         * already answers tens of thousands of rows, and at 20 a page "what
         * happened yesterday" is otherwise a page number somebody has to
         * guess. `KEY created_at` has been on the table since migration 001,
         * put there for exactly this range.
         */
        if (($filters['date_from'] ?? '') !== '') {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $filters['date_from'] . ' 00:00:00';
        }

        if (($filters['date_to'] ?? '') !== '') {
            $clauses[] = 'created_at <= %s';
            $params[] = (string) $filters['date_to'] . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $metadata = [];

        if (is_string($row['metadata'] ?? null) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) $row['id'],
            'actor_id' => (int) $row['actor_id'],
            'actor_login' => (string) $row['actor_login'],
            'action' => (string) $row['action'],
            'resource_type' => (string) $row['resource_type'],
            'resource_id' => (string) $row['resource_id'],
            'ip_address' => (string) $row['ip_address'],
            'metadata' => $metadata,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
