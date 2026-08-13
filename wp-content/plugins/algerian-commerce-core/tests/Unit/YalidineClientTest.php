<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Http\HttpTransportException;
use AlgerianCommerce\Integrations\Yalidine\YalidineClient;
use AlgerianCommerce\Integrations\Yalidine\YalidineCredentials;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Authentication, the quota, and how Yalidine's failures become ours.
 *
 * Every case here is one of roadmap §56's required tests — authentication
 * failure, provider timeout, provider API failure — run against recorded
 * responses, since there is no account to run them against for real.
 */
final class YalidineClientTest extends TestCase
{
    /** @var list<int> seconds the client asked to wait */
    private array $slept = [];

    /** @param list<HttpResponse|HttpTransportException> $script */
    private function client(array $script, int $maxRetries = 1, int $maxRetryWait = 5): array
    {
        $http = new RecordedHttpClient($script);

        $client = new YalidineClient(
            $http,
            new YalidineCredentials('api-id', 'api-token'),
            YalidineSettings::fromArray([]),
            new Logger('test', Logger::ERROR),
            $maxRetries,
            $maxRetryWait,
            function (int $seconds): void {
                $this->slept[] = $seconds;
            }
        );

        return [$client, $http];
    }

    public function testBothCredentialHeadersAreSentOnEveryCall(): void
    {
        [$client, $http] = $this->client([RecordedHttpClient::json(['data' => []])]);

        $client->get('wilayas/', ['page_size' => 1]);

        $headers = $http->lastRequest()['headers'];

        // Yalidine authenticates with two headers and rejects a request missing
        // either — there is no bearer token here.
        self::assertSame('api-id', $headers['X-API-ID']);
        self::assertSame('api-token', $headers['X-API-TOKEN']);
        self::assertStringContainsString('wilayas/?page_size=1', $http->lastRequest()['url']);
    }

    public function testTheBaseUrlIsJoinedWithoutDoubledSlashes(): void
    {
        [$client, $http] = $this->client([RecordedHttpClient::json(['data' => []])]);

        $client->get('/parcels/YAL-1');

        self::assertSame('https://api.yalidine.app/v1/parcels/YAL-1', $http->lastRequest()['url']);
    }

