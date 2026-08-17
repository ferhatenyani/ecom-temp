<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use wpdb;

/**
 * The only place that touches `ac_customer_segments` — migration 013.
 *
 * CRUD over a stored definition. The *resolving* of a definition into people is
 * `AudienceResolver`, deliberately a different class: this one knows nothing about
 * orders, shipments or consent, and that one knows nothing about how a segment is
 * stored.
 */
final class SegmentRepository
{
    /** @var list<string> */
    public const ORDERBY = ['name', 'created_at', 'updated_at', 'id'];

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_customer_segments';
    }

    public function insert(Segment $segment): ?int
    {
        $inserted = $this->wpdb->insert($this->table(), $segment->toRow(), $segment->rowFormats());

        return $inserted === false ? null : (int) $this->wpdb->insert_id;
    }

    public function update(Segment $segment): bool
    {
        if ($segment->id <= 0) {
            return false;
        }

        $row = $segment->toRow();
        unset($row['created_at']);

        return $this->wpdb->update(
            $this->table(),
            $row,
            ['id' => $segment->id],
            ['%s', '%s', '%s', '%d', '%s'],
            ['%d']
        ) !== false;
    }

    public function find(int $id): ?Segment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Segment::fromRow($row) : null;
    }

    /**
     * A segment with this name, ignoring one id.
     *
     * The unique index would refuse a duplicate anyway; this exists so the caller
     * is told which segment collides instead of being handed a database error —
     * `ShippingService::guardNoDuplicateScope()`'s reasoning.
     */
    public function findByName(string $name, int $ignoreId = 0): ?Segment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE name = %s AND id <> %d LIMIT 1",
                trim($name),
                $ignoreId
            ),
            ARRAY_A
        );

        return is_array($row) ? Segment::fromRow($row) : null;
    }

    public function delete(int $id): bool
    {
        return (bool) $this->wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    /** @return list<Segment> */
    public function paginate(int $page, int $perPage, string $orderby = 'name', string $order = 'asc'): array
    {
        $column = in_array($orderby, self::ORDERBY, true) ? $orderby : 'name';
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} ORDER BY {$column} {$direction}, id ASC LIMIT %d OFFSET %d",
                max(1, $perPage),
                max(0, ($page - 1) * $perPage)
            ),
            ARRAY_A
        );

        return array_map([Segment::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    public function count(): int
    {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    /**
     * How many campaigns name this segment.
     *
     * Read before a delete, because a campaign whose `segment_id` points at nothing
     * is one that cannot be resolved and reports "no audience" rather than "the
     * audience you chose was deleted".
     */
    public function campaignsUsing(int $id): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}ac_campaigns WHERE segment_id = %d",
                $id
            )
        );
    }
}
