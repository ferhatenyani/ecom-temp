<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\AbstractWebhookController;
use AlgerianCommerce\Core\Logger;

/**
 * Inbound courier events — roadmap §60, docs/SECURITY.md → "Webhooks".
 *
 * ```
 * POST /wp-json/algerian-commerce/v1/webhooks/yalidine
 * POST /wp-json/algerian-commerce/v1/webhooks/zrexpress
 * ```
 *
 * §60 writes the second one `zr-express`. The route is `zrexpress` because
 * `ZRExpressProvider::NAME` is, and that string is already written into every
 * `ac_shipments` row this plugin has created — a route that disagreed with the
 * provider slug would be a second name for one thing, and the mapping between
 * them would live in whichever file somebody remembered.
 *
 * One route per **registered** courier, from `Plugin::shippingProviders()`, so a
 * shop with no Yalidine credentials has no Yalidine endpoint. In-house delivery
 * answers its own route with `webhook_unsupported` — nobody sends webhooks about
 * a van we own — which is the adapter's business rather than the route's.
 *
 * Everything about how these routes behave is in `AbstractWebhookController`,
 * because it is the §55 rule rather than anything about parcels.
 */
final class ShippingWebhookController extends AbstractWebhookController
{
    public function __construct(
        Logger $logger,
        private readonly ProviderRegistry $providers,
        private readonly ShippingService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $this->registerWebhookRoutes(
            $this->providers->names(),
            fn (string $provider, array $payload, array $headers, string $rawBody): array
                => $this->service->handleWebhook($provider, $payload, $headers, $rawBody)
        );
    }
}
