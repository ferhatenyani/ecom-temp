<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Shipping\DestinationMatcher;
use AlgerianCommerce\Shipping\DestinationSyncPlan;
use AlgerianCommerce\Shipping\ProviderPlace;
use PHPUnit\Framework\TestCase;

/**
 * Matching a courier's places to the §51 dataset — the part of the destination
 * sync that decides anything, and therefore the part kept pure.
 */
final class DestinationMatcherTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function wilayas(): array
    {
        return [
            ['id' => 6, 'name' => 'Béjaïa', 'slug' => 'bejaia'],
            ['id' => 16, 'name' => 'Alger', 'slug' => 'alger'],
            ['id' => 33, 'name' => 'Illizi', 'slug' => 'illizi'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function communes(): array
    {
        return [
            ['id' => 512, 'wilaya_id' => 16, 'name' => 'Bouzareah', 'slug' => 'bouzareah'],
            ['id' => 513, 'wilaya_id' => 16, 'name' => 'Bab Ezzouar', 'slug' => 'bab-ezzouar'],
            ['id' => 900, 'wilaya_id' => 33, 'name' => 'Djanet', 'slug' => 'djanet'],
        ];
    }

    /** @param list<ProviderPlace> $places */
    private function plan(array $places): DestinationSyncPlan
    {
        return DestinationMatcher::plan('yalidine', $places, $this->wilayas(), $this->communes());
    }

    /**
     * The spelling problem this whole mechanism exists for: our dataset says
     * "Bouzareah", Yalidine says "Bouzaréah", and a parcel addressed with the
     * wrong one is rejected with an empty array and no message.
     */
    public function testAccentsDoNotStopAPlaceMatching(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '16', 'Alger'),
            new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bouzaréah', '16'),
        ]);

        $commune = $this->rowFor($plan, 16, 512);

        self::assertNotNull($commune);
        self::assertSame('1601', $commune['destination_id']);
        // And the courier's spelling is what gets stored, because it is what
        // has to be sent back to them.
        self::assertSame('Bouzaréah', $commune['metadata']['name']);
    }

    /** The name wins, whatever the courier's numbering says. */
    public function testAPlaceIsMatchedByNameRatherThanByItsId(): void
    {
        $plan = $this->plan([
            // A courier that numbers Alger 99 is still Alger.
            new ProviderPlace(ProviderPlace::WILAYA, '99', 'Alger'),
            new ProviderPlace(ProviderPlace::COMMUNE, '9901', 'Bab Ezzouar', '99'),
        ]);

        self::assertNotNull($this->rowFor($plan, 16, 0));
        self::assertSame('name', $this->rowFor($plan, 16, 0)['metadata']['matched_by']);
        self::assertSame('9901', $this->rowFor($plan, 16, 513)['destination_id']);
    }

    /**
     * The case a live run turned up: the courier says *Alger*, our dataset —
     * which took its wilaya names from WooCommerce — says *Algiers*, and the
     * mismatch silently took 57 communes with it. The wilaya code is a real
     * natural key, verified as identical on both sides across a whole published
     * list, so it breaks the tie.
     */
    public function testAWilayaTheNameMissedIsPlacedByItsOfficialCode(): void
    {
        $plan = DestinationMatcher::plan(
            'yalidine',
            [
                new ProviderPlace(ProviderPlace::WILAYA, '16', 'Alger'),
                new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bouzareah', '16'),
            ],
            [['id' => 16, 'name' => 'Algiers', 'slug' => 'algiers']],
            [['id' => 512, 'wilaya_id' => 16, 'name' => 'Bouzareah', 'slug' => 'bouzareah']]
        );

        $wilaya = $this->rowFor($plan, 16, 0);

        self::assertNotNull($wilaya);
        self::assertSame('code', $wilaya['metadata']['matched_by']);
        // Their spelling is still what gets stored — it is what a parcel has to
        // be addressed with.
        self::assertSame('Alger', $wilaya['metadata']['name']);
        // And the commune underneath it is reachable again, which is the point.
        self::assertNotNull($this->rowFor($plan, 16, 512));

        // Reported, not silent: nobody chose this match.
        $reported = $plan->gapsOfType(DestinationSyncPlan::MATCHED_BY_CODE);
        self::assertCount(1, $reported);
        self::assertSame('Alger', $reported[0]['provider_name']);
        self::assertSame('Algiers', $reported[0]['name']);
    }

    /**
     * The failure the "never parse their ids" rule was written against: a
     * courier whose numbering has drifted must not be able to hand one of our
     * wilayas to a second place that another of its names already claimed.
     */
    public function testACodeCannotStealAWilayaThatANameAlreadyClaimed(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '99', 'Alger'),
            // Numbered 16 but called something we do not know: 16 is taken.
            new ProviderPlace(ProviderPlace::WILAYA, '16', 'Wilaya Fantôme'),
        ]);

        self::assertSame('name', $this->rowFor($plan, 16, 0)['metadata']['matched_by']);
        self::assertSame('99', $this->rowFor($plan, 16, 0)['destination_id']);
        self::assertSame([], $plan->gapsOfType(DestinationSyncPlan::MATCHED_BY_CODE));
        self::assertCount(1, $plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED));
    }

    /**
     * Communes get no code fallback: their ids are the courier's own numbering
     * with no national equivalent to compare against. The nearest name we hold
     * is offered as a hint for a person to judge — never applied.
     */
    public function testAnUnmatchedCommuneIsReportedWithItsNearestNeighbour(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '16', 'Alger'),
            new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bab Ezouar', '16'),
        ]);

        self::assertNull($this->rowFor($plan, 16, 513));

        $gap = $plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED)[0];

        self::assertSame('no_commune_of_that_name', $gap['reason']);
        self::assertStringContainsString('Bab Ezzouar', $gap['nearest']);
    }

    public function testAStopDeskIsRecordedAgainstTheCommuneItSitsIn(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '16', 'Alger'),
            new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bouzaréah', '16'),
            new ProviderPlace(ProviderPlace::CENTER, '88', 'Agence Bouzaréah', '16', '1601', true, [
                'address' => '3 rue des Frères',
            ]),
        ]);

        $centers = $this->rowFor($plan, 16, 512)['metadata']['centers'];

        // A desk is a property of delivering to that commune, not a third kind
        // of place a parcel can be addressed to — the table holds one row per
        // (provider, wilaya, commune).
        self::assertCount(1, $centers);
        self::assertSame('88', $centers[0]['id']);
        self::assertSame('3 rue des Frères', $centers[0]['address']);
    }

    /**
     * The wilayas this account cannot reach, from the courier rather than from
     * a hard-coded set — and still written, because the adapter needs the name
     * even to explain the refusal.
     */
    public function testAnUndeliverablePlaceIsRecordedAndReported(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '33', 'Illizi', '', '', false),
        ]);

        $row = $this->rowFor($plan, 33, 0);

        self::assertNotNull($row);
        self::assertFalse($row['metadata']['is_deliverable']);
        self::assertCount(1, $plan->gapsOfType(DestinationSyncPlan::NOT_DELIVERABLE));
    }

    /** Their place, no counterpart here. */
    public function testAPlaceWeDoNotKnowIsNamedRatherThanDropped(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '77', 'Wilaya Fantôme'),
        ]);

        $gaps = $plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED);

        self::assertSame('Wilaya Fantôme', $gaps[0]['provider_name']);
        self::assertSame([], $plan->rows);
    }

    /** Our place, no counterpart there — the coverage hole that matters most. */
    public function testACommuneTheCourierDoesNotServeIsReported(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '16', 'Alger'),
            new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bouzaréah', '16'),
        ]);

        $uncovered = $plan->gapsOfType(DestinationSyncPlan::UNCOVERED);
        $names = array_column($uncovered, 'name');

        self::assertContains('Bab Ezzouar', $names);
        self::assertContains('Béjaïa', $names);
        // Not Djanet: its wilaya is unmatched, and repeating that fact per
        // commune would bury the report.
        self::assertNotContains('Djanet', $names);
    }

    /**
     * A commune whose wilaya never matched cannot be placed either, and saying
     * so once against the wilaya explains the whole block.
     */
    public function testACommuneOfAnUnmatchedWilayaIsNotGuessedAt(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bouzaréah', '16'),
        ]);

        self::assertSame([], $plan->rows);

        $gaps = $plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED);

        self::assertSame('wilaya_unmatched', $gaps[0]['reason']);
    }

    /**
     * Same-named communes in different wilayas are ordinary in Algeria, so a
     * commune only ever matches inside the wilaya it was published under.
     */
    public function testACommuneNeverMatchesAcrossWilayas(): void
    {
        $plan = DestinationMatcher::plan(
            'yalidine',
            [
                new ProviderPlace(ProviderPlace::WILAYA, '6', 'Béjaïa'),
                new ProviderPlace(ProviderPlace::COMMUNE, '601', 'Djanet', '6'),
            ],
            $this->wilayas(),
            $this->communes()
        );

        // Our only Djanet is in Illizi; it must not be claimed by Béjaïa.
        self::assertNull($this->rowFor($plan, 6, 900));
        self::assertNull($this->rowFor($plan, 33, 900));
        self::assertSame('no_commune_of_that_name', $plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED)[0]['reason']);
    }

    public function testTheFetchedCountsAreWhatTheCourierPublished(): void
    {
        $plan = $this->plan([
            new ProviderPlace(ProviderPlace::WILAYA, '16', 'Alger'),
            new ProviderPlace(ProviderPlace::COMMUNE, '1601', 'Bouzaréah', '16'),
            new ProviderPlace(ProviderPlace::COMMUNE, '1602', 'Bab Ezzouar', '16'),
            new ProviderPlace(ProviderPlace::CENTER, '88', 'Agence', '16', '1601'),
        ]);

        self::assertSame(1, $plan->fetched[ProviderPlace::WILAYA]);
        self::assertSame(2, $plan->fetched[ProviderPlace::COMMUNE]);
        self::assertSame(1, $plan->fetched[ProviderPlace::CENTER]);
        self::assertSame(3, $plan->summary()['mapped']);
    }

    /** @return array<string, mixed>|null */
    private function rowFor(DestinationSyncPlan $plan, int $wilayaId, int $communeId): ?array
    {
        foreach ($plan->rows as $row) {
            if ($row['wilaya_id'] === $wilayaId && $row['commune_id'] === $communeId) {
                return $row;
            }
        }

        return null;
    }
}
