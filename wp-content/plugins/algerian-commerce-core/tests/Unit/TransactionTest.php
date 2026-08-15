<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Payments\PaymentReport;
use AlgerianCommerce\Payments\PaymentResult;
use AlgerianCommerce\Payments\PaymentStatus;
use AlgerianCommerce\Payments\Transaction;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * One row of `ac_payment_transactions` — docs/PLAN.md §19, roadmap §59.
 *
 * The rules worth pinning are the ones about money surviving a round trip
 * through the database, and about a row never quietly changing whose order it
 * belongs to.
 */
final class TransactionTest extends TestCase
{
    private function transaction(string $status = PaymentStatus::PENDING): Transaction
    {
        return new Transaction(42, 'chargily', 'chk_1', '42-1', '4500.00', 'DZD', $status, ['a' => 1], '2026-08-15 10:00:00', '2026-08-15 10:00:00', 7);
    }

    public function testATransactionNeedsAnOrderAndAProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Transaction(0, 'chargily');
    }

    public function testAnUnknownStatusIsRefusedAtTheSeam(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Transaction(42, 'chargily', '', '', '0.00', 'DZD', 'authorized');
    }

    /** Amounts are normalised so the same money is written one way. */
    public function testTheAmountIsStoredAsATwoDecimalString(): void
    {
        self::assertSame('4500.00', (new Transaction(42, 'chargily', '', '', '4500'))->amount);
        self::assertSame('4500.50', (new Transaction(42, 'chargily', '', '', '4500.5'))->amount);
        self::assertSame('0.00', (new Transaction(42, 'chargily', '', '', ''))->amount);
    }

    public function testCurrencyIsUpperCased(): void
    {
        self::assertSame('DZD', (new Transaction(42, 'chargily', '', '', '1', 'dzd'))->currency);
    }

    /** Column widths, so a long gateway reference cannot fail the write. */
    public function testOverLongValuesAreTruncatedRatherThanRejected(): void
    {
        $transaction = new Transaction(42, str_repeat('p', 60), str_repeat('x', 200), str_repeat('r', 200));

        self::assertSame(Transaction::MAX_PROVIDER, mb_strlen($transaction->provider));
        self::assertSame(Transaction::MAX_PROVIDER_TRANSACTION_ID, mb_strlen($transaction->providerTransactionId));
        self::assertSame(Transaction::MAX_REFERENCE, mb_strlen($transaction->reference));
    }

    public function testItRoundTripsThroughARow(): void
    {
        $transaction = $this->transaction();
        $back = Transaction::fromRow($transaction->toRow() + ['id' => 7]);

        self::assertSame($transaction->toArray(), $back->toArray());
    }

    public function testTheProviderResultFillsInTheGatewaysIdentifier(): void
    {
        $opened = new Transaction(42, 'chargily', '', '42-1', '4500.00', 'DZD');
        $stored = $opened->withProviderResult(
            new PaymentResult('chk_9', PaymentStatus::PENDING, 'https://pay.example/x', ['livemode' => false]),
            '2026-08-15 11:00:00'
        );

        self::assertSame('chk_9', $stored->providerTransactionId);
        self::assertFalse($stored->metadata['livemode']);
        self::assertSame('2026-08-15 11:00:00', $stored->updatedAt);
    }

    /**
     * What the order was for is not rewritten by what a gateway says it took —
     * the disagreement is what `PaymentService` refuses on.
     */
    public function testAReportNeverOverwritesTheAmountTheRowWasOpenedWith(): void
    {
        $updated = $this->transaction()->withReport(
            new PaymentReport(PaymentStatus::PAID, 'paid', '45.00', 'DZD'),
            '2026-08-15 12:00:00'
        );

        self::assertSame('4500.00', $updated->amount);
        self::assertSame('45.00', $updated->metadata['reported_amount']);
        self::assertSame('paid', $updated->metadata['provider_status']);
    }

    public function testTheWireShapeNeverCarriesACheckoutUrl(): void
    {
        $wire = $this->transaction()->toArray();

        self::assertArrayNotHasKey('checkout_url', $wire);
        self::assertSame('2026-08-15T10:00:00Z', $wire['created_at']);
    }

    public function testOpenAndSettledReadFromTheStatusVocabulary(): void
    {
        self::assertTrue($this->transaction()->isOpen());
        self::assertFalse($this->transaction()->isSettled());
        self::assertTrue($this->transaction(PaymentStatus::PAID)->isSettled());
        self::assertFalse($this->transaction(PaymentStatus::EXPIRED)->isOpen());
    }
}
