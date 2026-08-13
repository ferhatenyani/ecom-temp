<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

/**
 * The two headers Yalidine authenticates with, and the webhook's shared secret.
 *
 * Pure — no WordPress, no `getenv()`. The values arrive from
 * `Plugin::shippingProviders()`, which is the only place in this codebase that
 * reads a courier's credentials (docs/ARCHITECTURE.md §4), and they are read
 * from the environment there — never from the options table, never from code
 * (docs/SECURITY.md).
 *
 * No accessor prints them and nothing here has a `__toString()`. The client
 * puts them into request headers and nowhere else; `Logger::redact()` masks any
 * context key containing "token" or "key", but the surer defence is that these
 * never reach a log call in the first place.
 *
 * `webhookSecret` is carried here even though nothing uses it yet. Yalidine's
 * webhook puts a `security_token` in the *body* rather than signing the
 * request, so verifying it is a constant-time comparison against this value —
 * and that lands with the webhook slice, after §55's security review.
 */
final class YalidineCredentials
{
    public function __construct(
        public readonly string $apiId = '',
        public readonly string $apiToken = '',
        public readonly string $webhookSecret = ''
    ) {
    }

    /** Both headers are required; Yalidine rejects a request missing either. */
    public function isComplete(): bool
    {
        return trim($this->apiId) !== '' && trim($this->apiToken) !== '';
    }
}
