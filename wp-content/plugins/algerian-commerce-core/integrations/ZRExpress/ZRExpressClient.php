<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpClientInterface;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Http\HttpTransportException;

/**
 * Everything ZR Express-specific about making an HTTP call — roadmap §57.
 *
 * Pure apart from the injected transport, so the whole of it is exercised
 * against recorded responses.
 *
 * ```
 * base    https://api.zrexpress.app/api/v1.0/   (a per-client setting)
 * auth    X-Tenant + X-Api-Key                  who, and the proof
 * errors  RFC 7807 problem+json                 {type,title,status,detail,traceId}
 * search  POST …/search  {keyword,filters,pageNumber,pageSize}
 *         → {items,pageNumber,pageSize,totalCount,totalPages,hasPrevious,hasNext}
 * ```
 *
 * **Their errors carry a usable sentence.** Unlike Yalidine's, a ZR Express
 * failure is a problem+json document with a `detail` — *"Delivery price not
 * found"*, *"Suppliers can only request rates for all wilayas or communes of
 * their origin wilaya"* — so `detail` is what reaches the operator, and
 * `traceId` goes to the log where support can be given it.
 *
 * Nothing here logs a credential: the headers are assembled at the moment of
 * the call and never put into a log context.
 */
final class ZRExpressClient
{
    /** @var callable(int): void */
    private $sleeper;

