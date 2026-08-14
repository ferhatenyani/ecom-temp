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
 * Weight and dimensions are still absent, and still on purpose: every courier
 * wants them in a different shape, and a per-client default parcel size is
 * configuration rather than a fact about an order — Yalidine's lives in
 * `YalidineSettings`. **Contents arrived with §56**, which is exactly the rule
 * §53 set: a field appears when a provider's own documentation says it is
 * required, not before.
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
         * first delivery fails, comes back, and goes out again.
         *
         * §53 assumed couriers treat this as an idempotency key. **Yalidine
         * does not** — verified 2026-08-14, where the same reference posted
         * twice produced two parcels — so an adapter that wants a retry to be
         * safe has to look the reference up first, which is what
         * `YalidineProvider` does. The value still matters for exactly the
         * reason it was introduced: it is how a parcel is found again at the
         * courier, by a number the shop chose.
         */
        public readonly string $reference = '',
        /**
         * What is in the parcel, in words — "Chemise bleue x2, Ceinture".
         *
         * Built from the order's line items. Couriers print it on the label and
         * read it out when a customer asks what has arrived; Yalidine requires
         * it as `product_list` (roadmap §56), which is what brought the field
         * here rather than guesswork.
         *
         * Not the line items themselves, and not prices: an adapter has no
         * business knowing what an order line is, and a driver holding the label
         * has no business knowing what each item cost.
         */
        public readonly string $contents = ''
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
            'contents' => $this->contents,
        ];
    }
}
