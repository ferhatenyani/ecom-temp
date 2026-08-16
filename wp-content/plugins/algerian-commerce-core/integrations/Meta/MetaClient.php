<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Meta;

use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Http\HttpClientInterface;
use AlgerianCommerce\Http\HttpTransportException;
use AlgerianCommerce\Marketing\MarketingResult;

/**
 * The Conversions API transport — roadmap §62b.
 *
 * Written from Meta's current documentation, read **2026-08-16**:
 * `POST https://graph.facebook.com/{version}/{pixel-id}/events` with `data` (up
 * to 1,000 events), `access_token`, and an optional `test_event_code`.
 *
 * ## What is verified and what is not
 *
 * The request shape, the field names, the hashing rules and the version pin all
 * come from that documentation rather than from memory, per roadmap §54.
 * **Nothing here has been run against a live dataset**, because that needs a
 * Meta ad account and a token this project does not have — the §56 situation
 * exactly. What the docs do not state is marked `ASSUMPTION (unverified)`:
 *
 *     grep -rn 'ASSUMPTION' integrations/Meta
 *
 * When a token exists, set `test_event_code` in `ac_meta_settings`, run
 * `wp algerian-commerce sync-marketing`, and watch the Test Events view. That
 * exercises the whole path without polluting a client's attribution, so none of
 * these markers needs to stay.
 *
 * The token travels in the **body**, never the query string: Meta accepts
 * either, and a URL is written to access logs, proxy logs and error reports.
 */
final class MetaClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly MetaCredentials $credentials,
        private readonly MetaSettings $settings,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param list<array<string, mixed>> $events already in Meta's server-event shape
     */
    public function sendEvents(array $events): MarketingResult
    {
        if ($events === []) {
            return MarketingResult::sent();
        }

        $payload = ['data' => $events, 'access_token' => $this->credentials->accessToken];

        if ($this->settings->testEventCode !== '') {
            $payload['test_event_code'] = $this->settings->testEventCode;
        }

        $url = $this->settings->eventsUrl($this->credentials->pixelId);

        try {
            $response = $this->http->request(
                'POST',
                $url,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                /*
                 * `json_encode`, not `wp_json_encode`: an adapter must be
                 * unit-testable without booting WordPress
                 * (docs/ARCHITECTURE.md §2), which is the whole reason the
                 * transport is injected. The Chargily and ZR Express clients
                 * do the same.
                 */
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (HttpTransportException $exception) {
            /*
             * No answer at all. Always retryable: the events are still in the
             * queue and Meta accepts a Purchase reported well after the fact,
             * so a network blip costs latency rather than a conversion.
             */
            $this->logger->warning('Meta unreachable', [
                'events' => count($events),
                'message' => $exception->getMessage(),
            ]);

            return MarketingResult::retryable('transport: ' . $exception->getMessage());
        }

        $decoded = $response->decoded();
        $decoded = is_array($decoded) ? $decoded : [];

        if ($response->isSuccess()) {
            /*
             * ASSUMPTION (unverified — no ad account): that a 2xx means every
             * event in the batch was accepted, and that a partial failure comes
             * back as a non-2xx rather than as a 200 naming the bad ones. The
             * documented success body is `{events_received, messages, fbtrace_id}`
             * and `messages` is where a warning would appear, so it is logged
             * whenever it is non-empty rather than discarded — if this turns out
             * to be wrong, that log line is the evidence.
             */
            $messages = $decoded['messages'] ?? [];

            if (is_array($messages) && $messages !== []) {
                $this->logger->warning('Meta accepted events with messages', [
                    'messages' => $messages,
                    'trace' => (string) ($decoded['fbtrace_id'] ?? ''),
                ]);
            }

            return MarketingResult::sent((string) ($decoded['fbtrace_id'] ?? ''));
        }

        return $this->failure($response->status, $decoded);
    }

    /**
     * Meta's error shape, and which side of the retry line it falls on.
     *
     * `{"error":{"message":…,"type":…,"code":…,"error_subcode":…,"fbtrace_id":…}}`
     * is documented and stable across the Graph API, so the codes below are read
     * from it rather than guessed from the status alone.
     *
     * @param array<string, mixed> $decoded
     */
    private function failure(int $status, array $decoded): MarketingResult
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = (int) ($error['code'] ?? 0);
        $message = trim((string) ($error['message'] ?? ''));
        $summary = sprintf('%d %s (code %d)', $status, $message === '' ? 'no message' : $message, $code);

        /*
         * The secret is never logged — `Logger` masks any key containing
         * "token" — and neither is the payload, which carries hashed customer
         * identifiers.
         */
        $this->logger->error('Meta refused a batch', [
            'status' => $status,
            'code' => $code,
            'subcode' => (int) ($error['error_subcode'] ?? 0),
            'provider_message' => $message,
            'trace' => (string) ($error['fbtrace_id'] ?? ''),
        ]);

        /*
         * Codes 1, 2 and 4 are Graph's "unknown / service / rate limit"
         * family, and 17 and 32 are user- and page-level rate limits. All are
         * temporary by definition, so they go back on the queue.
         */
        if (in_array($code, [1, 2, 4, 17, 32, 341, 613], true) || $status === 429 || $status >= 500) {
            return MarketingResult::retryable($summary);
        }

        /*
         * 190 is an invalid or expired access token. Retrying cannot fix it —
         * somebody has to issue a new one — but it is not the *event* that is
         * wrong, so it is logged loudly and refused rather than silently
         * discarding a real conversion five times.
         */
        if ($code === 190) {
            return MarketingResult::rejected('the access token was refused: ' . $summary);
        }

        /*
         * Anything else — a 400 about a malformed field, a 100 about a bad
         * parameter — will be just as wrong on the next attempt.
         */
        return MarketingResult::rejected($summary);
    }
}
