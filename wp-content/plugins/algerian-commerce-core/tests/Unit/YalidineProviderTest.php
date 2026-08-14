<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Integrations\Yalidine\YalidineClient;
use AlgerianCommerce\Integrations\Yalidine\YalidineCredentials;
use AlgerianCommerce\Integrations\Yalidine\YalidineProvider;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Tests\Support\InMemoryDestinationDirectory;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The adapter end to end, against recorded responses — roadmap §56's required
 * cases: a successful shipment, an invalid destination, a duplicate, a timeout,
 * an authentication failure and a provider failure.
 *
 * **The fixtures are real.** Every response body below was captured from the
 * live API on 2026-08-14 and trimmed of personal data, rather than imagined —
 * which is what makes this suite evidence rather than a restatement of the
 * adapter's own assumptions.
 */
final class YalidineProviderTest extends TestCase
{
    /** The create response, exactly as Yalidine shaped it. */
    private const CREATED = [
        'success' => true,
        'order_id' => '42-2',
        'tracking' => 'yal-LW21LH',
        'import_id' => 127,
        'label' => 'https://yalidine.app/app/bordereau.php?tracking=yal-LW21LH&token=REDACTED',
        'labels' => 'https://yalidine.app/app/bordereau.php?import_id=127&si=93376&token=REDACTED',
        'message' => '',
        'do_insurance' => false,
    ];

    /** No parcel for that reference — a 200 with an empty list, not a 404. */
    private const NO_PARCEL = ['has_more' => false, 'total_data' => 0, 'data' => [], 'links' => []];

    /**
     * A create needs its reference lookup answered first: the adapter looks
     * before it creates, because Yalidine will duplicate a parcel otherwise.
     *
     * @param list<HttpResponse> $script
     * @return array{0: YalidineProvider, 1: RecordedHttpClient}
     */
    private function creating(array $script, array $settings = [], bool $mapped = true): array
    {
        return $this->provider([RecordedHttpClient::json(self::NO_PARCEL), ...$script], $settings, $mapped);
    }

    /**
     * @param list<HttpResponse> $script
     * @return array{0: YalidineProvider, 1: RecordedHttpClient}
     */
    private function provider(array $script, array $settings = [], bool $mapped = true): array
    {
        $http = new RecordedHttpClient($script);
        $resolved = YalidineSettings::fromArray($settings + ['origin_wilaya_id' => 6]);

        $directory = new InMemoryDestinationDirectory($mapped ? [
            new ProviderDestination('yalidine', 6, 0, '6', ['name' => 'Béjaïa']),
            new ProviderDestination('yalidine', 16, 0, '16', ['name' => 'Alger']),
            new ProviderDestination('yalidine', 16, 512, '1601', [
                'name' => 'Bouzaréah',
                'centers' => [['id' => '88', 'name' => 'Agence Bouzaréah', 'address' => '3 rue X']],
            ]),
            new ProviderDestination('yalidine', 16, 513, '1602', ['name' => 'Bab Ezzouar']),
            new ProviderDestination('yalidine', 33, 0, '33', ['name' => 'Illizi', 'is_deliverable' => false]),
            new ProviderDestination('yalidine', 33, 900, '3301', ['name' => 'Djanet', 'is_deliverable' => false]),
        ] : []);

        $client = new YalidineClient(
            $http,
            new YalidineCredentials('api-id', 'api-token'),
            $resolved,
            new Logger('test', Logger::ERROR),
            0
        );

        return [new YalidineProvider($client, $directory, $resolved, new Logger('test', Logger::ERROR)), $http];
    }

    private function request(
        string $deliveryType = Destination::HOME,
        int $communeId = 512,
        int $wilayaId = 16
    ): ShipmentRequest {
        return new ShipmentRequest(
            42,
            new Destination($wilayaId, $communeId, $deliveryType),
            'Ahmed Ben Salah',
            '+213555112233',
            '12 rue Didouche Mourad',
            '4500.00',
            '',
            '42-2',
            'Chemise bleue x2'
        );
    }

    public function testASuccessfulParcelReturnsTheTrackingNumber(): void
    {
        [$provider, $http] = $this->creating([RecordedHttpClient::json(['42-2' => self::CREATED])]);

        $result = $provider->createShipment($this->request());

        self::assertSame('yal-LW21LH', $result->trackingNumber);
        // Yalidine has no identifier other than the tracking number, so both
        // fields hold it — the interface keeps them apart for couriers that
        // disagree.
        self::assertSame('yal-LW21LH', $result->providerShipmentId);
        self::assertSame(ShipmentStatus::CREATED, $result->status);
        self::assertStringContainsString('bordereau.php', $result->metadata['label']);

        // One parcel, wrapped in the array the endpoint takes.
        $sent = $http->sentBody(1);
        self::assertCount(1, $sent);
        self::assertSame('42-2', $sent[0]['order_id']);
        self::assertSame('Bouzaréah', $sent[0]['to_commune_name']);
    }

