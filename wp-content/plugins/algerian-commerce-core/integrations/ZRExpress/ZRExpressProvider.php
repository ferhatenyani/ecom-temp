<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\DestinationCatalogueInterface;
use AlgerianCommerce\Shipping\DestinationDirectoryInterface;
use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\RateQuote;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentResult;
use AlgerianCommerce\Shipping\ShipmentWebhookResult;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Shipping\ShippingProviderInterface;
use AlgerianCommerce\Shipping\StatusReport;

/**
 * ZR Express — roadmap §57.
 *
 * The second courier, and the test of whether §53's abstraction was real:
 * nothing above `ShippingProviderInterface` changed to accommodate it, and the
 * destination sync, the status poller and the CLI it uses were all written for
 * Yalidine without knowing this one existed.
 *
 * ## Written from the official specification
 *
 * Unlike §56, this had documentation. ZR Express publishes an OpenAPI
 * definition per endpoint under `docs.zrexpress.app/reference/*.md`, and every
 * field here comes from it, cross-checked against a Spring Boot implementation
 * in production and **verified against the live API on 2026-08-15** with a
 * merchant account's credentials.
 *
 * That live run covered the *outbound* API and nothing else. The webhook half
 * has never received a real event, and what is unproven is marked
 * `ASSUMPTION (unverified)`:
 *
 *     grep -rn 'ASSUMPTION' integrations/ZRExpress
 *
 * ## Three ways it differs from Yalidine, and what each costs
 *
 *  - **Destinations are UUIDs**, and a parcel carries `cityTerritoryId` and
 *    `districtTerritoryId` rather than names. Nothing can be misspelled, and
 *    the destination table holds the pair.
 *  - **A parcel needs a customer first.** `customers/search` by phone, then
 *    `customers/individual` if there is none, then the parcel carries the
 *    customer's UUID. Two calls before the parcel exists, both of which have to
 *    survive a retry — searching first is what makes them.
 *  - **The parcel id and the tracking number are different strings.** The id is
 *    a UUID and the tracking number reads `16-2E3IJ8WY17-ZR`. §53 kept those
 *    apart in `ShipmentResult` on the theory that some courier would disagree;
 *    this is that courier.
 */
final class ZRExpressProvider implements ShippingProviderInterface, DestinationCatalogueInterface
{
    public const NAME = 'zrexpress';

    /** docs/SECURITY.md → "Webhooks": five minutes, in either direction. */
    public const TIMESTAMP_TOLERANCE = 300;

    /** Svix labels its signing secrets; the key is the base64 after this. */
    private const SECRET_PREFIX = 'whsec_';

    private readonly ZRExpressTerritories $catalogue;

    public function __construct(
        private readonly ZRExpressClient $client,
        private readonly DestinationDirectoryInterface $directory,
        private readonly Logger $logger,
        ?ZRExpressTerritories $catalogue = null,
        /*
         * Only `handleWebhook()` reads this — §60 added it to an adapter that
         * had already shipped, and reordering the existing parameters would have
         * been a rename dressed as a feature. Optional: a shop may run this
         * courier on the poller alone, and an unconfigured secret then makes the
         * webhook route reject everything, which is what it should do.
         */
        private readonly ?ZRExpressCredentials $credentials = null
    ) {
        $this->catalogue = $catalogue ?? new ZRExpressTerritories($client);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'ZR Express';
    }

    public function verifyCredentials(): bool
    {
        return $this->client->verifyCredentials();
    }

    /** @return list<\AlgerianCommerce\Shipping\ProviderPlace> */
    public function destinations(): array
    {
        return $this->catalogue->all();
    }

