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
        public readonly bool $isFree = false,
        /**
         * Which journey this price is for — `home`, `desk`, or null.
         *
         * ## Why the core cannot work this out from `service`
         *
         * `service` is the *provider's* identifier and is documented one line
         * up as opaque to the core. Yalidine's happen to read `express_home`
         * and `economic_desk`, so a substring test would work today and would
         * be a rule about one courier's spelling written into shared code —
         * exactly the leak `ShippingProviderInterface` exists to prevent. The
         * adapter knows which journey it priced; it should say so.
         *
         * ## Why anything needs to know
         *
         * `ShippingService::rates()` does not: a manager comparing "he collects
         * it" against "we deliver it" wants all of them, which is why
         * `YalidineProvider::getShippingRates()` deliberately returns all four
         * services regardless of what was asked for.
         *
         * A checkout is the opposite. The shopper has already said which
         * journey they want, and `ShopperRates` has to charge for *that* one —
         * take the cheapest of Yalidine's four and a customer who chose home
         * delivery is charged the stop-desk price, which is both less money
         * than the parcel costs and a promise nobody made.
         *
         * **Null means the adapter did not say**, and is read as "this price is
         * for the destination it was asked about" rather than as a mismatch —
         * `getShippingRates()` takes a `Destination` and a courier that quotes
         * something else has misunderstood the question. Null is therefore the
         * safe default for an adapter that only ever returns one price.
         */
        public readonly ?string $deliveryType = null
    ) {
    }

    /**
     * Whether this price may be charged for that journey.
     *
     * Null passes, for the reason `$deliveryType` gives: an adapter that says
     * nothing has answered about the destination it was handed.
     */
    public function coversDeliveryType(string $deliveryType): bool
    {
        return $this->deliveryType === null || $this->deliveryType === $deliveryType;
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
            'delivery_type' => $this->deliveryType,
        ];
    }
}
