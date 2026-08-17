<?php

declare(strict_types=1);

namespace AlgerianCommerce\Http;

use WP_Error;

/**
 * The one implementation that actually opens a socket, over WordPress's HTTP
 * API.
 *
 * `wp_remote_request()` rather than cURL directly: a WordPress host may sit
 * behind a proxy, may have a CA bundle of its own, and site owners filter
 * `http_request_args` for both. Bypassing it would work on a laptop and fail on
 * exactly the shared hosting an Algerian client runs on.
 *
 * Two defaults that are not WordPress's:
 *
 *  - **A timeout that is set, not inherited.** WordPress defaults to 5 seconds,
 *    which is optimistic for a courier API on a bad day; more importantly, an
 *    unset timeout is how a slow provider becomes a hung checkout.
 *  - **TLS verification is never turned off.** docs/SECURITY.md requires TLS to
 *    every provider, and a flag to disable it is a flag someone eventually
 *    sets in production.
 *  - **The safe wrapper, so a provider URL cannot reach the VPS itself.** See
 *    `request()`; this is the one place it can be got wrong for every
 *    integration at once.
 */
final class WpHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 15,
        private readonly string $userAgent = 'algerian-commerce-core'
    ) {
    }

    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): HttpResponse {
        /*
         * `wp_safe_remote_request()` and never `wp_remote_request()`, which is
         * the same call with `reject_unsafe_urls` off. The safe wrapper runs
         * `wp_http_validate_url()`, which refuses loopback, link-local and
         * private ranges — so a base URL that ever becomes attacker-influenced
         * cannot be pointed at `169.254.169.254` or another service on the
         * VPS. docs/SECURITY.md has required this since it was written; the
         * §86 audit found the unsafe spelling here and this is the correction.
         * `tests/Unit/OutboundHttpSafetyTest` keeps the next call site honest.
         */
        $response = wp_safe_remote_request($url, [
            'method' => strtoupper($method),
            'headers' => $headers,
            'body' => $body,
            'timeout' => $this->timeout,
            'sslverify' => true,
            'user-agent' => $this->userAgent,
            // A courier's API never answers with a redirect to somewhere we
            // should follow carrying an API token in the headers.
            'redirection' => 0,
        ]);

        if ($response instanceof WP_Error) {
            throw new HttpTransportException($response->get_error_message());
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response),
            self::normalizeHeaders(wp_remote_retrieve_headers($response))
        );
    }

    /**
     * Header names lower-cased, so a caller can ask for `retry-after` without
     * guessing which capitalisation the provider chose today.
     *
     * @return array<string, string>
     */
    private static function normalizeHeaders(mixed $headers): array
    {
        // WordPress returns Requests_Utility_CaseInsensitiveDictionary, which
        // is traversable but not an array on every version.
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }

        if (!is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? implode(', ', array_map('strval', $value))
                : (string) $value;
        }

        return $normalized;
    }
}
