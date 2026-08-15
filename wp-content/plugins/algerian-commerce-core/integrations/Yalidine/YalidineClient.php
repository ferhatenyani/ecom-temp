<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpClientInterface;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Http\HttpTransportException;

/**
 * Everything Yalidine-specific about making an HTTP call: the two auth headers,
 * the quota, and the translation of their failures into ours.
 *
 * Pure apart from the injected transport — no WordPress, no globals — so all of
 * it is exercised in unit tests against recorded responses. That matters more
 * than usual: roadmap §56 was written without a merchant account or a sandbox,
 * so a fixture is the only evidence any of this behaves as intended before the
 * first live call.
 *
 * The API, as three independent implementations agree (roadmap §56) and as
 * **verified against the live API on 2026-08-14**:
 *
 * ```
 * base   https://api.yalidine.app/v1/   (a per-client setting, defaulted)
 * auth   X-API-ID + X-API-TOKEN         both required, both headers
 * quota  every response carries
 *        second-quota-left  minute-quota-left  hour-quota-left  day-quota-left
 * ```
 *
 * **The quota is published on every response**, which is better than the 429 we
 * were braced for: the account probed answered 5 a second, 50 a minute, 1,000
 * an hour and 10,000 a day, counted down in those headers. Reacting to a 429 is
 * still the backstop, but a caller that reads the headers never provokes one —
 * so they are recorded here and `quota()` hands them to whoever is pacing the
 * work.
 *
 * **Nothing here logs a credential.** The headers are assembled at the moment
 * of the call and never put into a log context; what is logged is the method,
 * the path and the status. `Logger::redact()` would mask a key containing
 * "token" anyway, but not relying on that is the point.
 */
final class YalidineClient
{
    /** Yalidine's own countdown, from the last response. */
    private const QUOTA_HEADERS = [
        'second-quota-left',
        'minute-quota-left',
        'hour-quota-left',
        'day-quota-left',
    ];

    /** @var callable(int): void */
    private $sleeper;

    /** @var array<string, int> */
    private array $quota = [];

