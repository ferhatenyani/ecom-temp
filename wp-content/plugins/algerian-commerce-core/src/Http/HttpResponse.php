<?php

declare(strict_types=1);

namespace AlgerianCommerce\Http;

/**
 * One answer from a remote host, as the transport saw it.
 *
 * Pure — no WordPress. Deliberately dumb: a status, a body and the headers,
 * with no opinion about what any of them mean. Deciding that 429 is a quota and
 * that an empty JSON array is a rejected parcel is the adapter's job, because
 * those are facts about a provider rather than about HTTP.
 */
final class HttpResponse
{
    /** @param array<string, string> $headers keys lower-cased by the transport */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = []
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * The body decoded as JSON, or null when it is not JSON at all.
     *
     * Returns whatever the JSON contained — an object becomes an associative
     * array and an array stays a list. Both shapes are real answers from
     * Yalidine's parcel endpoint, where a list means "rejected", so flattening
     * them here would destroy the distinction the adapter has to act on.
     */
    public function decoded(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
