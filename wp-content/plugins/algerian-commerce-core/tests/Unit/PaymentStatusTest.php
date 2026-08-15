<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Payments\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The payment vocabulary, and the transition rule that protects money.
 *
 * The case that matters most is `paid` → anything-but-refunded. Providers do
 * send late `pending` events and webhooks do arrive out of order; without the
 * rule, one of them silently un-pays a settled order and the shop holds the
 * customer's money while shipping nothing.
 */
final class PaymentStatusTest extends TestCase
{
    public function testTheVocabularyIsClosed(): void
    {
        self::assertTrue(PaymentStatus::isKnown(PaymentStatus::PAID));
        self::assertFalse(PaymentStatus::isKnown('authorized'));
        self::assertFalse(PaymentStatus::isKnown(''));
    }

    /** Providers write states in their own case and punctuation. */
    public function testStatusesAreNormalisedBeforeComparison(): void
    {
        self::assertTrue(PaymentStatus::isKnown('  PAID  '));
        self::assertSame(PaymentStatus::PAID, PaymentStatus::normalize(' Paid '));
    }

    /**
     * A delivered parcel is the end of the story; money that arrived can still
     * go back, so `paid` is settled rather than finished.
     */
    public function testPaidIsSettledButNotTerminal(): void
    {
        self::assertTrue(PaymentStatus::isSettled(PaymentStatus::PAID));
        self::assertFalse(PaymentStatus::isTerminal(PaymentStatus::PAID));
        self::assertTrue(PaymentStatus::isTerminal(PaymentStatus::REFUNDED));
        self::assertTrue(PaymentStatus::isTerminal(PaymentStatus::EXPIRED));
    }

    public function testARefundIsTheOnlyThingThatFollowsAPayment(): void
    {
        self::assertTrue(PaymentStatus::accepts(PaymentStatus::PAID, PaymentStatus::REFUNDED));

        foreach ([PaymentStatus::PENDING, PaymentStatus::FAILED, PaymentStatus::EXPIRED, PaymentStatus::CANCELLED] as $reported) {
            self::assertFalse(
                PaymentStatus::accepts(PaymentStatus::PAID, $reported),
                "a paid payment must not be walked back to \"{$reported}\""
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function terminalProvider(): array
    {
        return [
            'failed' => [PaymentStatus::FAILED],
            'expired' => [PaymentStatus::EXPIRED],
            'cancelled' => [PaymentStatus::CANCELLED],
            'refunded' => [PaymentStatus::REFUNDED],
        ];
    }

    /** A replayed webhook must not re-open a checkout that finished. */
    #[DataProvider('terminalProvider')]
    public function testAFinishedPaymentStaysFinished(string $terminal): void
    {
        foreach (PaymentStatus::ALL as $reported) {
            self::assertFalse(
                PaymentStatus::accepts($terminal, $reported),
                "\"{$terminal}\" must not move to \"{$reported}\""
            );
        }
    }

    public function testAPendingPaymentMayGoAnywhere(): void
    {
        self::assertTrue(PaymentStatus::accepts(PaymentStatus::PENDING, PaymentStatus::PAID));
        self::assertTrue(PaymentStatus::accepts(PaymentStatus::PENDING, PaymentStatus::FAILED));
        self::assertTrue(PaymentStatus::accepts(PaymentStatus::PENDING, PaymentStatus::EXPIRED));
    }

    public function testNoChangeAndUnknownStatesAreRefused(): void
    {
        // Saves a write and an audit event on every poll that finds nothing new.
        self::assertFalse(PaymentStatus::accepts(PaymentStatus::PENDING, PaymentStatus::PENDING));
        self::assertFalse(PaymentStatus::accepts(PaymentStatus::PENDING, 'authorized'));
    }
}