    /**
     * A store with the flag on and no keys must not reach the network at all:
     * a blank credential is a request that fails slowly, at a rate limit, for
     * no reason.
     */
    public function testMissingCredentialsAreRefusedBeforeAnyCall(): void
    {
        $http = new RecordedHttpClient([]);

        $client = new YalidineClient(
            $http,
            new YalidineCredentials('', ''),
            YalidineSettings::fromArray([]),
            new Logger('test', Logger::ERROR)
        );

        try {
            $client->get('wilayas/');
            self::fail('a call without credentials should be refused');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->statusCode());
            self::assertSame([], $http->requests);
        }
    }

    /**
     * Roadmap §56: obey `Retry-After`, since the limit itself is not published.
     * One short wait, one retry, and the caller never sees the 429.
     */
    public function testAShortQuotaWaitIsSatOutAndRetried(): void
    {
        [$client] = $this->client([
            new HttpResponse(429, '{"message":"quota"}', ['retry-after' => '2']),
            RecordedHttpClient::json(['data' => [['id' => 16]]]),
        ]);

        $response = $client->get('wilayas/');

        self::assertSame([2], $this->slept);
        self::assertSame([['id' => 16]], $response['data']);
    }

    /**
     * A long wait is not sat out inside a request. Obeying the header means not
     * hammering the API, not blocking a shop assistant for a minute — so the
     * caller gets a 429 with the delay in it and can schedule around it.
     */
    public function testALongQuotaWaitComesBackAsA429WithItsDelay(): void
    {
        [$client] = $this->client([
            new HttpResponse(429, '', ['retry-after' => '600']),
        ]);

        try {
            $client->get('wilayas/');
            self::fail('a long Retry-After should not be waited out');
        } catch (ApiException $exception) {
            self::assertSame('provider_rate_limited', $exception->errorCode());
            self::assertSame(429, $exception->statusCode());
            self::assertSame(600, $exception->details()['retry_after']);
            self::assertSame([], $this->slept);
        }
    }

    /** Retrying a quota response immediately is refused again by definition. */
    public function testAQuotaResponseWithoutAHeaderStillWaits(): void
    {
        [$client] = $this->client([
            new HttpResponse(429, ''),
            RecordedHttpClient::json(['data' => []]),
        ]);

        $client->get('wilayas/');

        self::assertSame([1], $this->slept);
    }

    public function testARepeatedQuotaResponseGivesUpRatherThanLooping(): void
    {
        [$client, $http] = $this->client([
            new HttpResponse(429, '', ['retry-after' => '1']),
            new HttpResponse(429, '', ['retry-after' => '1']),
            new HttpResponse(429, '', ['retry-after' => '1']),
        ]);

        try {
            $client->get('wilayas/');
            self::fail('the client should stop retrying');
        } catch (ApiException $exception) {
            self::assertSame('provider_rate_limited', $exception->errorCode());
            // One retry, not an unbounded loop against an unpublished quota.
            self::assertCount(2, $http->requests);
        }
    }

    /**
     * Their 401 is not our 401. The operator's session is fine; telling them to
     * log in again sends them after the wrong problem entirely.
     */
    public function testRejectedCredentialsAreNotReportedAsTheCallersProblem(): void
    {
        [$client] = $this->client([new HttpResponse(401, '{"message":"bad token"}')]);

        try {
            $client->get('wilayas/');
            self::fail('a 401 from Yalidine should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_auth_failed', $exception->errorCode());
            self::assertSame(502, $exception->statusCode());
            // And nothing of the credential, or their wording, in the body.
            self::assertArrayNotHasKey('provider_message', $exception->details());
        }
    }

    public function testATimeoutIsAnOutageRatherThanARejection(): void
    {
        [$client] = $this->client([new HttpTransportException('cURL error 28: Operation timed out')]);

        try {
            $client->get('parcels/YAL-1');
            self::fail('a transport failure should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_unavailable', $exception->errorCode());
            self::assertSame(502, $exception->statusCode());
            // The transport's message can name a host or a proxy; it stays in
            // the log (docs/SECURITY.md).
            self::assertStringNotContainsString('cURL', $exception->getMessage());
        }
    }

    public function testAProviderOutageIsA502(): void
    {
        [$client] = $this->client([new HttpResponse(503, 'Service Unavailable')]);

        try {
            $client->get('wilayas/');
            self::fail('a 5xx should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_unavailable', $exception->errorCode());
            self::assertSame(502, $exception->statusCode());
        }
    }

    /**
     * There is no published catalogue of Yalidine's errors, so their sentence
     * is carried through — it is the only thing that names the field they
     * disliked.
     */
    public function testAProviderRejectionKeepsItsExplanation(): void
    {
        [$client] = $this->client([new HttpResponse(400, '{"message":"champ to_commune_name invalide"}')]);

        try {
            $client->post('parcels/', [[]]);
            self::fail('a 400 should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_rejected', $exception->errorCode());
            self::assertSame(400, $exception->statusCode());
            self::assertSame('champ to_commune_name invalide', $exception->details()['provider_message']);
        }
    }

    public function testAnUnreadableBodyIsNotTreatedAsAnAnswer(): void
    {
        [$client] = $this->client([new HttpResponse(200, '<html>maintenance</html>')]);

        try {
            $client->get('wilayas/');
            self::fail('a non-JSON 200 should throw');
        } catch (ApiException $exception) {
            self::assertSame('provider_response_invalid', $exception->errorCode());
        }
    }

    /** The credential check a setup screen makes — roadmap §56. */
    public function testVerifyCredentialsAnswersYesOrNoRatherThanThrowing(): void
    {
        [$good] = $this->client([RecordedHttpClient::json(['data' => []])]);
        self::assertTrue($good->verifyCredentials());

        [$bad] = $this->client([new HttpResponse(403, '')]);
        self::assertFalse($bad->verifyCredentials());
    }

    /** "Is the courier down" is not an answer about the keys. */
    public function testVerifyCredentialsStillThrowsWhenYalidineIsDown(): void
    {
        [$client] = $this->client([new HttpResponse(500, '')]);

        $this->expectException(ApiException::class);

        $client->verifyCredentials();
    }

    public function testAPostSendsJsonAndReadsItBack(): void
    {
        [$client, $http] = $this->client([RecordedHttpClient::json(['42-1' => ['success' => true]])]);

        $response = $client->post('parcels/', [['order_id' => '42-1']]);

        self::assertSame('POST', $http->lastRequest()['method']);
        self::assertSame('application/json', $http->lastRequest()['headers']['Content-Type']);
        self::assertSame([['order_id' => '42-1']], $http->sentBody());
        self::assertTrue($response['42-1']['success']);
    }
}
