<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Http\HttpTransportException;
use AlgerianCommerce\Integrations\Chargily\ChargilyClient;
use AlgerianCommerce\Integrations\Chargily\ChargilyCredentials;
use AlgerianCommerce\Integrations\Chargily\ChargilyProvider;
use AlgerianCommerce\Integrations\Chargily\ChargilySettings;
use AlgerianCommerce\Payments\PaymentRequest;
use AlgerianCommerce\Payments\PaymentStatus;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The Chargily adapter against recorded responses — roadmap §59.
 *
 * The required cases §56 set for an integration: a successful payment, a
 * refused request, an unreachable gateway, an authentication failure, and — the
 * ones this section adds — a forged webhook, a replayed one and a stale one.
 *
 * Bodies below are the documented shapes from
 * `dev.chargily.com/pay-v2/api-reference/checkouts/*` and the webhooks page.
 */
final class ChargilyProviderTest extends TestCase
{
    // Obviously not a key. A fixture that is a real one's prefix is a real
    // one's prefix, and it would be in Git forever.
    private const SECRET = 'test_sk_0000000000000000000000000000000000000000';
    private const CHECKOUT = '01hj5n7cqpaf0mt2d0xx85tgz8';
    private const WEBHOOK_URL = 'https://shop.example/wp-json/algerian-commerce/v1/webhooks/chargily';

    /** @return array<string, mixed> */
    private static function checkout(string $status = 'pending', int $amount = 4500): array
    {
        return [
            'id' => self::CHECKOUT,
            'entity' => 'checkout',
            'livemode' => false,
            'amount' => $amount,
            'currency' => 'dzd',
            'fees' => 0,
            'status' => $status,
            'locale' => 'fr',
            'success_url' => 'https://shop.example/payments/success',
            'payment_method' => null,
            'customer_id' => null,
            'created_at' => 1703144567,
            'updated_at' => 1703144567,
            'checkout_url' => 'https://pay.chargily.dz/test/checkouts/' . self::CHECKOUT . '/pay',
        ];
    }

    /**
     * @param list<HttpResponse|HttpTransportException> $script
     * @param array<string, mixed>                      $settings
     * @return array{0: ChargilyProvider, 1: RecordedHttpClient}
     */
    private function provider(array $script, array $settings = [], string $secret = self::SECRET): array
    {
        $http = new RecordedHttpClient($script);
        $credentials = new ChargilyCredentials($secret);
        $config = ChargilySettings::fromArray($settings + [
            'success_url' => 'https://shop.example/payments/success',
            'failure_url' => 'https://shop.example/payments/failure',
        ]);

        $logger = new Logger('test', Logger::ERROR);

        return [
            new ChargilyProvider(
                new ChargilyClient($http, $credentials, $config, $logger),
                $config,
                $credentials,
                $logger,
                static fn (): string => self::WEBHOOK_URL
            ),
            $http,
        ];
    }

    private function request(string $amount = '4500.00'): PaymentRequest
    {
        return new PaymentRequest(42, $amount, 'DZD', '42-1', 'Order #42', 'Amina B', '', '0550000000');
    }

    // ---------------------------------------------------------------- create

    public function testItCreatesACheckoutAndReturnsTheRedirect(): void
    {
        [$provider, $http] = $this->provider([RecordedHttpClient::json(self::checkout())]);

        $result = $provider->createPayment($this->request());

        self::assertSame(self::CHECKOUT, $result->providerPaymentId);
        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertTrue($result->needsRedirect());
        self::assertStringStartsWith('https://pay.chargily.dz/', $result->checkoutUrl);

        $sent = $http->lastRequest();
        self::assertSame('POST', $sent['method']);
        // The test key selects the test environment by itself.
        self::assertSame('https://pay.chargily.net/test/api/v2/checkouts', $sent['url']);
        self::assertSame('Bearer ' . self::SECRET, $sent['headers']['Authorization']);
    }

    /**
     * Dinars, not centimes — the fact §58 guessed wrong about before anyone had
     * read the documentation.
     */
    public function testTheAmountIsSentInDinars(): void
    {
        [$provider, $http] = $this->provider([RecordedHttpClient::json(self::checkout())]);

        $provider->createPayment($this->request('4500.00'));

        $body = $http->sentBody();
        self::assertSame(4500, $body['amount']);
        self::assertSame('dzd', $body['currency']);
    }

