<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

/**
 * Order statuses, and which moves between them this API allows.
 *
 * Pure — no WordPress — so the rule that decides whether an order may change
 * status is unit-testable on its own.
 *
 * The vocabulary is WooCommerce's, unchanged. docs/PLAN.md §8 lists
 * operational states ("COD Pending Confirmation", "Shipped", "Delivered") and
 * then says not to create redundant statuses when metadata or events will do —
 * those are COD and shipping concerns (roadmap §52, §53) and will be recorded
 * as metadata against these statuses, not as new ones.
 *
 * WooCommerce itself permits any status to become any other: `set_status()`
 * validates the *name*, never the move. That is right for a payment gateway
 * replaying a callback and wrong for an admin API, where the interesting
 * mistakes are the ones that unwind a finished order — reopening a refund,
 * un-cancelling a cancelled order — leaving the stock ledger, the COD funnel
 * and the revenue reports describing a sequence of events that never happened.
 */
final class OrderStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const ON_HOLD = 'on-hold';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const FAILED = 'failed';

    /**
     * Every status this API accepts on a write.
     *
     * Deliberately not `wc_get_order_statuses()`: that is filterable, so a
     * third-party plugin could widen what our API accepts without anyone
     * deciding to. It also contains `checkout-draft`, a storefront-internal
     * state that no admin should be able to put an order into.
     *
     * @var list<string>
     */
    public const ALL = [
        self::PENDING,
        self::PROCESSING,
        self::ON_HOLD,
        self::COMPLETED,
        self::CANCELLED,
        self::REFUNDED,
        self::FAILED,
    ];

    /**
     * Statuses with no way out.
     *
     * A cancelled order is not revived, and a refunded one is not un-refunded:
     * the correct action is a new order. Both have already released or returned
     * stock and both are counted as closed by anything that reports on the
     * order book.
     *
     * @var list<string>
     */
    public const TERMINAL = [self::CANCELLED, self::REFUNDED];

    /**
     * Statuses a brand-new order may be created in.
     *
     * Not the same question as "what can pending become", which is why it is
     * not derived from the matrix. `pending → cancelled` is a legal *move* — an
     * order is placed and then called off — but creating an order that is
     * already cancelled records a cancellation of something that never
     * happened. Same for refunded, which additionally claims money changed
     * hands. Everything else is a real starting point: an order taken over the
     * phone begins as processing, and a walk-in sale recorded after the fact
     * begins as completed.
     *
     * @var list<string>
     */
    public const CREATABLE = [
        self::PENDING,
        self::PROCESSING,
        self::ON_HOLD,
        self::COMPLETED,
        self::FAILED,
    ];

    /**
     * The transition matrix.
     *
     * `refunded` is reachable only from `processing` and `completed`, the two
     * statuses that imply money was taken. Refunding an order that was never
     * paid is not a refund, it is a cancellation, and conflating the two
     * corrupts every revenue figure derived from the order book.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::PENDING => [self::PROCESSING, self::ON_HOLD, self::COMPLETED, self::CANCELLED, self::FAILED],
        self::PROCESSING => [self::ON_HOLD, self::COMPLETED, self::CANCELLED, self::REFUNDED, self::FAILED],
        self::ON_HOLD => [self::PENDING, self::PROCESSING, self::COMPLETED, self::CANCELLED, self::FAILED],
        self::COMPLETED => [self::REFUNDED],
        self::FAILED => [self::PENDING, self::PROCESSING, self::ON_HOLD, self::CANCELLED],
        self::CANCELLED => [],
        self::REFUNDED => [],
    ];

    /**
     * Strip WooCommerce's storage prefix.
     *
     * Statuses are stored as `wc-processing` and read back as `processing`;
     * `WC_Order::get_status()` already strips it, but a value arriving from a
     * request, a filter or a database row may carry it. Normalizing on the way
     * in means the matrix is keyed one way only.
     */
    public static function normalize(string $status): string
    {
        $status = strtolower(trim($status));

        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }

    public static function isKnown(string $status): bool
    {
        return in_array(self::normalize($status), self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(self::normalize($status), self::TERMINAL, true);
    }

    public static function canCreateAs(string $status): bool
    {
        return in_array(self::normalize($status), self::CREATABLE, true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[self::normalize($status)] ?? [];
    }

    /**
     * Re-setting the status an order already has is allowed and does nothing.
     *
     * A PATCH that echoes an order back unchanged must not fail merely because
     * it included the current status, and callers that retry a request should
     * not be punished for the second attempt.
     */
    public static function canTransition(string $from, string $to): bool
    {
        $from = self::normalize($from);
        $to = self::normalize($to);

        if (!self::isKnown($from) || !self::isKnown($to)) {
            return false;
        }

        if ($from === $to) {
            return true;
        }

        return in_array($to, self::allowedFrom($from), true);
    }
}