    /**
     * The rejection roadmap §56 insists on naming: an empty array, no message,
     * almost always a commune Yalidine does not recognise. "Unexpected
     * response" would send an operator looking at the wrong thing entirely.
     */
    public function testAnEmptyArrayIsReportedAsARejectedDestination(): void
    {
        [$provider] = $this->creating([RecordedHttpClient::json([])]);

        try {
            $provider->createShipment($this->request());
            self::fail('an empty array means rejected');
        } catch (ApiException $exception) {
            self::assertSame('yalidine_parcel_rejected', $exception->errorCode());
            self::assertSame(400, $exception->statusCode());
            self::assertSame('Bouzaréah', $exception->details()['to_commune_name']);
        }
    }

    /**
     * The real rejection, captured on 2026-08-14 by sending a commune name
     * Yalidine does not know. Their sentence names the field, which is the
     * whole reason it is carried through to the operator.
     */
    public function testAParcelYalidineRefusesKeepsItsReason(): void
    {
        [$provider] = $this->creating([
            RecordedHttpClient::json([
                '42-2' => [
                    'success' => false,
                    'order_id' => '42-2',
                    'tracking' => null,
                    'import_id' => null,
                    'label' => null,
                    'labels' => null,
                    'message' => 'Unknown to_commune_name value in the order_id 42-2. Please check the acceptable commune name in the docs',
                ],
            ]),
        ]);

        try {
            $provider->createShipment($this->request());
            self::fail('success:false must throw');
        } catch (ApiException $exception) {
            self::assertSame('yalidine_parcel_refused', $exception->errorCode());
            self::assertStringContainsString(
                'Unknown to_commune_name',
                $exception->details()['provider_message']
            );
        }
    }

    /**
     * A destination the sync never mapped is refused *before* the call, with
     * the command that fixes it — rather than being sent as a guessed name and
     * rejected without explanation.
     */
    public function testAnUnmappedDestinationIsRefusedWithoutCallingTheCourier(): void
    {
        [$provider, $http] = $this->provider([], [], false);

        try {
            $provider->createShipment($this->request());
            self::fail('an unmapped destination must not be sent');
        } catch (ApiException $exception) {
            self::assertSame('yalidine_destination_unmapped', $exception->errorCode());
            self::assertSame(409, $exception->statusCode());
            self::assertStringContainsString('sync-destinations', $exception->getMessage());
            self::assertSame([], $http->requests);
        }
    }

    /**
     * Coverage is the courier's own answer, not a set of unsupported wilayas
     * kept in code — which is what the reference implementation does.
     */
    public function testAWilayaTheAccountCannotReachIsRefused(): void
    {
        [$provider, $http] = $this->provider([]);

        try {
            // Illizi: published by the courier, and flagged as somewhere this
            // account cannot send.
            $provider->createShipment($this->request(Destination::HOME, 900, 33));
            self::fail('an undeliverable destination must not be sent');
        } catch (ApiException $exception) {
            self::assertSame('yalidine_destination_unavailable', $exception->errorCode());
            self::assertSame([], $http->requests);
        }
    }

    public function testAStoreWithNoOriginWilayaCannotShip(): void
    {
        [$provider, $http] = $this->provider([], ['origin_wilaya_id' => 0]);

        try {
            $provider->createShipment($this->request());
            self::fail('no origin means no parcel');
        } catch (ApiException $exception) {
            self::assertSame('yalidine_not_configured', $exception->errorCode());
            self::assertSame('origin_wilaya_id', $exception->details()['setting']);
            self::assertSame([], $http->requests);
        }
    }

    public function testACollectedParcelPicksTheDeskInThatCommune(): void
    {
        [$provider, $http] = $this->creating([RecordedHttpClient::json(['42-2' => self::CREATED])]);

        $provider->createShipment($this->request(Destination::DESK));

        $sent = $http->sentBody(1)[0];

        self::assertTrue($sent['is_stopdesk']);
        self::assertSame('88', $sent['stopdesk_id']);
    }

