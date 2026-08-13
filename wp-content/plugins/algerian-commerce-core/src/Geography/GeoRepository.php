<?php

declare(strict_types=1);

namespace AlgerianCommerce\Geography;

use wpdb;

/**
 * The only place that touches the ac_geo_* tables.
 *
 * Writes are upserts keyed on the natural keys — the wilaya code, and
 * (wilaya, commune slug) — so re-importing a corrected dataset updates rows in
 * place. Ids stay stable, which matters because orders, shipments and provider
 * mappings will point at them.
 *
 * Nothing here deletes. A commune that disappears from a new dataset is
 * deactivated rather than removed: it may still be referenced by a delivered
 * order, and a foreign key that dangles is worse than a row nobody selects.
 */
final class GeoRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function wilayaTable(): string
    {
        return $this->wpdb->prefix . 'ac_geo_wilayas';
    }

    public function communeTable(): string
    {
        return $this->wpdb->prefix . 'ac_geo_communes';
    }

    public function destinationTable(): string
    {
        return $this->wpdb->prefix . 'ac_geo_provider_destinations';
    }

    /**
     * @param array{search?: string, active_only?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function wilayas(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['active_only'])) {
            $where[] = 'is_active = 1';
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE %s OR slug LIKE %s OR code = %s)';
            $like = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = str_pad((string) $filters['search'], 2, '0', STR_PAD_LEFT);
        }

        $sql = 'SELECT id, code, slug, name, name_ar, is_active
                FROM ' . $this->wilayaTable()
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY id ASC';

        // The table name comes from $wpdb->prefix, never from input; every
        // value goes through prepare().
        $rows = $this->wpdb->get_results(
            $params === [] ? $sql : $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );

        return array_map([$this, 'hydrateWilaya'], is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed>|null */
    public function findWilaya(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, code, slug, name, name_ar, is_active FROM ' . $this->wilayaTable() . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateWilaya($row) : null;
    }

    /**
     * @param array{wilaya_id?: int, search?: string, postal_code?: string, active_only?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function communes(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['wilaya_id'])) {
            $where[] = 'wilaya_id = %d';
            $params[] = (int) $filters['wilaya_id'];
        }

        if (!empty($filters['active_only'])) {
            $where[] = 'is_active = 1';
        }

        if (!empty($filters['postal_code'])) {
            $where[] = 'postal_code = %s';
            $params[] = (string) $filters['postal_code'];
        }

        if (!empty($filters['search'])) {
            // Matches the daira too: people routinely name the daira when
            // asked where they live, and an autocomplete that only knows
            // communes rejects half of what they type.
            $where[] = '(name LIKE %s OR slug LIKE %s OR name_ar LIKE %s OR daira LIKE %s)';
            $like = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT id, wilaya_id, slug, name, name_ar, daira, daira_ar,
                       postal_code, national_code, latitude, longitude, is_active
                FROM ' . $this->communeTable()
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY wilaya_id ASC, name ASC';

        $rows = $this->wpdb->get_results(
            $params === [] ? $sql : $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );

        return array_map([$this, 'hydrateCommune'], is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed>|null */
    public function findCommune(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, wilaya_id, slug, name, name_ar, daira, daira_ar,
                        postal_code, national_code, latitude, longitude, is_active
                 FROM ' . $this->communeTable() . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrateCommune($row) : null;
    }

    public function countWilayas(): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->wilayaTable());
    }

    public function countCommunes(): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->communeTable());
    }

    public function countDestinations(): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $this->destinationTable());
    }

    /**
     * Commune ids keyed "wilayaCode/slug", for resolving provider mappings.
     *
     * @return array<string, int>
     */
    public function communeIdsBySlug(): array
    {
        $rows = $this->wpdb->get_results(
            'SELECT id, wilaya_id, slug FROM ' . $this->communeTable(),
            ARRAY_A
        );

        $index = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $index[$row['wilaya_id'] . '/' . $row['slug']] = (int) $row['id'];
        }

        return $index;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{inserted: int, updated: int}
     */
    public function upsertWilayas(array $rows): array
    {
        $inserted = 0;
        $updated = 0;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $exists = $this->findWilaya((int) $row['id']) !== null;

            $this->wpdb->query($this->wpdb->prepare(
                'INSERT INTO ' . $this->wilayaTable() . '
                    (id, code, slug, name, name_ar, is_active, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE
                    code = VALUES(code), slug = VALUES(slug), name = VALUES(name),
                    name_ar = VALUES(name_ar), is_active = VALUES(is_active),
                    updated_at = VALUES(updated_at)',
                $row['id'],
                $row['code'],
                $row['slug'],
                $row['name'],
                $row['name_ar'],
                $row['is_active'],
                $now
            ));

            $exists ? $updated++ : $inserted++;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{inserted: int, updated: int}
     */
    public function upsertCommunes(array $rows): array
    {
        $existing = $this->communeIdsBySlug();
        $inserted = 0;
        $updated = 0;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $key = $row['wilaya_id'] . '/' . $row['slug'];

            /*
             * Coordinates go in as %s, not %f. wpdb's %f formats through the
             * locale, so on a fr_FR host a longitude of -0.297222 is written
             * as "-0,297222" and MySQL stores 0. Passing the decimal string
             * through unchanged lets MySQL parse it, which it does correctly.
             * NULL is passed as a literal because prepare() has no null
             * placeholder.
             */
            $latitude = $row['latitude'] === null ? 'NULL' : "'" . esc_sql((string) $row['latitude']) . "'";
            $longitude = $row['longitude'] === null ? 'NULL' : "'" . esc_sql((string) $row['longitude']) . "'";

            $this->wpdb->query($this->wpdb->prepare(
                'INSERT INTO ' . $this->communeTable() . '
                    (wilaya_id, slug, name, name_ar, daira, daira_ar, postal_code,
                     national_code, latitude, longitude, is_active, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, ' . $latitude . ', ' . $longitude . ', %d, %s)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name), name_ar = VALUES(name_ar),
                    daira = VALUES(daira), daira_ar = VALUES(daira_ar),
                    postal_code = VALUES(postal_code), national_code = VALUES(national_code),
                    latitude = VALUES(latitude), longitude = VALUES(longitude),
                    is_active = VALUES(is_active), updated_at = VALUES(updated_at)',
                $row['wilaya_id'],
                $row['slug'],
                $row['name'],
                $row['name_ar'],
                $row['daira'],
                $row['daira_ar'],
                $row['postal_code'],
                $row['national_code'],
                $row['is_active'],
                $now
            ));

            isset($existing[$key]) ? $updated++ : $inserted++;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Every destination one provider has mapped — roadmap §56's sync writes
     * these, and its adapter reads them back to address a parcel.
     *
     * The whole provider at once, not one row per lookup: a shop creating a
     * parcel needs the wilaya row and the commune row together, a rates call
     * needs both again, and a courier's map is a few thousand short rows. One
     * query beats a lookup per field.
     *
     * @return list<array<string, mixed>>
     */
    public function destinations(string $provider): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT provider, wilaya_id, commune_id, destination_id, metadata
                 FROM ' . $this->destinationTable() . '
                 WHERE provider = %s
                 ORDER BY wilaya_id ASC, commune_id ASC',
                $provider
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{inserted: int, updated: int}
     */
    public function upsertDestinations(array $rows): array
    {
        $inserted = 0;
        $updated = 0;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $exists = (int) $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $this->destinationTable()
                . ' WHERE provider = %s AND wilaya_id = %d AND commune_id = %d',
                $row['provider'],
                $row['wilaya_id'],
                $row['commune_id']
            )) > 0;

            $this->wpdb->query($this->wpdb->prepare(
                'INSERT INTO ' . $this->destinationTable() . '
                    (provider, wilaya_id, commune_id, destination_id, metadata, updated_at)
                 VALUES (%s, %d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    destination_id = VALUES(destination_id), metadata = VALUES(metadata),
                    updated_at = VALUES(updated_at)',
                $row['provider'],
                $row['wilaya_id'],
                $row['commune_id'],
                $row['destination_id'],
                (string) wp_json_encode($row['metadata']),
                $now
            ));

            $exists ? $updated++ : $inserted++;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateWilaya(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'name_ar' => (string) $row['name_ar'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateCommune(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'wilaya_id' => (int) $row['wilaya_id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'name_ar' => (string) $row['name_ar'],
            'daira' => (string) $row['daira'],
            'daira_ar' => (string) $row['daira_ar'],
            'postal_code' => (string) $row['postal_code'],
            'national_code' => (string) $row['national_code'],
            // Emitted as strings, like every other decimal this API returns:
            // a coordinate is a fixed-precision value, and JSON floats are the
            // one thing guaranteed to change it in transit.
            'latitude' => $row['latitude'] === null ? null : (string) $row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (string) $row['longitude'],
            'is_active' => (bool) $row['is_active'],
        ];
    }
}
