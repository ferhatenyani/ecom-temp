<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use InvalidArgumentException;

/**
 * One row of `ac_payment_transactions` — docs/PLAN.md §19, roadmap §59.
 *
 * Pure — no WordPress, no database — so the rules that keep the table honest
 * are testable directly: a transaction belongs to an order, names a provider,
 * carries a status this system understands, and states an amount as a decimal
 * string.
 *
 * The counterpart of `Shipping\Shipment`, and truncated to column widths for the
 * same reason: MySQL in strict mode rejects an over-length value outright, which
 * would turn a gateway's unexpectedly long reference into a failed write *after*
 * a customer had been sent to a payment page — the one moment losing the record
 * is unrecoverable.
 *
 * **The amount is a decimal string and never a float.** It is compared against
 * an order total and against what a gateway says it collected, and a value that
 * has been through a float has already lost the centime that would make the
 * comparison meaningful.
 *
 * Timestamps are `'Y-m-d H:i:s'` in UTC, matching every other table here, and
 * are presented as ISO-8601 on the wire.
 */
final class Transaction
{
    public const MAX_PROVIDER = 32;
    public const MAX_PROVIDER_TRANSACTION_ID = 64;
    public const MAX_REFERENCE = 64;

    public readonly string $provider;
    public readonly string $providerTransactionId;
    public readonly string $reference;
    public readonly string $currency;
    public readonly string $status;
    public readonly string $amount;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly int $orderId,
        string $provider,
        string $providerTransactionId = '',
        string $reference = '',
        string $amount = '0.00',
        string $currency = '',
        string $status = PaymentStatus::PENDING,
        public readonly array $metadata = [],
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly int $id = 0
    ) {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('A transaction requires an order id.');
        }

        if (trim($provider) === '') {
            throw new InvalidArgumentException('A transaction requires a provider.');
        }

        if (!PaymentStatus::isKnown($status)) {
            throw new InvalidArgumentException("Unknown payment status \"{$status}\".");
        }

        $this->provider = mb_substr(trim($provider), 0, self::MAX_PROVIDER);
        $this->providerTransactionId = mb_substr(trim($providerTransactionId), 0, self::MAX_PROVIDER_TRANSACTION_ID);
        $this->reference = mb_substr(trim($reference), 0, self::MAX_REFERENCE);
        $this->amount = number_format((float) ($amount === '' ? '0' : $amount), 2, '.', '');
        $this->currency = strtoupper(mb_substr(trim($currency), 0, 3));
        $this->status = PaymentStatus::normalize($status);
    }

    /** @param array<string, mixed> $row as read from the table */
    public static function fromRow(array $row): self
    {
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);

        return new self(
            (int) ($row['order_id'] ?? 0),
            (string) ($row['provider'] ?? ''),
            (string) ($row['provider_transaction_id'] ?? ''),
            (string) ($row['reference'] ?? ''),
            (string) ($row['amount'] ?? '0.00'),
            (string) ($row['currency'] ?? ''),
            (string) ($row['status'] ?? PaymentStatus::PENDING),
            is_array($metadata) ? $metadata : [],
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            (int) ($row['id'] ?? 0)
        );
    }

    /**
     * The same attempt once the gateway has answered the create call.
     *
     * The row is written *before* the provider is called — docs/SECURITY.md
     * wants every attempt recorded — so this is the step that gives it the
     * provider's identifier. Without it the attempt can never be verified again,
     * which is why a failure to store it is treated as seriously as a failure to
     * create the checkout.
     */
    public function withProviderResult(PaymentResult $result, string $now): self
    {
        return new self(
            $this->orderId,
            $this->provider,
            $result->providerPaymentId,
            $this->reference,
            $this->amount,
            $this->currency,
            $result->status,
            [...$this->metadata, ...$result->metadata],
            $this->createdAt,
            $now,
            $this->id
        );
    }

    /**
     * The same attempt after the provider was asked what happened.
     *
     * The gateway's own wording is merged into the metadata rather than
     * replacing it, so what it said at each step survives — a mis-mapped status
     * is then visible in the record instead of inferred from a support call.
     *
     * **The amount is not overwritten.** What the order was for is what this row
     * was opened with; what the gateway says it collected is checked against it
     * by `PaymentService` and, when the two disagree, is a refusal and an audit
     * event rather than a quiet correction.
     */
    public function withReport(PaymentReport $report, string $now): self
    {
        return new self(
            $this->orderId,
            $this->provider,
            $this->providerTransactionId,
            $this->reference,
            $this->amount,
            $this->currency,
            $report->status,
            [
                ...$this->metadata,
                ...$report->metadata,
                'provider_status' => $report->providerStatus,
                'reported_amount' => $report->amount,
                'reported_currency' => $report->currency,
            ],
            $this->createdAt,
            $now,
            $this->id
        );
    }

    /** @param array<string, mixed> $metadata merged over what is already there */
    public function withStatus(string $status, string $now, array $metadata = []): self
    {
        return new self(
            $this->orderId,
            $this->provider,
            $this->providerTransactionId,
            $this->reference,
            $this->amount,
            $this->currency,
            $status,
            [...$this->metadata, ...$metadata],
            $this->createdAt,
            $now,
            $this->id
        );
    }

    /** Still waiting on the customer or the gateway. */
    public function isOpen(): bool
    {
        return PaymentStatus::isOpen($this->status);
    }

    public function isSettled(): bool
    {
        return PaymentStatus::isSettled($this->status);
    }

    /**
     * Row for `$wpdb->insert()`/`update()`, matching migration 007.
     *
     * @return array<string, string|int>
     */
    public function toRow(): array
    {
        return [
            'order_id' => $this->orderId,
            'provider' => $this->provider,
            'provider_transaction_id' => $this->providerTransactionId,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'metadata' => (string) json_encode($this->metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => $this->createdAt !== '' ? $this->createdAt : gmdate('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt !== '' ? $this->updatedAt : gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @return list<string> in toRow() order */
    public function rowFormats(): array
    {
        return ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];
    }

    /**
     * The wire shape.
     *
     * No checkout URL: it is a one-time link to a payment page for one
     * customer's order, it is never stored, and a list endpoint is the last
     * place it should reappear.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'provider' => $this->provider,
            'provider_transaction_id' => $this->providerTransactionId,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt !== '' ? str_replace(' ', 'T', $this->createdAt) . 'Z' : '',
            'updated_at' => $this->updatedAt !== '' ? str_replace(' ', 'T', $this->updatedAt) . 'Z' : '',
        ];
    }
}
