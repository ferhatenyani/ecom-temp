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

        // provider, provider_shipment_id, tracking_number, status,
        // live_order_id, metadata, updated_at — in toRow() order, minus the two
        // unset above.
        return $this->wpdb->update(
            $this->table(),
            $row,
            ['id' => $shipment->id],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Take the exclusive right to create a shipment for one order.
     *
     * The invariant is "one live shipment per order", and checking it with a
     * read is not enough: `ShippingService::create()` reads the table, calls a
     * courier, then writes: two requests arriving together both read "none",
     * both hand over a parcel, and the customer gets two. Migration 006's unique
     * index refuses the second *row*, but by then the second *parcel* exists —
     * which is the failure §53's "call the provider last" rule was built to
     * avoid, arriving through a different door.
     *
     * `GET_LOCK` closes it because it is decided by the database server, not by
     * this process: whichever connection asks first wins, and the second is told
     * so immediately. The `0` timeout is deliberate — a caller waiting on this
     * lock is a duplicate request, and the useful answer is "no", not a queue.
     *
     * The lock is held across the courier call, which is exactly the window that
     * needs covering, and released in a `finally`. MySQL drops it on its own if
     * the connection dies, so a killed request cannot wedge an order.
     */
    public function claimOrder(int $orderId): bool
    {
        $acquired = $this->wpdb->get_var(
            $this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName($orderId))
        );

        return (string) $acquired === '1';
    }

    public function releaseOrder(int $orderId): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName($orderId))
        );
    }

    /**
     * Hashed, because `GET_LOCK` names are scoped to the whole MySQL **server**
     * rather than to a database: two WordPress installs sharing a server and a
     * table prefix would otherwise lock each other's orders. The database name
     * and prefix go into the hash, and truncating it to 40 hex characters keeps
     * the name inside MySQL's 64-byte limit — which it rejects outright, rather
     * than trimming — whatever they are called.
     */
    private function lockName(int $orderId): string
    {
        $scope = $this->wpdb->dbname . '|' . $this->wpdb->prefix . '|' . $orderId;

        return 'ac_ship_' . substr(hash('sha256', $scope), 0, 40);
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
     * Parcels still in the air, least recently checked first — the queue the
     * status poller works through (roadmap §56).
     *
     * Ordered by `updated_at` rather than by id so that a run capped at 50
     * moves on through the backlog instead of asking about the same fifty
     * parcels every hour. A shipment that a poll updates goes to the back of
     * the queue by that alone.
     *
     * `$staleBefore` is how "do not ask about a parcel we asked about ten
     * minutes ago" is expressed, which matters against an API whose rate limit
     * is not published.
     *
     * @return list<Shipment>
     */
    public function live(string $provider = '', int $limit = 50, string $staleBefore = ''): array
    {
        $placeholders = implode(', ', array_fill(0, count(ShipmentStatus::TERMINAL), '%s'));
        $params = ShipmentStatus::TERMINAL;
        $clauses = ["status NOT IN ({$placeholders})"];

        if (trim($provider) !== '') {
            $clauses[] = 'provider = %s';
            $params[] = trim($provider);
        }

        // A shipment the provider never accepted has no id to ask about, and
        // asking anyway is a guaranteed 404 against a quota.
        $clauses[] = "provider_shipment_id <> ''";

        if (trim($staleBefore) !== '') {
            $clauses[] = 'updated_at <= %s';
            $params[] = trim($staleBefore);
        }

        $params[] = max(1, $limit);

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE " . implode(' AND ', $clauses) . '
                 ORDER BY updated_at ASC, id ASC LIMIT %d',
                $params
            ),
            ARRAY_A
        );

        return array_map([Shipment::class, 'fromRow'], is_array($rows) ? $rows : []);
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
