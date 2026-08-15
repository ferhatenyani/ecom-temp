<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * One row of `ac_geo_provider_destinations`, read back.
 *
 * Pure — no WordPress. What the destination sync wrote: our wilaya or commune,
 * the courier's id for it, and the courier's own spelling of its name.
 *
 * The name is the field an adapter actually needs. Yalidine addresses a parcel
 * by wilaya and commune *name* and matches them exactly, so an integration
 * either keeps a table of the courier's spellings or hard-codes one in PHP —
 * and roadmap §56 rejects the second, because it is a per-account fact that
 * goes stale silently. `commune_id = 0` means the row is the wilaya itself.
 */
final class ProviderDestination
{
    /** @param array<string, mixed> $metadata as the sync stored it */
    public function __construct(
        public readonly string $provider,
        public readonly int $wilayaId,
        public readonly int $communeId,
        public readonly string $destinationId,
        public readonly array $metadata = []
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $metadata = $row['metadata'] ?? [];

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return new self(
            (string) ($row['provider'] ?? ''),
            (int) ($row['wilaya_id'] ?? 0),
            (int) ($row['commune_id'] ?? 0),
            (string) ($row['destination_id'] ?? ''),
            is_array($metadata) ? $metadata : []
        );
    }

    /** The courier's own spelling, which is what has to be sent back to it. */
    public function name(): string
    {
        return (string) ($this->metadata['name'] ?? '');
    }

    public function isDeliverable(): bool
    {
        // Absent means yes: a courier that publishes a place without saying
        // otherwise is publishing somewhere it delivers, and defaulting to "no"
        // would empty the map the first time a provider drops the field.
        return (bool) ($this->metadata['is_deliverable'] ?? true);
    }

    /**
     * The stop desks the courier has in this commune, if the sync found any.
     *
     * @return list<array{id: string, name: string, address: string}>
     */
    public function centers(): array
    {
        $centers = $this->metadata['centers'] ?? [];

        if (!is_array($centers)) {
            return [];
        }

        $clean = [];

        foreach ($centers as $center) {
            if (!is_array($center) || ($center['id'] ?? '') === '') {
                continue;
            }

            $clean[] = [
                'id' => (string) $center['id'],
                'name' => (string) ($center['name'] ?? ''),
                'address' => (string) ($center['address'] ?? ''),
            ];
        }

        return $clean;
    }
}
