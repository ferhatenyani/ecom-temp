<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Chargily;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpClientInterface;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Http\HttpTransportException;

/**
 * Everything Chargily-specific about making an HTTP call — roadmap §59.
 *
 * Pure apart from the injected transport, so the whole of it is exercised
 * against recorded responses.
 *
 * ```
 * base    https://pay.chargily.net/test/api/v2/   test
 *         https://pay.chargily.net/api/v2/        live   (the key picks which)
 * auth    Authorization: Bearer <secret key>
 * create  POST   checkouts            → the checkout object, with checkout_url
 * read    GET    checkouts/{id}       → the checkout object
 * ```
 *
 * `POST checkouts/{id}/expire` exists and is deliberately not wired to
 * anything. A checkout expires by itself after thirty minutes, so calling it
 * would buy one network request and a second way for a payment to end; adding a
 * method to `PaymentProviderInterface` that only one gateway can answer is what
 * §58 built the seam to avoid.
 *
 * **Their errors are a Laravel validation envelope** — `{"message": …,
 * "errors": {field: [...]}}` — which is what their own WooCommerce plugin reads
 * when a checkout is refused. `message` is a sentence written for a developer
 * ("The success url field is required"), so it is carried through to the
 * operator; it names a field of *ours*, not a credential, and no URL or header
 * is ever read out of a response into an error.
 *
 * Nothing here logs the key. The header is assembled at the moment of the call
 * and never put into a log context — and `Logger` would mask it anyway, which is
 * a second line of defence rather than a reason to be careless.
 */
final class ChargilyClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ChargilyCredentials $credentials,
        private readonly ChargilySettings $settings,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $path, $body);
    }

    /**
     * The credential test a setup screen calls.
     *
     * `GET balance` is the smallest authenticated read this API offers and it
     * creates nothing. False for a refused key; a gateway that is *down* still
     * throws, because that is not an answer about the key.
     *
     * @throws ApiException
     */
    public function verifyCredentials(): bool
    {
        if (!$this->credentials->isComplete()) {
            return false;
        }

        try {
            $this->get('balance');

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
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    private function send(string $method, string $path, ?array $body): array
    {
        if (!$this->credentials->isComplete()) {
            throw ApiException::conflict(
                'Chargily is enabled but its secret key is missing.',
                ['provider' => ChargilyProvider::NAME]
            );
        }

        $url = $this->settings->baseUrl($this->credentials) . ltrim($path, '/');
        $encoded = $body === null
            ? null
            : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->http->request($method, $url, $this->headers(), $encoded);
        } catch (HttpTransportException $exception) {
            $this->logger->error('Chargily unreachable', [
                'method' => $method,
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            throw new ApiException(
                'provider_unavailable',
                'Chargily could not be reached.',
                502,
                ['provider' => ChargilyProvider::NAME],
                $exception
            );
        }

        if (!$response->isSuccess()) {
            throw $this->failure($response, $method, $path);
        }

        $decoded = $response->decoded();

        if (!is_array($decoded)) {
            $this->logger->error('Chargily returned an unreadable body', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status,
            ]);

            throw new ApiException(
                'provider_response_invalid',
                'Chargily returned a response this store could not read.',
                502,
                ['provider' => ChargilyProvider::NAME]
            );
        }

        return $decoded;
    }

    private function failure(HttpResponse $response, string $method, string $path): ApiException
    {
        $decoded = $response->decoded();
        $decoded = is_array($decoded) ? $decoded : [];

        $message = isset($decoded['message']) && is_scalar($decoded['message'])
            ? trim((string) $decoded['message'])
            : '';

        $this->logger->error('Chargily refused a request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status,
            'message' => $message,
        ]);

        if ($response->status === 401 || $response->status === 403) {
            return new ApiException(
                'provider_auth_failed',
                'Chargily rejected this store\'s credentials.',
                502,
                ['provider' => ChargilyProvider::NAME, 'provider_status' => $response->status]
            );
        }

        if ($response->status === 404) {
            return new ApiException(
                'provider_not_found',
                'Chargily has no record of that payment.',
                404,
                ['provider' => ChargilyProvider::NAME]
            );
        }

        if ($response->status === 429) {
            // Undocumented as a limit, so nothing is assumed about Retry-After.
            return new ApiException(
                'provider_rate_limited',
                'Chargily is rate limiting this store; try again shortly.',
                429,
                ['provider' => ChargilyProvider::NAME]
            );
        }

        if ($response->status >= 500) {
            return new ApiException(
                'provider_unavailable',
                'Chargily is not answering correctly.',
                502,
                ['provider' => ChargilyProvider::NAME, 'provider_status' => $response->status]
            );
        }

        return new ApiException(
            'provider_rejected',
            'Chargily rejected this request.',
            400,
            array_filter([
                'provider' => ChargilyProvider::NAME,
                'provider_status' => $response->status,
                'provider_message' => $message,
            ])
        );
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credentials->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
