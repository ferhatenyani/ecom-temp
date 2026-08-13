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
 * ## What this was written from
 *
 * There is **no merchant account and no sandbox**, so nothing here has been
 * confirmed against the live API. Roadmap §54 forbids writing an adapter from
 * memory; it does not forbid writing one from working code, and three
 * independent implementations agree on every endpoint and field name used here
 * — chiefly a Spring Boot service running in production against the live API.
 * Every point where they are silent is marked `ASSUMPTION (unverified)` in the
 * code, so the first live call proves or disproves each one visibly:
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
 * ## What it deliberately does not do
 *
 * `cancelShipment()` refuses. None of the three sources documents a cancel or
 * delete endpoint, and §56's own list of what they agree on does not contain
 * one. Inventing a `DELETE parcels/{tracking}` would be a guess whose failure
 * mode is destroying a real parcel record, so the operator is told to cancel in
 * the Yalidine dashboard and mark the shipment cancelled here.
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
     * **object keyed by `order_id`** — ours, the merchant reference — which is
     * how the result is found. An empty array in response means the request was
     * rejected outright, almost always because `to_commune_name` did not match
     * a Yalidine commune exactly; that gets a named error of its own, because
     * "unexpected response" would send an operator looking at the wrong thing.
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
                'Yalidine rejected this parcel without giving a reason, which usually means it does not recognise the destination. Re-run the destination sync for this commune.',
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
                'keys' => array_slice(array_keys($response), 0, 5),
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
     * Refused, with the reason — see the class docblock.
     *
     * Not `false`: that means "the courier declined", and `ShippingService`
     * turns it into "the provider will not cancel this shipment", which would
     * be us putting words in Yalidine's mouth about a call nobody made.
     */
    public function cancelShipment(string $providerShipmentId): bool
    {
        throw new ApiException(
            'cancel_unsupported',
            'Cancelling a Yalidine parcel is not part of the API surface this adapter was built from. Cancel it in the Yalidine dashboard, then mark this shipment cancelled.',
            409,
            ['provider' => self::NAME]
        );
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
     * ASSUMPTION (unverified): the single-parcel endpoint answers with the
     * parcel object itself. The list form of the same endpoint wraps rows in
     * `data`, so both shapes are accepted — one of them is right, and guessing
     * wrong would turn every status poll into an error.
     *
     * @return array<string, mixed>
     */
    private function parcel(string $tracking): array
    {
        $response = $this->client->get('parcels/' . rawurlencode($tracking));

        if (!is_array($response)) {
            throw new ApiException(
                'provider_response_invalid',
                'Yalidine returned a response this store could not read.',
                502,
                ['provider' => self::NAME]
            );
        }

        if (isset($response['data']) && is_array($response['data'])) {
            $first = $response['data'][0] ?? null;

            if (!is_array($first)) {
                throw new ApiException(
                    'provider_not_found',
                    'Yalidine has no record of that parcel.',
                    404,
                    ['provider' => self::NAME]
                );
            }

            return $first;
        }

        return $response;
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
