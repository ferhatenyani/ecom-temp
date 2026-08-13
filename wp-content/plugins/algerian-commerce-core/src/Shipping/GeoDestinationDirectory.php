<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\Geography\GeoRepository;

/**
 * The destination directory, backed by `ac_geo_provider_destinations`.
 *
 * Lives in `Shipping/` rather than `Geography/` on purpose. Shipping already
 * depends on geography — a parcel goes to a commune — and the dependency has to
 * keep running that way: putting a class that implements a shipping interface
 * into `Geography/` would make the geography module aware that couriers exist,
 * which is the direction docs/ARCHITECTURE.md rules out.
 *
 * One provider's map is loaded once and kept for the request. A create call
 * asks for the origin wilaya, the destination wilaya and the commune, a rates
 * call asks again, and each of those would otherwise be a query for one short
 * row.
 */
final class GeoDestinationDirectory implements DestinationDirectoryInterface
{
    /** @var array<string, array<string, ProviderDestination>> */
    private array $cache = [];

    public function __construct(private readonly GeoRepository $geography)
    {
    }

    public function find(string $provider, int $wilayaId, int $communeId = 0): ?ProviderDestination
    {
        $provider = strtolower(trim($provider));

        return $this->load($provider)[$wilayaId . '/' . $communeId] ?? null;
    }

    /** @return array<string, ProviderDestination> */
    private function load(string $provider): array
    {
        if (isset($this->cache[$provider])) {
            return $this->cache[$provider];
        }

        $map = [];

        foreach ($this->geography->destinations($provider) as $row) {
            $destination = ProviderDestination::fromRow($row);
            $map[$destination->wilayaId . '/' . $destination->communeId] = $destination;
        }

        return $this->cache[$provider] = $map;
    }
}
