<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use InvalidArgumentException;

/**
 * One row of the stock ledger, validated and shaped for storage.
 *
 * Pure — no WordPress, no database — so the invariant that makes the ledger
 * readable is testable directly: quantity_before + delta must equal
 * quantity_after. A row that fails that cannot be reconciled against its
 * neighbours, and a ledger you cannot reconcile is decoration.
 *
 * Values are truncated to their column widths for the same reason AuditEvent
 * does it: MySQL in strict mode rejects an over-length value outright, which
 * would turn a long note into a failed ledger write on a stock change that
 * has already happened.
 */
final class InventoryMovement
{
    public const MAX_REASON = 40;
    public const MAX_NOTE = 255;

    public readonly string $reason;
    public readonly string $note;
    public readonly string $createdAt;

    public function __construct(
        public readonly int $productId,
        public readonly int $delta,
        public readonly int $quantityBefore,
        public readonly int $quantityAfter,
        string $reason,
        string $note = '',
        public readonly int $orderId = 0,
        public readonly int $actorId = 0,
        ?string $createdAt = null
    ) {
        if ($productId <= 0) {
            throw new InvalidArgumentException('A movement requires a product id.');
        }

        if (!MovementReason::isKnown($reason)) {
            throw new InvalidArgumentException("Unknown movement reason \"{$reason}\".");
        }

        if ($quantityBefore + $delta !== $quantityAfter) {
            throw new InvalidArgumentException(sprintf(
                'Movement does not balance: %d + %d != %d.',
                $quantityBefore,
                $delta,
                $quantityAfter
            ));
        }

        $this->reason = mb_substr($reason, 0, self::MAX_REASON);
        $this->note = mb_substr(trim($note), 0, self::MAX_NOTE);
        $this->createdAt = $createdAt ?? gmdate('Y-m-d H:i:s');
    }

    /**
     * Build from the quantity WooCommerce reports after the write.
     *
     * $after is authoritative — WooCommerce applies increase/decrease as a
     * relative SQL update, so a concurrent adjustment may have landed since
     * the caller read the stock. Deriving `before` from `after - delta` keeps
     * the row internally consistent instead of pairing a stale `before` with a
     * fresh `after`.
     */
    public static function fromDelta(
        int $productId,
        int $delta,
        int $after,
        string $reason,
        string $note = '',
        int $orderId = 0,
        int $actorId = 0
    ): self {
        return new self($productId, $delta, $after - $delta, $after, $reason, $note, $orderId, $actorId);
    }

    /**
     * Row for $wpdb->insert(), matching migration 002.
     *
     * @return array<string, string|int>
     */
    public function toRow(): array
    {
        return [
            'product_id' => $this->productId,
            'delta' => $this->delta,
            'quantity_before' => $this->quantityBefore,
            'quantity_after' => $this->quantityAfter,
            'reason' => $this->reason,
            'note' => $this->note,
            'order_id' => $this->orderId,
            'actor_id' => $this->actorId,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Placeholders for $wpdb->insert(), in the same order as toRow().
     *
     * @return list<string>
     */
    public function rowFormats(): array
    {
        return ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'delta' => $this->delta,
            'quantity_before' => $this->quantityBefore,
            'quantity_after' => $this->quantityAfter,
            'reason' => $this->reason,
            'note' => $this->note,
            'order_id' => $this->orderId,
            'actor_id' => $this->actorId,
            'created_at' => $this->createdAt,
        ];
    }
}