    /**
     * @param callable(int): void|null $sleeper injected so the 429 path is
     *                                          testable without a test that
     *                                          actually waits
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly YalidineCredentials $credentials,
        private readonly YalidineSettings $settings,
        private readonly Logger $logger,
        /**
         * One retry, and only for a 429.
         *
         * Not for anything else, and deliberately: `POST parcels/` is the call
         * that matters and retrying it blind is how a shop gets two parcels for
         * one order. The merchant reference *should* make it idempotent — see
         * YalidineParcel — but "should" is not something to retry on.
         */
        private readonly int $maxRetries = 1,
        /**
         * How long a Retry-After we are willing to sit out inside a request.
         *
         * Obeying the header means not hammering the API, not blocking a
         * checkout for a minute. Anything longer comes back as a 429 with
         * `retry_after` in it, for the caller to schedule.
         */
        private readonly int $maxRetryWait = 5,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, string|int> $query
     *
     * @throws ApiException
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->send('GET', $path, $query, null);
    }

    /**
     * @param array<mixed> $body
     *
     * @throws ApiException
     */
    public function post(string $path, array $body): mixed
    {
        return $this->send('POST', $path, [], $body);
    }

    /**
     * Verified 2026-08-14: `DELETE parcels/{tracking}` exists and answers a
     * list of `{tracking, deleted}` results.
     *
     * @throws ApiException
     */
    public function delete(string $path): mixed
    {
        return $this->send('DELETE', $path, [], null);
    }

    /**
     * The cheap credential test a setup screen calls — roadmap §56.
     *
     * `GET wilayas/` because it is small, read-only, and answers for the
     * account rather than for a parcel. False rather than an exception for an
     * authentication failure: "are these keys good" is a question with a no in
     * it. A courier that is *down* still throws, because that is not an answer
     * about the keys.
     *
     * @throws ApiException when Yalidine cannot be reached at all
     */
    public function verifyCredentials(): bool
    {
        if (!$this->credentials->isComplete()) {
            return false;
        }

        try {
            $this->get('wilayas/', ['page_size' => 1]);

            return true;
        } catch (ApiException $exception) {
            if ($exception->errorCode() === 'provider_auth_failed') {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, string|int> $query
     * @param array<mixed>|null         $body
     *
     * @throws ApiException
     */
    private function send(string $method, string $path, array $query, ?array $body): mixed
    {
        if (!$this->credentials->isComplete()) {
            throw ApiException::conflict(
                'Yalidine is enabled but its API credentials are missing.',
                ['provider' => YalidineProvider::NAME]
            );
        }

        $url = $this->settings->baseUrl . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $encoded = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attempt = 0;

        while (true) {
            /*
             * The per-second allowance is the one a loop actually exhausts —
             * five, on the account this was verified against. Waiting out the
             * second we already know is spent is cheaper than the 429 it would
             * otherwise earn, and it is the whole reason these headers are read.
             */
            if (($this->quota['second-quota-left'] ?? 1) < 1) {
                ($this->sleeper)(1);
            }

            $response = $this->attempt($method, $url, $path, $encoded);
            $this->rememberQuota($response);

            if ($response->status !== 429) {
                return $this->decode($response, $method, $path);
            }

            $wait = self::retryAfter($response);

            if ($attempt >= $this->maxRetries || $wait > $this->maxRetryWait) {
                $this->logger->warning('Yalidine quota reached', [
                    'method' => $method,
                    'path' => $path,
                    'retry_after' => $wait,
                ]);

                throw new ApiException(
                    'provider_rate_limited',
                    'Yalidine is rate limiting this store; try again shortly.',
                    429,
                    ['provider' => YalidineProvider::NAME, 'retry_after' => $wait]
                );
            }

            $attempt++;
            ($this->sleeper)($wait);
        }
    }

    /**
     * What Yalidine says is left, from the last response.
     *
     * @return array<string, int> empty before the first call
     */
    public function quota(): array
    {
        return $this->quota;
    }

    private function rememberQuota(HttpResponse $response): void
    {
        foreach (self::QUOTA_HEADERS as $header) {
            $value = trim($response->header($header));

            if ($value !== '' && ctype_digit($value)) {
                $this->quota[$header] = (int) $value;
            }
        }
    }

    private function attempt(string $method, string $url, string $path, ?string $body): HttpResponse
    {
        try {
            return $this->http->request($method, $url, $this->headers(), $body);
        } catch (HttpTransportException $exception) {
            /*
             * A timeout is not a rejected parcel. It is logged with the
             * transport's message — which can name a proxy or a host — and
             * answered with our own wording, because that message must not
             * reach a response body (docs/SECURITY.md).
             */
            $this->logger->error('Yalidine unreachable', [
                'method' => $method,
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            throw new ApiException(
                'provider_unavailable',
                'Yalidine could not be reached.',
                502,
                ['provider' => YalidineProvider::NAME],
                $exception
            );
        }
    }

    /** @throws ApiException */
    private function decode(HttpResponse $response, string $method, string $path): mixed
    {
        if ($response->isSuccess()) {
            $decoded = $response->decoded();

            if ($decoded === null) {
                $this->logger->error('Yalidine returned a body that is not JSON', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $response->status,
                ]);

                throw new ApiException(
                    'provider_response_invalid',
                    'Yalidine returned a response this store could not read.',
                    502,
                    ['provider' => YalidineProvider::NAME]
                );
            }

            return $decoded;
        }

        throw $this->failure($response, $method, $path);
    }

    private function failure(HttpResponse $response, string $method, string $path): ApiException
    {
        $message = self::providerMessage($response);

        $this->logger->error('Yalidine refused a request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status,
            'provider_message' => $message,
        ]);

        // 401/403 is about *our* credentials, so it must never come back as a
        // 401 to the caller: the operator's own session is fine, and telling
        // them to log in again would send them chasing the wrong problem.
        if ($response->status === 401 || $response->status === 403) {
            return new ApiException(
                'provider_auth_failed',
                'Yalidine rejected this store\'s API credentials.',
                502,
                ['provider' => YalidineProvider::NAME]
            );
        }

        if ($response->status === 404) {
            return new ApiException(
                'provider_not_found',
                'Yalidine has no record of that.',
                404,
                ['provider' => YalidineProvider::NAME]
            );
        }

        if ($response->status >= 500) {
            return new ApiException(
                'provider_unavailable',
                'Yalidine is not answering correctly.',
                502,
                ['provider' => YalidineProvider::NAME, 'provider_status' => $response->status]
            );
        }

        /*
         * ASSUMPTION (unverified): there is no published catalogue of create
         * errors, so the provider's own message is passed through in `details`
         * rather than mapped. It is a validation sentence about a field —
         * never a URL, a header or a credential, none of which are read here —
         * and it is the only thing that tells an operator which field Yalidine
         * disliked. The catalogue grows as real failures arrive.
         */
        return new ApiException(
            'provider_rejected',
            'Yalidine rejected this request.',
            400,
            array_filter([
                'provider' => YalidineProvider::NAME,
                'provider_status' => $response->status,
                'provider_message' => $message,
            ])
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-API-ID' => $this->credentials->apiId,
            'X-API-TOKEN' => $this->credentials->apiToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Whatever Yalidine called the problem, if it said.
     *
     * Three shapes are looked for because an error body is the least
     * documented part of any provider API and the least stable.
     */
    private static function providerMessage(HttpResponse $response): string
    {
        $decoded = $response->decoded();

        if (!is_array($decoded)) {
            return '';
        }

        foreach (['message', 'error', 'detail'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                return trim((string) $decoded[$key]);
            }
        }

        return '';
    }

    /**
     * Seconds to wait, from the header — roadmap §56: obey it, since the limit
     * itself is not published.
     *
     * A missing or unparseable header falls back to one second rather than to
     * zero: retrying immediately after a quota response is the one thing
     * guaranteed to be refused again.
     */
    private static function retryAfter(HttpResponse $response): int
    {
        $header = trim($response->header('retry-after'));

        if ($header !== '' && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        // ASSUMPTION (still unverified, 2026-08-14): that Retry-After arrives
        // as a number of seconds. No 429 was provoked during verification —
        // deliberately, since exhausting a live merchant's quota to read one
        // header is not a reasonable trade — and the quota countdown above is
        // what keeps us from meeting one. HTTP also allows an absolute date; if
        // Yalidine sends one, this falls back to a second and the caller gets a
        // 429 it can schedule around rather than a wait of unknown length.
        if ($header !== '') {
            $timestamp = strtotime($header);

            if ($timestamp !== false) {
                return max(1, $timestamp - time());
            }
        }

        return 1;
    }
}
