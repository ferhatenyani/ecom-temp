<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Geography\GeoSlug;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\DestinationCatalogueInterface;
use AlgerianCommerce\Shipping\DestinationDirectoryInterface;
use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\RateQuote;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentResult;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Shipping\ShippingProviderInterface;
use AlgerianCommerce\Shipping\StatusReport;

/**
 * Yalidine — roadmap §56.
 *
 * The adapter, and nothing above it changed to accommodate it: it implements
 * `ShippingProviderInterface`, receives our value objects, and returns ours.
 * `ShippingService` still says *create shipment*.
 *
 * ## What this was written from, and what has since been verified
 *
 * Written from three independent implementations that agree on every endpoint
 * and field name — chiefly a Spring Boot service running in production against
 * the live API — because roadmap §54 forbids writing an adapter from memory but
 * not from working code. Every point where they were silent was marked
 * `ASSUMPTION (unverified)`.
 *
 * **On 2026-08-14 those markers were tested against the live API**, using the
 * merchant credentials of that same Spring Boot project, with its owner's
 * permission. Most were confirmed; three were wrong and are corrected here:
 *
 * ```
 * GET parcels/{tracking}   wrapped in {data:[…]}, not the bare object assumed
 * order_id                 NOT idempotent — a repeat creates a second parcel
 * DELETE parcels/{t}       exists, so cancellation is real after all
 * ```
 *
 * What remains marked is what a single afternoon against one account cannot
 * settle:
 *
 * ```
 * grep -rn 'ASSUMPTION' integrations/Yalidine
 * ```
 *
 * ## The two things that make it work
 *
 *  - **Destinations come from the table, not from PHP.** Yalidine addresses a
 *    parcel by wilaya and commune *name*, matched exactly, and answers an
 *    unrecognised commune with an empty array and no message. The reference
 *    implementation copes by hard-coding 58 wilaya names and re-fetching the
 *    fees endpoint to guess at commune spellings. Here
 *    `wp algerian-commerce sync-destinations` has already recorded Yalidine's
 *    own spelling of every place in `ac_geo_provider_destinations`, and this
 *    adapter quotes it back to them. An unmapped destination is refused *before*
 *    the call, with the fix in the message.
 *  - **Statuses are mapped by whole label, never by substring.** See
 *    `YalidineStatusMap`, and the two traps it exists to avoid.
 *
 * ## Idempotency is ours, because it is not theirs
 *
 * Sending the same `order_id` twice produced two parcels with two tracking
 * numbers, so the merchant reference is *not* an idempotency key — the belief
 * §53 and §56 were both written under. What is true is that
 * `GET parcels/?order_id=` finds a parcel by our reference, so this adapter
 * looks before it creates: a retry after a lost response returns the parcel
 * that already exists instead of putting a second van on the road.
 */
final class YalidineProvider implements ShippingProviderInterface, DestinationCatalogueInterface
{
    public const NAME = 'yalidine';

    private readonly YalidineDestinations $catalogue;

    public function __construct(
        private readonly YalidineClient $client,
        private readonly DestinationDirectoryInterface $directory,
        private readonly YalidineSettings $settings,
        private readonly Logger $logger,
        ?YalidineDestinations $catalogue = null
    ) {
        $this->catalogue = $catalogue ?? new YalidineDestinations($client);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Yalidine';
    }

    /** The credential check a setup screen makes — roadmap §56. */
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
     * Hand a parcel to Yalidine.
     *
     * `POST parcels/` takes an **array** of parcels and answers with an
     * **object keyed by `order_id`** — ours, the merchant reference — whose
     * entry is `{success, order_id, tracking, import_id, label, labels,
     * message}`. Verified 2026-08-14, including the failure: a commune name
     * Yalidine does not know comes back as that same object with
     * `success: false` and a message naming the field, which is a far better
     * answer than the bare `[]` the production implementation logged. Both are
     * handled — the empty array is rare, and it was seen in the wild.
     */
    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $origin = $this->requireOrigin();
        $toWilaya = $this->requireDestination($request->destination->wilayaId, 0);
        $toCommune = $this->requireDestination(
            $request->destination->wilayaId,
            $request->destination->communeId
        );

