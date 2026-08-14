<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

use AlgerianCommerce\Shipping\ProviderPlace;

/**
 * Yalidine's own answer to "where do you deliver" — the three list endpoints,
 * read into our `ProviderPlace` shape.
 *
 * ```
 * GET wilayas/    the wilayas this account can send to
 * GET communes/   every commune, with the wilaya it belongs to
 * GET centers/    the stop desks, each in a commune
 * ```
 *
 * All three answer with `{has_more, total_data, data: [...], links}` and take
 * `?page_size=` and `?page=` — verified against the live API on 2026-08-14,
 * along with every field name read below.
 *
 * **Nothing here matches anything.** It reads what the courier published and
 * stops; `DestinationMatcher` decides what lines up with the §51 dataset, which
 * is why the hard part is pure and testable. Rows are still read defensively —
 * one without an id and a name is skipped rather than guessed at, and the sync
 * reports the shortfall instead of writing a map with holes it does not mention.
 */
final class YalidineDestinations
{
    /** Roadmap §56: the page size these endpoints are read with. */
    private const PAGE_SIZE = 1000;

    /**
     * A ceiling on paging, not an expectation.
     *
     * 1,541 communes at 1,000 a page is two requests. This exists so that a
     * `has_more` that never turns false — a bug at either end — costs a bounded
     * number of calls against an undisclosed quota instead of an endless loop.
     */
    private const MAX_PAGES = 20;

    public function __construct(private readonly YalidineClient $client)
    {
    }

    /** @return list<ProviderPlace> */
    public function all(): array
    {
        return [...$this->wilayas(), ...$this->communes(), ...$this->centers()];
    }

    /** @return list<ProviderPlace> */
    public function wilayas(): array
    {
        $places = [];

        foreach ($this->pages('wilayas/') as $row) {
            /*
             * Verified 2026-08-14: a wilaya row is exactly
             * `{id, name, zone, is_deliverable}` — e.g.
             * `{"id": 1, "name": "Adrar", "zone": 4, "is_deliverable": 1}`.
             * Note the **integer** 1/0 rather than a JSON boolean, which is why
             * `deliverable()` below cannot simply cast.
             *
             * `wilaya_id`/`wilaya_name` are still accepted as alternates: they
             * cost nothing and that is how the same fields are spelled on the
             * other endpoints.
             */
            $id = self::str($row, ['id', 'wilaya_id']);
            $name = self::str($row, ['name', 'wilaya_name']);

            if ($id === '' || $name === '') {
                continue;
            }

            $places[] = new ProviderPlace(
                ProviderPlace::WILAYA,
                $id,
                $name,
                '',
                '',
                self::deliverable($row),
                self::scalars($row)
            );
        }

        return $places;
    }

    /** @return list<ProviderPlace> */
    public function communes(): array
    {
        $places = [];

        foreach ($this->pages('communes/') as $row) {
            /*
             * Verified 2026-08-14: `{id, name, wilaya_id, wilaya_name,
             * has_stop_desk, is_deliverable, delivery_time_parcel,
             * delivery_time_payment}`.
             *
             * The two delivery-time fields are kept in the metadata rather than
             * turned into `RateQuote::$estimatedDays`: they are plainly a
             * duration, but nothing observed says in what unit, and a storefront
             * promising "15" of the wrong thing is worse than promising nothing
             * (see RateQuote on why that field is nullable).
             */
            $id = self::str($row, ['id', 'commune_id']);
            $name = self::str($row, ['name', 'commune_name']);
            $wilayaId = self::str($row, ['wilaya_id']);

            if ($id === '' || $name === '' || $wilayaId === '') {
                continue;
            }

            $places[] = new ProviderPlace(
                ProviderPlace::COMMUNE,
                $id,
                $name,
                $wilayaId,
                '',
                self::deliverable($row),
                self::scalars($row)
            );
        }

        return $places;
    }

    /**
     * The stop desks.
     *
     * This is the one shape taken verbatim from a DTO rather than assumed:
     * `{center_id, name, address, gps, commune_id, commune_name, wilaya_id,
     * wilaya_name}`.
     *
     * @return list<ProviderPlace>
     */
    public function centers(): array
    {
        $places = [];

        foreach ($this->pages('centers/') as $row) {
            $id = self::str($row, ['center_id', 'id']);
            $communeId = self::str($row, ['commune_id']);

            if ($id === '' || $communeId === '') {
                continue;
            }

            $places[] = new ProviderPlace(
                ProviderPlace::CENTER,
                $id,
                self::str($row, ['name']),
                self::str($row, ['wilaya_id']),
                $communeId,
                true,
                self::scalars($row)
            );
        }

        return $places;
    }

    /**
     * Every row of a list endpoint, following `has_more`.
     *
     * @return list<array<string, mixed>>
     */
    private function pages(string $path): array
    {
        $rows = [];
        $page = 1;

        do {
            // Verified 2026-08-14: `page` selects the page, counting from 1 —
            // `?page_size=2&page=2` returned wilayas 3 and 4 where page 1
            // returned 1 and 2. The envelope is
            // `{has_more, total_data, data, links}`.
            $response = $this->client->get($path, ['page_size' => self::PAGE_SIZE, 'page' => $page]);

            if (!is_array($response)) {
                break;
            }

            $data = $response['data'] ?? [];

            if (!is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $hasMore = (bool) ($response['has_more'] ?? false);
            $page++;
        } while ($hasMore && $page <= self::MAX_PAGES);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $keys in order of preference
     */
    private static function str(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    /**
     * Absent means deliverable.
     *
     * A courier that publishes a place without saying otherwise is publishing
     * somewhere it delivers; defaulting to "no" would empty the map the first
     * time the field is renamed, and an empty map fails every parcel.
     *
     * @param array<string, mixed> $row
     */
    private static function deliverable(array $row): bool
    {
        if (!array_key_exists('is_deliverable', $row)) {
            return true;
        }

        $value = $row['is_deliverable'];

        // JSON booleans arrive as booleans, but a provider that stores this in
        // MySQL may send 1/0 or "1"/"0", and "0" is truthy in PHP.
        return !in_array($value, [false, 0, '0', '', null], true);
    }

    /**
     * The row as sent, minus anything that is not a plain value.
     *
     * Kept so the courier's own record of a place survives next to our mapping
     * — a commune's `has_stop_desk`, a wilaya's `zone`, whatever they add next
     * — without this class having to know in advance what any of it means.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function scalars(array $row): array
    {
        return array_filter($row, static fn (mixed $value): bool => is_scalar($value));
    }
}
