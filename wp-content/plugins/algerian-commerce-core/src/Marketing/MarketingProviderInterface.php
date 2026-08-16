<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

/**
 * What a marketing destination must be able to do — roadmap §62b.
 *
 * The same shape as `ShippingProviderInterface` and `PaymentProviderInterface`,
 * and for the same reason: the core never learns a provider's name. TikTok and
 * Google Ads are the second and third implementations, and adding one must
 * change nothing above this interface.
 *
 * Everything crossing the boundary is one of our value objects — `MarketingEvent`
 * in, `MarketingResult` out — and an adapter never sees a `WC_Order`, an option,
 * or a raw customer email.
 */
interface MarketingProviderInterface
{
    public function name(): string;

    public function label(): string;

    /**
     * The ids a storefront may put in browser JavaScript.
     *
     * A pixel id is public by construction — it ships in the page — so this is
     * what `GET /marketing/config` serves. **An access token is never in here**;
     * the token is a credential and the compiler cannot stop somebody adding it,
     * so the test suite does.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array;

    /**
     * Send one event, now.
     *
     * Called by the queue drain, never on the checkout path. An adapter must
     * therefore not retry internally: retrying is the queue's job, and an
     * adapter that also retries turns one outage into a storm.
     */
    public function send(MarketingEvent $event): MarketingResult;
}
