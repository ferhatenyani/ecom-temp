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
    /** Priced by this shop's own rules — roadmap §4 step 28b, PLAN §14. */
    public const SOURCE_RULES = 'rules';

    /** Quoted by the courier's own rate API. */
    public const SOURCE_PROVIDER = 'provider';

    public function __construct(
        /** The provider's own service identifier, opaque to the core. */
        public readonly string $service,
        public readonly string $label,
        public readonly string $amount,
        public readonly string $currency = 'DZD',
        public readonly ?int $estimatedDays = null,
        /**
         * Where the number came from.
         *
         * A shop's own tariff and a courier's quote are both real answers and
         * they routinely disagree — the shop is charging the customer, the
         * courier is charging the shop. A client showing both has to be able to
         * say which is which, and defaults to `provider` because an adapter
         * that says nothing is quoting its own API.
         */
        public readonly string $source = self::SOURCE_PROVIDER,
        /** True when a free-shipping threshold brought this to zero. */
        public readonly bool $isFree = false
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
            'source' => $this->source,
            'free_shipping' => $this->isFree,
        ];
    }
}