        $stopdeskId = $request->destination->isDesk() ? $this->requireStopDesk($toCommune) : null;
        $reference = $request->reference !== '' ? $request->reference : (string) $request->orderId;

        $existing = $this->findByReference($reference);

        if ($existing !== null) {
            return $existing;
        }

        $payload = YalidineParcel::payload($request, $origin, $toWilaya, $toCommune, $this->settings, $stopdeskId);

        // The array is the payload shape, not a batch: one parcel per call, so
        // that a partial failure cannot leave half an order shipped.
        $response = $this->client->post('parcels/', [$payload]);

        if (!is_array($response) || $response === [] || array_is_list($response)) {
            $this->logger->error('Yalidine rejected a parcel outright', [
                'order_id' => $request->orderId,
                'reference' => $reference,
                'to_wilaya_name' => $toWilaya->name(),
                'to_commune_name' => $toCommune->name(),
            ]);

            throw new ApiException(
                'yalidine_parcel_rejected',
                'Yalidine rejected this parcel without giving a reason. Re-run the destination sync for this commune.',
                400,
                [
                    'provider' => self::NAME,
                    'to_wilaya_name' => $toWilaya->name(),
                    'to_commune_name' => $toCommune->name(),
                ]
            );
        }

        $parcel = $response[$reference] ?? null;

        if (!is_array($parcel)) {
            $this->logger->error('Yalidine answered without the parcel we sent', [
                'order_id' => $request->orderId,
                'reference' => $reference,
                // `answered_for`, not `keys`: Logger::redact() masks any key
                // containing "key" — including "response_keys" — so the one
                // diagnostic this line exists to produce was being written as
                // [redacted] (docs/SECURITY.md, §55). These are the references
                // Yalidine answered about, which is what the name now says.
                'answered_for' => array_slice(array_keys($response), 0, 5),
            ]);

            throw new ApiException(
                'provider_response_invalid',
                'Yalidine answered about a different parcel.',
                502,
                ['provider' => self::NAME]
            );
        }

        $tracking = isset($parcel['tracking']) && is_scalar($parcel['tracking'])
            ? trim((string) $parcel['tracking'])
            : '';

        if (empty($parcel['success']) || $tracking === '') {
            $message = isset($parcel['message']) && is_scalar($parcel['message'])
                ? trim((string) $parcel['message'])
                : '';

            $this->logger->error('Yalidine refused a parcel', [
                'order_id' => $request->orderId,
                'reference' => $reference,
                'provider_message' => $message,
            ]);

            throw new ApiException(
                'yalidine_parcel_refused',
                'Yalidine would not create this parcel.',
                400,
                array_filter([
                    'provider' => self::NAME,
                    // Their sentence, kept: it names the field they disliked,
                    // and there is no published catalogue of these to map
                    // against. It carries no URL, header or credential.
                    'provider_message' => $message,
                ])
            );
        }

