<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

/**
 * The canonical shipment status vocabulary — roadmap §53, docs/PLAN.md §14.
 *
 * Pure — no WordPress — so the rule that decides whether a reported status may
 * be applied is unit-testable on its own.
 *
 * **This is our vocabulary, not any courier's.** Yalidine, Zedair and whoever
 * comes third each name their states differently and will rename them again
 * without telling us; each adapter maps its provider's states onto these, and
 * keeps the provider's own spelling in the shipment metadata so a mis-mapping
 * can be seen rather than guessed at. The list is deliberately short: a state
 * earns its place only if the shop would *do* something different because of
 * it, and the provider's fine-grained detail survives in the metadata.
 *
 * **There is no transition matrix**, unlike orders and COD, and that is not an
 * oversight. We do not control a parcel — a courier does, and it reports what
 * it reports, sometimes late and sometimes out of order. Refusing a status
 * because it did not follow the one we expected would mean our record
 * disagreeing with the physical world to defend a diagram. What *is* enforced
 * is that a finished shipment stays finished: once a parcel is delivered,
 * returned, cancelled or failed, a later report does not reopen it.
 */
final class ShipmentStatus
{
    /** Recorded here; the provider has not accepted it yet. */
    public const PENDING = 'pending';

    /** The provider accepted it and issued a tracking number. */
    public const CREATED = 'created';

    /** The courier has the parcel. */
    public const PICKED_UP = 'picked_up';

    public const IN_TRANSIT = 'in_transit';

    /** With a driver, on the delivery round. */
    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    /**
     * On its way back, but not back yet.
     *
     * Added for Yalidine (roadmap §56), whose vocabulary spends seven states
     * here — *Retour vers centre*, *Retourné au centre*, *Retour transfert*,
     * *Retour groupé*, *Retour à retirer*, *Retour non retiré*, *Echèc
     * livraison* — and it is not a Yalidine detail: every Algerian courier
     * carries an undelivered parcel back through its own network, and that trip
     * takes days.
     *
     * Neither existing value can hold it. `returned` is terminal, so a parcel
     * still moving would stop being tracked, and a COD shop could not tell *on
     * its way back to me* from *back in my hands* — which is the difference
     * between waiting and re-listing the stock. `in_transit` reads to an
     * operator as heading to the customer, which is the opposite of what is
     * happening.
     *
     * Live, not terminal: `returning → returned` is the ordinary ending and
     * `returning → failed` is the parcel that never arrives. The column is
     * varchar(30) and TERMINAL is unchanged, so nothing migrates.
     */
    public const RETURNING = 'returning';

    /** Came back to the shop — the outcome COD shops watch most closely. */
    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /** The provider refused it, lost it, or gave up. */
    public const FAILED = 'failed';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::CREATED,
        self::PICKED_UP,
        self::IN_TRANSIT,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
        self::RETURNING,
        self::RETURNED,
        self::CANCELLED,
        self::FAILED,
    ];

    /**
     * Finished, one way or another.
     *
     * A shipment in one of these is not tracked further and cannot be
     * cancelled: the parcel has already arrived, come back, or stopped being a
     * parcel.
     *
     * @var list<string>
     */
    public const TERMINAL = [self::DELIVERED, self::RETURNED, self::CANCELLED, self::FAILED];

    public static function normalize(string $status): string
    {
        return strtolower(trim(str_replace('-', '_', $status)));
    }

    public static function isKnown(string $status): bool
    {
        return in_array(self::normalize($status), self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(self::normalize($status), self::TERMINAL, true);
    }

    public static function isLive(string $status): bool
    {
        return self::isKnown($status) && !self::isTerminal($status);
    }

    /**
     * Whether a status reported by a provider should be written down.
     *
     * Three refusals, each for a real failure mode:
     *
     *  - An unknown status. The adapter's mapping missed a case, and storing a
     *    word we cannot reason about is worse than keeping the last one we can
     *    — the raw value is in the metadata either way.
     *  - No change. Saves a write and an event on every poll of a parcel that
     *    has not moved, which is most polls.
     *  - A finished shipment. A webhook replayed out of order, or a poll that
     *    crossed a delivery in flight, must not walk a delivered parcel back to
     *    "in transit" (docs/SECURITY.md: duplicate delivery must never
     *    duplicate a shipment or an order transition).
     */
    public static function accepts(string $current, string $reported): bool
    {
        $current = self::normalize($current);
        $reported = self::normalize($reported);

        if (!self::isKnown($reported) || $reported === $current) {
            return false;
        }

        return !self::isTerminal($current);
    }
}
