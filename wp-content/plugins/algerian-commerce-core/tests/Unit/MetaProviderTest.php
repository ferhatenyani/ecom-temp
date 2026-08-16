<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpTransportException;
use AlgerianCommerce\Integrations\Meta\MetaClient;
use AlgerianCommerce\Integrations\Meta\MetaCredentials;
use AlgerianCommerce\Integrations\Meta\MetaProvider;
use AlgerianCommerce\Integrations\Meta\MetaSettings;
use AlgerianCommerce\Marketing\MarketingEvent;
use AlgerianCommerce\Marketing\MarketingResult;
use AlgerianCommerce\Marketing\UserData;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Conversions API adapter — roadmap §62b.
 *
 * Written from Meta's documentation read 2026-08-16 and **never run against a
 * live dataset**, because that needs an ad account this project does not have.
 * That is the §56 situation, and it is exactly why the transport is injected:
 * what an adapter sends is half of what it can get wrong, and a recorded
 * response is the only evidence available before the first real call.
 *
 * `grep -rn ASSUMPTION integrations/Meta` lists what is still unproven.
 */
final class MetaProviderTest extends TestCase
{
    private const PIXEL = '123456789012345';

    private function provider(RecordedHttpClient $http, array $settings = []): MetaProvider
    {
        $credentials = new MetaCredentials(self::PIXEL, 'EAA-test-token');
        $resolved = MetaSettings::fromArray($settings);

        return new MetaProvider(
            new MetaClient($http, $credentials, $resolved, new Logger('test')),
            $credentials,
            $resolved
        );
    }

    private function purchase(): MarketingEvent
    {
        return new MarketingEvent(
            MarketingEvent::PURCHASE,
            'abc123',
            1755300000,
            UserData::fromCustomer(
                ['email' => 'john_smith@gmail.com', 'phone' => '0551020304'],
                ['client_ip_address' => '41.100.1.2']
            ),
            [
                'value' => '7500.00',
                'currency' => 'dzd',
                'order_id' => '42',
                'content_type' => 'product',
                'content_ids' => [11, 12],
                'contents' => [['id' => 11, 'quantity' => 2, 'item_price' => '2500']],
                'num_items' => 3,
            ],
            'https://boutique.dz/merci',
            MarketingEvent::SOURCE_WEBSITE,
            42
        );
    }

    // ------------------------------------------------------------ the request --

    public function testItPostsToTheVersionedPixelEndpoint(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $this->provider($http)->send($this->purchase());

        self::assertCount(1, $http->requests);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame(
            'https://graph.facebook.com/v26.0/123456789012345/events',
            $http->requests[0]['url']
        );
    }

    /**
     * A URL is written to access logs, proxy logs and error reports; a body is
     * not. Meta accepts the token either way, so this is free.
     */
    public function testTheAccessTokenTravelsInTheBodyNotTheUrl(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $this->provider($http)->send($this->purchase());

        self::assertStringNotContainsString('EAA-test-token', $http->requests[0]['url']);
        self::assertStringNotContainsString('access_token', $http->requests[0]['url']);

        $body = json_decode((string) $http->requests[0]['body'], true);
        self::assertSame('EAA-test-token', $body['access_token']);
    }

    public function testTheEventCarriesWhatMetaRequires(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $this->provider($http)->send($this->purchase());

        $event = json_decode((string) $http->requests[0]['body'], true)['data'][0];

        self::assertSame('Purchase', $event['event_name']);
        self::assertSame(1755300000, $event['event_time']);
        self::assertSame('abc123', $event['event_id']);
        self::assertSame('website', $event['action_source']);
        self::assertSame('https://boutique.dz/merci', $event['event_source_url']);
    }

    /** Meta validates it as a URL, so an empty string is a rejected event. */
    public function testAnEmptySourceUrlIsOmittedRatherThanSentEmpty(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $event = new MarketingEvent('Purchase', 'x', 1, UserData::empty(), [], '');
        $this->provider($http)->send($event);

        $sent = json_decode((string) $http->requests[0]['body'], true)['data'][0];

        self::assertArrayNotHasKey('event_source_url', $sent);
    }

    public function testUserDataIsHashedAndContextIsNot(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $this->provider($http)->send($this->purchase());

        $body = (string) $http->requests[0]['body'];
        $userData = json_decode($body, true)['data'][0]['user_data'];

        self::assertSame(hash('sha256', 'john_smith@gmail.com'), $userData['em']);
        self::assertSame(hash('sha256', '213551020304'), $userData['ph']);
        self::assertSame('41.100.1.2', $userData['client_ip_address']);
        // The whole point, asserted on the bytes that leave the process.
        self::assertStringNotContainsString('john_smith@gmail.com', $body);
        self::assertStringNotContainsString('0551020304', $body);
    }

