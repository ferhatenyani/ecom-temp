<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use wpdb;

/**
 * The only place that touches the ac_shipping_rates table.
 *
 * A shop's whole tariff is a handful of rows — a national rate and the
 * exceptions to it — so `active()` reads all of them in one query and lets
 * RateResolver pick in PHP. That is deliberate: expressing "most specific match
 * wins" as SQL means an ORDER BY that encodes the precedence a second time, in
 * a place no unit test can reach, and the two would drift.
 */
final class ShippingRuleRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_shipping_rates';
    }

    /** @return int|null the new row id, or null when the insert failed */
    public function insert(ShippingRule $rule): ?int
    {
        $row = $rule->toRow();
        $inserted = $this->wpdb->insert($this->table(), $row, ShippingRule::formatsFor($row));

        return $inserted === false ? null : (int) $this->wpdb->insert_id;
    }

    public function update(ShippingRule $rule): bool
    {
        if ($rule->id <= 0) {
            return false;
        }

        $row = $rule->toRow();

        // Not rewritable: an edit that could move created_at would let a price
        // change re-date the rule it changed.
        unset($row['created_at']);

        return $this->wpdb->update(
            $this->table(),
            $row,
            ['id' => $rule->id],
            ShippingRule::formatsFor($row),
            ['%d']
        ) !== false;
    }

    public function delete(int $id): bool
    {
        return $this->wpdb->delete($this->table(), ['id' => $id], ['%d']) !== false;
    }

    public function find(int $id): ?ShippingRule
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? ShippingRule::fromRow($row) : null;
    }

    /**
     * Every rule that could price something, for the resolver to choose from.
     *
     * @return list<ShippingRule>
     */
    public function active(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table()} WHERE is_active = 1",
            ARRAY_A
        );

        return array_map([ShippingRule::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * All rules, narrowest first, for an admin screen.
     *
     * Sorted the way the resolver reads them so a person editing a tariff sees
     * the exceptions above the rule they are exceptions to.
     *
     * @param array<string, mixed> $filters
     * @return list<ShippingRule>
     */
    public function all(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT * FROM {$this->table()} {$where}
                ORDER BY commune_id DESC, wilaya_id DESC, delivery_type DESC, provider DESC, id ASC";

        $rows = $this->wpdb->get_results(
            $params === [] ? $sql : $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );

        return array_map([ShippingRule::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * Whether another rule already covers exactly this scope.
     *
     * The table's unique key is the real guarantee; this exists so the caller
     * can be told *which* rule collides instead of being handed a database
     * error nobody can act on.
     */
    public function findConflict(ShippingRule $rule): ?ShippingRule
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE provider = %s AND wilaya_id = %d AND commune_id = %d AND delivery_type = %s
                   AND id <> %d
                 LIMIT 1",
                $rule->provider,
                $rule->wilayaId,
                $rule->communeId,
                $rule->deliveryType,
                $rule->id
            ),
            ARRAY_A
        );

        return is_array($row) ? ShippingRule::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach (['wilaya_id' => '%d', 'commune_id' => '%d', 'provider' => '%s', 'delivery_type' => '%s'] as $field => $format) {
            if (empty($filters[$field])) {
                continue;
            }

            $clauses[] = "{$field} = {$format}";
            $params[] = $format === '%d' ? (int) $filters[$field] : (string) $filters[$field];
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $clauses[] = 'is_active = %d';
            $params[] = $filters['is_active'] ? 1 : 0;
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
