<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

/**
 * Everything a provider needs to start one payment, and nothing WooCommerce.
 *
 * Pure — no WordPress, no `WC_Order`. That is the point of the layer: an adapter
 * receives this object and therefore cannot reach into an order, read a meta
 * key, or depend on how this shop happens to store things
 * (docs/ARCHITECTURE.md §4). It is also what makes an adapter testable without
 * a database, which is how both courier adapters were built.
 *
 * Roadmap §58 sketches `createPayment(array $order)`. A typed object is used
 * instead, exactly as §53's `array $order` became `ShipmentRequest`: with a bare
 * array every adapter re-derives what a valid request looks like, and the second
 * one gets it subtly wrong. The operation is unchanged; only the shape is
 * pinned down.
 *
 * **The amount is a decimal string**, like every other amount in this codebase,
 * and never a float — a fee that arrives as a float has lost the last centime
 * before it reaches a total. Providers want minor units (Chargily quotes in
 * centimes); converting is the adapter's job, because the size of a minor unit
 * is a fact about a provider and a currency, not about an order.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly int $orderId,
        /** Decimal string — "4500.00". Never a float. */
        public readonly string $amount,
        /** ISO 4217. Algerian shops bill in DZD; the field exists so an adapter never assumes. */
        public readonly string $currency = 'DZD',
        /**
         * This shop's own reference for this attempt — `"42-2"`, the second
         * payment started for order 42.
         *
         * Not the order id, because an order can be paid for more than once: a
         * card is declined, the customer tries again with another. §56 proved
         * the hard way that a merchant reference is not automatically an
         * idempotency key at the provider — Yalidine happily made two parcels
         * from one reference — so an adapter that needs a retry to be safe
         * verifies it, and does not assume.
         */
        public readonly string $reference = '',
        /** What the customer sees on the provider's checkout page and their statement. */
        public readonly string $description = '',
        public readonly string $customerName = '',
        public readonly string $customerEmail = '',
        public readonly string $customerPhone = '',
        /**
         * Where the provider sends the shopper when checkout finishes.
         *
         * Required by every redirect-based provider, which is all of them here.
         * It points at the Next.js storefront, and **whatever it carries is a
         * hint, never proof**: the shopper's browser is not a trustworthy
         * reporter of whether money moved. The backend confirms with
         * `verifyPayment()` or a signature-verified webhook before anything is
         * marked paid (docs/SECURITY.md, "Payments").
         */
        public readonly string $returnUrl = ''
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'description' => $this->description,
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'customer_phone' => $this->customerPhone,
            'return_url' => $this->returnUrl,
        ];
    }
}
