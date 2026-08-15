<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The parts of an inbound provider endpoint that are the same for every
 * provider — roadmap §60, docs/SECURITY.md → "Webhooks".
 *
 * Extracted when the couriers got webhooks and it became clear that the
 * payments controller and the shipping one differed only in *which service they
 * call*. Everything else — reading the raw bytes before anything else touches
 * them, the header shape an adapter expects, what an unverified request is told,
 * what is written to the log — is the §55 rule rather than a domain decision,
 * and a rule copied into two files is a rule that will hold in one of them.
 *
 * **A route exists only for a provider that is registered.** Subclasses pass the
 * names from their own registry, which is built from feature flags and
 * credentials — so a shop with no Yalidine key has no Yalidine endpoint at all.
 * An unconfigured secret is a 404, never a door that accepts what it cannot
 * check.
 *
 * `permission_callback => '__return_true'` is correct here and nowhere else but
 * `/health`: **the signature is the authentication**, and a capability check
 * would reject the provider, which has no WordPress user and never will.
 *
 * Rate limiting still applies: `RateLimitGuard` is registered across the whole
 * namespace, so a forgery loop against any of these routes is bounded.
 *
 * ## What comes back, and why each one
 *
 * ```
 * 401 webhook_unverified   signature, timestamp or shape — never which
 * 400 webhook_unsupported  a provider that receives no webhooks at all
 * 200 …                    verified; the service says what it did with it
 * 500 webhook_failed       verified and we failed — see below
 * ```
 *
 * The 500 is honest rather than useful, and the difference matters. The event id
 * is claimed *before* processing, because a read-then-write test races exactly
 * when a provider retries in parallel — so a retry after a 500 finds the claim
 * taken and is answered `duplicate`. The retry does not fix anything; the
 * reconciliation poll does. The 500 is there so the failure appears in the
 * provider's own delivery log as well as in ours.
 */
abstract class AbstractWebhookController extends AbstractController
{
    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
    }

    /**
     * One POST route per registered provider.
     *
     * @param list<string>                                    $providers from the domain's registry
     * @param callable(string, array, array, string): array   $handler   provider, payload, headers, raw body
     */
    protected function registerWebhookRoutes(array $providers, callable $handler): void
    {
        foreach ($providers as $provider) {
            register_rest_route($this->restNamespace(), '/webhooks/' . $provider, [
                'methods' => 'POST',
                'callback' => $this->handle(fn (WP_REST_Request $request): WP_REST_Response
                    => $this->receive((string) $provider, $request, $handler)),
                /*
                 * The signature is the authentication — see the class docblock.
                 * Besides /health this is the only place in the plugin where
                 * __return_true is allowed, and it carries this comment because
                 * a reviewer must be able to tell it apart from a forgotten one.
                 */
                'permission_callback' => '__return_true',
            ]);
        }
    }

    /** @param callable(string, array, array, string): array $handler */
    private function receive(string $provider, WP_REST_Request $request, callable $handler): WP_REST_Response
    {
        // The bytes as they arrived. This is what the signature is over —
        // decoding and re-encoding changes them and breaks every scheme there
        // is — and it is read before anything else happens.
        $rawBody = (string) $request->get_body();

        try {
            $result = $handler($provider, self::decode($rawBody), self::headers($request), $rawBody);
        } catch (ApiException $exception) {
            if ($exception->errorCode() === 'webhook_unsupported') {
                /*
                 * A provider that receives no webhooks at all — cash on
                 * delivery, in-house delivery. Its own 400 is the honest answer
                 * and must not be dressed up as a 500, which would tell a caller
                 * to retry something that can never work.
                 */
                return Response::fromException($exception);
            }

            if ($exception->errorCode() === 'webhook_unverified') {
                /*
                 * Warning with the provider, the route and the source IP — never
                 * the body, never the headers, never the secret, and never the
                 * event id, which at this point is unverified attacker input.
                 */
                $this->logger->warning('Rejected an unverified webhook', [
                    'provider' => $provider,
                    'route' => $request->get_route(),
                    'ip' => self::sourceIp(),
                ]);

                // No WWW-Authenticate header, no echo of the body, and no hint
                // about which check failed: a verifier that distinguishes them
                // is an oracle for building a valid signature.
                return Response::fromException($exception);
            }

            $this->logger->error('Verified webhook failed to process', [
                'provider' => $provider,
                'error' => $exception->errorCode(),
            ]);

            return Response::error('webhook_failed', 'This event could not be processed.', 500);
        }

        return Response::success($result);
    }

    /**
     * The body decoded, or an empty array.
     *
     * A parse with no side effect, and nothing is *acted on* from it until the
     * adapter has verified the raw bytes it came from. Malformed JSON is not
     * distinguished here: it goes on to fail verification like anything else,
     * and gets the same answer as every other unverified request.
     *
     * @return array<string, mixed>
     */
    private static function decode(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Header names as they appear on the wire: lower-case, hyphenated.
     *
     * WordPress canonicalises them to `signature` and `svix_id`; an adapter is
     * written against a provider's documentation, which says `svix-id`. The
     * translation belongs here rather than in every adapter.
     *
     * @return array<string, string>
     */
    private static function headers(WP_REST_Request $request): array
    {
        $headers = [];

        foreach ($request->get_headers() as $name => $values) {
            $headers[str_replace('_', '-', strtolower((string) $name))] = is_array($values)
                ? (string) reset($values)
                : (string) $values;
        }

        return $headers;
    }

    /**
     * The remote address, and only the remote address.
     *
     * No `X-Forwarded-For`: it is caller-controlled, so a forgery loop could
     * write whatever it liked into this shop's logs. Behind a proxy this is the
     * proxy, which is a true statement about where the request reached us.
     */
    private static function sourceIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($ip) ? $ip : '';
    }
}