    /**
     * Hand a parcel to ZR Express.
     *
     * `POST parcels` answers `{id}` and nothing else, so the tracking number is
     * read back with `GET parcels/{id}`.
     *
     * A repeated `externalId` is refused with a 409 — verified — which makes
     * the merchant reference a real idempotency key here, unlike at Yalidine.
     * A retry after a lost response therefore recovers the existing parcel
     * rather than making a second one.
     */
    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $wilaya = $this->requireDestination($request->destination->wilayaId, 0);
        $commune = $this->requireDestination(
            $request->destination->wilayaId,
            $request->destination->communeId
        );

        $hubId = $request->destination->isDesk() ? $this->requireHub($commune) : null;
        $reference = $request->reference !== '' ? $request->reference : (string) $request->orderId;

        $payload = ZRExpressParcel::payload(
            $request,
            $wilaya,
            $commune,
            $this->resolveCustomer($request),
            $hubId
        );

        try {
            $created = $this->client->post('parcels', $payload);
        } catch (ApiException $exception) {
            if ($exception->errorCode() === 'provider_conflict') {
                $existing = $this->findByReference($reference);

                if ($existing !== null) {
                    $this->logger->warning('ZR Express already had this parcel; reusing it', [
                        'reference' => $reference,
                        'tracking' => $existing->trackingNumber,
                    ]);

                    return $existing;
                }
            }

            throw $exception;
        }

        $parcelId = is_array($created) && isset($created['id']) && is_scalar($created['id'])
            ? trim((string) $created['id'])
            : '';

        if ($parcelId === '') {
            $this->logger->error('ZR Express accepted a parcel without identifying it', [
                'reference' => $reference,
            ]);

            throw new ApiException(
                'provider_response_invalid',
                'ZR Express created a parcel but did not say which.',
                502,
                ['provider' => self::NAME]
            );
        }

        /*
         * The parcel exists from here on, so nothing below may throw.
         *
         * `POST parcels` answers with an id and nothing else, and the tracking
         * number needs a second call — which is a second chance to fail. It did:
         * the first live run timed out on exactly this read and reported the
         * whole create as unreachable, leaving a real parcel at the courier that
         * this shop had no record of. That is the failure §53 designed the
         * "provider called last" rule against, and a read-back is not worth it.
         * A shipment with an id and no tracking number is recoverable — the next
         * poll fills it in — and one with neither is a parcel nobody knows about.
         */
        try {
            $parcel = $this->parcel('parcels/' . rawurlencode($parcelId));
        } catch (ApiException $exception) {
            $this->logger->error('ZR Express created a parcel this store could not read back', [
                'parcel_id' => $parcelId,
                'reference' => $reference,
                'error' => $exception->errorCode(),
            ]);

            $parcel = [];
        }

