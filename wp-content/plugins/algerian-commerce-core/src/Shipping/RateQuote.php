<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * One price a provider will carry a parcel for.
 *
 * Pure — no WordPress. Money is a decimal string, as everywhere else in this
 * codebase: a shipping fee that arrives as a float has already lost the last
 * dinar by the time it reaches a total.
 *
 * `estimatedDays` is nullable because half the couriers in this market quote a
 * price without committing to a time, and inventing "3" so a column is never
 * empty is how a storefront ends up promising something nobody agreed to.
 */
final class RateQuote
{
    public function __construct(
        /** The provider's own service identifier, opaque to the core. */
        public readonly string $service,
        public readonly string $label,
        public readonly string $amount,
        public readonly string $currency = 'DZD',
        public readonly ?int $estimatedDays = null
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'label' => $this->label,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'estimated_days' => $this->estimatedDays,
        ];
    }
}
