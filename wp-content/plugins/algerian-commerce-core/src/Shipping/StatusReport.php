<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use InvalidArgumentException;

/**
 * What a provider says about a shipment now.
 *
 * Pure — no WordPress. Roadmap §53 sketches `getShipmentStatus(): ShipmentStatus`;
 * this is that return type, and it carries two things rather than one.
 *
 * **The provider's own wording is kept.** `status` is our vocabulary, mapped by
 * the adapter, and `providerStatus` is the raw string it was mapped from. The
 * second one exists because a courier status mapping is the part of an
 * integration most likely to be quietly wrong — a provider adds a state, the
 * adapter's `match` falls through to a default, and every parcel in that state
 * reads as `in_transit` for a month. With the raw value stored next to the
 * mapped one, that is a query; without it, it is an outage nobody can explain.
 */
final class StatusReport
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $status,
        public readonly string $providerStatus = '',
        public readonly array $metadata = []
    ) {
        if (!ShipmentStatus::isKnown($status)) {
            throw new InvalidArgumentException(
                "A provider reported the unmapped shipment status \"{$status}\"."
            );
        }
    }
}
