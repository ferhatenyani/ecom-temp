<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\Geography\GeoSlug;

/**
 * Ties a courier's places to the §51 dataset, and names everything left over.
 *
 * Pure — no WordPress, no database, no HTTP. It takes what the provider
 * published and what we have on file, and returns the rows to write plus the
 * gaps in both directions. That is what makes the hardest part of a courier
 * integration testable without an account.
 *
 * **Matched on the accent-folded name, never on the id.** Two rules are at work
 * and both are deliberate:
 *
 *  - Ids are not comparable. Yalidine's wilaya ids look like the official
 *    Algerian codes right up until they do not, and roadmap §56 is explicit
 *    that the sync must never parse them. They are stored, not interpreted.
 *  - Names are, once folded. `GeoSlug` is reused rather than reimplemented —
 *    it is the codebase's one accent-folding table, already the natural key the
 *    geography importer upserts on, and already tested. Béjaïa and Bejaia have
 *    to reach the same commune whichever side spelled it which way, which is the
 *    exact failure the reference implementation works around at parcel-creation
 *    time by re-fetching the fees endpoint and matching names by hand.
 *
 * A name that folds to nothing matches nothing: that is a courier sending an
 * Arabic-only or empty label, and inventing a match for it would be worse than
 * reporting it.
 */
final class DestinationMatcher
{
    /**
     * @param list<ProviderPlace>        $places   what the courier published
     * @param list<array<string, mixed>> $wilayas  our wilayas: id, name, slug
     * @param list<array<string, mixed>> $communes our communes: id, wilaya_id, name, slug
     */
    public static function plan(
        string $provider,
        array $places,
        array $wilayas,
        array $communes
    ): DestinationSyncPlan {
        $ourWilayaBySlug = [];
        foreach ($wilayas as $wilaya) {
            $ourWilayaBySlug[self::key((string) ($wilaya['slug'] ?? $wilaya['name'] ?? ''))] = $wilaya;
        }

        $ourCommuneByPlace = [];
        foreach ($communes as $commune) {
            $key = (int) ($commune['wilaya_id'] ?? 0)
                . '/' . self::key((string) ($commune['slug'] ?? $commune['name'] ?? ''));
            $ourCommuneByPlace[$key] = $commune;
        }

        $rows = [];
        $gaps = [];
        $fetched = [ProviderPlace::WILAYA => 0, ProviderPlace::COMMUNE => 0, ProviderPlace::CENTER => 0];

        /** @var array<string, int> their wilaya id → our wilaya id */
        $wilayaLink = [];
        /** @var array<string, array{wilaya_id: int, commune_id: int}> their commune id → ours */
        $communeLink = [];
        /** @var array<string, list<array<string, mixed>>> our "wilaya/commune" → centres */
        $centresByPlace = [];

        // Wilayas first, then communes, then centres: a commune is placed
        // through its wilaya and a centre through its commune, so each pass
        // needs the one before it to have finished.
        foreach (self::ofKind($places, ProviderPlace::WILAYA) as $place) {
            $fetched[ProviderPlace::WILAYA]++;

            $ours = $ourWilayaBySlug[self::key($place->name)] ?? null;

            if ($ours === null) {
                $gaps[] = self::gap(DestinationSyncPlan::PROVIDER_UNMATCHED, ProviderPlace::WILAYA, [
                    'provider_id' => $place->id,
                    'provider_name' => $place->name,
                ]);

                continue;
            }

            $wilayaLink[$place->id] = (int) $ours['id'];

            if (!$place->isDeliverable) {
                /*
                 * Recorded, and still written. The row is how the adapter
                 * learns the courier's own spelling of the wilaya, which it
                 * needs even to explain the refusal; suppressing it would turn
                 * "they do not deliver there" into "we have never heard of the
                 * place", which reads as our data being broken.
                 */
                $gaps[] = self::gap(DestinationSyncPlan::NOT_DELIVERABLE, ProviderPlace::WILAYA, [
                    'provider_id' => $place->id,
                    'provider_name' => $place->name,
                    'wilaya_id' => (int) $ours['id'],
                    'name' => (string) ($ours['name'] ?? ''),
                ]);
            }

            $rows[] = self::row($provider, (int) $ours['id'], 0, $place);
        }

        foreach (self::ofKind($places, ProviderPlace::COMMUNE) as $place) {
            $fetched[ProviderPlace::COMMUNE]++;

            $ourWilayaId = $wilayaLink[$place->wilayaId] ?? 0;

            if ($ourWilayaId === 0) {
                // Its wilaya never matched, so the commune cannot be placed
                // either. Reported against the wilaya it names so one line in
                // the report explains a whole block of missing communes.
                $gaps[] = self::gap(DestinationSyncPlan::PROVIDER_UNMATCHED, ProviderPlace::COMMUNE, [
                    'provider_id' => $place->id,
                    'provider_name' => $place->name,
                    'provider_wilaya_id' => $place->wilayaId,
                    'reason' => 'wilaya_unmatched',
                ]);

                continue;
            }

            $ours = $ourCommuneByPlace[$ourWilayaId . '/' . self::key($place->name)] ?? null;

            if ($ours === null) {
                $gaps[] = self::gap(DestinationSyncPlan::PROVIDER_UNMATCHED, ProviderPlace::COMMUNE, [
                    'provider_id' => $place->id,
                    'provider_name' => $place->name,
                    'wilaya_id' => $ourWilayaId,
                    'reason' => 'no_commune_of_that_name',
                ]);

                continue;
            }

            $communeLink[$place->id] = [
                'wilaya_id' => $ourWilayaId,
                'commune_id' => (int) $ours['id'],
            ];

            if (!$place->isDeliverable) {
                $gaps[] = self::gap(DestinationSyncPlan::NOT_DELIVERABLE, ProviderPlace::COMMUNE, [
                    'provider_id' => $place->id,
                    'provider_name' => $place->name,
                    'wilaya_id' => $ourWilayaId,
                    'commune_id' => (int) $ours['id'],
                    'name' => (string) ($ours['name'] ?? ''),
                ]);
            }

            $rows[] = self::row($provider, $ourWilayaId, (int) $ours['id'], $place);
        }

        foreach (self::ofKind($places, ProviderPlace::CENTER) as $place) {
            $fetched[ProviderPlace::CENTER]++;

            $link = $communeLink[$place->communeId] ?? null;

            if ($link === null) {
                $gaps[] = self::gap(DestinationSyncPlan::PROVIDER_UNMATCHED, ProviderPlace::CENTER, [
                    'provider_id' => $place->id,
                    'provider_name' => $place->name,
                    'provider_commune_id' => $place->communeId,
                    'reason' => 'commune_unmatched',
                ]);

                continue;
            }

            $centresByPlace[$link['wilaya_id'] . '/' . $link['commune_id']][] = [
                'id' => $place->id,
                'name' => $place->name,
                'address' => (string) ($place->metadata['address'] ?? ''),
            ];
        }

        // Centres are folded into the commune row rather than given rows of
        // their own: `ac_geo_provider_destinations` is keyed one row per
        // (provider, wilaya, commune), and a desk is a *property* of delivering
        // to that commune — which desk, at which address — not a third kind of
        // place a parcel can be addressed to.
        foreach ($rows as $index => $row) {
            $key = $row['wilaya_id'] . '/' . $row['commune_id'];

            if (isset($centresByPlace[$key])) {
                $rows[$index]['metadata']['centers'] = $centresByPlace[$key];
            }
        }

        $mappedWilayas = array_flip(array_values($wilayaLink));
        $mappedCommunes = [];
        foreach ($communeLink as $link) {
            $mappedCommunes[$link['commune_id']] = true;
        }

        foreach ($wilayas as $wilaya) {
            if (!isset($mappedWilayas[(int) $wilaya['id']])) {
                $gaps[] = self::gap(DestinationSyncPlan::UNCOVERED, ProviderPlace::WILAYA, [
                    'wilaya_id' => (int) $wilaya['id'],
                    'name' => (string) ($wilaya['name'] ?? ''),
                ]);
            }
        }

        foreach ($communes as $commune) {
            if (isset($mappedCommunes[(int) $commune['id']])) {
                continue;
            }

            // A commune in a wilaya the courier never matched is already
            // explained by that wilaya's gap; repeating it per commune would
            // bury the report under a thousand lines saying the same thing.
            if (!isset($mappedWilayas[(int) ($commune['wilaya_id'] ?? 0)])) {
                continue;
            }

            $gaps[] = self::gap(DestinationSyncPlan::UNCOVERED, ProviderPlace::COMMUNE, [
                'wilaya_id' => (int) ($commune['wilaya_id'] ?? 0),
                'commune_id' => (int) $commune['id'],
                'name' => (string) ($commune['name'] ?? ''),
            ]);
        }

        return new DestinationSyncPlan($rows, $gaps, $fetched);
    }

