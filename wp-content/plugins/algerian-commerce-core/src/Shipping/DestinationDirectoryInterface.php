<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * How an adapter asks "what does this courier call this place".
 *
 * An interface rather than the repository itself, so an adapter can be unit
 * tested with a handful of rows in memory instead of a database. That is not a
 * convenience here: roadmap §56 has no sandbox account behind it, so the only
 * evidence that a parcel payload is built correctly is a test that builds one.
 */
interface DestinationDirectoryInterface
{
    /**
     * The courier's destination for one of our places, or null if the sync
     * never mapped it.
     *
     * `$communeId = 0` asks for the wilaya itself.
     */
    public function find(string $provider, int $wilayaId, int $communeId = 0): ?ProviderDestination;
}
