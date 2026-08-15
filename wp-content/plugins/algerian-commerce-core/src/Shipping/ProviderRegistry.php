<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;

/**
 * Which couriers this shop has, and which one to use when nobody says.
 *
 * Pure — no WordPress — so provider selection is unit-testable without a
 * container. Providers are handed in already constructed, because deciding
 * *whether* a courier is configured means reading credentials and feature flags
 * (`ENABLE_YALIDINE`, `ENABLE_ZR_EXPRESS`), and that belongs in Plugin's wiring
 * where every other environment decision is made (docs/ARCHITECTURE.md §4).
 * This class only knows what it was given.
 *
 * The default is the first provider registered, which makes the wiring order
 * the shop's preference order — one shop leads with Yalidine, another delivers
 * everything in-house. A shipment always records the provider it was created
 * with, so changing the default later never re-routes a parcel that already
 * exists.
 */
final class ProviderRegistry
{
    /** @var array<string, ShippingProviderInterface> */
    private array $providers = [];

    /** @param list<ShippingProviderInterface> $providers in preference order */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    public function add(ShippingProviderInterface $provider): void
    {
        // Keyed by name, so a second registration of the same courier replaces
        // it rather than shadowing it — two live adapters for one provider
        // would make which credentials get used depend on array order.
        $this->providers[$provider->name()] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[strtolower(trim($name))]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    /** @return list<array{name: string, label: string, is_default: bool}> */
    public function describe(): array
    {
        $default = $this->defaultName();

        return array_values(array_map(
            static fn (ShippingProviderInterface $provider): array => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'is_default' => $provider->name() === $default,
            ],
            $this->providers
        ));
    }

    public function defaultName(): string
    {
        return (string) (array_key_first($this->providers) ?? '');
    }

    /**
     * Resolve a provider by name, or the default when none is asked for.
     *
     * A name that is not registered is a 400 rather than a 404: the caller sent
     * a value this shop does not accept, and the response names the ones it
     * does so an admin UI can correct itself. A shop with no provider at all is
     * a 409 — nothing is wrong with the request, the shop simply cannot ship
     * anything until one is configured.
     *
     * @throws ApiException
     */
    public function get(string $name = ''): ShippingProviderInterface
    {
        $name = strtolower(trim($name));

        if ($this->providers === []) {
            throw new ApiException(
                'no_shipping_provider',
                'This store has no shipping provider configured.',
                409
            );
        }

        if ($name === '') {
            return $this->providers[$this->defaultName()];
        }

        if (!isset($this->providers[$name])) {
            throw ApiException::invalidRequest('The shipment data is invalid.', [
                'fields' => ['provider' => "Unknown provider \"{$name}\"."],
                'available' => $this->names(),
            ]);
        }

        return $this->providers[$name];
    }
}
