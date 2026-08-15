<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;

/**
 * Which payment methods this shop offers, and which one to use when nobody says.
 *
 * Pure — no WordPress — so method selection is unit-testable without a
 * container. Providers arrive already constructed, because deciding *whether* a
 * provider is configured means reading credentials and feature flags
 * (`ENABLE_COD`, `ENABLE_CHARGILY`), and that belongs in `Plugin`'s wiring where
 * every other environment decision is made (docs/ARCHITECTURE.md §4). This class
 * only knows what it was given.
 *
 * The default is the first provider registered, so the wiring order is the
 * shop's preference order. A transaction always records the provider it was
 * created with, so changing the default later never re-routes a payment that
 * already exists.
 *
 * Deliberately a sibling of `Shipping\ProviderRegistry` rather than a shared
 * generic one. The two look alike today and are about to stop: payment methods
 * are offered to a *shopper* at checkout and are filtered by what an order is
 * for, while couriers are chosen by an *operator* and filtered by where a parcel
 * is going. Merging them now to save thirty lines would mean unpicking them at
 * the first rule that applies to only one.
 */
final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    /** @param list<PaymentProviderInterface> $providers in preference order */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    public function add(PaymentProviderInterface $provider): void
    {
        // Keyed by name, so registering the same provider twice replaces it
        // rather than shadowing it — two live adapters for one provider would
        // make which credentials get used depend on array order.
        $this->providers[$provider->name()] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[strtolower(trim($name))]);
    }

    public function isEmpty(): bool
    {
        return $this->providers === [];
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
            static fn (PaymentProviderInterface $provider): array => [
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
     * An unregistered name is a 400 rather than a 404 — the caller sent a value
     * this shop does not accept, and the response names the ones it does so a
     * checkout can correct itself. A shop with no provider at all is a 409:
     * nothing is wrong with the request, the shop simply cannot take money yet.
     *
     * @throws ApiException
     */
    public function get(string $name = ''): PaymentProviderInterface
    {
        $name = strtolower(trim($name));

        if ($this->providers === []) {
            throw new ApiException(
                'no_payment_provider',
                'This store has no payment method configured.',
                409
            );
        }

        if ($name === '') {
            return $this->providers[$this->defaultName()];
        }

        if (!isset($this->providers[$name])) {
            throw ApiException::invalidRequest('The payment data is invalid.', [
                'fields' => ['provider' => "Unknown payment method \"{$name}\"."],
                'available' => $this->names(),
            ]);
        }

        return $this->providers[$name];
    }
}
