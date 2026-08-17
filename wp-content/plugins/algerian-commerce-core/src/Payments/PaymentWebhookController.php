<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\AbstractWebhookController;
use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;

/**
 * Inbound gateway events — roadmap §59 and §60, docs/SECURITY.md → "Webhooks".
 *
 * ```
 * POST /wp-json/algerian-commerce/v1/webhooks/chargily
 * ```
 *
 * One route per **registered** payment provider, from
 * `Plugin::paymentProviders()` — so a shop with no Chargily key has no Chargily
 * endpoint. Everything about how such a route behaves lives in
 * `AbstractWebhookController`, because it is the §55 rule rather than anything
 * about payments; this class supplies the registry and the service, and nothing
 * else.
 *
 * A provider that receives no webhooks — cash on delivery — answers its own
 * route with `webhook_unsupported`, which is the adapter's business and not the
 * route's. Registering per provider keeps "a route exists only when its provider
 * does" literally true rather than approximately.
 */
final class PaymentWebhookController extends AbstractWebhookController
{
    public function __construct(
        Logger $logger,
        private readonly PaymentProviderRegistry $providers,
        private readonly PaymentService $service,
        ?Config $config = null
    ) {
        parent::__construct($logger, $config);
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
