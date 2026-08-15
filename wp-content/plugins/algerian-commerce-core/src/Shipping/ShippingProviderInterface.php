<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;

/**
 * What every courier looks like from inside this system — roadmap §53,
 * docs/ARCHITECTURE.md §4.
 *
 * The order service says *create shipment*; it never says *call the Yalidine
 * endpoint*. That is the whole point: one codebase serves several Algerian
 * clients on different couriers, and a provider is added by writing an adapter
 * against its current official documentation (roadmap §54) without a line
 * changing anywhere above this interface.
 *
 * Everything crossing this boundary is a value object of ours — ShipmentRequest
 * in, ShipmentResult / StatusReport / RateQuote out. No WC_Order, no arrays of
 * whatever the caller had lying around, and no provider payloads leaking
 * upward. An adapter owns, for its provider alone: authentication, endpoint
 * URLs, payload shapes, destination-id mapping, status mapping, timeouts,
 * retries, idempotency keys, and the translation of the provider's errors into
 * ours.
 *
 * **Failures are ApiException**, thrown by the adapter. A courier being down,
 * refusing a destination or rejecting a credential is not something the service
 * above can second-guess, and returning false for all three would tell an
 * operator nothing. Adapters map a provider's error to one of our codes — 400
 * for a request the provider rejected, 409 for a state it refuses, 502-ish for
 * a provider that is unreachable — and must never let a raw provider message,
 * URL or credential reach the response body (docs/SECURITY.md).
 *
 * Every method is expected to be **idempotent where the provider allows it**:
 * creating the same shipment twice must not put two parcels on a van, which is
 * what the adapter's idempotency key is for, and what ShippingService's
 * one-live-shipment-per-order rule backs up on our side.
 */
interface ShippingProviderInterface
{
    /**
     * The provider's slug — `manual`, `yalidine`, `zrexpress`.
     *
     * Stored on every shipment row and used to route a later cancel or status
     * check back to the same courier, so it must not change once shipments
     * exist.
     */
    public function name(): string;

    /** Human-readable, for a provider picker in an admin UI. */
    public function label(): string;

    /**
     * Hand a parcel to the courier.
     *
     * @throws ApiException when the provider refuses it or cannot be reached
     */
    public function createShipment(ShipmentRequest $request): ShipmentResult;

    /**
     * Call a shipment off at the provider.
     *
     * Returns false when the provider will not cancel it — a parcel already
     * with a driver, typically — which is a legitimate answer rather than a
     * failure, and one the service turns into a 409. Anything genuinely broken
     * throws.
     *
     * @throws ApiException
     */
    public function cancelShipment(string $providerShipmentId): bool;

    /**
     * Where the parcel is now, in our vocabulary.
     *
     * @throws ApiException
     */
    public function getShipmentStatus(string $providerShipmentId): StatusReport;

    /**
     * What this provider charges to reach that destination.
     *
     * An empty list is a valid answer: it means this courier publishes no rate
     * API, not that the destination is unreachable. Local pricing — zones,
     * wilaya tariffs, free-shipping thresholds — is roadmap §14 and does not
     * belong to any provider.
     *
     * @return list<RateQuote>
     *
     * @throws ApiException
     */
    public function getShippingRates(Destination $destination): array;

    /**
     * Verify and translate one inbound event — roadmap §60.
     *
     * Added after the two courier adapters shipped, because §56 and §57 both
     * deferred their webhooks until §55 produced a written rule. The rule is
     * `docs/SECURITY.md` → "Webhooks" and it is not negotiable per provider:
     * raw body before any JSON decode, `hash_equals()`, a 5-minute timestamp
     * tolerance **where the timestamp is inside the signed material**, and an
     * event id claimed rather than checked.
     *
     * The adapter does the verifying, because the scheme is a fact about the
     * provider: ZR Express signs with Svix, and Yalidine puts a shared secret in
     * the body — which binds to nothing, and is therefore a hint to go and look
     * rather than something to believe.
     *
     * **Throw on anything that does not verify**, with code `webhook_unverified`
     * and status 401, saying nothing about which check failed — a verifier that
     * distinguishes "bad timestamp" from "bad signature" is an oracle for
     * building a valid one.
     *
     * `ShipmentWebhookResult` deliberately carries **no status**: every verified
     * event ends in `getShipmentStatus()`, which is the path the poller already
     * takes. See that class for why that holds even for a properly signed one.
     *
     * A courier that receives no webhooks at all — in-house delivery — throws
     * `webhook_unsupported` rather than pretending to have handled something.
     *
     * @param array<string, mixed>  $payload the decoded body, for reading
     * @param array<string, string> $headers lower-cased header names
     * @param string                $rawBody the bytes as received — what the
     *                                       signature is actually over, since
     *                                       decoding and re-encoding changes them
     *
     * @throws ApiException 401 `webhook_unverified` when verification fails
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): ShipmentWebhookResult;
}
