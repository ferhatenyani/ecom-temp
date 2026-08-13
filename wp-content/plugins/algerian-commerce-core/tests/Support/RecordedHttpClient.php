<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Support;

use AlgerianCommerce\Http\HttpClientInterface;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Http\HttpTransportException;

/**
 * A transport that answers from a script instead of a network.
 *
 * This is how the Yalidine adapter is tested at all. Roadmap §56 was written
 * without a merchant account and without a sandbox, so there is nothing to call
 * — and an adapter that can only be checked against the live API of a provider
 * nobody can sign into is an adapter nobody can check.
 *
 * Every request is recorded, because half of what an adapter gets wrong is in
 * what it *sends*: the field names, the wilaya spelling, the two auth headers,
 * the array wrapper around a single parcel.
 */
final class RecordedHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<HttpResponse|HttpTransportException> */
    private array $script;

    /** @param list<HttpResponse|HttpTransportException> $script one per call, in order */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    /** @param array<string, mixed> $body */
    public static function json(array|string $body, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse(
            $status,
            is_string($body) ? $body : (string) json_encode($body),
            $headers
        );
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        $next = array_shift($this->script);

        if ($next instanceof HttpTransportException) {
            throw $next;
        }

        // An unscripted call is a bug in the test, not a 404 to be handled:
        // saying so here is how "the adapter made a call nobody expected" gets
        // noticed instead of being absorbed by an error path.
        return $next ?? new HttpResponse(599, '{"message":"no response scripted"}');
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string} */
    public function lastRequest(): array
    {
        return $this->requests[array_key_last($this->requests)] ?? [
            'method' => '',
            'url' => '',
            'headers' => [],
            'body' => null,
        ];
    }

    /** The decoded body of the nth request (0-based). */
    public function sentBody(int $index = 0): mixed
    {
        return json_decode((string) ($this->requests[$index]['body'] ?? ''), true);
    }
}
