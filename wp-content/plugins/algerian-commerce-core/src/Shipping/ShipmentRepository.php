<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use wpdb;

/**
 * The only place that touches the ac_shipments table.
 *
 * Unlike the stock ledger, these rows are **updated**: a shipment is one parcel
 * whose status changes as a courier moves it, not an append-only history of
 * events. What happened to it along the way is in the audit trail, which is the
 * store that is append-only by design — so the row answers "where is this
 * parcel" and the trail answers "what has been done to it", and neither has to
 * pretend to be the other.
 */
final class ShipmentRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_shipments';
    }

    /** @return int|null the new row id, or null when the insert failed */
    public function insert(Shipment $shipment): ?int
    {
        $inserted = $this->wpdb->insert($this->table(), $shipment->toRow(), $shipment->rowFormats());

        if ($inserted === false) {
            return null;
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update(Shipment $shipment): bool
    {
        if ($shipment->id <= 0) {
            return false;
        }

        $row = $shipment->toRow();

        // The row's own identity and its birth time are not rewritable: an
        // update that could move created_at would let a status change quietly
        // re-date the parcel.
        unset($row['created_at'], $row['order_id']);

        return $this->wpdb->update(
            $this->table(),
            $row,
            ['id' => $shipment->id],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function find(int $id): ?Shipment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Shipment::fromRow($row) : null;
    }

    /** @return list<Shipment> newest first */
    public function forOrder(int $orderId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC",
                $orderId
            ),
            ARRAY_A
        );

        return array_map([Shipment::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * The shipment for this order that has not finished yet, if there is one.
     *
     * This is the query behind "one live shipment per order". A finished
     * shipment — delivered, returned, cancelled, failed — does not block a new
     * one, which is what makes a re-send after a failed delivery possible
     * without deleting history.
     */
    public function liveForOrder(int $orderId): ?Shipment
    {
        $placeholders = implode(', ', array_fill(0, count(ShipmentStatus::TERMINAL), '%s'));

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE order_id = %d AND status NOT IN ({$placeholders})
                 ORDER BY id DESC LIMIT 1",
                [$orderId, ...ShipmentStatus::TERMINAL]
            ),
            ARRAY_A
        );

        return is_array($row) ? Shipment::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<Shipment>
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT * FROM {$this->table()} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = max(0, ($page - 1) * $perPage);

        // The table name comes from $wpdb->prefix, never from input; every
        // value goes through prepare().
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return array_map([Shipment::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$this->table()} {$where}";

        return (int) $this->wpdb->get_var($params === [] ? $sql : $this->wpdb->prepare($sql, $params));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach (['order_id' => '%d', 'provider' => '%s', 'status' => '%s', 'tracking_number' => '%s'] as $field => $format) {
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
}
