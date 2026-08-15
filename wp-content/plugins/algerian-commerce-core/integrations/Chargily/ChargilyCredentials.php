<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Chargily;

/**
 * One key, and the environment it belongs to — roadmap §59.
 *
 * Pure — no WordPress, no `getenv()`. The value arrives from
 * `Plugin::paymentProviders()`, the only place a gateway's credentials are read
 * (docs/ARCHITECTURE.md §4), and it comes from the environment there.
 *
 * ## There is one secret, not two
 *
 * `.env` used to carry `CHARGILY_WEBHOOK_SECRET` beside `CHARGILY_SECRET_KEY`,
 * written before anyone had read Chargily's documentation and assuming the shape
 * ZR Express has. Chargily does not work that way: **webhooks are signed with
 * the API secret key itself** — `hash_hmac('sha256', $body, $secretKey)` — which
 * their documentation, their own WooCommerce plugin and their PHP SDK all agree
 * on. A second variable would have nothing to put in it, and anything anyone did
 * put there would silently fail every signature check. It has been removed
 * rather than left to be filled in wrongly; docs/SECURITY.md records why.
 *
 * ## The key picks the environment
 *
 * Chargily runs two separate environments with separate keys and separate base
 * URLs, and their reference says plainly: *the API URL and the secret key you
 * provide will determine the mode*. Two settings that must agree are one setting
 * that will eventually not — a live key against the test URL is a shop that
 * takes no money and reports no error worth reading — so the mode is **derived
 * from the key** and cannot be configured apart from it.
 *
 * Test keys are documented as beginning `test_sk_`. Anything else is treated as
 * live, which is the safe direction of the two: a mis-typed key fails
 * authentication loudly against the live endpoint, where a live key silently
 * pointed at the test endpoint would look like a working shop with no money in
 * it.
 */
final class ChargilyCredentials
{
    /** Documented prefix for a Test Mode secret key. */
    public const TEST_PREFIX = 'test_';

    public function __construct(public readonly string $secretKey = '')
    {
    }

    public function isComplete(): bool
    {
        return trim($this->secretKey) !== '';
    }

    public function isTestMode(): bool
    {
        return str_starts_with(trim($this->secretKey), self::TEST_PREFIX);
    }

    /**
     * The secret the webhook signature is computed against.
     *
     * Named for the job rather than aliased to `secretKey`, so a reader of
     * `ChargilyProvider::handleWebhook()` sees *which* secret is meant without
     * having to know this class — and so the day Chargily issues a separate
     * signing secret, one method changes.
     */
    public function signingSecret(): string
    {
        return $this->secretKey;
    }
}