        return new ShipmentResult(
            $parcelId,
            self::str($parcel, 'trackingNumber'),
            // Their initial state, mapped — not an assumed "created". If it is
            // one this adapter does not know, the parcel still exists and the
            // raw value is kept; refusing here would lose the record of it.
            ZRExpressStateMap::toShipmentStatus(self::stateName($parcel)) ?? ShipmentStatus::CREATED,
            array_filter([
                'reference' => $reference,
                'provider_status' => self::stateName($parcel),
                'hub_id' => $hubId ?? '',
                'delivery_price' => self::str($parcel, 'deliveryPrice'),
            ])
        );
    }

    /**
     * `DELETE parcels/{id}`.
     *
     * A parcel already moving cannot be deleted, and ZR Express says so with a
     * 4xx rather than by pretending — which is the `false` the interface asks
     * for, and leaves the shipment live because the parcel is.
     */
    public function cancelShipment(string $providerShipmentId): bool
    {
        try {
            $this->client->delete('parcels/' . rawurlencode($providerShipmentId));

            return true;
        } catch (ApiException $exception) {
            if (in_array($exception->errorCode(), ['provider_rejected', 'provider_conflict'], true)) {
                $this->logger->warning('ZR Express would not cancel a parcel', [
                    'parcel_id' => $providerShipmentId,
                    'provider_message' => $exception->details()['provider_message'] ?? '',
                ]);

                return false;
            }

            throw $exception;
        }
    }

    /**
     * Where the parcel is, in our vocabulary.
     *
     * Addressed by the parcel's own id, which is what `ShipmentResult` recorded
     * as the provider shipment id. An unmapped state throws: §57 records that
     * the state enumeration is undocumented, and the reference implementation's
     * substring guessing is exactly what must not be repeated.
     */
    public function getShipmentStatus(string $providerShipmentId): StatusReport
    {
        $parcel = $this->parcel('parcels/' . rawurlencode($providerShipmentId));
        $state = self::stateName($parcel);
        $status = ZRExpressStateMap::toShipmentStatus($state);

        if ($status === null) {
            $this->logger->error('ZR Express reported a state this adapter does not map', [
                'parcel_id' => $providerShipmentId,
                'state' => $state,
            ]);

            throw new ApiException(
                'provider_status_unmapped',
                'ZR Express reported a parcel state this store does not recognise.',
                502,
                ['provider' => self::NAME, 'provider_status' => $state]
            );
        }

        return new StatusReport($status, $state, array_filter([
            // Their human wording for the same state, which is what an operator
            // reading a shipment will recognise from the ZR dashboard.
            'provider_status_label' => self::stateLabel($parcel),
            'provider_status_at' => self::str($parcel, 'lastStateUpdateAt'),
            'tracking_number' => self::str($parcel, 'trackingNumber'),
        ]));
    }

    /**
     * What ZR Express charges to reach a destination.
     *
     * `GET delivery-pricing/rates/{territoryId}` — asked of the commune first
     * and of the wilaya when there is no commune-level price, which is the
     * shape of their pricing: a wilaya rate, with specific prices inside the
     * supplier's own wilaya.
     *
     * An empty list rather than an exception when the destination is unmapped
     * or the account may not quote it: `GET /shipping/rates` asks every courier
     * in one call, and one shop's restriction must not take the price list
     * down. ZR Express refuses rates outside the origin wilaya with a plain
     * sentence, which is logged.
     *
     * @return list<RateQuote>
     */
    public function getShippingRates(Destination $destination): array
    {
        $commune = $this->directory->find(self::NAME, $destination->wilayaId, $destination->communeId);
        $wilaya = $this->directory->find(self::NAME, $destination->wilayaId, 0);

        if ($commune === null && $wilaya === null) {
            $this->logger->warning('ZR Express cannot quote a destination it has not mapped', [
                'wilaya_id' => $destination->wilayaId,
                'commune_id' => $destination->communeId,
            ]);

            return [];
        }

        foreach (array_filter([$commune, $wilaya]) as $territory) {
            try {
                $rate = $this->client->get('delivery-pricing/rates/' . rawurlencode($territory->destinationId));
            } catch (ApiException $exception) {
                if ($exception->errorCode() === 'provider_not_found') {
                    // No price at this level — try the wilaya above it.
                    continue;
                }

                if ($exception->errorCode() === 'provider_rejected') {
                    $this->logger->warning('ZR Express will not quote this destination', [
                        'wilaya_id' => $destination->wilayaId,
                        'provider_message' => $exception->details()['provider_message'] ?? '',
                    ]);

                    return [];
                }

                throw $exception;
            }

            $quotes = self::quotesFrom($rate, $destination);

            if ($quotes !== []) {
                return $quotes;
            }
        }

        return [];
    }

    /**
     * The customer this parcel is for, created if ZR Express has never seen
     * them.
     *
     * Searched by phone first, which is what makes the pair idempotent: a
     * retried shipment finds the customer it made a moment ago instead of
     * filling the account with duplicates of one person.
     */
    private function resolveCustomer(ShipmentRequest $request): string
    {
        $phone = ZRExpressParcel::phone($request->phone);

        $found = $this->client->search('customers/search', ['keyword' => $phone], 1, 5);

        foreach ($found['items'] as $candidate) {
            /*
             * The match is checked here rather than trusted. A search is a
             * search: `keyword` is theirs to interpret, and attaching a parcel
             * to a customer whose number merely *resembles* this one puts a
             * stranger's name and phone on somebody's delivery. A duplicate
             * customer record, which is what happens when this finds nothing,
             * costs nobody anything.
             */
            if (self::hasPhone($candidate, $phone) && self::str($candidate, 'id') !== '') {
                return self::str($candidate, 'id');
            }
        }

        $created = $this->client->post('customers/individual', [
            'name' => $request->recipient,
            'phone' => ['number1' => $phone],
        ]);

        $id = is_array($created) ? self::str($created, 'id') : '';

        if ($id === '') {
            throw new ApiException(
                'provider_response_invalid',
                'ZR Express would not register the recipient for this parcel.',
                502,
                ['provider' => self::NAME]
            );
        }

        return $id;
    }

    /**
     * The parcel this shop already created for that reference, if any.
     *
     * **`keyword`, not `filters`.** The search endpoint accepts a `filters`
     * object and ignores it — verified on 2026-08-15, where filtering by
     * `externalId` returned all 706 parcels on the account. The reference
     * implementation recovers duplicates exactly that way, which means it takes
     * whichever parcel happens to be first and calls it the customer's.
     * `keyword` does match, and the result is checked anyway.
     */
    private function findByReference(string $reference): ?ShipmentResult
    {
        $found = $this->client->search('parcels/search', ['keyword' => $reference], 1, 5);
        $parcel = null;

        foreach ($found['items'] as $candidate) {
            // Exactly ours, or none: a recovered parcel is about to be recorded
            // against a customer's order, and the wrong one would be worse than
            // the 409 this is trying to soften.
            if (self::str($candidate, 'externalId') === $reference) {
                $parcel = $candidate;
                break;
            }
        }

        if (!is_array($parcel) || self::str($parcel, 'id') === '') {
            return null;
        }

        $state = self::stateName($parcel);

        return new ShipmentResult(
            self::str($parcel, 'id'),
            self::str($parcel, 'trackingNumber'),
            ZRExpressStateMap::toShipmentStatus($state) ?? ShipmentStatus::CREATED,
            array_filter([
                'reference' => $reference,
                'provider_status' => $state,
                'reused_existing_parcel' => true,
            ])
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function parcel(string $path): array
    {
        $parcel = $this->client->get($path);

        if (!is_array($parcel) || self::str($parcel, 'id') === '') {
            throw new ApiException(
                'provider_response_invalid',
                'ZR Express returned a parcel this store could not read.',
                502,
                ['provider' => self::NAME]
            );
        }

        return $parcel;
    }

    private function requireDestination(int $wilayaId, int $communeId): ProviderDestination
    {
        $destination = $this->directory->find(self::NAME, $wilayaId, $communeId);

        if ($destination === null) {
            throw new ApiException(
                'zrexpress_destination_unmapped',
                'ZR Express has no territory recorded for that place. Run: wp algerian-commerce sync-destinations --provider=zrexpress',
                409,
                ['provider' => self::NAME, 'wilaya_id' => $wilayaId, 'commune_id' => $communeId]
            );
        }

        if (!$destination->isDeliverable()) {
            throw new ApiException(
                'zrexpress_destination_unavailable',
                'ZR Express does not deliver to that destination for this account.',
                409,
                [
                    'provider' => self::NAME,
                    'wilaya_id' => $wilayaId,
                    'commune_id' => $communeId,
                    'name' => $destination->name(),
                ]
            );
        }

        return $destination;
    }

    /**
     * Which pickup point a collected parcel goes to — the first the sync found
     * in that commune, as at Yalidine, and refused rather than quietly
     * delivered to the door when there is none.
     */
    private function requireHub(ProviderDestination $commune): string
    {
        $hubs = $commune->centers();

        if ($hubs === []) {
            throw new ApiException(
                'zrexpress_no_pickup_point',
                'ZR Express has no pickup point in that commune; send this parcel to the customer\'s address instead.',
                409,
                ['provider' => self::NAME, 'commune' => $commune->name()]
            );
        }

        return $hubs[0]['id'];
    }

    /**
     * `deliveryPrices` → our quotes, keeping only the service the caller asked
     * for: a home delivery and a pickup point are different journeys at
     * different prices, and this API names which is which.
     *
     * @return list<RateQuote>
     */
    private static function quotesFrom(mixed $rate, Destination $destination): array
    {
        if (!is_array($rate) || !is_array($rate['deliveryPrices'] ?? null)) {
            return [];
        }

        $wanted = $destination->isDesk() ? ZRExpressParcel::PICKUP_POINT : ZRExpressParcel::HOME;
        $quotes = [];

        foreach ($rate['deliveryPrices'] as $price) {
            if (!is_array($price) || strtolower(self::str($price, 'deliveryType')) !== $wanted) {
                continue;
            }

            // A discounted price is what this account actually pays; the list
            // price beside it is what it would have.
            $amount = $price['discountedPrice'] ?? $price['price'] ?? null;

            if (!is_numeric($amount)) {
                continue;
            }

            $quotes[] = new RateQuote(
                $wanted,
                $wanted === ZRExpressParcel::PICKUP_POINT
                    ? 'ZR Express — pickup point'
                    : 'ZR Express — home delivery',
                number_format((float) $amount, 2, '.', ''),
                'DZD',
                null,
                RateQuote::SOURCE_PROVIDER,
                false,
                // Stated, though this adapter has already filtered to the
                // journey that was asked for: leaving it null would work here
                // and would mean the one adapter that *does* filter is the one
                // that says nothing about what it filtered to.
                $destination->deliveryType
            );
        }

        return $quotes;
    }

    /**
     * Whether a customer record really carries this number.
     *
     * Their phone is an object of up to three numbers (`number1`…`number3`),
     * and older records may carry a plain string, so both shapes are checked.
     *
     * @param array<string, mixed> $customer
     */
    private static function hasPhone(array $customer, string $phone): bool
    {
        $stored = $customer['phone'] ?? null;

        if (is_scalar($stored)) {
            return ZRExpressParcel::phone((string) $stored) === $phone;
        }

        if (!is_array($stored)) {
            return false;
        }

        foreach ($stored as $number) {
            if (is_scalar($number) && ZRExpressParcel::phone((string) $number) === $phone) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $parcel */
    private static function stateName(array $parcel): string
    {
        $state = $parcel['state'] ?? null;

        return is_array($state) ? self::str($state, 'name') : '';
    }

    /** @param array<string, mixed> $parcel */
    private static function stateLabel(array $parcel): string
    {
        $state = $parcel['state'] ?? null;

        return is_array($state) ? self::str($state, 'description') : '';
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        return isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';
    }

    /**
     * Verify one inbound event — docs/SECURITY.md → "Webhooks", roadmap §60.
     *
     * ZR Express delivers through **Svix**, whose scheme is published, so §57
     * was right that this slice had nothing left to invent:
     *
     * ```
     * svix-id          the event id — claimed, never checked
     * svix-timestamp   seconds; inside the signed material, so the 5-minute
     *                    tolerance actually binds
     * svix-signature   one or more space-separated "v1,<base64>" values, any
     *                    one matching is a pass — that is how they rotate keys
     * secret           whsec_<base64>; the base64 *after* the prefix is the
     *                    key, and using the prefixed string verifies nothing
     * signed material  {svix-id}.{svix-timestamp}.{raw body}
     * ```
     *
     * Implemented here rather than by adding the Svix SDK as a Composer
     * dependency: it is fifteen lines of HMAC against a documented string, the
     * house rule is that a package needs a stated reason, and a verifier this
     * codebase can read is worth more than one it cannot.
     *
     * **ASSUMPTION (unverified — no live webhook has ever arrived): that ZR
     * Express signs exactly the scheme Svix publishes.** Every test payload in
     * `tests/Unit/CourierWebhookTest` and `tests/Api/shipping-webhooks.php` is
     * *constructed* from that specification, so the suite proves this verifier
     * matches the published scheme and cannot prove the sender implements it —
     * the header casing, the prefix handling and the signed-material order are
     * all read from documentation, not observed. Unlike §57's live API run,
     * there is no way to settle it without a merchant account receiving a real
     * delivery. `ac_webhook_events` is the standing evidence: a row whose
     * `provider` is `zr-express` means one has genuinely been verified, and
     * today that table holds none.
     *
     * **A real signature may be acted on — and this one still is not.** The
     * webhook reference documents `state.name` as a display string ("Out for
     * Delivery"); the live API returns stable snake_case identifiers, which is
     * what `ZRExpressStateMap` maps and the poller has read since §57. Two
     * documented shapes for the field that decides a parcel's status is exactly
     * where believing a payload writes something nothing else can reason about.
     * So the result carries no status and the handler re-fetches — see
     * `ShipmentWebhookResult`.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers lower-cased header names
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): ShipmentWebhookResult
    {
        $secret = $this->credentials?->webhookSecret ?? '';
        $id = trim($headers['svix-id'] ?? '');
        $timestamp = trim($headers['svix-timestamp'] ?? '');
        $signatures = trim($headers['svix-signature'] ?? '');

        if ($secret === '' || $id === '' || $timestamp === '' || $signatures === '' || $rawBody === '') {
            throw $this->unverified();
        }

        // The timestamp is signed material, so this tolerance binds rather than
        // being theatre — the case docs/SECURITY.md distinguishes.
        if (!ctype_digit(ltrim($timestamp, '-')) || abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            throw $this->unverified();
        }

        $key = base64_decode(self::stripPrefix($secret), true);

        if ($key === false || $key === '') {
            throw $this->unverified();
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$rawBody}", $key, true));
        $matched = false;

        foreach (explode(' ', $signatures) as $candidate) {
            // "v1,<base64>". A version this adapter does not know is skipped
            // rather than refused: Svix adds versions, and every existing one
            // keeps being sent alongside.
            [$version, $value] = array_pad(explode(',', trim($candidate), 2), 2, '');

            if ($version !== 'v1' || $value === '') {
                continue;
            }

            // hash_equals, never == or ===, and no early break — comparing every
            // candidate keeps the work constant whichever one matches.
            if (hash_equals($expected, $value)) {
                $matched = true;
            }
        }

        if (!$matched) {
            throw $this->unverified();
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return new ShipmentWebhookResult(
            // Svix's own id. It is stable across their retries, which is what
            // makes the claim the right idempotency mechanism here.
            $id,
            isset($data['id']) && is_scalar($data['id']) ? trim((string) $data['id']) : '',
            isset($data['trackingNumber']) && is_scalar($data['trackingNumber'])
                ? trim((string) $data['trackingNumber'])
                : '',
            isset($payload['eventType']) && is_scalar($payload['eventType'])
                ? trim((string) $payload['eventType'])
                : '',
            []
        );
    }

    /** `whsec_` is a label on the secret, not part of the key. */
    private static function stripPrefix(string $secret): string
    {
        return str_starts_with($secret, self::SECRET_PREFIX)
            ? substr($secret, strlen(self::SECRET_PREFIX))
            : $secret;
    }

    /**
     * One answer for every verification failure.
     *
     * Logged at warning with the provider and nothing else — never the body,
     * never the headers, never the secret, and never the event id, which at
     * this point is unverified attacker input (docs/SECURITY.md).
     */
    private function unverified(): ApiException
    {
        $this->logger->warning('ZR Express webhook failed verification', ['provider' => self::NAME]);

        return new ApiException(
            'webhook_unverified',
            'This request could not be verified.',
            401,
            ['provider' => self::NAME]
        );
    }
}
