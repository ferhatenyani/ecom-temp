<?php

declare(strict_types=1);

namespace AlgerianCommerce\Http;

/**
 * The seam between an integration and the network.
 *
 * It exists so that an adapter is testable. A provider's client is mostly
 * decisions — which field goes where, what a 429 means, how a courier spells
 * failure — and none of those can be exercised if calling them opens a socket.
 * With the transport injected, every one of those decisions is a unit test with
 * a recorded response in it, which matters more than usual here: roadmap §56 is
 * written against an API with no sandbox and no test account, so fixtures are
 * the only evidence available before the first live call.
 *
 * Deliberately not Yalidine's: WordPress's HTTP API, timeouts and TLS are not
 * facts about a courier, and the second adapter would otherwise copy this out of
 * the first one.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException when no answer arrived at all — a timeout,
     *                                a DNS failure, a refused connection. An
     *                                HTTP error *is* an answer and comes back as
     *                                an HttpResponse with its status.
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null
    ): HttpResponse;
}
