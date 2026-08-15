<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Support;

use AlgerianCommerce\Shipping\DestinationDirectoryInterface;
use AlgerianCommerce\Shipping\ProviderDestination;

/**
 * The destination map, as a handful of rows in memory.
 *
 * The real one reads `ac_geo_provider_destinations`; this one is what lets an
 * adapter's payload building be tested without a database, which is the whole
 * reason `DestinationDirectoryInterface` exists.
 */
final class InMemoryDestinationDirectory implements DestinationDirectoryInterface
{
    /** @var array<string, ProviderDestination> */
    private array $rows = [];

    /** @param list<ProviderDestination> $destinations */
    public function __construct(array $destinations = [])
    {
        foreach ($destinations as $destination) {
            $this->add($destination);
        }
    }

    public function add(ProviderDestination $destination): void
    {
        $key = $destination->provider . '/' . $destination->wilayaId . '/' . $destination->communeId;
        $this->rows[$key] = $destination;
    }

    public function find(string $provider, int $wilayaId, int $communeId = 0): ?ProviderDestination
    {
        return $this->rows[$provider . '/' . $wilayaId . '/' . $communeId] ?? null;
    }
}