    /**
     * @param list<ProviderPlace> $places
     * @return list<ProviderPlace>
     */
    private static function ofKind(array $places, string $kind): array
    {
        return array_values(array_filter(
            $places,
            static fn (ProviderPlace $place): bool => $place->kind === $kind
        ));
    }

    /**
     * The comparison key: a folded slug, or '' for a name that folds away.
     *
     * '' is returned rather than the raw string so that two unmatchable names
     * never collide with each other.
     */
    private static function key(string $name): string
    {
        return GeoSlug::make($name);
    }

    /** @return array<string, mixed> */
    private static function row(string $provider, int $wilayaId, int $communeId, ProviderPlace $place): array
    {
        return [
            'provider' => $provider,
            'wilaya_id' => $wilayaId,
            'commune_id' => $communeId,
            'destination_id' => $place->id,
            /*
             * `name` is not decoration: Yalidine addresses a parcel by
             * `to_wilaya_name` / `to_commune_name` and matches them exactly, so
             * the courier's own spelling is the thing the adapter has to send
             * back. Storing it here is what removes the hard-coded name table
             * roadmap §56 forbids.
             */
            'metadata' => [
                'name' => $place->name,
                'is_deliverable' => $place->isDeliverable,
            ] + $place->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function gap(string $type, string $kind, array $detail): array
    {
        return ['type' => $type, 'kind' => $kind] + $detail;
    }
}
