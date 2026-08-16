<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

/**
 * The marketing destinations this shop has — roadmap §62b.
 *
 * **An empty registry is the normal case**, not a misconfiguration: a client
 * without an ad account is an ordinary client, and the difference from
 * `PaymentProviderRegistry` matters. A shop with no payment provider cannot
 * take money and must say so with a 409; a shop with no pixel simply has no
 * pixel, so `GET /marketing/config` answers "off" and nothing errors.
 */
final class MarketingProviderRegistry
{
    /** @var array<string, MarketingProviderInterface> */
    private array $providers = [];

    /** @param list<MarketingProviderInterface> $providers */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): ?MarketingProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /** @return list<MarketingProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    public function isEmpty(): bool
    {
        return $this->providers === [];
    }
}
