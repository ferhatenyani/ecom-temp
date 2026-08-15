<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * What a destination sync would write, and everything it could not place.
 *
 * Pure — no WordPress, no database — so the matching that produces it is a unit
 * test rather than a live courier call, which is the only way to test it at all
 * while roadmap §56 has no sandbox account behind it.
 *
 * **The gaps are the point.** A sync that quietly wrote 1,400 of 1,541 communes
 * and said "done" would leave 141 communes that fail at parcel creation, one
 * order at a time, weeks later — and Yalidine's rejection for an unknown
 * commune name is an empty array with no message in it. Every place that could
 * not be matched, in either direction, comes out here so the operator sees the
 * hole before a customer does.
 */
final class DestinationSyncPlan
{
    /** Their place has no counterpart in our dataset. */
    public const PROVIDER_UNMATCHED = 'provider_unmatched';

    /** Our place has no counterpart at the provider. */
    public const UNCOVERED = 'uncovered';

    /** They publish it and say this account cannot send there. */
    public const NOT_DELIVERABLE = 'not_deliverable';

    /**
     * Placed by its official code because the two names disagree.
     *
     * Not a failure — the row is written and parcels to it work — but it is the
     * one match nobody chose deliberately, so it is surfaced rather than left
     * for someone to notice that a courier calls Algiers *Alger*.
     */
    public const MATCHED_BY_CODE = 'matched_by_code';

    /**
     * @param list<array<string, mixed>> $rows  ready for GeoRepository::upsertDestinations()
     * @param list<array<string, mixed>> $gaps  each with `type`, `kind` and enough naming to act on
     * @param array<string, int>         $fetched how many places of each kind the provider published
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $gaps = [],
        public readonly array $fetched = []
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function gapsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->gaps,
            static fn (array $gap): bool => ($gap['type'] ?? '') === $type
        ));
    }

    /** @return array<string, int> */
    public function gapCounts(): array
    {
        $counts = [];

        foreach ($this->gaps as $gap) {
            $key = ($gap['type'] ?? 'unknown') . ':' . ($gap['kind'] ?? 'unknown');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'fetched' => $this->fetched,
            'mapped' => count($this->rows),
            'gaps' => $this->gapCounts(),
        ];
    }
}
