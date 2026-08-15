<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

/**
 * Which merchant, and the key that proves it — roadmap §57.
 *
 * Pure — no WordPress, no `getenv()`. The values arrive from
 * `Plugin::shippingProviders()`, the only place a courier's credentials are
 * read (docs/ARCHITECTURE.md §4), and they come from the environment there.
 *
 * Two headers, like Yalidine, but they mean different things: `X-Tenant`
 * identifies the account and `X-Api-Key` authenticates it. Both are required —
 * the API answers a wrong key with `401 Invalid API Key` and no detail about
 * which half was wrong.
 *
 * `webhookSecret` is carried and unused, as Yalidine's is. ZR Express signs
 * webhooks with **Svix**, whose scheme is public — `svix-id`, `svix-timestamp`
 * and `svix-signature`, HMAC-SHA256 over `id.timestamp.body` against a
 * `whsec_` secret. That is a published standard rather than something to
 * invent, which is what §57 was waiting to know; the webhook still lands with
 * §55's review.
 */
final class ZRExpressCredentials
{
    public function __construct(
        public readonly string $tenantId = '',
        public readonly string $apiKey = '',
        public readonly string $webhookSecret = ''
    ) {
    }

    public function isComplete(): bool
    {
        return trim($this->tenantId) !== '' && trim($this->apiKey) !== '';
    }
}