    /**
     * WooCommerce hands totals about as strings, and a quoted number is one of
     * the things Meta's validator is fussy about.
     */
    public function testCustomDataIsCoercedToTheDocumentedTypes(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $this->provider($http)->send($this->purchase());

        $custom = json_decode((string) $http->requests[0]['body'], true)['data'][0]['custom_data'];

        /*
         * The requirement is "a JSON number", not "a PHP float": JSON has one
         * numeric type, so 7500.0 is serialised as 7500 and decodes as an int.
         * What must never happen is `"7500.00"` — WooCommerce hands totals
         * about as strings, and a quoted number is one of the things Meta's
         * validator refuses.
         */
        self::assertIsNotString($custom['value']);
        self::assertEqualsWithDelta(7500.0, $custom['value'], 0.001);
        // ISO 4217 is uppercase.
        self::assertSame('DZD', $custom['currency']);
        self::assertSame('42', $custom['order_id']);
        self::assertSame(['11', '12'], $custom['content_ids']);
        self::assertSame(3, $custom['num_items']);
        self::assertSame([['id' => '11', 'quantity' => 2, 'item_price' => 2500]], $custom['contents']);
    }

    /** A fractional total must survive the encode as a fraction. */
    public function testAFractionalValueKeepsItsDecimals(): void
    {
        $http = new RecordedHttpClient([RecordedHttpClient::json(['events_received' => 1])]);

        $event = new MarketingEvent(
            'Purchase',
            'x',
            1,
            UserData::empty(),
            ['value' => '7500.50', 'currency' => 'DZD']
        );

        $this->provider($http)->send($event);

        $custom = json_decode((string) $http->requests[0]['body'], true)['data'][0]['custom_data'];

        self::assertSame(7500.5, $custom['value']);
    }

    public function testTheTestEventCodeIsSentOnlyWhenConfigured(): void
    {
        $http = new RecordedHttpClient([
            RecordedHttpClient::json(['events_received' => 1]),
            RecordedHttpClient::json(['events_received' => 1]),
        ]);

        $this->provider($http)->send($this->purchase());
        self::assertArrayNotHasKey('test_event_code', json_decode((string) $http->requests[0]['body'], true));

        $this->provider($http, ['test_event_code' => 'TEST12345'])->send($this->purchase());
        self::assertSame('TEST12345', json_decode((string) $http->requests[1]['body'], true)['test_event_code']);
    }

    // ----------------------------------------------------------- the responses --

    public function testASuccessIsSent(): void
    {
        $http = new RecordedHttpClient([
            RecordedHttpClient::json(['events_received' => 1, 'fbtrace_id' => 'Axyz']),
        ]);

        $result = $this->provider($http)->send($this->purchase());

        self::assertTrue($result->isSent());
        self::assertSame('Axyz', $result->reference);
    }

    /**
     * No answer at all. Always retryable: the event is still queued and Meta
     * accepts a Purchase reported well after the fact, so a blip costs latency
     * rather than a conversion.
     */
    public function testATransportFailureIsRetryable(): void
    {
        $http = new RecordedHttpClient([new HttpTransportException('cURL error 28: timed out')]);

        $result = $this->provider($http)->send($this->purchase());

        self::assertTrue($result->isRetryable());
        self::assertStringContainsString('timed out', $result->message);
    }

    /** @return array<string, array{0: int, 1: int, 2: string}> */
    public static function failureProvider(): array
    {
        return [
            'rate limited' => [400, 4, MarketingResult::RETRYABLE],
            'user rate limit' => [400, 17, MarketingResult::RETRYABLE],
            'transient graph error' => [400, 2, MarketingResult::RETRYABLE],
            'http 429' => [429, 0, MarketingResult::RETRYABLE],
            'server error' => [500, 0, MarketingResult::RETRYABLE],
            'bad token' => [400, 190, MarketingResult::REJECTED],
            'malformed parameter' => [400, 100, MarketingResult::REJECTED],
        ];
    }

    /**
     * The retry line is the whole reason `MarketingResult` has three states: a
     * malformed event retried hourly for a week burns the account's rate limit
     * and buries the events that would have worked.
     */
    #[DataProvider('failureProvider')]
    public function testFailuresAreSortedByWhetherRetryingCouldHelp(int $status, int $code, string $expected): void
    {
        $http = new RecordedHttpClient([
            RecordedHttpClient::json(
                ['error' => ['message' => 'nope', 'code' => $code, 'fbtrace_id' => 'A1']],
                $status
            ),
        ]);

        self::assertSame($expected, $this->provider($http)->send($this->purchase())->status);
    }

    public function testABadTokenSaysSoRatherThanBeingRetriedFiveTimes(): void
    {
        $http = new RecordedHttpClient([
            RecordedHttpClient::json(['error' => ['message' => 'Invalid OAuth token', 'code' => 190]], 400),
        ]);

        $result = $this->provider($http)->send($this->purchase());

        self::assertFalse($result->isRetryable());
        self::assertStringContainsString('access token', $result->message);
    }

    // -------------------------------------------------------------- the config --

    /**
     * A pixel id ships in browser JavaScript, so serving it is not a leak — but
     * the token authorises writing conversions into an ad account, and nothing
     * may put it here.
     */
    public function testPublicConfigCarriesThePixelIdAndNeverTheToken(): void
    {
        $config = $this->provider(new RecordedHttpClient())->publicConfig();

        self::assertSame(self::PIXEL, $config['pixel_id']);
        self::assertSame('v26.0', $config['api_version']);
        self::assertFalse($config['test_mode']);
        self::assertStringNotContainsString('EAA-test-token', (string) json_encode($config));
    }

    public function testTestModeIsReportedToTheStorefront(): void
    {
        $config = $this->provider(new RecordedHttpClient(), ['test_event_code' => 'TEST12345'])->publicConfig();

        self::assertTrue($config['test_mode']);
    }
}
