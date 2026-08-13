<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use InvalidArgumentException;

/**
 * What a provider hands back when it accepts a shipment.
 *
 * Pure — no WordPress. Three facts every courier returns in some form, plus
 * whatever else that courier said.
 *
 * The status is validated in the constructor rather than trusted. An adapter
 * that returns a provider's own word here — "en préparation", "ACCEPTED" —
 * would put a status into our table that nothing else in the system can reason
 * about, and it would be found weeks later by an operations screen with a blank
 * column. Failing at the seam names the adapter that got it wrong.
 *
 * `metadata` is where a provider's particulars live: label URLs, desk ids,
 * whatever it invents next. Keeping them here rather than in columns is what
 * lets a third provider be a data change instead of a migration — but nothing
 * in the core may *read* a key out of it, or the abstraction has leaked.
 */
final class ShipmentResult
{
    /**
     * @param array<string, mixed> $metadata the provider's own response detail
     */
    public function __construct(
        public readonly string $providerShipmentId,
        public readonly string $trackingNumber,
        public readonly string $status = ShipmentStatus::CREATED,
        public readonly array $metadata = []
    ) {
        if (!ShipmentStatus::isKnown($status)) {
            throw new InvalidArgumentException(
                "A provider returned the unmapped shipment status \"{$status}\"."
            );
        }
    }
}
