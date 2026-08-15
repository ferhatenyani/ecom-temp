<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use InvalidArgumentException;

/**
 * One **verified** inbound courier event, translated out of a provider's shape —
 * roadmap §60.
 *
 * Pure — no WordPress. The sibling of `Payments\WebhookResult`, and read
 * `docs/SECURITY.md` → "Webhooks" before implementing `handleWebhook()`; that
 * section is the rule this object exists to serve.
 *
 * **This object only ever describes a verified event.** An adapter that cannot
 * verify a request throws — a boolean is a thing a caller can forget to check,
 * an exception is not — so the mere existence of one of these means the
 * signature or shared secret already passed.
 *
 * ## It deliberately carries no status
 *
 * That is the whole difference from `Payments\WebhookResult`, and it is not an
 * omission. A courier event says *go and look*, never *this is what happened*:
 *
 *  - **Yalidine's `security_token` is a shared secret in the body, not a
 *    signature.** It binds to nothing, so anyone who has ever seen one — a proxy
 *    log, a support ticket — can forge any event with it. docs/SECURITY.md is
 *    explicit that such a payload is a hint and never a source of truth.
 *  - **ZR Express signs properly, with Svix, and is still re-fetched.** Its
 *    webhook reference documents `state.name` as a display string ("Out for
 *    Delivery"); the live API returns stable snake_case identifiers, which is
 *    what `ZRExpressStateMap` maps and what the poller has been reading since
 *    §57. Two documented shapes for one field is exactly the situation where
 *    believing the payload writes a status nothing else can reason about.
 *
 * So both end the same way: `ShippingService::handleWebhook()` claims the event
 * and then calls `getShipmentStatus()`, which is the code path the poller
 * already uses. A signature proves who sent the message; asking the courier
 * proves where the parcel is.
 *
 * `eventId` is what gets **claimed** — a write-once insert whose duplicate-key
 * failure is the idempotency answer, never a read-then-write, which races
 * exactly when a provider retries in parallel. Where a courier sends no id of
 * its own, the adapter derives a stable one by hashing the signed material.
 */
final class ShipmentWebhookResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        /** The provider's event id, or a hash of the signed material when it sends none. */
        public readonly string $eventId,
        /**
         * The courier's own id for the parcel, where it sends one.
         *
         * Yalidine identifies a parcel by its tracking number and nothing else;
         * ZR Express has both an id and a tracking number, and §57 records that
         * the two are genuinely different things. Either is enough to find the
         * shipment, and a result with neither is verified but unactionable.
         */
        public readonly string $providerShipmentId = '',
        public readonly string $trackingNumber = '',
        /** The provider's event type, for the audit trail and the claim row. */
        public readonly string $eventType = '',
        public readonly array $metadata = []
    ) {
        if (trim($eventId) === '') {
            // Without an id there is nothing to claim, and replay protection
            // silently stops existing — the failure §55 designed against.
            throw new InvalidArgumentException(
                'A webhook result needs an event id to claim; derive one by hashing the signed material.'
            );
        }
    }

    /**
     * Whether this event names a parcel we could go and look up.
     *
     * A verified event that identifies nothing is acknowledged and dropped, so
     * the courier stops retrying over a gap on our side.
     */
    public function identifiesAParcel(): bool
    {
        return trim($this->providerShipmentId) !== '' || trim($this->trackingNumber) !== '';
    }
}
