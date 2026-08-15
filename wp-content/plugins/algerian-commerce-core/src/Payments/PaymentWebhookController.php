<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\API\AbstractController;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inbound gateway events — roadmap §60, docs/SECURITY.md → "Webhooks".
 *
 * ```
 * POST /wp-json/algerian-commerce/v1/webhooks/chargily
 * ```
 *
 * **A route exists only for a provider that is registered.** The list comes from
 * `PaymentProviderRegistry`, which `Plugin::paymentProviders()` builds from
 * feature flags and credentials — so a shop with no Chargily key has no Chargily
 * endpoint at all. An unconfigured secret is a 404, never a door that accepts
 * what it cannot check.
 *
 * `permission_callback => '__return_true'` is correct here and nowhere else but
 * `/health`: **the signature is the authentication**, and a capability check
 * would reject the gateway, which has no WordPress user and never will. The
 * verification it stands in for is not optional and not deferred — it is the
 * first thing the adapter does, on the raw bytes, before a single field of the
 * body is acted on.
 *
 * Rate limiting still applies: `RateLimitGuard` is registered across the whole
 * namespace, so a forgery loop against this route is bounded like any other.
 *
 * ## What comes back, and why each one
 *
 * ```
 * 401 webhook_unverified   signature, timestamp or shape — never which
 * 200 processed            verified, claimed, re-fetched, applied
 * 200 duplicate            the claim was refused: already handled
 * 200 ignored              verified, but nothing we act on
 * 500                      verified and we failed — see below
 * ```
 *
 * The 500 is honest rather than useful, and the difference matters. The event id
 * is claimed *before* processing, because a read-then-write test races exactly
 * when a gateway retries in parallel — so a retry after a 500 finds the claim
 * already taken and is answered `duplicate`. The retry does not fix anything;
 * `wp algerian-commerce sync-payments` does. The 500 is there so the failure is
 * visible in the gateway's own delivery log as well as in ours.
 */
final class PaymentWebhookController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        foreach ($this->providers->names() as $provider) {
            register_rest_route($this->restNamespace(), '/webhooks/' . $provider, [
                'methods' => 'POST',
                'callback' => $this->handle(fn (WP_REST_Request $request): WP_REST_Response
                    => $this->receive($provider, $request)),
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

    /**
     * A provider that receives no webhooks — cash on delivery — answers its own
     * route with `webhook_unsupported`, which is the adapter's business and not
     * the route's. Registering per provider keeps the rule above literally true
     * rather than approximately.
     */
    private function receive(string $provider, WP_REST_Request $request): WP_REST_Response
    {
        // The bytes as they arrived. This is what the signature is over —
        // decoding and re-encoding changes them and breaks every scheme there
        // is — and it is read before anything else happens.
        $rawBody = (string) $request->get_body();

        try {
            $result = $this->service->handleWebhook(
                $provider,
                self::decode($rawBody),
                self::headers($request),
                $rawBody
            );
        } catch (ApiException $exception) {
            if ($exception->errorCode() === 'webhook_unsupported') {
                /*
                 * A provider that receives no webhooks at all — cash on
                 * delivery. Its own 400 is the honest answer and must not be
                 * dressed up as a 500, which would tell a caller to retry
                 * something that can never work.
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

            // Verified and we failed: 500, so the failure shows up in the
            // gateway's delivery log too.
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
