<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * One place a courier says it delivers to, in the courier's own words.
 *
 * Pure — no WordPress. This is the *input* to the destination sync, not its
 * result: what the provider published, before anything has been matched against
 * the §51 dataset. `ProviderDestination` is what comes out the other side, once
 * a row exists tying one of these to one of our wilayas or communes.
 *
 * Ids are strings even when a courier numbers its wilayas 1–58, for the same
 * reason `ac_geo_provider_destinations.destination_id` is a varchar: the id is
 * theirs to define and ours to store, and the first provider that ships a code
 * like "16A" breaks every integer column that assumed otherwise.
 *
 * **Never parse the id.** Yalidine's wilaya ids happen to line up with the
 * official Algerian codes today; treating that as a rule would silently
 * mis-route every parcel on the day it stops being true, and the whole point of
 * the sync is that coverage is data the courier gives us rather than knowledge
 * we hold.
 */
final class ProviderPlace
{
    public const WILAYA = 'wilaya';
    public const COMMUNE = 'commune';

    /** A courier's office a customer collects from — a "stop desk". */
    public const CENTER = 'center';

    /** @var list<string> */
    public const KINDS = [self::WILAYA, self::COMMUNE, self::CENTER];

    /** @param array<string, mixed> $metadata the provider's own row, as sent */
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $name,
        /** The provider's wilaya id — empty on a wilaya, which is its own. */
        public readonly string $wilayaId = '',
        /** The provider's commune id — set on a centre, which sits in one. */
        public readonly string $communeId = '',
        /**
         * Whether this client's account can actually send here.
         *
         * A courier publishes places it does not currently serve, and that is
         * the answer to "which wilayas can we not reach" — which roadmap §56
         * insists must be data from the provider rather than a hard-coded set
         * in the adapter.
         */
        public readonly bool $isDeliverable = true,
        public readonly array $metadata = []
    ) {
    }
}
