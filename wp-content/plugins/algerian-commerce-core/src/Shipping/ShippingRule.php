<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use InvalidArgumentException;

/**
 * One price for one kind of destination — one row of `ac_shipping_rates`.
 *
 * Pure — no WordPress, no database — so the rules that keep the table coherent
 * are testable directly.
 *
 * `0` means "any" on both place ids and `''` means "any" on provider and
 * delivery type, which is what lets a shop express a flat national rate plus a
 * few exceptions in three rows instead of sixty-nine. The one combination
 * refused is a commune without its wilaya: a commune belongs to exactly one
 * wilaya, so a rule naming a commune and no wilaya describes a place that
 * cannot be matched — and it would sit in the table looking like it worked.
 *
 * Money is kept as the decimal string it arrived as. Nothing here does
 * arithmetic on it; RateResolver does, in integer minor units.
 */
final class ShippingRule
{
    public const MAX_PROVIDER = 32;

    public readonly string $provider;
    public readonly string $deliveryType;

    public function __construct(
        public readonly int $wilayaId,
        public readonly int $communeId,
        public readonly string $amount,
        string $provider = '',
        string $deliveryType = '',
        /** Free above this subtotal. Null means the rule has no threshold. */
        public readonly ?string $freeOver = null,
        public readonly ?int $estimatedDays = null,
        public readonly bool $isActive = true,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly int $id = 0
    ) {
        if ($wilayaId < 0 || $communeId < 0) {
            throw new InvalidArgumentException('A shipping rule cannot name a negative place.');
        }

        if ($communeId > 0 && $wilayaId === 0) {
            throw new InvalidArgumentException('A rule naming a commune must name its wilaya.');
        }

        if ($deliveryType !== '' && !Destination::isKnownDeliveryType($deliveryType)) {
            throw new InvalidArgumentException("Unknown delivery type \"{$deliveryType}\".");
        }

        $this->provider = mb_substr(strtolower(trim($provider)), 0, self::MAX_PROVIDER);
        $this->deliveryType = strtolower(trim($deliveryType));
    }

    /** @param array<string, mixed> $row as read from the table */
    public static function fromRow(array $row): self
    {
        $freeOver = $row['free_over'] ?? null;
        $estimated = $row['estimated_days'] ?? null;

        return new self(
            (int) ($row['wilaya_id'] ?? 0),
            (int) ($row['commune_id'] ?? 0),
            (string) ($row['amount'] ?? '0'),
            (string) ($row['provider'] ?? ''),
            (string) ($row['delivery_type'] ?? ''),
            $freeOver === null ? null : (string) $freeOver,
            $estimated === null ? null : (int) $estimated,
            (bool) ($row['is_active'] ?? true),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            (int) ($row['id'] ?? 0)
        );
    }

    /**
     * Whether this rule can price that destination for that courier.
     *
     * An inactive rule matches nothing — that is what deactivating one is for.
     */
    public function matches(Destination $destination, string $provider): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->provider !== '' && $this->provider !== strtolower(trim($provider))) {
            return false;
        }

        if ($this->wilayaId !== 0 && $this->wilayaId !== $destination->wilayaId) {
            return false;
        }

        if ($this->communeId !== 0 && $this->communeId !== $destination->communeId) {
            return false;
        }

        return $this->deliveryType === '' || $this->deliveryType === $destination->deliveryType;
    }

    /**
     * How specific this rule is, as a score — higher wins.
     *
     * Powers of two, one per dimension, so every combination scores uniquely
     * and two different rules can never tie. Without that, "which price did the
     * customer get" would depend on row order, and the answer would change when
     * a shop edited an unrelated rule.
     *
     * Commune outranks wilaya because it is the narrower place; delivery type
     * outranks provider because a shop that prices desk pickup differently
     * means it for every courier.
     */
    public function specificity(): int
    {
        return ($this->communeId !== 0 ? 8 : 0)
            + ($this->wilayaId !== 0 ? 4 : 0)
            + ($this->deliveryType !== '' ? 2 : 0)
            + ($this->provider !== '' ? 1 : 0);
    }

    /**
     * Row for $wpdb, matching migration 005.
     *
     * @return array<string, string|int|null>
     */
    public function toRow(): array
    {
        return [
            'provider' => $this->provider,
            'wilaya_id' => $this->wilayaId,
            'commune_id' => $this->communeId,
            'delivery_type' => $this->deliveryType,
            'amount' => $this->amount,
            'free_over' => $this->freeOver,
            'estimated_days' => $this->estimatedDays,
            'is_active' => $this->isActive ? 1 : 0,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Placeholders for $wpdb, keyed by column.
     *
     * Keyed rather than positional because an update writes a *subset* of the
     * row — `created_at` is not rewritable — and a positional list has to be
     * spliced to match, which is a silent corruption waiting for the first
     * person to reorder a column.
     *
     * `free_over` and `estimated_days` are `%s`: $wpdb has no nullable numeric
     * placeholder, and `%d` would turn a null into 0 — which here is the
     * difference between "no free-shipping threshold" and "free from the first
     * dinar".
     *
     * @var array<string, string>
     */
    private const FORMATS = [
        'provider' => '%s',
        'wilaya_id' => '%d',
        'commune_id' => '%d',
        'delivery_type' => '%s',
        'amount' => '%s',
        'free_over' => '%s',
        'estimated_days' => '%s',
        'is_active' => '%d',
        'created_at' => '%s',
        'updated_at' => '%s',
    ];

    /**
     * Placeholders for the columns this row actually carries, in its order.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function formatsFor(array $row): array
    {
        return array_values(array_intersect_key(self::FORMATS, $row));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'wilaya_id' => $this->wilayaId,
            'commune_id' => $this->communeId,
            'delivery_type' => $this->deliveryType,
            'amount' => $this->amount,
            'free_over' => $this->freeOver,
            'estimated_days' => $this->estimatedDays,
            'is_active' => $this->isActive,
            // Emitted so an admin screen can sort a rule list the way the
            // resolver reads it, instead of re-deriving the precedence and
            // getting it subtly different.
            'specificity' => $this->specificity(),
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