    /**
     * Silently delivering to the door instead would charge desk prices for home
     * delivery and send a parcel somewhere the customer did not agree to.
     */
    public function testACommuneWithNoDeskRefusesACollectedParcel(): void
    {
        [$provider, $http] = $this->provider([]);

        try {
            $provider->createShipment($this->request(Destination::DESK, 513));
            self::fail('there is no desk in that commune');
        } catch (ApiException $exception) {
            self::assertSame('yalidine_no_stopdesk', $exception->errorCode());
            self::assertSame([], $http->requests);
        }
    }

    /**
     * The real envelope: `GET parcels/{tracking}` answers with the list
     * wrapper, not the bare object this adapter first assumed. Verified
     * 2026-08-14.
     */
    public function testAStatusPollMapsTheCouriersWordAndKeepsIt(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json([
                'has_more' => false,
                'total_data' => 1,
                'links' => [],
                'data' => [[
                    'tracking' => 'yal-DZ-0001',
                    'order_id' => '42-2',
                    'last_status' => 'Sorti en livraison',
                    'date_last_status' => '2026-08-13 09:15:00',
                    'payment_status' => 'not-ready',
                    'delivery_fee' => 650,
                    'freeshipping' => 1,
                ]],
            ]),
        ]);

        $report = $provider->getShipmentStatus('yal-DZ-0001');

        self::assertSame(ShipmentStatus::OUT_FOR_DELIVERY, $report->status);
        // Their spelling, stored next to ours — the thing that makes a
        // mis-mapping a query rather than an outage nobody can explain.
        self::assertSame('Sorti en livraison', $report->providerStatus);
        self::assertSame('2026-08-13 09:15:00', $report->metadata['provider_status_at']);
    }

    public function testAReturningParcelIsReportedAsStillMoving(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(['data' => [['tracking' => 'yal-DZ-0001', 'last_status' => 'Retour vers centre']]]),
        ]);

        $report = $provider->getShipmentStatus('yal-DZ-0001');

        self::assertSame(ShipmentStatus::RETURNING, $report->status);
        self::assertFalse(ShipmentStatus::isTerminal($report->status));
    }

    public function testAStatusTheAdapterCannotMapIsRaisedRatherThanGuessed(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(['data' => [['tracking' => 'yal-DZ-0001', 'last_status' => 'Nouveau statut 2027']]]),
        ]);

        try {
            $provider->getShipmentStatus('yal-DZ-0001');
            self::fail('an unmapped status must not be defaulted');
        } catch (ApiException $exception) {
            self::assertSame('provider_status_unmapped', $exception->errorCode());
            self::assertSame('Nouveau statut 2027', $exception->details()['provider_status']);
        }
    }

    /**
     * The bare object is still accepted — it costs a line, and it was the
     * defensive branch that carried this adapter while the primary assumption
     * was the wrong way round.
     */
    public function testABareParcelObjectIsStillRead(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(['tracking' => 'yal-DZ-0001', 'last_status' => 'Livré']),
        ]);

        self::assertSame(ShipmentStatus::DELIVERED, $provider->getShipmentStatus('yal-DZ-0001')->status);
    }

    /**
     * A parcel Yalidine has forgotten is a 200 with `total_data: 0`, not a 404,
     * so "not found" has to be read from the body.
     */
    public function testAMissingParcelIsA404EvenThoughYalidineSays200(): void
    {
        [$provider] = $this->provider([RecordedHttpClient::json(self::NO_PARCEL)]);

        try {
            $provider->getShipmentStatus('yal-GONE');
            self::fail('an empty result is not a status');
        } catch (ApiException $exception) {
            self::assertSame('provider_not_found', $exception->errorCode());
            self::assertSame(404, $exception->statusCode());
        }
    }

    public function testRatesQuoteAllFourServicesForTheCommune(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([
                'from_wilaya_name' => 'Béjaïa',
                'to_wilaya_name' => 'Alger',
                'zone' => 1,
                'per_commune' => [
                    '1601' => [
                        'commune_id' => 1601,
                        'commune_name' => 'Bouzaréah',
                        'express_home' => 600,
                        'express_desk' => 400,
                        'economic_home' => 500,
                        'economic_desk' => 350,
                    ],
                ],
            ]),
        ]);

        $quotes = $provider->getShippingRates(new Destination(16, 512));

        self::assertCount(4, $quotes);
        self::assertSame('express_home', $quotes[0]->service);
        // Money stays a decimal string, as everywhere else in this codebase.
        self::assertSame('600.00', $quotes[0]->amount);
        self::assertSame('DZD', $quotes[0]->currency);
        self::assertStringContainsString('from_wilaya_id=6', $http->lastRequest()['url']);
        self::assertStringContainsString('to_wilaya_id=16', $http->lastRequest()['url']);
    }

    /** A commune with no desk simply has no desk prices to quote. */
    public function testAServiceYalidineDoesNotOfferIsNotQuoted(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json([
                'per_commune' => [
                    '1602' => [
                        'commune_name' => 'Bab Ezzouar',
                        'express_home' => 600,
                        'express_desk' => null,
                        'economic_home' => null,
                        'economic_desk' => null,
                    ],
                ],
            ]),
        ]);

        $quotes = $provider->getShippingRates(new Destination(16, 513));

        self::assertCount(1, $quotes);
        self::assertSame('express_home', $quotes[0]->service);
    }

    /**
     * `ShippingService::rates()` quotes every configured courier in one call.
     * One shop's unfinished Yalidine setup must not take the whole price list
     * down with it.
     */
    public function testAnUnmappedDestinationQuotesNothingRatherThanFailing(): void
    {
        [$provider, $http] = $this->provider([], [], false);

        self::assertSame([], $provider->getShippingRates(new Destination(16, 512)));
        self::assertSame([], $http->requests);
    }

    /**
     * This adapter first refused to cancel, because no source documented the
     * endpoint. It does exist — verified 2026-08-14, response body below —
     * so refusing would have left operators cancelling by hand forever.
     */
    public function testCancellationDeletesTheParcel(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([['tracking' => 'yal-LW21LH', 'deleted' => true]]),
        ]);

        self::assertTrue($provider->cancelShipment('yal-LW21LH'));
        self::assertSame('DELETE', $http->lastRequest()['method']);
        self::assertStringContainsString('parcels/yal-LW21LH', $http->lastRequest()['url']);
    }

    /**
     * A parcel already collected is a legitimate refusal, not a fault: false
     * keeps the shipment live, which is the truth about where the parcel is.
     */
    public function testARefusedCancellationIsAnAnswerRatherThanAnError(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json([[
                'tracking' => 'yal-LW21LH',
                'deleted' => false,
                'reason' => 'The parcel does not exist or cannot be deleted.',
            ]]),
        ]);

        self::assertFalse($provider->cancelShipment('yal-LW21LH'));
    }

    /**
     * Roadmap §56's "duplicate shipment", and the assumption that broke:
     * Yalidine does **not** deduplicate on `order_id` — the same reference sent
     * twice produced two parcels with two tracking numbers on 2026-08-14. So
     * the adapter looks the reference up first and hands back the parcel that
     * already exists, rather than putting a second van on the road.
     */
    public function testARetryReturnsTheParcelThatAlreadyExists(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([
                'has_more' => false,
                'total_data' => 1,
                'data' => [[
                    'tracking' => 'yal-LW21LH',
                    'order_id' => '42-2',
                    'last_status' => 'En préparation',
                    'label' => 'https://yalidine.app/app/bordereau.php?tracking=yal-LW21LH',
                ]],
            ]),
        ]);

        $result = $provider->createShipment($this->request());

        self::assertSame('yal-LW21LH', $result->trackingNumber);
        self::assertTrue($result->metadata['reused_existing_parcel']);
        // Its real state, not an invented "created".
        self::assertSame('En préparation', $result->metadata['provider_status']);
        // One lookup, and no POST at all.
        self::assertCount(1, $http->requests);
        self::assertSame('GET', $http->lastRequest()['method']);
        self::assertStringContainsString('order_id=42-2', $http->lastRequest()['url']);
    }

    /**
     * The lookup is best effort. A courier that cannot answer a question is not
     * a reason to refuse a shipment — the behaviour then is simply what it was
     * before the guard existed.
     */
    public function testAFailedLookupDoesNotBlockTheShipment(): void
    {
        [$provider] = $this->provider([
            new HttpResponse(500, ''),
            RecordedHttpClient::json(['42-2' => self::CREATED]),
        ]);

        self::assertSame('yal-LW21LH', $provider->createShipment($this->request())->trackingNumber);
    }

    public function testAnAuthenticationFailureSurfacesAsTheProvidersProblem(): void
    {
        [$provider] = $this->provider([new HttpResponse(401, '{"message":"bad token"}')]);

        try {
            $provider->getShipmentStatus('yal-DZ-0001');
            self::fail('a 401 should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_auth_failed', $exception->errorCode());
            self::assertSame(502, $exception->statusCode());
        }
    }
}
