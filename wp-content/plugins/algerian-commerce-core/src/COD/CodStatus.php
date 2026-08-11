<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

/**
 * The cash-on-delivery confirmation states, and which moves between them this
 * API allows — roadmap §52, docs/PLAN.md §12.
 *
 * Pure — no WordPress — so the rule that decides whether a confirmation outcome
 * may be recorded is unit-testable on its own.
 *
 * These are **not** order statuses and never become any. PLAN.md §8 lists "COD
 * Pending Confirmation" and "COD Confirmed" among the operational states and
 * then says not to create redundant statuses when metadata will do; an order
 * carries WooCommerce's status and, alongside it, this one. The two answer
 * different questions — *where is this order in the shop's workflow* versus
 * *has the customer said yes on the phone* — and a shop that ships on COD needs
 * both at once.
 *
 * Every legal move is listed, including the ones that stay put. There is no
 * blanket "a state may always be re-set to itself" rule as there is for order
 * statuses, because an outcome here is an *event*: recording one increments the
 * attempt counter, so `unreachable → unreachable` is a second failed phone call
 * and has to be legal, while `rejected → rejected` would be a call to a
 * customer this shop has already stopped calling.
 */
final class CodStatus
{
    /** Awaiting the confirmation call. Where every COD order starts. */
    public const PENDING = 'pending';

    /** The customer confirmed the order on the phone. */
    public const CONFIRMED = 'confirmed';

    /** The customer refused the order at confirmation time. */
    public const REJECTED = 'rejected';

    /** The call did not connect. Not a refusal — it is retried. */
    public const UNREACHABLE = 'unreachable';

    /** Called off after the fact, including by cancelling the order itself. */
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::CONFIRMED,
        self::REJECTED,
        self::UNREACHABLE,
        self::CANCELLED,
    ];

    /**
     * Outcomes a confirmation call can be recorded with.
     *
     * `pending` is absent because it is where an order begins, not something a
     * call concludes. `cancelled` is absent because a cancellation is not a
     * call outcome: it is what happens to the *order*, and it reaches this
     * state through CodSubscriber when the order is cancelled.
     *
     * @var list<string>
     */
    public const OUTCOMES = [self::CONFIRMED, self::REJECTED, self::UNREACHABLE];

    /**
     * States with no way out.
     *
     * A rejected COD order is not re-confirmed and a cancelled one is not
     * revived — the correct action is a new order, exactly as it is for a
     * cancelled order status.
     *
     * @var list<string>
     */
    public const TERMINAL = [self::REJECTED, self::CANCELLED];

    /**
     * The transition matrix.
     *
     * `confirmed → rejected` is deliberately absent while `confirmed →
     * cancelled` is present. A customer who says yes and later changes their
     * mind has cancelled; a rejection is a refusal *at* confirmation. Folding
     * the two together would make the confirmation rate — the number this whole
     * module exists to produce — count the same event two different ways.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::REJECTED, self::UNREACHABLE, self::CANCELLED],
        // A call that did not connect is retried, so it may land on itself.
        self::UNREACHABLE => [self::CONFIRMED, self::REJECTED, self::UNREACHABLE, self::CANCELLED],
        // Re-confirming is allowed and changes nothing but the attempt count:
        // a client that retries a request must not be punished for it.
        self::CONFIRMED => [self::CONFIRMED, self::CANCELLED],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    public static function normalize(string $status): string
    {
        return strtolower(trim($status));
    }

    public static function isKnown(string $status): bool
    {
        return in_array(self::normalize($status), self::ALL, true);
    }

    public static function isOutcome(string $status): bool
    {
        return in_array(self::normalize($status), self::OUTCOMES, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(self::normalize($status), self::TERMINAL, true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[self::normalize($status)] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array(self::normalize($to), self::allowedFrom($from), true);
    }
}