    /** Nothing is rounded: a shop that quietly loses centimes is wrong about money. */
    public function testAFractionalAmountIsSentAsItStands(): void
    {
        [$provider, $http] = $this->provider([RecordedHttpClient::json(self::checkout(amount: 1500))]);

        $provider->createPayment($this->request('1500.50'));

        self::assertSame(1500.5, $http->sentBody()['amount']);
    }

    public function testItSendsTheWebhookEndpointAndTheOrderReference(): void
    {
        [$provider, $http] = $this->provider([RecordedHttpClient::json(self::checkout())]);

        $provider->createPayment($this->request());

        $body = $http->sentBody();
        self::assertSame(self::WEBHOOK_URL, $body['webhook_endpoint']);
        self::assertSame('42', $body['metadata']['order_id']);
        self::assertSame('42-1', $body['metadata']['reference']);
        self::assertSame('https://shop.example/payments/failure', $body['failure_url']);
    }

    /** A caller's return URL wins over the client's configured one. */
    public function testTheRequestReturnUrlOverridesTheSetting(): void
    {
        [$provider, $http] = $this->provider([RecordedHttpClient::json(self::checkout())]);

        $provider->createPayment(new PaymentRequest(
            42,
            '4500.00',
            'DZD',
            '42-1',
            '',
            '',
            '',
            '',
            'https://storefront.example/thanks'
        ));

        self::assertSame('https://storefront.example/thanks', $http->sentBody()['success_url']);
    }

    /**
     * Chargily takes dinars and nothing else, and a WooCommerce install ships
     * set to USD — so this is refused before the customer can pay a "dzd"
     * amount that is not the order's total.
     */
    public function testItRefusesAnOrderThatIsNotInDinars(): void
    {
        [$provider, $http] = $this->provider([]);

        try {
            $provider->createPayment(new PaymentRequest(42, '4500.00', 'USD'));
            self::fail('a non-DZD order should be refused');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->statusCode());
        }

