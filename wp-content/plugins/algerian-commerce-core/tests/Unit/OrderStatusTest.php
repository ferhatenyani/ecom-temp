<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Orders\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testNormalizeStripsTheStoragePrefix(): void
    {
        self::assertSame('processing', OrderStatus::normalize('wc-processing'));
        self::assertSame('on-hold', OrderStatus::normalize('wc-on-hold'));
        self::assertSame('completed', OrderStatus::normalize('COMPLETED'));
        self::assertSame('pending', OrderStatus::normalize('  pending '));
    }

    public function testNormalizeStripsOnlyALeadingPrefix(): void
    {
        // Not a status, but it must not lose characters from the middle.
        self::assertSame('order-wc-thing', OrderStatus::normalize('order-wc-thing'));
    }

    public function testIsKnownAcceptsBothSpellings(): void
    {
        self::assertTrue(OrderStatus::isKnown('cancelled'));
        self::assertTrue(OrderStatus::isKnown('wc-cancelled'));
        self::assertFalse(OrderStatus::isKnown('shipped'));
        self::assertFalse(OrderStatus::isKnown(''));
    }

    /**
     * checkout-draft is a real WooCommerce status that this API must not
     * accept: it is the storefront's internal half-finished cart.
     */
    public function testCheckoutDraftIsNotAWritableStatus(): void
    {
        self::assertFalse(OrderStatus::isKnown('checkout-draft'));
        self::assertNotContains('checkout-draft', OrderStatus::ALL);
    }

    public function testTerminalStatusesGoNowhere(): void
    {
        foreach (OrderStatus::TERMINAL as $status) {
            self::assertTrue(OrderStatus::isTerminal($status));
            self::assertSame([], OrderStatus::allowedFrom($status), "{$status} must be terminal");

            foreach (OrderStatus::ALL as $target) {
                if ($target === $status) {
                    continue;
                }

                self::assertFalse(
                    OrderStatus::canTransition($status, $target),
                    "{$status} must not reach {$target}"
                );
            }
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function allowedTransitionProvider(): array
    {
        return [
            'pending to processing' => ['pending', 'processing'],
            'pending to cancelled' => ['pending', 'cancelled'],
            'pending straight to completed' => ['pending', 'completed'],
            'processing to completed' => ['processing', 'completed'],
            'processing to refunded' => ['processing', 'refunded'],
            'on-hold back to pending' => ['on-hold', 'pending'],
            'completed to refunded' => ['completed', 'refunded'],
            'failed retried as pending' => ['failed', 'pending'],
            'prefixed spelling still resolves' => ['wc-pending', 'wc-processing'],
        ];
    }

    #[DataProvider('allowedTransitionProvider')]
    public function testAllowedTransitions(string $from, string $to): void
    {
        self::assertTrue(OrderStatus::canTransition($from, $to));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedTransitionProvider(): array
    {
        return [
            // The moves that would make the order book describe events that
            // never happened.
            'un-cancel' => ['cancelled', 'processing'],
            'un-refund' => ['refunded', 'completed'],
            'completed back to processing' => ['completed', 'processing'],
            'completed cancelled instead of refunded' => ['completed', 'cancelled'],
            // A refund implies money was taken; these statuses mean it was not.
            'refund an unpaid order' => ['pending', 'refunded'],
            'refund a failed order' => ['failed', 'refunded'],
            'refund an order on hold' => ['on-hold', 'refunded'],
            'unknown source' => ['shipped', 'processing'],
            'unknown target' => ['processing', 'delivered'],
        ];
    }

    #[DataProvider('refusedTransitionProvider')]
    public function testRefusedTransitions(string $from, string $to): void
    {
        self::assertFalse(OrderStatus::canTransition($from, $to));
    }

    /** A PATCH that echoes an order back unchanged must not fail. */
    public function testSettingTheStatusAnOrderAlreadyHasIsAllowed(): void
    {
        foreach (OrderStatus::ALL as $status) {
            self::assertTrue(
                OrderStatus::canTransition($status, $status),
                "{$status} must accept being re-set to itself"
            );
        }
    }

    /**
     * Creatable is not "reachable from pending".
     *
     * `pending → cancelled` is a legal transition, so deriving the create rule
     * from the matrix let an order be born cancelled — a cancellation of
     * something never placed. The REST suite caught it; this pins it.
     */
    public function testTerminalStatusesCannotBeCreatedEvenWhenReachableFromPending(): void
    {
        self::assertTrue(OrderStatus::canTransition('pending', 'cancelled'));
        self::assertFalse(OrderStatus::canCreateAs('cancelled'));
        self::assertFalse(OrderStatus::canCreateAs('refunded'));
    }

    public function testAnOrderMayBeCreatedInAnyNonTerminalStatus(): void
    {
        foreach (OrderStatus::ALL as $status) {
            self::assertSame(
                !in_array($status, OrderStatus::TERMINAL, true),
                OrderStatus::canCreateAs($status),
                "{$status} is on the wrong side of CREATABLE"
            );
        }
    }

    public function testCanCreateAsRejectsUnknownStatuses(): void
    {
        self::assertFalse(OrderStatus::canCreateAs('shipped'));
        self::assertFalse(OrderStatus::canCreateAs(''));
    }

    public function testEveryStatusHasATransitionRow(): void
    {
        // A status in ALL with no row would silently be terminal, which is a
        // policy nobody wrote down.
        foreach (OrderStatus::ALL as $status) {
            if (in_array($status, OrderStatus::TERMINAL, true)) {
                continue;
            }

            self::assertNotSame([], OrderStatus::allowedFrom($status), "{$status} has no transitions");
        }
    }

    public function testEveryTransitionTargetIsAKnownStatus(): void
    {
        foreach (OrderStatus::ALL as $status) {
            foreach (OrderStatus::allowedFrom($status) as $target) {
                self::assertContains($target, OrderStatus::ALL, "{$status} points at unknown {$target}");
                self::assertNotSame($status, $target, "{$status} lists itself as a transition");
            }
        }
    }
}
