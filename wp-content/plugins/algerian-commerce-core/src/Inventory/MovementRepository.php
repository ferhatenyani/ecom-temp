<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use wpdb;

/**
 * The only place that touches the ac_inventory_movements table.
 *
 * Append and read. A ledger is corrected by writing a compensating movement,
 * never by editing a row — an editable history cannot be reconciled, and the
 * balance invariant on InventoryMovement would be meaningless if a later
 * UPDATE could break it. Retention pruning is an operational concern, not
 * something the application performs mid-request.
 */
final class MovementRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_inventory_movements';
    }

    /** @return int|null the new row id, or null when the insert failed */
    public function insert(InventoryMovement $movement): ?int
    {
        $inserted = $this->wpdb->insert($this->table(), $movement->toRow(), $movement->rowFormats());

        if ($inserted === false) {
            return null;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT id, product_id, delta, quantity_before, quantity_after,
                       reason, note, order_id, actor_id, created_at
                FROM {$this->table()}
                {$where}
                ORDER BY id DESC
                LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = max(0, ($page - 1) * $perPage);

        // The table name comes from $wpdb->prefix, never from input; every
        // value goes through prepare().
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return is_array($rows) ? array_map([$this, 'hydrate'], $rows) : [];
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$this->table()} {$where}";

        $prepared = $params === [] ? $sql : $this->wpdb->prepare($sql, $params);

        return (int) $this->wpdb->get_var($prepared);
    }

    /**
     * Net change per reason over a filtered window.
     *
     * The reason movements live in their own table rather than in audit
     * metadata: this is a GROUP BY over indexed columns, not a decode-then-add
     * loop in PHP over every row ever written.
     *
     * @param array<string, mixed> $filters
     * @return array<string, array{net: int, movements: int}>
     */
    public function summariseByReason(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT reason, SUM(delta) AS net, COUNT(*) AS movements
                FROM {$this->table()}
                {$where}
                GROUP BY reason
                ORDER BY reason ASC";

        $prepared = $params === [] ? $sql : $this->wpdb->prepare($sql, $params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        $summary = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $summary[(string) $row['reason']] = [
                'net' => (int) $row['net'],
                'movements' => (int) $row['movements'],
            ];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach (['product_id' => '%d', 'order_id' => '%d', 'actor_id' => '%d', 'reason' => '%s'] as $field => $format) {
            if (empty($filters[$field])) {
                continue;
            }

            $clauses[] = "{$field} = {$format}";
            $params[] = $format === '%d' ? (int) $filters[$field] : (string) $filters[$field];
        }

        // Dates arrive as Y-m-d and are widened to cover the whole day, so
        // date_from=date_to=today returns today rather than nothing.
        if (!empty($filters['date_from'])) {
            $clauses[] = 'created_at >= %s';
            $params[] = ((string) $filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'created_at <= %s';
            $params[] = ((string) $filters['date_to']) . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'delta' => (int) $row['delta'],
            'quantity_before' => (int) $row['quantity_before'],
            'quantity_after' => (int) $row['quantity_after'],
            'reason' => (string) $row['reason'],
            'note' => (string) $row['note'],
            'order_id' => (int) $row['order_id'],
            'actor_id' => (int) $row['actor_id'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