        return new ShipmentResult(
            // Yalidine addresses a parcel by its tracking number and has no
            // second identifier, so the two are the same string here. The
            // interface keeps them apart because at other couriers they differ.
            $tracking,
            $tracking,
            ShipmentStatus::CREATED,
            array_filter([
                'label' => isset($parcel['label']) && is_scalar($parcel['label']) ? (string) $parcel['label'] : '',
                'reference' => $reference,
                'to_wilaya_name' => $toWilaya->name(),
                'to_commune_name' => $toCommune->name(),
                'stopdesk_id' => $stopdeskId ?? '',
            ])
        );
    }

    /**
     * `DELETE parcels/{tracking}`.
     *
     * This adapter first refused to cancel at all: no source documented the
     * endpoint, and §54 forbids inventing one whose failure mode is destroying a
     * real parcel. Verified on 2026-08-14 — it exists, and answers
     * `[{"tracking": "…", "deleted": true}]`, or `deleted: false` with a reason
     * when it will not.
     *
     * `false` rather than an exception for that second case, which is exactly
     * what the interface asks for: a parcel already collected is a legitimate
     * refusal, not a fault, and `ShippingService` turns it into a 409 that keeps
     * the shipment live — because the parcel *is* still live.
     */
    public function cancelShipment(string $providerShipmentId): bool
    {
        $response = $this->client->delete('parcels/' . rawurlencode($providerShipmentId));

        // A list of results, one per tracking number: the endpoint takes
        // several, and we send one.
        $result = is_array($response) ? ($response[0] ?? null) : null;

        if (!is_array($result)) {
            throw new ApiException(
                'provider_response_invalid',
                'Yalidine did not say whether the parcel was cancelled.',
                502,
                ['provider' => self::NAME]
            );
        }

        if (empty($result['deleted'])) {
            $this->logger->warning('Yalidine would not cancel a parcel', [
                'tracking' => $providerShipmentId,
                'reason' => isset($result['reason']) && is_scalar($result['reason'])
                    ? (string) $result['reason']
                    : '',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Where the parcel is, in our vocabulary.
     *
     * An unmapped `last_status` throws rather than defaulting to anything: a
     * courier that adds a state must be visible on the first parcel that
     * reaches it, not after a month of parcels reading as "in transit".
     */
    public function getShipmentStatus(string $providerShipmentId): StatusReport
    {
        $parcel = $this->parcel($providerShipmentId);

        $lastStatus = isset($parcel['last_status']) && is_scalar($parcel['last_status'])
            ? trim((string) $parcel['last_status'])
            : '';

        $status = YalidineStatusMap::toShipmentStatus($lastStatus);

        if ($status === null) {
            $this->logger->error('Yalidine reported a status this adapter does not map', [
                'tracking' => $providerShipmentId,
                'last_status' => $lastStatus,
            ]);

            throw new ApiException(
                'provider_status_unmapped',
                'Yalidine reported a parcel state this store does not recognise.',
                502,
                ['provider' => self::NAME, 'provider_status' => $lastStatus]
            );
        }

        return new StatusReport($status, $lastStatus, array_filter([
            'provider_status_at' => isset($parcel['date_last_status']) && is_scalar($parcel['date_last_status'])
                ? (string) $parcel['date_last_status']
                : '',
            // What Yalidine says about the money. COD reconciliation is a later
            // section; recording it now costs nothing and means the history is
            // there when it arrives.
            'payment_status' => isset($parcel['payment_status']) && is_scalar($parcel['payment_status'])
                ? (string) $parcel['payment_status']
                : '',
        ]));
    }

    /**
     * What Yalidine charges to reach a destination.
     *
     * `GET fees/?from_wilaya_id=&to_wilaya_id=` prices a whole wilaya at once,
     * per commune, for four services: express and economic, each to the door or
     * to a desk. All four are returned rather than only the pair matching the
     * requested delivery type — they arrive in the same call, `RateQuote`
     * carries the service name, and a manager choosing between "he collects it"
     * and "we deliver it" is exactly the comparison this endpoint exists for.
     *
     * An unconfigured or unmapped shop gets an empty list, not an exception:
     * `ShippingService::rates()` quotes every configured courier in one call,
     * and one shop's missing origin wilaya must not take the whole price list
     * down. A courier that *fails* still throws — that is not the same thing.
     *
     * @return list<RateQuote>
     */
    public function getShippingRates(Destination $destination): array
    {
        $origin = $this->directory->find(self::NAME, $this->settings->originWilayaId, 0);
        $toWilaya = $this->directory->find(self::NAME, $destination->wilayaId, 0);
        $toCommune = $this->directory->find(self::NAME, $destination->wilayaId, $destination->communeId);

        if ($origin === null || $toWilaya === null || $toCommune === null) {
            $this->logger->warning('Yalidine cannot quote a destination it has not mapped', [
                'origin_wilaya_id' => $this->settings->originWilayaId,
                'wilaya_id' => $destination->wilayaId,
                'commune_id' => $destination->communeId,
            ]);

            return [];
        }

        $fees = $this->client->get('fees/', [
            'from_wilaya_id' => $origin->destinationId,
            'to_wilaya_id' => $toWilaya->destinationId,
        ]);

        $commune = self::communeFees($fees, $toCommune);

        if ($commune === null) {
            $this->logger->warning('Yalidine quoted a wilaya without the commune asked for', [
                'wilaya_id' => $destination->wilayaId,
                'commune_id' => $destination->communeId,
            ]);

            return [];
        }

        $quotes = [];

        foreach ([
            'express_home' => 'Yalidine Express — home delivery',
            'express_desk' => 'Yalidine Express — stop desk',
            'economic_home' => 'Yalidine Economic — home delivery',
            'economic_desk' => 'Yalidine Economic — stop desk',
        ] as $service => $label) {
            $amount = $commune[$service] ?? null;

            // Absent means Yalidine does not offer that service there — a
            // commune with no desk, most often. A zero it *did* send is a real
            // price and is kept.
            if ($amount === null || !is_numeric($amount)) {
                continue;
            }

            $quotes[] = new RateQuote(
                $service,
                $label,
                number_format((float) $amount, 2, '.', ''),
                'DZD',
                null,
                RateQuote::SOURCE_PROVIDER
            );
        }

        return $quotes;
    }

    /**
     * `GET parcels/{tracking}`.
     *
     * Verified 2026-08-14: the answer is the **list envelope**,
     * `{has_more, total_data, data: [ … ], links}`, with the parcel as its only
     * row — not the bare object this adapter first assumed. A missing parcel is
     * a 200 with `total_data: 0`, not a 404, so "not found" has to be read from
     * the body rather than the status.
     *
     * The bare-object form is still accepted, because it costs one line and the
     * defensive branch is what kept this working when the assumption turned out
     * to be wrong.
     *
     * @return array<string, mixed>
     */
    private function parcel(string $tracking): array
    {
        $parcel = $this->findParcel('parcels/' . rawurlencode($tracking));

        if ($parcel === null) {
            throw new ApiException(
                'provider_not_found',
                'Yalidine has no record of that parcel.',
                404,
                ['provider' => self::NAME]
            );
        }

        return $parcel;
    }

    /**
     * The first parcel a query returns, or null when it returns none.
     *
     * @return array<string, mixed>|null
     */
    private function findParcel(string $path): ?array
    {
        $response = $this->client->get($path);

        if (!is_array($response)) {
            throw new ApiException(
                'provider_response_invalid',
                'Yalidine returned a response this store could not read.',
                502,
                ['provider' => self::NAME]
            );
        }

        if (array_key_exists('data', $response)) {
            $first = is_array($response['data']) ? ($response['data'][0] ?? null) : null;

            return is_array($first) ? $first : null;
        }

        return $response === [] ? null : $response;
    }

    /**
     * The parcel this shop already created for that reference, if there is one.
     *
     * Yalidine does **not** deduplicate on `order_id` — verified on 2026-08-14,
     * where sending the same one twice produced two parcels — so a lost response
     * on create would otherwise mean two vans and one customer served twice.
     * `GET parcels/?order_id=` is the query that makes this recoverable, and one
     * cheap read before an expensive, irreversible write is the trade.
     *
     * **Best effort by design.** If the lookup itself fails, the create goes
     * ahead: a courier that cannot answer a question is not a reason to refuse a
     * shipment, and the behaviour then is simply what it was before this guard
     * existed.
     */
    private function findByReference(string $reference): ?ShipmentResult
    {
        try {
            $parcel = $this->findParcel('parcels/?' . http_build_query([
                'order_id' => $reference,
                'page_size' => 1,
            ]));
        } catch (ApiException $exception) {
            $this->logger->warning('Yalidine could not be asked about an existing parcel', [
                'reference' => $reference,
                'error' => $exception->errorCode(),
            ]);

            return null;
        }

        $tracking = isset($parcel['tracking']) && is_scalar($parcel['tracking'])
            ? trim((string) $parcel['tracking'])
            : '';

        if ($tracking === '') {
            return null;
        }

        $lastStatus = isset($parcel['last_status']) && is_scalar($parcel['last_status'])
            ? trim((string) $parcel['last_status'])
            : '';

        $this->logger->warning('Yalidine already had a parcel for this reference; reusing it', [
            'reference' => $reference,
            'tracking' => $tracking,
            'last_status' => $lastStatus,
        ]);

        /*
         * An unmappable status does not refuse the parcel here, unlike a status
         * poll. The parcel exists either way, and the point of this branch is to
         * stop a second one being made — reporting `created` with the courier's
         * own word beside it keeps that record, and the next poll corrects the
         * status the moment the mapping covers it.
         */
        return new ShipmentResult(
            $tracking,
            $tracking,
            YalidineStatusMap::toShipmentStatus($lastStatus) ?? ShipmentStatus::CREATED,
            array_filter([
                'label' => isset($parcel['label']) && is_scalar($parcel['label']) ? (string) $parcel['label'] : '',
                'reference' => $reference,
                'provider_status' => $lastStatus,
                'reused_existing_parcel' => true,
            ])
        );
    }

    /**
     * The origin wilaya, as Yalidine spells it.
     *
     * Two distinct refusals, because they need two different fixes: the shop
     * has not said where it ships from, or it has and the sync has not run.
     */
    private function requireOrigin(): ProviderDestination
    {
        if (!$this->settings->hasOrigin()) {
            throw new ApiException(
                'yalidine_not_configured',
                'Set this store\'s origin wilaya before creating a Yalidine parcel.',
                409,
                ['provider' => self::NAME, 'setting' => 'origin_wilaya_id']
            );
        }

        $origin = $this->directory->find(self::NAME, $this->settings->originWilayaId, 0);

        if ($origin === null) {
            throw new ApiException(
                'yalidine_destination_unmapped',
                'Yalidine has no destination recorded for this store\'s origin wilaya. Run: wp algerian-commerce sync-destinations --provider=yalidine',
                409,
                ['provider' => self::NAME, 'wilaya_id' => $this->settings->originWilayaId]
            );
        }

        return $origin;
    }

    private function requireDestination(int $wilayaId, int $communeId): ProviderDestination
    {
        $destination = $this->directory->find(self::NAME, $wilayaId, $communeId);

        if ($destination === null) {
            throw new ApiException(
                'yalidine_destination_unmapped',
                'Yalidine has no destination recorded for that place. Run: wp algerian-commerce sync-destinations --provider=yalidine',
                409,
                [
                    'provider' => self::NAME,
                    'wilaya_id' => $wilayaId,
                    'commune_id' => $communeId,
                ]
            );
        }

        if (!$destination->isDeliverable()) {
            // The courier's own answer, not a list of unsupported wilayas kept
            // in code — roadmap §56 is explicit that coverage is data.
            throw new ApiException(
                'yalidine_destination_unavailable',
                'Yalidine does not deliver to that destination for this account.',
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
     * Which desk a collected parcel goes to.
     *
     * The first one the sync recorded in that commune. Letting a *customer*
     * pick a specific desk needs a stop-desk id on the shipment request and a
     * way for a storefront to list them, which is a checkout question rather
     * than an adapter one — deferred deliberately, and noted in the README.
     * A commune with no desk is refused rather than silently delivered to the
     * door, which would charge desk prices for home delivery.
     */
    private function requireStopDesk(ProviderDestination $commune): string
    {
        $centers = $commune->centers();

        if ($centers === []) {
            throw new ApiException(
                'yalidine_no_stopdesk',
                'Yalidine has no stop desk in that commune; send this parcel to the customer\'s address instead.',
                409,
                ['provider' => self::NAME, 'commune' => $commune->name()]
            );
        }

        if (count($centers) > 1) {
            $this->logger->info('Yalidine commune has several stop desks; using the first', [
                'commune' => $commune->name(),
                'centers' => count($centers),
            ]);
        }

        return $centers[0]['id'];
    }

    /**
     * One commune's fees out of a wilaya's price list.
     *
     * Keyed by Yalidine's commune id, which is what the destination table
     * holds; the name is a fallback for the day that key turns out to be
     * something else, and it is folded before comparing because these names
     * differ by accent between every two sources that print them.
     *
     * @return array<string, mixed>|null
     */
    private static function communeFees(mixed $fees, ProviderDestination $commune): ?array
    {
        if (!is_array($fees) || !isset($fees['per_commune']) || !is_array($fees['per_commune'])) {
            return null;
        }

        $byId = $fees['per_commune'][$commune->destinationId] ?? null;

        if (is_array($byId)) {
            return $byId;
        }

        $wanted = GeoSlug::make($commune->name());

        foreach ($fees['per_commune'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = isset($row['commune_name']) && is_scalar($row['commune_name'])
                ? (string) $row['commune_name']
                : '';

            if ($wanted !== '' && GeoSlug::make($name) === $wanted) {
                return $row;
            }
        }

        return null;
    }
}
