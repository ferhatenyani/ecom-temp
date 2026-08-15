<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Integrations\Yalidine\YalidineClient;
use AlgerianCommerce\Integrations\Yalidine\YalidineCredentials;
use AlgerianCommerce\Integrations\Yalidine\YalidineProvider;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressClient;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressCredentials;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressProvider;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressSettings;
use AlgerianCommerce\Shipping\ManualProvider;
use AlgerianCommerce\Tests\Support\InMemoryDestinationDirectory;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The two courier webhook verifiers — roadmap §60, docs/SECURITY.md → "Webhooks".
 *
 * §60 requires **forgery** and **replay** tests, and says why: they are the only
 * proof the rule is implemented rather than merely written down. The two
 * couriers are tested together because the interesting thing about them is the
 * contrast — one signs properly and one does not — and a test file per adapter
 * would put the comparison nowhere.
 *
 * ```
 * ZR Express   Svix: HMAC-SHA256 over {id}.{timestamp}.{body}, base64,
 *                against the base64 key behind whsec_. Timestamp is
 *                signed material, so the 5-minute tolerance binds.
 * Yalidine     security_token in the body. Binds to nothing, so it is a
 *                hint to re-fetch — and a timestamp check would be theatre.
 * ```
 */
final class CourierWebhookTest extends TestCase
{
    /** The base64 key behind the whsec_ label, as Svix issues it. */
    private const ZR_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const PARCEL = '7c3af8c0-a8f8-4bd4-bcff-005a948f2698';
    private const TRACKING = 'PD123456789';

    private function zrExpress(string $secret = self::ZR_SECRET): ZRExpressProvider
    {
        $logger = new Logger('test', Logger::ERROR);
        $credentials = new ZRExpressCredentials('tenant-uuid', 'api-key', $secret);

        return new ZRExpressProvider(
            new ZRExpressClient(
                new RecordedHttpClient([]),
                $credentials,
                ZRExpressSettings::fromArray([]),
                $logger,
                0
            ),
            new InMemoryDestinationDirectory([]),
            $logger,
            null,
            $credentials
        );
    }

    private function yalidine(string $secret = 'yal-webhook-secret'): YalidineProvider
    {
        $logger = new Logger('test', Logger::ERROR);
        $credentials = new YalidineCredentials('api-id', 'api-token', $secret);
        $settings = YalidineSettings::fromArray([]);

        return new YalidineProvider(
            new YalidineClient(new RecordedHttpClient([]), $credentials, $settings, $logger),
            new InMemoryDestinationDirectory([]),
            $settings,
            $logger,
            null,
            $credentials
        );
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function svix(array $body, ?int $timestamp = null, string $secret = self::ZR_SECRET): array
    {
        $raw = (string) json_encode($body);
        $id = 'msg_2b3c4d5e6f7g8h9i';
        $timestamp ??= time();
        $key = (string) base64_decode(substr($secret, strlen('whsec_')), true);
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$raw}", $key, true));

        return [$raw, [
            'svix-id' => $id,
            'svix-timestamp' => (string) $timestamp,
            'svix-signature' => 'v1,' . $signature,
        ]];
    }

    /** @return array<string, mixed> */
    private static function zrEvent(string $type = 'parcel.state.updated'): array
    {
        return [
            'eventType' => $type,
            'occurredAt' => '2026-08-15T10:30:00.000Z',
            'data' => [
                'id' => self::PARCEL,
                'trackingNumber' => self::TRACKING,
                'externalId' => '42',
                'state' => ['id' => 'state-uuid', 'name' => 'out_for_delivery'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function yalidineEvent(string $token = 'yal-webhook-secret'): array
    {
        return [
            'event' => 'status_updated',
            'tracking_number' => 'yal-BB-123456',
            'order_id' => '42',
            'status' => 'Livré',
            'updated_at' => '2026-08-15 10:30:00',
            'amount_collected' => '4500.00',
            'signature_url' => 'https://yalidine.app/signatures/abc?token=secret',
            'security_token' => $token,
        ];
    }

    // ------------------------------------------------------------ ZR Express

    public function testAValidlySignedSvixEventIsAccepted(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent());

        $result = $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw);

        self::assertSame('msg_2b3c4d5e6f7g8h9i', $result->eventId);
        self::assertSame(self::PARCEL, $result->providerShipmentId);
        self::assertSame(self::TRACKING, $result->trackingNumber);
        self::assertSame('parcel.state.updated', $result->eventType);
        self::assertTrue($result->identifiesAParcel());
    }

    /** Key rotation: several signatures arrive, and any one matching is a pass. */
    public function testOneMatchingSignatureAmongSeveralIsAccepted(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent());
        $headers['svix-signature'] = 'v1,bm90LXRoZS1yaWdodC1vbmU= ' . $headers['svix-signature'];

        self::assertSame(
            self::PARCEL,
            $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw)->providerShipmentId
        );
    }

