<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use InvalidArgumentException;

/**
 * What a provider hands back when it accepts a payment attempt.
 *
 * Pure — no WordPress. The status is validated here rather than trusted: an
 * adapter returning a provider's own word — "processing", "AUTHORIZED" — would
 * put a state into our records that nothing else can reason about, and it would
 * be found weeks later by a finance screen with a blank column. Failing at the
 * seam names the adapter that got it wrong.
 *
 * **`checkoutUrl` is where the shopper is sent**, and it is the whole reason a
 * created payment is not a paid one. A redirect provider answers with a URL, the
 * storefront sends the customer there, and the money is confirmed later by
 * `verifyPayment()` or a signature-verified webhook. A provider needing no
 * redirect — cash on delivery — leaves it empty, which is a meaningful answer
 * and not a missing one.
 *
 * `metadata` holds the provider's particulars, the way `ShipmentResult`'s does.
 * Nothing in the core may read a key out of it, or the abstraction has leaked.
 */
final class PaymentResult
{
    /** @param array<string, mixed> $metadata the provider's own response detail */
    public function __construct(
        public readonly string $providerPaymentId,
        public readonly string $status = PaymentStatus::PENDING,
        public readonly string $checkoutUrl = '',
        public readonly array $metadata = []
    ) {
        if (!PaymentStatus::isKnown($status)) {
            throw new InvalidArgumentException(
                "A provider returned the unmapped payment status \"{$status}\"."
            );
        }
    }

    /** Whether the shopper still has somewhere to be sent. */
    public function needsRedirect(): bool
    {
        return trim($this->checkoutUrl) !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider_payment_id' => $this->providerPaymentId,
            'status' => $this->status,
            'checkout_url' => $this->checkoutUrl,
            'metadata' => $this->metadata,
        ];
    }
}
