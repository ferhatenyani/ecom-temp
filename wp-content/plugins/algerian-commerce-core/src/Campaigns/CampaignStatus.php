<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

/**
 * The campaign lifecycle — roadmap §85.
 *
 * Pure — no WordPress — so the one rule that protects a customer list is a unit
 * test: **a campaign can be sent exactly once.**
 *
 * ```
 *   draft ──send──▶ sending ──drain finishes──▶ sent
 *     │                 │
 *     └───cancel────────┴──▶ cancelled
 * ```
 *
 * `sent` and `cancelled` are terminal. There is no `sending → draft`: a campaign
 * that has begun going out cannot be edited back into a draft, because some of
 * its recipients already have the old message and the shop would have no record of
 * which version anybody received.
 *
 * **There is a transition matrix here where `ShipmentStatus` refuses one**, and
 * the difference is who owns the object. A parcel is a courier's to move and it
 * reports what it reports, sometimes late and out of order; a campaign is entirely
 * this system's, so a state it cannot reach is a bug rather than the physical
 * world disagreeing with a diagram.
 */
final class CampaignStatus
{
    /** Editable, not yet claimed by a send. */
    public const DRAFT = 'draft';

    /** Recipient rows are written and the drain is working through them. */
    public const SENDING = 'sending';

    /** Every recipient row reached a terminal state. */
    public const SENT = 'sent';

    /** Stopped by hand — before a send, or part-way through one. */
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ALL = [self::DRAFT, self::SENDING, self::SENT, self::CANCELLED];

    /** @var list<string> */
    public const TERMINAL = [self::SENT, self::CANCELLED];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::DRAFT => [self::SENDING, self::CANCELLED],
        self::SENDING => [self::SENT, self::CANCELLED],
        self::SENT => [],
        self::CANCELLED => [],
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** Only a draft may have its message, subject or audience rewritten. */
    public static function isEditable(string $status): bool
    {
        return $status === self::DRAFT;
    }

    public static function accepts(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }
}
