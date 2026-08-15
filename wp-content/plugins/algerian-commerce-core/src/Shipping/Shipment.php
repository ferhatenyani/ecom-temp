<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use InvalidArgumentException;

/**
 * One row of `ac_shipments`, validated and shaped for storage.
 *
 * Pure — no WordPress, no database — so the rules that keep the table readable
 * are testable directly: a shipment belongs to an order, names a provider, and
 * carries a status this system understands.
 *
 * Values are truncated to their column widths for the same reason AuditEvent
 * and InventoryMovement do it: MySQL in strict mode rejects an over-length
 * value outright, which would turn a courier's unexpectedly long tracking
 * number into a failed write *after* the parcel has already been handed over —
 * the one moment losing the record is unrecoverable.
 *
 * Timestamps are `'Y-m-d H:i:s'` in UTC, matching every other table here, and
 * are presented as ISO-8601 on the wire.
 */
final class Shipment
{
    public const MAX_PROVIDER = 32;
    public const MAX_PROVIDER_SHIPMENT_ID = 64;
    public const MAX_TRACKING = 64;

    public readonly string $provider;
    public readonly string $providerShipmentId;
    public readonly string $trackingNumber;
    public readonly string $status;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly int $orderId,
        string $provider,
        string $providerShipmentId = '',
        string $trackingNumber = '',
        string $status = ShipmentStatus::PENDING,
        public readonly array $metadata = [],
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly int $id = 0
    ) {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('A shipment requires an order id.');
        }

        if (trim($provider) === '') {
            throw new InvalidArgumentException('A shipment requires a provider.');
        }

        if (!ShipmentStatus::isKnown($status)) {
            throw new InvalidArgumentException("Unknown shipment status \"{$status}\".");
        }

        $this->provider = mb_substr(trim($provider), 0, self::MAX_PROVIDER);
        $this->providerShipmentId = mb_substr(trim($providerShipmentId), 0, self::MAX_PROVIDER_SHIPMENT_ID);
        $this->trackingNumber = mb_substr(trim($trackingNumber), 0, self::MAX_TRACKING);
        $this->status = ShipmentStatus::normalize($status);
    }

    /** @param array<string, mixed> $row as read from the table */
    public static function fromRow(array $row): self
    {
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);

        return new self(
            (int) ($row['order_id'] ?? 0),
            (string) ($row['provider'] ?? ''),
            (string) ($row['provider_shipment_id'] ?? ''),
            (string) ($row['tracking_number'] ?? ''),
            (string) ($row['status'] ?? ShipmentStatus::PENDING),
            is_array($metadata) ? $metadata : [],
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            (int) ($row['id'] ?? 0)
        );
    }

    /**
     * The same shipment after a provider said something new about it.
     *
     * The provider's own wording is merged into the metadata rather than
     * replacing it, so what the courier reported at each step survives — see
     * StatusReport on why that raw value is worth keeping.
     */
    public function withReport(StatusReport $report, string $now): self
    {
        return new self(
            $this->orderId,
            $this->provider,
            $this->providerShipmentId,
            $this->trackingNumber,
            $report->status,
            [...$this->metadata, ...$report->metadata, 'provider_status' => $report->providerStatus],
            $this->createdAt,
            $now,
            $this->id
        );
    }

    public function withStatus(string $status, string $now): self
    {
        return new self(
            $this->orderId,
            $this->provider,
            $this->providerShipmentId,
            $this->trackingNumber,
            $status,
            $this->metadata,
            $this->createdAt,
            $now,
            $this->id
        );
    }

    public function isLive(): bool
    {
        return ShipmentStatus::isLive($this->status);
    }

    /**
     * Row for $wpdb->insert()/update(), matching migrations 004 and 006.
     *
     * `live_order_id` is derived, never passed in: the order id while the
     * parcel is still in the air, NULL once it is finished. Migration 006 puts
     * a unique index on it, and a unique index ignores NULLs — so the column
     * *is* the rule "one live shipment per order, any number of finished ones",
     * enforced by the database rather than by whoever remembered to check.
     *
     * Derived from `isLive()` rather than restated here, so the live/finished
     * vocabulary stays in `ShipmentStatus` where the rest of the system reads
     * it. A status added there is covered by this the moment it exists.
     *
     * @return array<string, string|int|null>
     */
    public function toRow(): array
    {
        return [
            'order_id' => $this->orderId,
            'provider' => $this->provider,
            'provider_shipment_id' => $this->providerShipmentId,
            'tracking_number' => $this->trackingNumber,
            'status' => $this->status,
            'live_order_id' => $this->isLive() ? $this->orderId : null,
            'metadata' => $this->encodedMetadata(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Placeholders for $wpdb, in the same order as toRow().
     *
     * @return list<string>
     */
    public function rowFormats(): array
    {
        return ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'];
    }

    /**
     * `json_encode`, not `wp_json_encode`: this class must stay loadable
     * without WordPress so the storage rules above can be unit-tested, which is
     * the same reason AuditEvent encodes its own metadata.
     */
    public function encodedMetadata(): string
    {
        if ($this->metadata === []) {
            return '';
        }

        $encoded = json_encode($this->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'provider' => $this->provider,
            'provider_shipment_id' => $this->providerShipmentId,
            'tracking_number' => $this->trackingNumber,
            'status' => $this->status,
            // Whether the shop is still waiting on this parcel — the one
            // derived fact a client needs before offering a cancel button.
            'is_live' => $this->isLive(),
            'metadata' => $this->metadata,
            'created_at' => self::iso($this->createdAt),
            'updated_at' => self::iso($this->updatedAt),
        ];
    }

    private static function iso(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        $timestamp = strtotime($stored . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
