<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use InvalidArgumentException;

/**
 * One **verified** inbound event, translated out of a provider's shape.
 *
 * Pure — no WordPress. Read `docs/SECURITY.md` → "Webhooks" before implementing
 * `handleWebhook()`; that section is the rule this object exists to serve, and
 * it was settled by §55 before any endpoint existed so the providers are not
 * each argued out separately.
 *
 * **This object only ever describes a verified event.** An adapter that cannot
 * verify a request throws — see `PaymentProviderInterface::handleWebhook()` —
 * rather than returning something with a `verified` flag on it. A boolean is a
 * thing a caller can forget to check; an exception is not. So the mere existence
 * of a `WebhookResult` means the signature or shared secret already passed.
 *
 * `eventId` is what gets **claimed** — a write-once insert whose duplicate-key
 * failure is the idempotency answer, never a read-then-write check, which races
 * exactly when a provider retries in parallel. Where a provider sends no id of
 * its own, the adapter derives a stable one by hashing the signed material.
 *
 * `status` is nullable, and that is a real case rather than laziness: §55 ruled
 * that a verified event we do not recognise is acknowledged with 200 and
 * dropped, so the provider stops retrying over a gap on our side. Null means
 * *verified, nothing to apply* — distinct from an unverified request, which
 * never gets this far.
 */
final class WebhookResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        /** The provider's event id, or a hash of the signed material when it sends none. */
        public readonly string $eventId,
        public readonly string $providerPaymentId = '',
        /** Null when the event is verified but carries no state we act on. */
        public readonly ?string $status = null,
        /** What the provider says was paid — for the server-side amount re-check. */
        public readonly string $amount = '',
        public readonly string $currency = '',
        public readonly array $metadata = []
    ) {
        if (trim($eventId) === '') {
            // Without an id there is nothing to claim, and replay protection
            // silently stops existing — the failure §55 designed against.
            throw new InvalidArgumentException(
                'A webhook result needs an event id to claim; derive one by hashing the signed material.'
            );
        }

        if ($status !== null && !PaymentStatus::isKnown($status)) {
            throw new InvalidArgumentException(
                "A provider reported the unmapped payment status \"{$status}\" on a webhook."
            );
        }
    }

    /** Whether this event asks us to change anything. */
    public function isActionable(): bool
    {
        return $this->status !== null;
    }

    /** The same facts as a verification, so both paths re-check an amount alike. */
    public function toReport(): ?PaymentReport
    {
        return $this->status === null
            ? null
            : new PaymentReport($this->status, '', $this->amount, $this->currency, $this->metadata);
    }
}
