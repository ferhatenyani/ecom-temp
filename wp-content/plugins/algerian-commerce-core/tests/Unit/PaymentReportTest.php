<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Payments\PaymentReport;
use AlgerianCommerce\Payments\PaymentStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The server-side amount check — docs/SECURITY.md, "Payments".
 *
 * A provider reporting `paid` says money arrived; it does not say how much. This
 * is the comparison standing between a confirmed payment and a shop shipping a
 * 45,000 DZD order against a 45 DZD payment.
 */
final class PaymentReportTest extends TestCase
{
    public function testAnUnmappedStatusIsRefusedAtTheSeam(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Naming the adapter that got it wrong beats a blank column found weeks
        // later by a finance screen.
        new PaymentReport('authorized');
    }

    /** The same money written three ways — providers are not consistent. */
    public function testAmountsAreComparedNumericallyNotAsStrings(): void
    {
        foreach (['4500.00', '4500.0', '4500'] as $reported) {
            $report = new PaymentReport(PaymentStatus::PAID, 'paid', $reported, 'DZD');

            self::assertTrue($report->matches('4500.00', 'DZD'), "\"{$reported}\" should match 4500.00");
        }
    }

    public function testAShortPaymentDoesNotMatch(): void
    {
        $report = new PaymentReport(PaymentStatus::PAID, 'paid', '45.00', 'DZD');

        self::assertFalse($report->matches('45000.00', 'DZD'));
    }

    public function testACurrencyMismatchDoesNotMatch(): void
    {
        $report = new PaymentReport(PaymentStatus::PAID, 'paid', '4500.00', 'EUR');

        self::assertFalse($report->matches('4500.00', 'DZD'));
        // Case is a provider's whim, not a difference.
        self::assertTrue((new PaymentReport(PaymentStatus::PAID, 'paid', '4500.00', 'dzd'))->matches('4500.00', 'DZD'));
    }

    /**
     * An unstated amount is not a matching one.
     *
     * Reading '' as zero would compare equal to nothing and pass silently, which
     * is the worst available outcome for a check whose whole job is refusing.
     */
    public function testAReportWithNoAmountNeverMatches(): void
    {
        $report = new PaymentReport(PaymentStatus::PAID, 'paid');

        self::assertFalse($report->hasAmount());
        self::assertFalse($report->matches('0'));
        self::assertFalse($report->matches('4500.00', 'DZD'));
    }

    /** Sub-centime drift from a provider's float handling is not a mismatch. */
    public function testRoundingNoiseIsTolerated(): void
    {
        self::assertTrue((new PaymentReport(PaymentStatus::PAID, 'paid', '4500.001', 'DZD'))->matches('4500.00', 'DZD'));
        self::assertFalse((new PaymentReport(PaymentStatus::PAID, 'paid', '4500.01', 'DZD'))->matches('4500.00', 'DZD'));
    }
}
