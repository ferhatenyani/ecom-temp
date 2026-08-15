<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\PaymentMethod;

/**
 * Cash on delivery — the driver collects at the door.
 *
 * Not a placeholder, and not a stub. COD is how the large majority of Algerian
 * e-commerce is actually paid for, so this is the payment method a real client
 * uses; Chargily is the one some of them add. It also means the abstraction
 * ships with a working implementation rather than none, exactly as
 * `Shipping\ManualProvider` did for couriers — the seam is proven before §59
 * puts a network call behind it.
 *
 * Pure by construction: no HTTP, no credentials, no configuration. There is
 * nothing to authenticate against, because the "provider" is a driver holding
 * banknotes.
 *
 * **This is the module CLAUDE.md was pointing at.** `COD/` owns the confirmation
 * queue — call the customer, record the attempt, decide whether to dispatch —
 * and deliberately does not read `ENABLE_COD`, because that flag gates *what
 * checkout offers*, which is this section. The two do not overlap and must not:
 * `COD/` is about a phone call before dispatch, this is about how an order is
 * paid for. Nothing here reads or writes COD state, and `COD/` knows nothing
 * about `Payments/`.
 *
 * Money moves at the door, so nothing here can confirm it. `verifyPayment()`
 * answers `pending` forever, and it is the delivery — a shipment reaching
 * `delivered`, reconciled against what the courier says it collected — that
 * settles a COD order. Wiring that reconciliation is not this section's job;
 * §56 already records `amount_collected` and `amount_due` from Yalidine against
 * the day it is.
 */
final class CashOnDeliveryProvider implements PaymentProviderInterface
{
    public const NAME = PaymentMethod::COD;

    /** Ours, and not pretending to be a provider's — nobody issued it but us. */
    private const REFERENCE_PREFIX = 'COD';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Cash on delivery';
    }

    /**
     * Accepted immediately, because there is nobody to ask.
     *
     * `pending` rather than `paid`, and the distinction is the entire point: the
     * order is placed, the money is not here, and it will not be until a driver
     * hands it over. A COD provider that reported `paid` at checkout would mark
     * every unpaid order as settled and make the finance figures fiction.
     *
     * No `checkoutUrl` — there is nowhere to send the shopper. `PaymentResult`
     * treats an empty one as a real answer rather than a missing field.
     */
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        // The reference, not the order id: an order paid for twice — a first
        // attempt refused at the door, a second delivery arranged — would
        // otherwise reuse the first attempt's identifier.
        $id = sprintf(
            '%s-%s',
            self::REFERENCE_PREFIX,
            $request->reference !== '' ? $request->reference : (string) $request->orderId
        );

        return new PaymentResult($id, PaymentStatus::PENDING, '', [
            'amount' => $request->amount,
            'currency' => $request->currency,
            'collect_on_delivery' => true,
        ]);
    }

    /**
     * Always pending, and honestly so.
     *
     * There is no provider to ask. Answering anything else would be inventing
     * a fact about money, which is the one thing this layer must never do.
     */
    public function verifyPayment(string $providerPaymentId): PaymentReport
    {
        return new PaymentReport(
            PaymentStatus::PENDING,
            'awaiting_delivery',
            '',
            '',
            ['collect_on_delivery' => true]
        );
    }

    /**
     * Nobody sends us COD webhooks.
     *
     * Throwing rather than returning an empty result, and mirroring how
     * `ManualProvider::getShipmentStatus()` refuses a poll it cannot answer: a
     * request arriving here means something is misrouted, and quietly returning
     * "nothing to do" would hide that for as long as it took someone to notice
     * payments were not being recorded.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): WebhookResult
    {
        throw new ApiException(
            'webhook_unsupported',
            'Cash on delivery does not receive webhooks.',
            400,
            ['provider' => self::NAME]
        );
    }
}
