<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressClient;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressCredentials;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressProvider;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressSettings;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Tests\Support\InMemoryDestinationDirectory;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The ZR Express adapter against recorded responses — roadmap §57, and the same
 * required cases as §56: a successful shipment, an invalid destination, a
 * duplicate, a timeout, an authentication failure and a provider failure.
 *
 * Every body below was captured from the live API on 2026-08-15 and trimmed of
 * personal data.
 */
final class ZRExpressProviderTest extends TestCase
{
    private const ALGER = 'd134c182-7dac-4655-9d9b-bbdb62aa2ec4';
    private const ALGER_CENTRE = '8c97a133-681b-4da4-8466-2f6110557fa2';
    private const CUSTOMER = '0f5a9a1e-1111-2222-3333-444455556666';
    private const PARCEL = '7c3af8c0-a8f8-4bd4-bcff-005a948f2698';

    /** A customer search that found nobody. */
    private const NO_CUSTOMER = ['items' => [], 'totalCount' => 0, 'hasNext' => false];

    /**
     * @param list<HttpResponse> $script
     * @return array{0: ZRExpressProvider, 1: RecordedHttpClient}
     */
    private function provider(array $script, bool $mapped = true): array
    {
        $http = new RecordedHttpClient($script);
        $settings = ZRExpressSettings::fromArray([]);

        $directory = new InMemoryDestinationDirectory($mapped ? [
            new ProviderDestination('zrexpress', 16, 0, self::ALGER, ['name' => 'Alger']),
            new ProviderDestination('zrexpress', 16, 512, self::ALGER_CENTRE, [
                'name' => 'Alger Centre',
                'centers' => [['id' => 'ee77ffe4-19bf-4e34-9435-01a4b7670b7a', 'name' => 'Hub Alger 01', 'address' => '']],
            ]),
            new ProviderDestination('zrexpress', 16, 513, 'no-hub-territory', ['name' => 'Bab Ezzouar']),
            new ProviderDestination('zrexpress', 33, 0, 'illizi-uuid', ['name' => 'Illizi', 'is_deliverable' => false]),
        ] : []);

        $client = new ZRExpressClient(
            $http,
            new ZRExpressCredentials('tenant-uuid', 'api-key'),
            $settings,
            new Logger('test', Logger::ERROR),
            0
        );

        return [new ZRExpressProvider($client, $directory, new Logger('test', Logger::ERROR)), $http];
    }

    private function request(string $deliveryType = Destination::HOME, int $communeId = 512, int $wilayaId = 16): ShipmentRequest
    {
        return new ShipmentRequest(
            42,
            new Destination($wilayaId, $communeId, $deliveryType),
            'Ahmed Ben Salah',
            '0555112233',
            '12 rue Didouche Mourad',
            '5500.00',
            '',
            '42-2',
            'Chemise bleue x2'
        );
    }

    /** @return array<string, mixed> */
    private static function parcel(string $state = 'commande_recue', string $description = 'Commande reçue'): array
    {
        return [
            'id' => self::PARCEL,
            'trackingNumber' => '16-2E3IJ8WY17-ZR',
            'externalId' => '42-2',
            'amount' => 5500.0,
            'deliveryPrice' => 600.0,
            'deliveryType' => 'home',
            'lastStateUpdateAt' => '2026-08-15T09:15:00',
            'state' => ['id' => 'state-uuid', 'name' => $state, 'description' => $description],
        ];
    }

