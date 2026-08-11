<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\COD\CodState;
use AlgerianCommerce\COD\CodStatus;
use PHPUnit\Framework\TestCase;

final class CodStateTest extends TestCase
{
    private const NOW = '2026-08-12 09:15:00';
    private const LATER = '2026-08-12 14:40:00';

    public function testAnOrderWithNoCodMetaTakesItsAnswerFromThePaymentMethod(): void
    {
        $codOrder = CodState::fromMeta([], true);
        self::assertTrue($codOrder->enabled);
        self::assertSame(CodStatus::PENDING, $codOrder->status);
        self::assertSame(0, $codOrder->attempts);

        self::assertFalse(CodState::fromMeta([], false)->enabled);
    }

    /**
     * The explicit flag wins, so an operator can turn COD off for one order
     * without changing how it was paid.
     */
    public function testTheStoredFlagOverridesThePaymentMethod(): void
    {
        self::assertFalse(CodState::fromMeta([CodState::META_ENABLED => '0'], true)->enabled);
        self::assertTrue(CodState::fromMeta([CodState::META_ENABLED => '1'], false)->enabled);
    }

    /**
     * WordPress returns meta as strings, and the string '0' is truthy in PHP.
     * Reading it as true would silently re-enable COD on every order it had
     * been turned off for.
     */
    public function testTheStringZeroIsFalse(): void
    {
        self::assertFalse(CodState::fromMeta([CodState::META_ENABLED => '0'], true)->enabled);
        self::assertFalse(CodState::fromMeta([CodState::META_ENABLED => 'false'], true)->enabled);
        self::assertTrue(CodState::fromMeta([CodState::META_ENABLED => 'yes'], false)->enabled);
    }

    /**
     * An empty flag is "never written", not "false" — otherwise every order
     * placed before this module existed reads as non-COD.
     */
    public function testAnEmptyFlagFallsBackToThePaymentMethod(): void
    {
        self::assertTrue(CodState::fromMeta([CodState::META_ENABLED => ''], true)->enabled);
    }

    /**
     * A stored status outside the matrix reaches nothing, which would freeze
     * the order's confirmation with no way to notice.
     */
    public function testAnUnrecognisedStoredStatusFallsBackToPending(): void
    {
        $state = CodState::fromMeta([CodState::META_STATUS => 'delivered'], true);

        self::assertSame(CodStatus::PENDING, $state->status);
        self::assertNotSame([], $state->allowedOutcomes());
    }

    public function testRecordingAnOutcomeCountsTheAttemptAndStampsTheTime(): void
    {
        $state = CodState::fromMeta([], true)->record(CodStatus::UNREACHABLE, 'no answer', self::NOW);

        self::assertSame(CodStatus::UNREACHABLE, $state->status);
        self::assertSame(1, $state->attempts);
        self::assertSame(self::NOW, $state->lastAttemptAt);
        self::assertSame('no answer', $state->reason);
        self::assertSame('', $state->confirmedAt);
    }

    /** Two calls that both failed to connect are two attempts. */
    public function testRepeatedOutcomesKeepCounting(): void
    {
        $state = CodState::fromMeta([], true)
            ->record(CodStatus::UNREACHABLE, '', self::NOW)
            ->record(CodStatus::UNREACHABLE, '', self::LATER);

        self::assertSame(2, $state->attempts);
        self::assertSame(self::LATER, $state->lastAttemptAt);
    }

    public function testConfirmingStampsConfirmedAt(): void
    {
        $state = CodState::fromMeta([], true)->record(CodStatus::CONFIRMED, '', self::NOW);

        self::assertSame(CodStatus::CONFIRMED, $state->status);
        self::assertSame(self::NOW, $state->confirmedAt);
    }

    /**
     * A re-confirmation is the same customer saying yes to the same order.
     * Moving the timestamp forward would misreport when they agreed.
     */
    public function testReConfirmingKeepsTheOriginalConfirmationTime(): void
    {
        $state = CodState::fromMeta([], true)
            ->record(CodStatus::CONFIRMED, '', self::NOW)
            ->record(CodStatus::CONFIRMED, '', self::LATER);

        self::assertSame(self::NOW, $state->confirmedAt);
        self::assertSame(2, $state->attempts);
    }

