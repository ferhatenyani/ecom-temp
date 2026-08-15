<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Payments\CashOnDeliveryProvider;
use AlgerianCommerce\Payments\PaymentProviderRegistry;
use AlgerianCommerce\Payments\PaymentRequest;
use AlgerianCommerce\Payments\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Provider selection, and the one implementation §58 ships with.
 *
 * `CashOnDeliveryProvider` is to payments what `ManualProvider` is to shipping:
 * a real method a real Algerian shop uses, which also means the whole seam is
 * exercisable before §59 puts a network call behind it.
 */
final class PaymentProviderRegistryTest extends TestCase
{
    public function testAShopWithNoConfiguredMethodCannotTakeMoney(): void
    {
        $registry = new PaymentProviderRegistry();

        self::assertTrue($registry->isEmpty());

        try {
            $registry->get();
            self::fail('An empty registry must refuse.');
        } catch (ApiException $exception) {
            // 409, not 400: nothing is wrong with the request, the shop simply
            // is not set up to take money yet.
            self::assertSame('no_payment_provider', $exception->errorCode());
            self::assertSame(409, $exception->statusCode());
        }
    }

    public function testTheFirstRegisteredProviderIsTheDefault(): void
    {
        $registry = new PaymentProviderRegistry([new CashOnDeliveryProvider()]);

        self::assertSame(CashOnDeliveryProvider::NAME, $registry->defaultName());
        self::assertSame(CashOnDeliveryProvider::NAME, $registry->get()->name());
        self::assertSame([['name' => 'cod', 'label' => 'Cash on delivery', 'is_default' => true]], $registry->describe());
    }

    public function testAnUnknownMethodIsRefusedAndTheAvailableOnesAreNamed(): void
    {
        $registry = new PaymentProviderRegistry([new CashOnDeliveryProvider()]);

        try {
            $registry->get('stripe');
            self::fail('An unknown provider must be refused.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_request', $exception->errorCode());
            // So a checkout can correct itself rather than guess.
            self::assertSame(['cod'], $exception->details()['available']);
        }
    }

    /**
     * The order is placed; the money is not here. A COD provider reporting
     * `paid` at checkout would mark every unpaid order settled.
     */
    public function testCashOnDeliveryStartsPendingAndNeedsNoRedirect(): void
    {
        $result = (new CashOnDeliveryProvider())->createPayment(
            new PaymentRequest(42, '4500.00', 'DZD', '42-2')
        );

        self::assertSame(PaymentStatus::PENDING, $result->status);
        self::assertFalse($result->needsRedirect());
        // The reference, not the order id: a second delivery attempt must not
        // reuse the first attempt's identifier.
        self::assertSame('COD-42-2', $result->providerPaymentId);
    }

    public function testCashOnDeliveryStaysPendingBecauseThereIsNobodyToAsk(): void
    {
        $report = (new CashOnDeliveryProvider())->verifyPayment('COD-42-2');

        self::assertSame(PaymentStatus::PENDING, $report->status);
        // No amount stated, so an amount check cannot pass by accident.
        self::assertFalse($report->hasAmount());
    }

    /** A COD webhook means something is misrouted; saying so beats silence. */
    public function testCashOnDeliveryRefusesWebhooks(): void
    {
        $this->expectException(ApiException::class);

        (new CashOnDeliveryProvider())->handleWebhook([], [], '');
    }
}