        self::assertSame([], $http->requests, 'nothing should reach the gateway');
    }

    public function testItRefusesToCreateAPaymentWithNowhereToSendTheShopper(): void
    {
        [$provider] = $this->provider([], ['success_url' => '']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/success URL/');

        $provider->createPayment($this->request());
    }

    /**
     * Chargily really does return `http://` here — seen live on 2026-08-15 —
     * and this is where a shopper is sent to type card details.
     */
    public function testAPlaintextCheckoutUrlIsUpgradedToHttps(): void
    {
        $checkout = self::checkout();
        $checkout['checkout_url'] = 'http://pay.chargily.dz/test/checkouts/' . self::CHECKOUT . '/pay';

        [$provider] = $this->provider([RecordedHttpClient::json($checkout)]);

        self::assertSame(
            'https://pay.chargily.dz/test/checkouts/' . self::CHECKOUT . '/pay',
            $provider->createPayment($this->request())->checkoutUrl
        );
    }

    /** The checkout page URL is never stored — it is a link to one customer's payment. */
    public function testTheCheckoutUrlIsNotKeptInMetadata(): void
    {
        [$provider] = $this->provider([RecordedHttpClient::json(self::checkout())]);

        $result = $provider->createPayment($this->request());

        self::assertArrayNotHasKey('checkout_url', $result->metadata);
        self::assertArrayNotHasKey('url', $result->metadata);
    }

    public function testAGatewayThatAnswersWithoutAUrlIsAFailure(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(['id' => self::CHECKOUT, 'status' => 'pending']),
        ]);

        $this->expectException(ApiException::class);

        $provider->createPayment($this->request());
    }

    // ---------------------------------------------------------------- errors

    public function testARefusedRequestCarriesTheGatewaysSentence(): void
    {
        [$provider] = $this->provider([
            RecordedHttpClient::json(['message' => 'The success url field is required.'], 422),
        ]);

        try {
            $provider->createPayment($this->request());
            self::fail('a refused request should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_rejected', $exception->errorCode());
            self::assertSame(400, $exception->statusCode());
        }
    }

    public function testABadKeyIsAnAuthenticationFailure(): void
    {
        [$provider] = $this->provider([RecordedHttpClient::json(['message' => 'Unauthenticated.'], 401)]);

        try {
            $provider->verifyPayment(self::CHECKOUT);
            self::fail('a refused key should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_auth_failed', $exception->errorCode());
        }
    }

    public function testAnUnreachableGatewayIsA502(): void
    {
        [$provider] = $this->provider([new HttpTransportException('cURL error 28: timed out')]);

        try {
            $provider->verifyPayment(self::CHECKOUT);
            self::fail('an unreachable gateway should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_unavailable', $exception->errorCode());
            self::assertSame(502, $exception->statusCode());
        }
    }

    public function testAMissingSecretKeyIsRefusedBeforeAnyCallIsMade(): void
    {
        [$provider, $http] = $this->provider([], [], '');

        try {
            $provider->verifyPayment(self::CHECKOUT);
            self::fail('an unconfigured gateway should throw');
        } catch (ApiException $exception) {
            self::assertSame('conflict', $exception->errorCode());
        }

        self::assertSame([], $http->requests);
    }

    // ---------------------------------------------------------------- verify

    public function testItVerifiesAPaidCheckoutAgainstTheGateway(): void
    {
        [$provider, $http] = $this->provider([RecordedHttpClient::json(self::checkout('paid'))]);

        $report = $provider->verifyPayment(self::CHECKOUT);

        self::assertSame(PaymentStatus::PAID, $report->status);
        self::assertSame('paid', $report->providerStatus);
        self::assertSame('4500.00', $report->amount);
        self::assertSame('DZD', $report->currency);
        self::assertTrue($report->matches('4500.00', 'DZD'));

        self::assertSame('GET', $http->lastRequest()['method']);
        self::assertStringEndsWith('/checkouts/' . self::CHECKOUT, $http->lastRequest()['url']);
    }

    /** The amount is what makes the re-check possible; a mismatch must not pass. */
    public function testAShortPaymentDoesNotMatchTheOrder(): void
    {
        [$provider] = $this->provider([RecordedHttpClient::json(self::checkout('paid', 45))]);

        self::assertFalse($provider->verifyPayment(self::CHECKOUT)->matches('4500.00', 'DZD'));
    }

    public function testProcessingIsStillPendingBecauseTheMoneyHasNotArrived(): void
    {
        [$provider] = $this->provider([RecordedHttpClient::json(self::checkout('processing'))]);

        self::assertSame(PaymentStatus::PENDING, $provider->verifyPayment(self::CHECKOUT)->status);
    }

    public function testAnUnmappedGatewayStateRaisesRatherThanGuessing(): void
    {
        [$provider] = $this->provider([RecordedHttpClient::json(self::checkout('half_paid'))]);

        try {
            $provider->verifyPayment(self::CHECKOUT);
            self::fail('an unknown state should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_status_unknown', $exception->errorCode());
        }
    }

    // --------------------------------------------------------------- webhook

    /** @param array<string, mixed> $event */
    private static function sign(array $event, string $secret = self::SECRET): array
    {
        $raw = (string) json_encode($event);

        return [$raw, ['signature' => hash_hmac('sha256', $raw, $secret)]];
    }

    /** @return array<string, mixed> */
    private static function event(string $type = 'checkout.paid', ?int $createdAt = null): array
    {
        return [
            'id' => '01hjjjzf7wbc454te45mwx35fe',
            'entity' => 'event',
            'livemode' => false,
            'type' => $type,
            'data' => self::checkout($type === 'checkout.paid' ? 'paid' : 'failed'),
            'created_at' => $createdAt ?? time(),
            'updated_at' => $createdAt ?? time(),
        ];
    }

    public function testAValidlySignedEventIsAccepted(): void
    {
        [$provider] = $this->provider([]);
        [$raw, $headers] = self::sign(self::event());

        $result = $provider->handleWebhook(json_decode($raw, true), $headers, $raw);

        self::assertSame('01hjjjzf7wbc454te45mwx35fe', $result->eventId);
        self::assertSame(self::CHECKOUT, $result->providerPaymentId);
        self::assertSame(PaymentStatus::PAID, $result->status);
        self::assertSame('checkout.paid', $result->metadata['event_type']);
    }

    /**
     * The forgery case §60 requires. One byte changed in the body and the
     * signature no longer holds.
     */
    public function testATamperedBodyIsRejected(): void
    {
        [$provider] = $this->provider([]);
        [$raw, $headers] = self::sign(self::event());

        $tampered = str_replace('"amount":4500', '"amount":1', $raw);
        self::assertNotSame($raw, $tampered, 'the fixture must actually change');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This request could not be verified.');

        $provider->handleWebhook(json_decode($tampered, true), $headers, $tampered);
    }

    public function testASignatureFromTheWrongKeyIsRejected(): void
    {
        [$provider] = $this->provider([]);
        [$raw, $headers] = self::sign(self::event(), 'test_sk_someone_elses_key');

        $this->expectException(ApiException::class);

        $provider->handleWebhook(json_decode($raw, true), $headers, $raw);
    }

    public function testAnUnsignedRequestIsRejected(): void
    {
        [$provider] = $this->provider([]);
        [$raw] = self::sign(self::event());

        $this->expectException(ApiException::class);

        $provider->handleWebhook(json_decode($raw, true), [], $raw);
    }

    /**
     * The replay case §60 requires: a genuine, correctly signed event captured
     * off the wire and sent again later. The timestamp is inside the signed
     * body, so it cannot be refreshed without breaking the signature.
     */
    public function testAStaleEventIsRejectedEvenThoughItsSignatureIsValid(): void
    {
        [$provider] = $this->provider([]);
        [$raw, $headers] = self::sign(self::event(createdAt: time() - 600));

        $this->expectException(ApiException::class);

        $provider->handleWebhook(json_decode($raw, true), $headers, $raw);
    }

    /** Clock skew is not one-sided — five minutes in either direction. */
    public function testAnEventFromASlightlyFastClockIsAccepted(): void
    {
        [$provider] = $this->provider([]);
        [$raw, $headers] = self::sign(self::event(createdAt: time() + 60));

        self::assertSame(
            PaymentStatus::PAID,
            $provider->handleWebhook(json_decode($raw, true), $headers, $raw)->status
        );
    }

    public function testEveryRejectionGivesTheSameAnswerAndSaysNothing(): void
    {
        [$provider] = $this->provider([]);

        $attempts = [];

        foreach ([
            'tampered' => static fn (string $raw): string => str_replace('4500', '1', $raw),
            'stale' => static fn (string $raw): string => $raw,
        ] as $name => $mutate) {
            [$raw, $headers] = $name === 'stale'
                ? self::sign(self::event(createdAt: time() - 3600))
                : self::sign(self::event());

            $body = $mutate($raw);

            try {
                $provider->handleWebhook(json_decode($body, true) ?? [], $headers, $body);
                self::fail("{$name} should not verify");
            } catch (ApiException $exception) {
                $attempts[$name] = [
                    $exception->errorCode(),
                    $exception->statusCode(),
                    $exception->getMessage(),
                ];
            }
        }

        // A verifier that distinguishes "bad timestamp" from "bad signature" is
        // an oracle for building a valid one.
        self::assertSame($attempts['tampered'], $attempts['stale']);
        self::assertSame('webhook_unverified', $attempts['stale'][0]);
        self::assertSame(401, $attempts['stale'][1]);
    }

    /** A verified event we do not handle is acknowledged and dropped, not an error. */
    public function testAVerifiedEventOfAnUnknownTypeIsNotActionable(): void
    {
        [$provider] = $this->provider([]);
        [$raw, $headers] = self::sign(self::event('customer.created'));

        $result = $provider->handleWebhook(json_decode($raw, true), $headers, $raw);

        self::assertFalse($result->isActionable());
        self::assertNotSame('', $result->eventId);
    }

    /**
     * The reason the webhook payload is never the source of truth: its checkout
     * object carries no `currency`, so the re-check docs/SECURITY.md requires
     * cannot be satisfied from it — see PaymentReportTest, where an unstated
     * currency is refused rather than skipped.
     */
    public function testTheWebhookPayloadCannotSatisfyTheAmountRecheckOnItsOwn(): void
    {
        [$provider] = $this->provider([]);

        $event = self::event();
        unset($event['data']['currency']);
        [$raw, $headers] = self::sign($event);

        $result = $provider->handleWebhook(json_decode($raw, true), $headers, $raw);

        self::assertSame('', $result->currency);
        self::assertFalse($result->toReport()?->matches('4500.00', 'DZD'));
    }
}