    /** Nobody phoned anyone, so the attempt counter must not move. */
    public function testCancellingIsNotAnAttempt(): void
    {
        $state = CodState::fromMeta([], true)
            ->record(CodStatus::UNREACHABLE, '', self::NOW)
            ->cancel('customer moved abroad', self::LATER);

        self::assertSame(CodStatus::CANCELLED, $state->status);
        self::assertSame(1, $state->attempts);
        self::assertSame(self::LATER, $state->cancelledAt);
        self::assertSame(self::NOW, $state->lastAttemptAt);
        self::assertSame('customer moved abroad', $state->reason);
    }

    /**
     * The confirmation stamp survives a cancellation — that is what lets the
     * funnel still count an order that was confirmed and then called off.
     */
    public function testCancellingAConfirmedOrderKeepsTheConfirmation(): void
    {
        $state = CodState::fromMeta([], true)
            ->record(CodStatus::CONFIRMED, '', self::NOW)
            ->cancel('', self::LATER);

        self::assertSame(self::NOW, $state->confirmedAt);
        self::assertSame(self::LATER, $state->cancelledAt);
    }

    /** A cancellation with no reason must not wipe the one already recorded. */
    public function testCancellingWithoutAReasonKeepsTheLastOne(): void
    {
        $state = CodState::fromMeta([], true)
            ->record(CodStatus::UNREACHABLE, 'phone off', self::NOW)
            ->cancel('', self::LATER);

        self::assertSame('phone off', $state->reason);
    }

    public function testWithEnabledChangesNothingElse(): void
    {
        $before = CodState::fromMeta([], true)->record(CodStatus::CONFIRMED, 'yes', self::NOW);
        $after = $before->withEnabled(false);

        self::assertFalse($after->enabled);
        self::assertSame($before->status, $after->status);
        self::assertSame($before->attempts, $after->attempts);
        self::assertSame($before->confirmedAt, $after->confirmedAt);
        self::assertSame($before->reason, $after->reason);
    }

    /**
     * A partial write would leave a stale confirmed_at next to a status saying
     * the order was never confirmed.
     */
    public function testToMetaWritesEveryKeyEveryTime(): void
    {
        $meta = CodState::fromMeta([], true)->toMeta();

        self::assertSame(
            [
                CodState::META_ENABLED,
                CodState::META_STATUS,
                CodState::META_ATTEMPTS,
                CodState::META_CONFIRMED_AT,
                CodState::META_CANCELLED_AT,
                CodState::META_LAST_ATTEMPT_AT,
                CodState::META_REASON,
            ],
            array_keys($meta)
        );

        foreach ($meta as $key => $value) {
            self::assertIsString($value, "{$key} must be stored as a string");
        }
    }

    public function testMetaSurvivesARoundTrip(): void
    {
        $before = CodState::fromMeta([], true)
            ->record(CodStatus::CONFIRMED, 'confirmed by phone', self::NOW)
            ->cancel('out of stock', self::LATER);

        $after = CodState::fromMeta($before->toMeta(), false);

        self::assertEquals($before, $after);
    }

    public function testTheWireFormatPresentsTimestampsAsIso(): void
    {
        $wire = CodState::fromMeta([], true)->record(CodStatus::CONFIRMED, '', self::NOW)->toArray();

        self::assertSame('2026-08-12T09:15:00+00:00', $wire['confirmed_at']);
        self::assertSame('2026-08-12T09:15:00+00:00', $wire['last_attempt_at']);
        self::assertNull($wire['cancelled_at']);
    }

    public function testTheWireFormatOffersTheOutcomesThatWillWork(): void
    {
        $pending = CodState::fromMeta([], true)->toArray();
        self::assertSame(['confirmed', 'rejected', 'unreachable'], $pending['allowed_outcomes']);

        $confirmed = CodState::fromMeta([], true)->record(CodStatus::CONFIRMED, '', self::NOW)->toArray();
        self::assertSame(['confirmed'], $confirmed['allowed_outcomes']);

        $rejected = CodState::fromMeta([], true)->record(CodStatus::REJECTED, '', self::NOW)->toArray();
        self::assertSame([], $rejected['allowed_outcomes']);
    }

    /**
     * Every key CodSettingsInput drops as read-only must actually be emitted,
     * or a client's round trip is rejected for a field it was given.
     */
    public function testTheWireFormatIsWhatTheSettingsInputExpectsToRoundTrip(): void
    {
        $wire = CodState::fromMeta([], true)->toArray();

        self::assertSame(
            ['enabled', 'status', 'attempts', 'confirmed_at', 'cancelled_at',
                'last_attempt_at', 'reason', 'allowed_outcomes'],
            array_keys($wire)
        );
    }
}