    /** @param callable(int): void|null $sleeper injected so the 429 path is testable */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ZRExpressCredentials $credentials,
        private readonly ZRExpressSettings $settings,
        private readonly Logger $logger,
        private readonly int $maxRetries = 1,
        private readonly int $maxRetryWait = 5,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /** @throws ApiException */
    public function get(string $path): mixed
    {
        return $this->send('GET', $path, null);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException
     */
    public function post(string $path, array $body): mixed
    {
        return $this->send('POST', $path, $body);
    }

    /** @throws ApiException */
    public function delete(string $path): mixed
    {
        return $this->send('DELETE', $path, null);
    }

    /**
     * A `…/search` endpoint, one page.
     *
     * @param array<string, mixed> $criteria
     * @return array{items: list<array<string, mixed>>, has_next: bool}
     *
     * @throws ApiException
     */
    public function search(string $path, array $criteria, int $page = 1, int $pageSize = 200): array
    {
        $response = $this->post($path, $criteria + ['pageNumber' => $page, 'pageSize' => $pageSize]);

        if (!is_array($response)) {
            throw new ApiException(
                'provider_response_invalid',
                'ZR Express returned a response this store could not read.',
                502,
                ['provider' => ZRExpressProvider::NAME]
            );
        }

        $items = $response['items'] ?? [];

        return [
            'items' => is_array($items) ? array_values(array_filter($items, 'is_array')) : [],
            'has_next' => (bool) ($response['hasNext'] ?? false),
        ];
    }

    /**
     * Every page of a search, up to a bound.
     *
     * @param array<string, mixed> $criteria
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function searchAll(string $path, array $criteria = [], int $pageSize = 1000, int $maxPages = 20): array
    {
        $rows = [];
        $page = 1;

        do {
            $result = $this->search($path, $criteria, $page, $pageSize);
            $rows = [...$rows, ...$result['items']];
            $page++;
        } while ($result['has_next'] && $page <= $maxPages);

        return $rows;
    }

    /**
     * The credential test a setup screen calls.
     *
     * One territory, which is the smallest read this API offers. False for a
     * refused key; a courier that is *down* still throws, because that is not
     * an answer about the key.
     *
     * @throws ApiException
     */
    public function verifyCredentials(): bool
    {
        if (!$this->credentials->isComplete()) {
            return false;
        }

        try {
            $this->search('territories/search', [], 1, 1);

            return true;
        } catch (ApiException $exception) {
            if ($exception->errorCode() === 'provider_auth_failed') {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @throws ApiException
     */
    private function send(string $method, string $path, ?array $body): mixed
    {
        if (!$this->credentials->isComplete()) {
            throw ApiException::conflict(
                'ZR Express is enabled but its credentials are missing.',
                ['provider' => ZRExpressProvider::NAME]
            );
        }

        $url = $this->settings->baseUrl . ltrim($path, '/');
        $encoded = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attempt = 0;

        while (true) {
            $response = $this->attempt($method, $url, $path, $encoded);

            if ($response->status !== 429) {
                return $this->decode($response, $method, $path);
            }

            $wait = self::retryAfter($response);

            if ($attempt >= $this->maxRetries || $wait > $this->maxRetryWait) {
                $this->logger->warning('ZR Express is rate limiting', [
                    'method' => $method,
                    'path' => $path,
                    'retry_after' => $wait,
                ]);

                throw new ApiException(
                    'provider_rate_limited',
                    'ZR Express is rate limiting this store; try again shortly.',
                    429,
                    ['provider' => ZRExpressProvider::NAME, 'retry_after' => $wait]
                );
            }

            $attempt++;
            ($this->sleeper)($wait);
        }
    }

    private function attempt(string $method, string $url, string $path, ?string $body): HttpResponse
    {
        try {
            return $this->http->request($method, $url, $this->headers(), $body);
        } catch (HttpTransportException $exception) {
            $this->logger->error('ZR Express unreachable', [
                'method' => $method,
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            throw new ApiException(
                'provider_unavailable',
                'ZR Express could not be reached.',
                502,
                ['provider' => ZRExpressProvider::NAME],
                $exception
            );
        }
    }

    /** @throws ApiException */
    private function decode(HttpResponse $response, string $method, string $path): mixed
    {
        if ($response->isSuccess()) {
            // 204 on a delete: an empty body is an answer, not a broken one.
            return trim($response->body) === '' ? true : ($response->decoded() ?? true);
        }

        throw $this->failure($response, $method, $path);
    }

    private function failure(HttpResponse $response, string $method, string $path): ApiException
    {
        $problem = $response->decoded();
        $problem = is_array($problem) ? $problem : [];

        $detail = isset($problem['detail']) && is_scalar($problem['detail']) ? trim((string) $problem['detail']) : '';
        $title = isset($problem['title']) && is_scalar($problem['title']) ? trim((string) $problem['title']) : '';

        $this->logger->error('ZR Express refused a request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status,
            'title' => $title,
            'detail' => $detail,
            // Their support asks for this, and it identifies the request rather
            // than the account.
            'trace_id' => isset($problem['traceId']) && is_scalar($problem['traceId'])
                ? (string) $problem['traceId']
                : '',
        ]);

        if ($response->status === 401 || $response->status === 403) {
            return new ApiException(
                'provider_auth_failed',
                'ZR Express rejected this store\'s credentials.',
                502,
                ['provider' => ZRExpressProvider::NAME]
            );
        }

        if ($response->status === 404) {
            return new ApiException(
                'provider_not_found',
                'ZR Express has no record of that.',
                404,
                array_filter(['provider' => ZRExpressProvider::NAME, 'provider_message' => $detail])
            );
        }

        if ($response->status === 409) {
            // A duplicate externalId, in practice — the caller decides whether
            // that is an error or a parcel to go and find.
            return new ApiException(
                'provider_conflict',
                'ZR Express already has this parcel.',
                409,
                array_filter(['provider' => ZRExpressProvider::NAME, 'provider_message' => $detail])
            );
        }

        if ($response->status >= 500) {
            return new ApiException(
                'provider_unavailable',
                'ZR Express is not answering correctly.',
                502,
                ['provider' => ZRExpressProvider::NAME, 'provider_status' => $response->status]
            );
        }

        /*
         * Their `detail` is a sentence written for a person — "Suppliers can
         * only request rates for all wilayas or communes of their origin
         * wilaya" — and it names the actual problem, so it is carried through.
         * It contains no URL, header or credential; none of those are read
         * here.
         */
        return new ApiException(
            'provider_rejected',
            'ZR Express rejected this request.',
            400,
            array_filter([
                'provider' => ZRExpressProvider::NAME,
                'provider_status' => $response->status,
                'provider_message' => $detail !== '' ? $detail : $title,
            ])
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-Tenant' => $this->credentials->tenantId,
            'X-Api-Key' => $this->credentials->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private static function retryAfter(HttpResponse $response): int
    {
        $header = trim($response->header('retry-after'));

        if ($header !== '' && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        return 1;
    }
}