    public function testASuccessfulParcelReturnsBothIdentifiers(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),                 // customers/search
            RecordedHttpClient::json(['id' => self::CUSTOMER]),          // customers/individual
            RecordedHttpClient::json(['id' => self::PARCEL]),            // parcels
            RecordedHttpClient::json(self::parcel()),                    // parcels/{id}
        ]);

        $result = $provider->createShipment($this->request());

        // The pair §53 kept apart on the theory that some courier would
        // disagree with Yalidine. This is that courier.
        self::assertSame(self::PARCEL, $result->providerShipmentId);
        self::assertSame('16-2E3IJ8WY17-ZR', $result->trackingNumber);
        self::assertNotSame($result->providerShipmentId, $result->trackingNumber);
        self::assertSame(ShipmentStatus::CREATED, $result->status);
        self::assertSame('commande_recue', $result->metadata['provider_status']);

        // The parcel payload: UUIDs, not names.
        $sent = $http->sentBody(2);
        self::assertSame(self::ALGER, $sent['deliveryAddress']['cityTerritoryId']);
        self::assertSame(self::ALGER_CENTRE, $sent['deliveryAddress']['districtTerritoryId']);
        self::assertSame(self::CUSTOMER, $sent['customer']['customerId']);
        self::assertSame('home', $sent['deliveryType']);
        // Numeric equality, not identity: JSON has one number type, so 5500.0
        // comes back off the wire as an int.
        self::assertEquals(5500.0, $sent['amount']);
        self::assertSame('42-2', $sent['externalId']);
    }

    /**
     * A customer ZR Express already knows is reused. Searching by phone first
     * is what keeps a retried shipment from filling the account with copies of
     * one person.
     */
    public function testAKnownRecipientIsNotRegisteredTwice(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([
                'items' => [[
                    'id' => self::CUSTOMER,
                    'name' => 'Ahmed Ben Salah',
                    'phone' => ['number1' => '+213555112233'],
                ]],
                'hasNext' => false,
            ]),
            RecordedHttpClient::json(['id' => self::PARCEL]),
            RecordedHttpClient::json(self::parcel()),
        ]);

        $provider->createShipment($this->request());

        // Three calls, not four: no customers/individual.
        self::assertCount(3, $http->requests);
        self::assertStringContainsString('customers/search', $http->requests[0]['url']);
        self::assertStringContainsString('parcels', $http->requests[1]['url']);
    }

    /** ZR Express takes the international form, unlike Yalidine's national one. */
    public function testThePhoneIsSentInInternationalForm(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            RecordedHttpClient::json(['id' => self::PARCEL]),
            RecordedHttpClient::json(self::parcel()),
        ]);

        $provider->createShipment($this->request());

        self::assertSame('+213555112233', $http->sentBody(0)['keyword']);
        self::assertSame('+213555112233', $http->sentBody(1)['phone']['number1']);
    }

    /**
     * Roadmap §57's "duplicate shipment". Unlike Yalidine, ZR Express refuses a
     * repeated `externalId` with a 409 — so the merchant reference really is an
     * idempotency key here, and a retry recovers the parcel that exists.
     */
    public function testARepeatedReferenceRecoversTheExistingParcel(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            new HttpResponse(409, (string) json_encode([
                'title' => 'ParcelErrors.DuplicateExternalId',
                'status' => 409,
                'detail' => 'A parcel with this external id already exists.',
            ])),
            RecordedHttpClient::json(['items' => [self::parcel('sortie_en_livraison', 'Sortie en livraison')], 'hasNext' => false]),
        ]);

        $result = $provider->createShipment($this->request());

        self::assertSame(self::PARCEL, $result->providerShipmentId);
        self::assertTrue($result->metadata['reused_existing_parcel']);
        // And its real state, rather than a fresh "created".
        self::assertSame(ShipmentStatus::OUT_FOR_DELIVERY, $result->status);
    }

    /**
     * The first live run created a parcel and then timed out reading it back,
     * and the whole create was reported as unreachable — leaving a real parcel
     * at the courier that this shop had no record of. Once the parcel exists,
     * nothing after it may throw.
     */
    public function testAParcelIsNotLostWhenTheReadBackFails(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            RecordedHttpClient::json(['id' => self::PARCEL]),
            new HttpResponse(504, ''),                       // the read-back dies
        ]);

        $result = $provider->createShipment($this->request());

        self::assertSame(self::PARCEL, $result->providerShipmentId);
        // No tracking number yet — the next poll fills it in. A shipment with
        // an id is recoverable; one with neither is a parcel nobody knows about.
        self::assertSame('', $result->trackingNumber);
        self::assertSame(ShipmentStatus::CREATED, $result->status);
    }

    /**
     * Their search takes a `filters` object and ignores it — filtering by
     * `externalId` returns every parcel on the account. The reference
     * implementation recovers duplicates that way, so it takes whichever parcel
     * is first and calls it the customer's. Ours checks.
     */
    public function testAReferenceLookupWillNotAcceptSomebodyElsesParcel(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            new HttpResponse(409, (string) json_encode(['detail' => 'duplicate'])),
            // What an unfiltered search returns: the whole account.
            RecordedHttpClient::json([
                'items' => [
                    ['id' => 'someone-else', 'externalId' => 'ORD-20260602-0010', 'trackingNumber' => '15-XX-ZR'],
                    ['id' => 'also-not-ours', 'externalId' => '', 'trackingNumber' => '16-YY-ZR'],
                ],
                'hasNext' => true,
            ]),
        ]);

        try {
            $provider->createShipment($this->request());
            self::fail('a 409 with no parcel of ours must not be softened');
        } catch (ApiException $exception) {
            // The conflict stands rather than a stranger's parcel being adopted.
            self::assertSame('provider_conflict', $exception->errorCode());
        }

        self::assertSame('keyword', array_key_first((array) $http->sentBody(3)));
    }

    /**
     * Same reasoning one step earlier: a customer whose number merely resembles
     * this one would put a stranger's name and phone on somebody's delivery.
     */
    public function testARecipientIsOnlyReusedOnAnExactPhoneMatch(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([
                'items' => [
                    ['id' => 'wrong-person', 'name' => 'Someone Else', 'phone' => ['number1' => '+213555112299']],
                    ['id' => self::CUSTOMER, 'name' => 'Ahmed Ben Salah', 'phone' => ['number1' => '+213555112233']],
                ],
                'hasNext' => false,
            ]),
            RecordedHttpClient::json(['id' => self::PARCEL]),
            RecordedHttpClient::json(self::parcel()),
        ]);

        $provider->createShipment($this->request());

        self::assertSame(self::CUSTOMER, $http->sentBody(1)['customer']['customerId']);
    }

    /** No exact match means a new record, not the nearest stranger. */
    public function testANearMissRegistersANewRecipient(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([
                'items' => [['id' => 'wrong-person', 'phone' => ['number1' => '+213555112299']]],
                'hasNext' => false,
            ]),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            RecordedHttpClient::json(['id' => self::PARCEL]),
            RecordedHttpClient::json(self::parcel()),
        ]);

        $provider->createShipment($this->request());

        self::assertStringContainsString('customers/individual', $http->requests[1]['url']);
        self::assertSame(self::CUSTOMER, $http->sentBody(2)['customer']['customerId']);
    }

    public function testAnUnmappedDestinationIsRefusedWithoutCallingTheCourier(): void
    {
        [$provider, $http] = $this->provider([], false);

        try {
            $provider->createShipment($this->request());
            self::fail('an unmapped territory must not be sent');
        } catch (ApiException $exception) {
            self::assertSame('zrexpress_destination_unmapped', $exception->errorCode());
            self::assertStringContainsString('sync-destinations', $exception->getMessage());
            self::assertSame([], $http->requests);
        }
    }

    /**
     * Coverage from the courier's own `delivery.canSend`, not the reference
     * implementation's hard-coded list of four unsupported wilayas.
     */
    public function testAnUndeliverableTerritoryIsRefused(): void
    {
        [$provider, $http] = $this->provider([]);

        try {
            $provider->createShipment($this->request(Destination::HOME, 0, 33));
            self::fail('an undeliverable territory must not be sent');
        } catch (ApiException $exception) {
            self::assertSame('zrexpress_destination_unavailable', $exception->errorCode());
            self::assertSame([], $http->requests);
        }
    }

    public function testACollectedParcelNamesItsPickupPoint(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            RecordedHttpClient::json(['id' => self::PARCEL]),
            RecordedHttpClient::json(self::parcel()),
        ]);

        $provider->createShipment($this->request(Destination::DESK));

        $sent = $http->sentBody(2);
        self::assertSame('pickup-point', $sent['deliveryType']);
        self::assertSame('ee77ffe4-19bf-4e34-9435-01a4b7670b7a', $sent['hubId']);
    }

    public function testACommuneWithNoPickupPointRefusesACollectedParcel(): void
    {
        [$provider, $http] = $this->provider([]);

        try {
            $provider->createShipment($this->request(Destination::DESK, 513));
            self::fail('there is no pickup point in that commune');
        } catch (ApiException $exception) {
            self::assertSame('zrexpress_no_pickup_point', $exception->errorCode());
            self::assertSame([], $http->requests);
        }
    }

    public function testAStatusPollMapsTheStateAndKeepsBothWordings(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(self::parcel('sortie_en_livraison', 'Sortie en livraison')),
        ]);

        $report = $provider->getShipmentStatus(self::PARCEL);

        self::assertSame(ShipmentStatus::OUT_FOR_DELIVERY, $report->status);
        // The machine name is what we mapped from, and the description is what
        // an operator recognises from the ZR dashboard.
        self::assertSame('sortie_en_livraison', $report->providerStatus);
        self::assertSame('Sortie en livraison', $report->metadata['provider_status_label']);
    }

    /** The end of a return, and the state before it. */
    public function testTheJourneyBackIsMappedToItsTwoStages(): void
    {
        [$waiting] = $this->provider([
            RecordedHttpClient::json(self::parcel('attente_recuperation_fournisseur', 'En attente récupération fournisseur')),
        ]);
        self::assertSame(ShipmentStatus::RETURNING, $waiting->getShipmentStatus(self::PARCEL)->status);

        [$back] = $this->provider([
            RecordedHttpClient::json(self::parcel('recupere_par_fournisseur', 'Récupéré par fournisseur')),
        ]);
        self::assertSame(ShipmentStatus::RETURNED, $back->getShipmentStatus(self::PARCEL)->status);
    }

    /**
     * §57's documented gap: the state enumeration is not published. An unknown
     * one is raised rather than guessed at — the reference implementation's
     * substring matching is exactly what this refuses to repeat.
     */
    public function testAnUnknownStateIsRaisedRatherThanGuessed(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(self::parcel('etat_inconnu_2027', 'État inconnu')),
        ]);

        try {
            $provider->getShipmentStatus(self::PARCEL);
            self::fail('an unmapped state must not be defaulted');
        } catch (ApiException $exception) {
            self::assertSame('provider_status_unmapped', $exception->errorCode());
            self::assertSame('etat_inconnu_2027', $exception->details()['provider_status']);
        }
    }

    public function testCancellationDeletesTheParcel(): void
    {
        [$provider, $http] = $this->provider([new HttpResponse(204, '')]);

        self::assertTrue($provider->cancelShipment(self::PARCEL));
        self::assertSame('DELETE', $http->lastRequest()['method']);
        self::assertStringContainsString('parcels/' . self::PARCEL, $http->lastRequest()['url']);
    }

    /** A parcel already moving cannot be deleted, and that is an answer. */
    public function testARefusedCancellationIsFalseRatherThanAnError(): void
    {
        [$provider] = $this->provider([
            new HttpResponse(400, (string) json_encode([
                'title' => 'ParcelErrors.CannotBeDeleted',
                'status' => 400,
                'detail' => 'The parcel cannot be deleted in its current state.',
            ])),
        ]);

        self::assertFalse($provider->cancelShipment(self::PARCEL));
    }

    public function testRatesQuoteTheServiceThatWasAskedFor(): void
    {
        [$provider, $http] = $this->provider([
            RecordedHttpClient::json([
                'toTerritoryId' => self::ALGER_CENTRE,
                'toTerritoryName' => 'Alger Centre',
                'toTerritoryLevel' => 'commune',
                'deliveryPrices' => [
                    ['deliveryType' => 'pickup-point', 'price' => 470.0, 'discountedPrice' => null],
                    ['deliveryType' => 'home', 'price' => 600.0, 'discountedPrice' => 550.0],
                ],
            ]),
        ]);

        $quotes = $provider->getShippingRates(new Destination(16, 512));

        self::assertCount(1, $quotes);
        self::assertSame('home', $quotes[0]->service);
        // The discounted price is what this account actually pays.
        self::assertSame('550.00', $quotes[0]->amount);
        self::assertStringContainsString('delivery-pricing/rates/' . self::ALGER_CENTRE, $http->lastRequest()['url']);
    }

    /**
     * Their pricing is a wilaya rate with specific prices inside the supplier's
     * own wilaya, so a commune with no price of its own falls back rather than
     * quoting nothing.
     */
    public function testACommuneWithoutItsOwnPriceFallsBackToTheWilaya(): void
    {
        [$provider, $http] = $this->provider([
            new HttpResponse(404, (string) json_encode(['detail' => 'Delivery price not found'])),
            RecordedHttpClient::json([
                'toTerritoryLevel' => 'wilaya',
                'deliveryPrices' => [['deliveryType' => 'home', 'price' => 600.0, 'discountedPrice' => null]],
            ]),
        ]);

        $quotes = $provider->getShippingRates(new Destination(16, 512));

        self::assertCount(1, $quotes);
        self::assertSame('600.00', $quotes[0]->amount);
        self::assertCount(2, $http->requests);
    }

    /**
     * ZR Express restricts rates to the supplier's own origin wilaya and says
     * so in a sentence. `GET /shipping/rates` asks every courier at once, so one
     * restriction must not take the price list down.
     */
    public function testARestrictedQuoteIsEmptyRatherThanAnError(): void
    {
        [$provider] = $this->provider([
            new HttpResponse(400, (string) json_encode([
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Suppliers can only request rates for all wilayas or communes of their origin wilaya',
            ])),
        ]);

        self::assertSame([], $provider->getShippingRates(new Destination(16, 512)));
    }

    public function testAnAuthenticationFailureSurfacesAsTheProvidersProblem(): void
    {
        [$provider] = $this->provider([new HttpResponse(401, 'Invalid API Key')]);

        try {
            $provider->getShipmentStatus(self::PARCEL);
            self::fail('a 401 should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_auth_failed', $exception->errorCode());
            self::assertSame(502, $exception->statusCode());
        }
    }

    /** Their problem+json carries a sentence worth passing on. */
    public function testAProviderRejectionKeepsItsDetail(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(self::NO_CUSTOMER),
            RecordedHttpClient::json(['id' => self::CUSTOMER]),
            new HttpResponse(400, (string) json_encode([
                'type' => 'https://tools.ietf.org/html/rfc9110',
                'title' => 'ValidationError',
                'status' => 400,
                'detail' => 'districtTerritoryId must belong to cityTerritoryId',
                'traceId' => '0HNNPS6794KV1:00000048',
            ])),
        ]);

        try {
            $provider->createShipment($this->request());
            self::fail('a 400 should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_rejected', $exception->errorCode());
            self::assertSame(
                'districtTerritoryId must belong to cityTerritoryId',
                $exception->details()['provider_message']
            );
            // The trace id goes to the log for support, not to the caller.
            self::assertArrayNotHasKey('trace_id', $exception->details());
        }
    }
}
