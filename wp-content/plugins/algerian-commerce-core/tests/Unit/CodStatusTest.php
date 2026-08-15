<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\COD\CodStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodStatusTest extends TestCase
{
    public function testNormalizeIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('confirmed', CodStatus::normalize('  CONFIRMED '));
        self::assertSame('unreachable', CodStatus::normalize('Unreachable'));
    }

    /**
     * COD states are not order statuses and carry no `wc-` prefix. If one ever
     * arrives, it must not be silently accepted as a COD state.
     */
    public function testOrderStatusSpellingIsNotACodStatus(): void
    {
        self::assertFalse(CodStatus::isKnown('wc-cancelled'));
        self::assertFalse(CodStatus::isKnown('processing'));
        self::assertFalse(CodStatus::isKnown(''));
    }

    public function testOutcomesAreTheSubsetACallCanConcludeWith(): void
    {
        self::assertTrue(CodStatus::isOutcome('confirmed'));
        self::assertTrue(CodStatus::isOutcome('rejected'));
        self::assertTrue(CodStatus::isOutcome('unreachable'));

        // Where an order starts, and what happens to the order rather than on
        // the phone — neither is something a call concludes with.
        self::assertFalse(CodStatus::isOutcome('pending'));
        self::assertFalse(CodStatus::isOutcome('cancelled'));
    }

    public function testEveryOutcomeIsAKnownStatus(): void
    {
        foreach (CodStatus::OUTCOMES as $outcome) {
            self::assertContains($outcome, CodStatus::ALL);
        }
    }

    public function testTerminalStatesGoNowhere(): void
    {
        foreach (CodStatus::TERMINAL as $status) {
            self::assertTrue(CodStatus::isTerminal($status));
            self::assertSame([], CodStatus::allowedFrom($status), "{$status} must be terminal");

            foreach (CodStatus::ALL as $target) {
                self::assertFalse(
                    CodStatus::canTransition($status, $target),
                    "{$status} must not reach {$target}"
                );
            }
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function allowedProvider(): array
    {
        return [
            'first call confirms' => ['pending', 'confirmed'],
            'first call is refused' => ['pending', 'rejected'],
            'first call does not connect' => ['pending', 'unreachable'],
            'order cancelled before any call' => ['pending', 'cancelled'],
            'call back and connect' => ['unreachable', 'confirmed'],
            // The normal case for an Algerian COD shop: a customer who does not
            // answer is called again, and the second failure is a second
            // attempt, not an illegal move.
            'call back and fail again' => ['unreachable', 'unreachable'],
            'confirmed order called off later' => ['confirmed', 'cancelled'],
            're-confirming is a harmless retry' => ['confirmed', 'confirmed'],
            'case insensitive' => ['PENDING', 'Confirmed'],
        ];
    }

    #[DataProvider('allowedProvider')]
    public function testAllowedTransitions(string $from, string $to): void
    {
        self::assertTrue(CodStatus::canTransition($from, $to));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedProvider(): array
    {
        return [
            // A customer who said yes and then changed their mind has
            // cancelled. Recording it as a rejection would make the
            // confirmation rate count one event two different ways.
            'confirmed cannot become rejected' => ['confirmed', 'rejected'],
            'confirmed cannot become unreachable' => ['confirmed', 'unreachable'],
            'a rejection is not reconsidered' => ['rejected', 'confirmed'],
            'a cancellation is not revived' => ['cancelled', 'confirmed'],
            'nothing returns to pending' => ['confirmed', 'pending'],
            'unreachable does not return to pending' => ['unreachable', 'pending'],
            'unknown source' => ['delivered', 'confirmed'],
            'unknown target' => ['pending', 'delivered'],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testRefusedTransitions(string $from, string $to): void
    {
        self::assertFalse(CodStatus::canTransition($from, $to));
    }

    /**
     * Unlike an order status, a COD state has no blanket "may always be re-set
     * to itself" rule: recording an outcome increments the attempt counter, so
     * a self-transition is a second phone call and has to be decided one state
     * at a time.
     */
    public function testSelfTransitionsAreDecidedIndividually(): void
    {
        self::assertTrue(CodStatus::canTransition('unreachable', 'unreachable'));
        self::assertTrue(CodStatus::canTransition('confirmed', 'confirmed'));
        self::assertFalse(CodStatus::canTransition('pending', 'pending'));
        self::assertFalse(CodStatus::canTransition('rejected', 'rejected'));
    }

    public function testEveryTransitionTargetIsAKnownStatus(): void
    {
        foreach (CodStatus::ALL as $status) {
            foreach (CodStatus::allowedFrom($status) as $target) {
                self::assertContains($target, CodStatus::ALL, "{$status} points at unknown {$target}");
            }
        }
    }

    /**
     * Every non-terminal state must be able to reach a terminal one, or an
     * order can enter a state it can never leave without anyone deciding that.
     */
    public function testEveryNonTerminalStateCanBeClosed(): void
    {
        foreach (CodStatus::ALL as $status) {
            if (CodStatus::isTerminal($status)) {
                continue;
            }

            self::assertNotSame(
                [],
                array_intersect(CodStatus::allowedFrom($status), CodStatus::TERMINAL),
                "{$status} cannot be closed"
            );
        }
    }
}
