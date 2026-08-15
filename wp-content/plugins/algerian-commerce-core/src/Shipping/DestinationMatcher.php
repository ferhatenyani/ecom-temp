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
 * **Matched on the accent-folded name.** `GeoSlug` is reused rather than
 * reimplemented — it is the codebase's one accent-folding table, already the
 * natural key the geography importer upserts on, and already tested. Béjaïa and
 * Bejaia have to reach the same commune whichever side spelled it which way,
 * which is the exact failure the reference implementation works around at
 * parcel-creation time by re-fetching the fees endpoint and matching names by
 * hand.
 *
 * **A wilaya may also be matched on its code, and only a wilaya.** Roadmap §56
 * says never to parse a provider's ids, written when nobody could check them.
 * They were checked on 2026-08-14: across a courier's whole published list,
 * every wilaya that matched by name carried an id identical to the official
 * Algerian code — 54 agreements, no disagreement — while four failed on
 * spelling alone (*Alger* against our *Algiers*, *Tipaza* against *Tipasa*),
 * taking 96 of their communes down with them. So the code is used, but only
 * where it is a real natural key and only as a tie-break:
 *
 *  - the name is tried first and always wins;
 *  - the code is consulted only for a wilaya the name could not place, and only
 *    if no other place has claimed it;
 *  - the match records which of the two found it, and a code that contradicts a
 *    name is reported rather than trusted.
 *
 * Communes get no such fallback. Their ids are the courier's own numbering with
 * no national equivalent, and there is nothing to compare against.
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
        $ourWilayaById = [];
        foreach ($wilayas as $wilaya) {
            $ourWilayaBySlug[self::key((string) ($wilaya['slug'] ?? $wilaya['name'] ?? ''))] = $wilaya;
            $ourWilayaById[(string) ($wilaya['id'] ?? '')] = $wilaya;
        }

        // Which of our wilayas a name already claimed, so the code fallback
        // cannot hand the same place to two of the courier's.
        $claimed = [];
        foreach (self::ofKind($places, ProviderPlace::WILAYA) as $place) {
            $byName = $ourWilayaBySlug[self::key($place->name)] ?? null;

            if ($byName !== null) {
                $claimed[(int) $byName['id']] = true;
            }
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
            $matchedBy = 'name';

            if ($ours === null) {
                /*
                 * The courier's own statement of the official code when it
                 * makes one — ZR Express publishes `code: 16` for Alger — and
                 * otherwise the id, which is the same number at Yalidine.
                 */
                $byCode = $ourWilayaById[$place->code !== '' ? $place->code : $place->id] ?? null;

                // Only an unclaimed wilaya, so a courier that renumbers cannot
                // quietly steal a place another of its wilayas already matched
                // by name.
                if ($byCode !== null && !isset($claimed[(int) $byCode['id']])) {
                    $ours = $byCode;
                    $matchedBy = 'code';
                    $claimed[(int) $byCode['id']] = true;

                    $gaps[] = self::gap(DestinationSyncPlan::MATCHED_BY_CODE, ProviderPlace::WILAYA, [
                        'provider_id' => $place->id,
                        'provider_name' => $place->name,
                        'wilaya_id' => (int) $byCode['id'],
                        'name' => (string) ($byCode['name'] ?? ''),
                        // The code the match was made on, which is the whole
                        // claim being reported — not the courier's opaque id,
                        // which at ZR Express is a UUID and says nothing.
                        'code' => $place->code !== '' ? $place->code : $place->id,
                    ]);
                }
            }

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

            $rows[] = self::row($provider, (int) $ours['id'], 0, $place, $matchedBy);
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
                    /*
                     * A hint, and deliberately never an action. Most of these
                     * are transliteration variance — *Abou El Hassan* against
                     * our *Abou El Hassane* — and matching them automatically is
                     * the fuzzy-matching this class exists to refuse: at three
                     * edits, "Bitam" and "Batna" are neighbours too, and that
                     * mistake is a parcel driven to the wrong town. Naming the
                     * nearest candidate lets a person settle it in a second
                     * without a machine settling it wrongly in a millisecond.
                     */
                    'nearest' => self::nearest($place->name, $communes, $ourWilayaId),
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
     * The closest name we hold in that wilaya, for a report to quote.
     *
     * Bounded at three edits because beyond that the "nearest" name is noise,
     * and shown with its distance so the reader can weigh it: one edit is
     * almost always the same place spelled differently, three is a coin toss.
     *
     * @param list<array<string, mixed>> $communes
     */
    private static function nearest(string $name, array $communes, int $wilayaId): string
    {
        $theirs = self::key($name);

        if ($theirs === '') {
            return '';
        }

        $best = '';
        $bestDistance = 4;

        foreach ($communes as $commune) {
            if ((int) ($commune['wilaya_id'] ?? 0) !== $wilayaId) {
                continue;
            }

            $distance = levenshtein($theirs, self::key((string) ($commune['name'] ?? '')));

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) ($commune['name'] ?? '');
            }
        }

        return $best === '' ? '' : sprintf('%s (%d)', $best, $bestDistance);
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
    private static function row(
        string $provider,
        int $wilayaId,
        int $communeId,
        ProviderPlace $place,
        string $matchedBy = 'name'
    ): array {
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
                // Which of the two rules placed this row. A row matched on the
                // code is one whose names disagree, and that is worth being
                // able to query later rather than only reading in a report.
                'matched_by' => $matchedBy,
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
