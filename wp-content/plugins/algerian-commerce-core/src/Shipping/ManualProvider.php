<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;

/**
 * In-house delivery — the shop's own driver, or a courier arranged off-system.
 *
 * Not a placeholder. A large share of Algerian shops deliver inside their own
 * wilaya themselves and hand only the distant orders to Yalidine or ZR Express, so
 * this is a courier a real client uses. It also means the abstraction ships
 * with one working implementation rather than none: the whole path — create,
 * track, cancel, status — is exercisable today, and the seam is proven before
 * §56 puts a network call behind it.
 *
 * Pure by construction: no HTTP, no credentials, no configuration. There is
 * nothing to authenticate against, because the "provider" is a person with a
 * van who already works here.
 *
 * The tracking number is ours, and is not pretending to be a courier's. It is
 * derived from the shop's own reference for the attempt, so a driver reading it
 * aloud on the phone lands on the right parcel — not merely the right order —
 * and it carries a prefix so nobody mistakes it for a Yalidine code when both
 * appear in the same list.
 */
final class ManualProvider implements ShippingProviderInterface
{
    public const NAME = 'manual';

    private const TRACKING_PREFIX = 'MAN';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'In-house delivery';
    }

    /**
     * Accepted immediately, because there is nobody to ask.
     *
     * The status is `created` rather than `pending`: a parcel handed to our own
     * driver is as accepted as it is ever going to be, and leaving it pending
     * would put every in-house shipment permanently in a queue that means
     * "waiting for a provider to respond".
     */
    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        // The reference, not the order id: an order that failed to deliver and
        // went out again would otherwise get a second parcel with the first
        // one's number, and a driver reading it aloud would land on either.
        $tracking = sprintf(
            '%s-%s',
            self::TRACKING_PREFIX,
            $request->reference !== '' ? $request->reference : (string) $request->orderId
        );

        return new ShipmentResult(
            $tracking,
            $tracking,
            ShipmentStatus::CREATED,
            [
                'delivery_type' => $request->destination->deliveryType,
                'wilaya_id' => $request->destination->wilayaId,
                'commune_id' => $request->destination->communeId,
                // Written down because an in-house driver is told the amount
                // verbally, and afterwards the only record of what they were
                // sent to collect is this one.
                'cod_amount' => $request->codAmount,
            ]
        );
    }

    /** Always cancellable: the only person to tell is the one who works here. */
    public function cancelShipment(string $providerShipmentId): bool
    {
        return true;
    }

    /**
     * There is no API to ask.
     *
     * Refused rather than answered, and the distinction matters: returning
     * `created` here would look like a successful sync, and since a person may
     * already have moved this shipment on to `in_transit`, a later poll would
     * quietly walk it *backwards* to what this method invented. An in-house
     * shipment advances through `PATCH /shipments/{id}`, by the people doing
     * the delivering.
     */
    public function getShipmentStatus(string $providerShipmentId): StatusReport
    {
        throw new ApiException(
            'sync_unsupported',
            'In-house delivery reports no status of its own; update this shipment directly.',
            409
        );
    }

    /**
     * No rates. Not "free" — unpriced: what a shop charges for its own delivery
     * is roadmap §14's zone and wilaya pricing, and returning 0.00 here would
     * be this module quietly making that decision on §14's behalf.
     *
     * @return list<RateQuote>
     */
    public function getShippingRates(Destination $destination): array
    {
        return [];
    }

    /**
     * Nobody sends us webhooks about a van we own.
     *
     * Throwing rather than returning an empty result, and mirroring how
     * `getShipmentStatus()` refuses a poll it cannot answer: a request arriving
     * here means something is misrouted, and quietly returning "nothing to do"
     * would hide that for as long as it took someone to notice parcels were not
     * moving.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): ShipmentWebhookResult
    {
        throw new ApiException(
            'webhook_unsupported',
            'In-house delivery does not receive webhooks.',
            400,
            ['provider' => self::NAME]
        );
    }
}
