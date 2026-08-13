<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * Everything a provider needs to create one shipment, and nothing WooCommerce.
 *
 * Pure — no WordPress, no WC_Order. That is the point of the whole layer: an
 * adapter receives this object, and so cannot reach into an order, read a meta
 * key, or depend on how this shop happens to store things
 * (docs/ARCHITECTURE.md §4). It is also what makes an adapter testable without
 * a database.
 *
 * Roadmap §53 sketches `createShipment(array $order)`. A typed object is used
 * instead, deliberately: with a bare array every adapter re-derives what a
 * valid request looks like, and the third one gets it subtly wrong. The
 * operation is still *create shipment* — only the shape is pinned down.
 *
 * **`codAmount` is not decoration.** In Algeria the courier collects the money
 * at the door, so the amount to collect is as much a part of the shipment as
 * the address is; getting it wrong means a driver taking the wrong sum from a
 * customer. It is a decimal string like every other amount in this codebase,
 * and '0' means there is nothing to collect because the order was already paid.
 *
 * Weight, dimensions and parcel contents are absent on purpose. Every courier
 * wants them in a different shape and with different rules, and inventing a
 * field now — from memory, with no provider documentation in front of us —
 * is precisely what roadmap §54 forbids. They arrive with the first adapter
 * whose official docs say what it actually requires.
 */
final class ShipmentRequest
{
    public function __construct(
        public readonly int $orderId,
        public readonly Destination $destination,
        public readonly string $recipient,
        public readonly string $phone,
        public readonly string $address,
        /** Decimal string. '0' when the order is already paid. */
        public readonly string $codAmount = '0',
        public readonly string $note = '',
        /**
         * This shop's own reference for this attempt — `"42-2"`, the second
         * parcel sent for order 42.
         *
         * Not the order id, because an order can be shipped more than once: a
         * first delivery fails, comes back, and goes out again. Every courier
         * has a field for a merchant's own reference and most treat it as an
         * idempotency key, which is what makes this the right thing to send —
         * a retried create then returns the existing parcel instead of putting
         * a second one on a van.
         */
        public readonly string $reference = ''
    ) {
    }

    public function isCashOnDelivery(): bool
    {
        return (float) $this->codAmount > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'destination' => $this->destination->toArray(),
            'recipient' => $this->recipient,
            'phone' => $this->phone,
            'address' => $this->address,
            'cod_amount' => $this->codAmount,
            'note' => $this->note,
            'reference' => $this->reference,
        ];
    }
}
