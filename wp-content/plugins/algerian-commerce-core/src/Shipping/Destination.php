<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * Where a parcel is going, in the only terms Algeria actually uses.
 *
 * Pure — no WordPress. A wilaya and a commune, by the ids of the §51 dataset,
 * plus whether the customer collects it or it comes to their door.
 *
 * **Not an address.** This is what a courier prices and routes on: every
 * Algerian provider quotes per wilaya, most quote differently per commune, and
 * all of them charge differently for a desk pickup than for home delivery. The
 * recipient's name, phone and street live on ShipmentRequest, because
 * `getShippingRates()` has no business knowing who the customer is — asking for
 * a price should not require handing a provider a person.
 *
 * Ids, not names. "Ouled Fayet" is spelled six ways across three couriers and
 * two languages; the dataset's commune id is the one thing that does not drift,
 * and each provider's own destination code is mapped against it in
 * `ac_geo_provider_destinations` (roadmap §51) rather than being guessed from
 * the name at request time.
 */
final class Destination
{
    /** To the customer's door. */
    public const HOME = 'home';

    /** Collected from the courier's desk — "stop desk", in the local idiom. */
    public const DESK = 'desk';

    /** @var list<string> */
    public const DELIVERY_TYPES = [self::HOME, self::DESK];

    public function __construct(
        public readonly int $wilayaId,
        public readonly int $communeId,
        public readonly string $deliveryType = self::HOME
    ) {
    }

    public static function isKnownDeliveryType(string $type): bool
    {
        return in_array(strtolower(trim($type)), self::DELIVERY_TYPES, true);
    }

    public function isDesk(): bool
    {
        return $this->deliveryType === self::DESK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'wilaya_id' => $this->wilayaId,
            'commune_id' => $this->communeId,
            'delivery_type' => $this->deliveryType,
        ];
    }
}