    /** A version this adapter does not know is skipped, not refused. */
    public function testAnUnknownSignatureVersionAlongsideAValidOneIsIgnored(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent());
        $headers['svix-signature'] = 'v9,c29tZXRoaW5nLWVsc2U= ' . $headers['svix-signature'];

        self::assertSame(
            self::PARCEL,
            $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw)->providerShipmentId
        );
    }

    public function testATamperedBodyIsRejected(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent());
        $tampered = str_replace('out_for_delivery', 'delivered', $raw);
        self::assertNotSame($raw, $tampered, 'the fixture must actually change');

        $this->expectException(ApiException::class);

        $this->zrExpress()->handleWebhook(json_decode($tampered, true), $headers, $tampered);
    }

    /**
     * The signed material includes the id, so swapping it invalidates the
     * signature — which is what stops one event being replayed under a fresh
     * id to defeat the claim.
     */
    public function testChangingTheEventIdInvalidatesTheSignature(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent());
        $headers['svix-id'] = 'msg_something_else';

        $this->expectException(ApiException::class);

        $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw);
    }

    public function testASignatureFromAnotherSecretIsRejected(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent(), null, 'whsec_b3RoZXItc2VjcmV0LWtleS12YWx1ZQ==');

        $this->expectException(ApiException::class);

        $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw);
    }

    /** The replay case §60 requires: genuine, correctly signed, sent again later. */
    public function testAStaleSvixEventIsRejectedThoughItsSignatureIsValid(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent(), time() - 600);

        $this->expectException(ApiException::class);

        $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw);
    }

    /** Clock skew is not one-sided. */
    public function testAnEventFromASlightlyFastClockIsAccepted(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent(), time() + 60);

        self::assertSame(
            self::PARCEL,
            $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw)->providerShipmentId
        );
    }

    public function testAnUnconfiguredSecretVerifiesNothing(): void
    {
        [$raw, $headers] = self::svix(self::zrEvent());

        $this->expectException(ApiException::class);

        $this->zrExpress('')->handleWebhook(json_decode($raw, true), $headers, $raw);
    }

    public function testMissingSvixHeadersAreRejected(): void
    {
        [$raw] = self::svix(self::zrEvent());

        $this->expectException(ApiException::class);

        $this->zrExpress()->handleWebhook(json_decode($raw, true), [], $raw);
    }

    // -------------------------------------------------------------- Yalidine

    public function testAMatchingSecurityTokenIsAccepted(): void
    {
        $body = self::yalidineEvent();
        $raw = (string) json_encode($body);

        $result = $this->yalidine()->handleWebhook($body, [], $raw);

        self::assertSame(hash('sha256', $raw), $result->eventId, 'no event id is sent, so one is derived');
        self::assertSame('yal-BB-123456', $result->trackingNumber);
        // Yalidine identifies a parcel by its tracking number and nothing else.
        self::assertSame('yal-BB-123456', $result->providerShipmentId);
        self::assertSame('status_updated', $result->eventType);
    }

    public function testAWrongSecurityTokenIsRejected(): void
    {
        $body = self::yalidineEvent('not-the-secret');

        $this->expectException(ApiException::class);

        $this->yalidine()->handleWebhook($body, [], (string) json_encode($body));
    }

    public function testAMissingSecurityTokenIsRejected(): void
    {
        $body = self::yalidineEvent();
        unset($body['security_token']);

        $this->expectException(ApiException::class);

        $this->yalidine()->handleWebhook($body, [], (string) json_encode($body));
    }

    public function testAnUnconfiguredYalidineSecretVerifiesNothing(): void
    {
        $body = self::yalidineEvent();

        $this->expectException(ApiException::class);

        $this->yalidine('')->handleWebhook($body, [], (string) json_encode($body));
    }

    /**
     * Nothing from the body travels onward.
     *
     * A body secret proves only that the sender once saw the token, so every
     * field beside it is unauthenticated — including `signature_url`, a link to
     * the customer's handwritten signature, which `Logger::SENSITIVE_EXACT` also
     * keeps out of the log.
     */
    public function testNothingFromTheYalidinePayloadIsCarriedOnward(): void
    {
        $body = self::yalidineEvent();

        $result = $this->yalidine()->handleWebhook($body, [], (string) json_encode($body));

        self::assertSame([], $result->metadata);
    }

    /**
     * The same event twice produces the same id, so the claim can refuse the
     * second — and two genuinely different events do not collide.
     */
    public function testTheDerivedEventIdIsStableAndDistinct(): void
    {
        $first = self::yalidineEvent();
        $second = self::yalidineEvent();
        $second['updated_at'] = '2026-08-15 11:00:00';

        $provider = $this->yalidine();
        $a = $provider->handleWebhook($first, [], (string) json_encode($first));
        $b = $provider->handleWebhook($first, [], (string) json_encode($first));
        $c = $provider->handleWebhook($second, [], (string) json_encode($second));

        self::assertSame($a->eventId, $b->eventId);
        self::assertNotSame($a->eventId, $c->eventId);
    }

    // ------------------------------------------------------------- both, and
    // ------------------------------------------------------------- in-house

    /**
     * Every rejection is the same answer with the same message.
     *
     * A verifier that distinguishes "bad timestamp" from "bad signature" is an
     * oracle for building a valid one, so the two couriers must not differ from
     * each other either.
     */
    public function testEveryRejectionLooksIdentical(): void
    {
        $seen = [];

        [$raw, $headers] = self::svix(self::zrEvent(), time() - 3600);

        try {
            $this->zrExpress()->handleWebhook(json_decode($raw, true), $headers, $raw);
        } catch (ApiException $e) {
            $seen['zr-stale'] = [$e->errorCode(), $e->statusCode(), $e->getMessage()];
        }

        [$raw2, $headers2] = self::svix(self::zrEvent());
        $tampered = str_replace('out_for_delivery', 'delivered', $raw2);

        try {
            $this->zrExpress()->handleWebhook(json_decode($tampered, true), $headers2, $tampered);
        } catch (ApiException $e) {
            $seen['zr-forged'] = [$e->errorCode(), $e->statusCode(), $e->getMessage()];
        }

        $body = self::yalidineEvent('wrong');

        try {
            $this->yalidine()->handleWebhook($body, [], (string) json_encode($body));
        } catch (ApiException $e) {
            $seen['yal-forged'] = [$e->errorCode(), $e->statusCode(), $e->getMessage()];
        }

        self::assertCount(3, $seen);
        self::assertSame($seen['zr-stale'], $seen['zr-forged']);
        self::assertSame($seen['zr-stale'], $seen['yal-forged']);
        self::assertSame('webhook_unverified', $seen['zr-stale'][0]);
        self::assertSame(401, $seen['zr-stale'][1]);
    }

    /** Nobody sends webhooks about a van we own. */
    public function testInHouseDeliveryRefusesWebhooksOutright(): void
    {
        try {
            (new ManualProvider())->handleWebhook([], [], '{}');
            self::fail('in-house delivery should refuse a webhook');
        } catch (ApiException $exception) {
            self::assertSame('webhook_unsupported', $exception->errorCode());
            self::assertSame(400, $exception->statusCode());
        }
    }
}
