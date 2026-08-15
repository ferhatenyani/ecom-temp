<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use InvalidArgumentException;

/**
 * What the provider says about a payment when we ask it — the answer to
 * `verifyPayment()`.
 *
 * Pure — no WordPress. The counterpart of `Shipping\StatusReport`, and named
 * for the same reason: roadmap §58 writes the signature as
 * `verifyPayment(string $paymentId): PaymentStatus`, but `PaymentStatus` is this
 * codebase's *vocabulary* (a set of constants, like `ShipmentStatus`), so the
 * thing coming back needs a name of its own. The operation is unchanged.
 *
 * **`amount` and `currency` are not informational.** docs/SECURITY.md requires
 * that both are re-checked server-side against the order before anything is
 * marked paid, and a report that omitted them would make that impossible to do
 * — the rule would survive as a sentence in a document while the code had no way
 * to obey it. They are what the *provider* says was paid, to be compared against
 * what the *order* says was owed. Trusting a provider's "paid" without checking
 * how much is how a shop ships a 45,000 DZD order against a 45 DZD payment.
 *
 * `providerStatus` keeps the provider's own spelling, so a mis-mapping is
 * visible in the record instead of being inferred from a support call.
 */
final class PaymentReport
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $status,
        /** The provider's own word for the same thing, stored raw. */
        public readonly string $providerStatus = '',
        /** Decimal string, as the provider reports it. '' when it does not say. */
        public readonly string $amount = '',
        public readonly string $currency = '',
        public readonly array $metadata = []
    ) {
        if (!PaymentStatus::isKnown($status)) {
            throw new InvalidArgumentException(
                "A provider reported the unmapped payment status \"{$status}\"."
            );
        }
    }

    /**
     * Whether the provider stated an amount at all.
     *
     * A provider that does not report one cannot have its figure checked, and
     * the caller has to decide what to do about that rather than read '' as
     * zero — which would compare equal to nothing and pass silently.
     */
    public function hasAmount(): bool
    {
        return trim($this->amount) !== '';
    }

    /**
     * Whether what the provider collected matches what was owed.
     *
     * Compared numerically, because "4500.00", "4500.0" and "4500" are the same
     * money written three ways and providers are not consistent about which. A
     * report with no amount answers false — an unstated figure is not a matching
     * one, and this is the check standing between a confirmed payment and a
     * shipped order.
     */
    public function matches(string $expectedAmount, string $expectedCurrency = ''): bool
    {
        if (!$this->hasAmount()) {
            return false;
        }

        /*
         * An unstated currency fails, and §59 is why the exception it used to
         * get is gone. Chargily's *webhook* carries a checkout object with an
         * amount and no currency at all — so the lenient version answered "yes,
         * this matches" on a payload where the currency check had simply not
         * happened. That is the shape docs/SECURITY.md's rule exists to refuse:
         * a check that quietly does not run is worse than one that fails, and a
         * caller with an expectation to test is entitled to an answer about it.
         *
         * Nothing legitimate loses by this. A provider that reports an amount
         * reports what it is denominated in; a caller that does not care passes
         * no expected currency and reaches this branch never.
         */
        if ($expectedCurrency !== ''
            && strtoupper(trim($this->currency)) !== strtoupper(trim($expectedCurrency))
        ) {
            return false;
        }

        return abs((float) $this->amount - (float) $expectedAmount) < 0.005;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'provider_status' => $this->providerStatus,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
        ];
    }
}
