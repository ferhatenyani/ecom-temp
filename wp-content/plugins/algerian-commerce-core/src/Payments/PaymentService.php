<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Order;

/**
 * Taking money, without knowing who from — roadmap §58, docs/PLAN.md §17.
 *
 * The one place that turns an order into a `PaymentRequest` and hands it to
 * whichever provider this shop uses. Adapters never see a `WC_Order`; this class
 * is the reason they do not have to (docs/ARCHITECTURE.md §4).
 *
 * The dependency runs one way, as `Shipping\ShippingService`'s does: `Payments/`
 * reads `Orders/`, because a payment is *for* an order, and nothing in `Orders/`
 * knows this module exists.
 *
 * ## What this section deliberately does not do
 *
 * **There is no transaction storage here, and no REST controller.** docs/PLAN.md
 * §19 owns the `payment_transactions` record — internal id, order id, provider,
 * provider transaction id, amount, currency, status, timestamps, metadata — and
 * roadmap §59 lands it alongside Chargily, which is the first provider with
 * anything worth storing. Building the table now would mean designing its
 * columns against an imagined provider rather than a real one, which is exactly
 * how §56 says not to write an integration.
 *
 * So this service resolves providers, builds requests, delegates, and audits.
 * `verifyPayment()` performs the amount re-check that docs/SECURITY.md requires
 * but cannot yet *persist* the outcome, and says so where it returns. That is
 * the honest shape of one phase, not an oversight — CLAUDE.md's "implement one
 * phase at a time, do not build ahead".
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * What this shop can take money with, in preference order.
     *
     * The list is built in `Plugin::paymentProviders()` from feature flags and
     * credentials, so a method a client has not configured never appears — and
     * therefore can never be chosen at checkout. This is the gate `ENABLE_COD`
     * and `ENABLE_CHARGILY` actually operate.
     *
     * @return list<array{name: string, label: string, is_default: bool}>
     */
    public function availableMethods(): array
    {
        return $this->providers->describe();
    }

    /**
     * Start a payment for an order.
     *
     * The provider is called last, after everything that can be refused has been
     * refused — the rule §53 arrived at for couriers and for the same reason: an
     * error raised *after* a provider has created a checkout leaves a payment
     * this system has no record of, and a customer who may be looking at a
     * payment page for it.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ApiException
     */
    public function createPayment(int $orderId, array $payload): PaymentResult
    {
        Permissions::assert(Capabilities::MANAGE_PAYMENTS);

        $order = $this->requireOrder($orderId);
        $input = PaymentInput::fromPayload($payload);
        $provider = $this->providers->get($input->provider);

        $this->guardOrderIsPayable($order);

        $result = $provider->createPayment($this->buildRequest($order, $input));

        $this->audit->record('payment.created', 'order', $orderId, [
            'provider' => $provider->name(),
            'provider_payment_id' => $result->providerPaymentId,
            'status' => $result->status,
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            // The checkout URL is not recorded. It is a one-time link to a
            // payment page for this customer's order, which is the same class
            // of thing as a courier's label URL (docs/SECURITY.md).
        ]);

        return $result;
    }

    /**
     * Ask the provider what really happened, and check it against the order.
     *
     * This is the server-side confirmation docs/SECURITY.md demands. A client
     * callback, a return URL query parameter and a customer saying "it went
     * through" are all worth exactly nothing here.
     *
     * **The amount check is the point, not a formality.** A provider reporting
     * `paid` says money arrived; it does not say *how much*, and an order
     * marked paid against a smaller sum is a shop shipping goods it was not paid
     * for. A mismatch is refused loudly and audited, because it is either an
     * attack or a bug and both need a human.
     *
     * Recording the outcome as a transaction row is docs/PLAN.md §19, with
     * roadmap §59 — see the class docblock.
     *
     * @throws ApiException
     */
    public function verifyPayment(int $orderId, string $providerName, string $providerPaymentId): PaymentReport
    {
        Permissions::assert(Capabilities::MANAGE_PAYMENTS);

        $order = $this->requireOrder($orderId);
        $provider = $this->providers->get($providerName);

        if (trim($providerPaymentId) === '') {
            throw ApiException::invalidRequest('The payment data is invalid.', [
                'fields' => ['provider_payment_id' => 'A provider payment id is required.'],
            ]);
        }

        $report = $provider->verifyPayment(trim($providerPaymentId));

        if (PaymentStatus::isSettled($report->status)
            && !$report->matches((string) $order->get_total(), (string) $order->get_currency())
        ) {
            $this->audit->record('payment.amount_mismatch', 'order', $orderId, [
                'provider' => $provider->name(),
                'provider_payment_id' => $providerPaymentId,
                'reported_amount' => $report->amount,
                'reported_currency' => $report->currency,
                'order_total' => $order->get_total(),
                'order_currency' => $order->get_currency(),
            ]);

            throw new ApiException(
                'payment_amount_mismatch',
                'The provider reported a payment that does not match this order.',
                409,
                ['provider' => $provider->name()]
            );
        }

        return $report;
    }

    /**
     * Everything the provider needs, and nothing WooCommerce.
     *
     * The billing contact rather than the shipping one: a payment provider is
     * verifying who is paying, not where goods go.
     */
    private function buildRequest(WC_Order $order, PaymentInput $input): PaymentRequest
    {
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        return new PaymentRequest(
            $order->get_id(),
            (string) $order->get_total(),
            (string) $order->get_currency(),
            $input->reference !== '' ? $input->reference : (string) $order->get_id(),
            $input->description,
            $name,
            (string) $order->get_billing_email(),
            (string) $order->get_billing_phone(),
            $input->returnUrl
        );
    }

    /** @throws ApiException */
    private function requireOrder(int $orderId): WC_Order
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            throw ApiException::notFound('No order with that id.');
        }

        return $order;
    }

    /**
     * A cancelled or refunded order is not something to start taking money for.
     *
     * The same guard shipping applies before handing a parcel over, and the same
     * reasoning: the states that mean "this order is over" are the ones where a
     * new payment page would be a customer paying for nothing.
     *
     * @throws ApiException
     */
    private function guardOrderIsPayable(WC_Order $order): void
    {
        if (!OrderStatus::isTerminal($order->get_status())) {
            return;
        }

        throw ApiException::conflict('An order in this status cannot be paid for.', [
            'order_status' => $order->get_status(),
        ]);
    }
}
