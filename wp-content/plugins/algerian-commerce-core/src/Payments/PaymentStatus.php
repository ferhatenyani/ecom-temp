<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

/**
 * The canonical payment status vocabulary — roadmap §58, docs/PLAN.md §17.
 *
 * Pure — no WordPress — so the rule deciding whether a reported state may be
 * written is unit-testable on its own. Deliberately shaped like
 * `Shipping\ShipmentStatus`, for the same reason: **this is our vocabulary, not
 * any provider's.** Chargily and whoever comes second name their states
 * differently and will rename them without telling us. Each adapter maps onto
 * these and keeps the provider's own spelling in the report, so a mis-mapping
 * can be seen rather than guessed at.
 *
 * The list is short on purpose — a state earns its place only if the shop would
 * *do* something different because of it.
 *
 * **`PAID` is not terminal, and that is the one real difference from a
 * shipment.** A delivered parcel is the end of the story; money that has arrived
 * can still go back. `REFUNDED` is the only state reachable from `PAID`, which
 * is what stops a replayed or out-of-order webhook walking a paid order back to
 * pending and re-opening a checkout that already succeeded.
 */
final class PaymentStatus
{
    /** Created at the provider; the customer has not paid yet. */
    public const PENDING = 'pending';

    /**
     * The money is confirmed — **by a server-side check, never by a client
     * callback** (docs/SECURITY.md, "Payments").
     */
    public const PAID = 'paid';

    /** The provider tried and failed: refused card, insufficient funds. */
    public const FAILED = 'failed';

    /** The checkout window elapsed before the customer paid. */
    public const EXPIRED = 'expired';

    /** Abandoned by the customer, or called off by the shop. */
    public const CANCELLED = 'cancelled';

    /** Paid, then returned. */
    public const REFUNDED = 'refunded';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::PAID,
        self::FAILED,
        self::EXPIRED,
        self::CANCELLED,
        self::REFUNDED,
    ];

    /**
     * Finished, with no money still in flight.
     *
     * `PAID` is absent on purpose — see the class docblock. A paid payment is
     * settled, not finished, because a refund is still ahead of it.
     *
     * @var list<string>
     */
    public const TERMINAL = [self::FAILED, self::EXPIRED, self::CANCELLED, self::REFUNDED];

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

    /** Still waiting on the customer or the provider. */
    public static function isOpen(string $status): bool
    {
        return self::normalize($status) === self::PENDING;
    }

    public static function isSettled(string $status): bool
    {
        return self::normalize($status) === self::PAID;
    }

    /**
     * Whether a state reported by a provider should be written down.
     *
     * Four refusals, each for a failure mode that has actually happened to
     * somebody:
     *
     *  - **An unknown state.** The adapter's mapping missed a case. Storing a
     *    word nothing can reason about is worse than keeping the last one we
     *    could; the provider's raw value survives in the report either way.
     *  - **No change.** Most verification polls find a payment exactly where it
     *    was, and a write plus an audit event for each of those is noise.
     *  - **A finished payment.** A replayed webhook must not re-open a
     *    cancelled or expired checkout (docs/SECURITY.md: duplicate delivery
     *    must never duplicate a payment or an order transition).
     *  - **Anything after `PAID` except a refund.** This is the one that matters
     *    for money. Providers do send late `pending` events, and webhooks do
     *    arrive out of order; without this rule one of them silently un-pays a
     *    settled order, and the shop ships nothing while holding the customer's
     *    money.
     */
    public static function accepts(string $current, string $reported): bool
    {
        $current = self::normalize($current);
        $reported = self::normalize($reported);

        if (!self::isKnown($reported) || $reported === $current) {
            return false;
        }

        if (self::isTerminal($current)) {
            return false;
        }

        if ($current === self::PAID) {
            return $reported === self::REFUNDED;
        }

        return true;
    }
}
