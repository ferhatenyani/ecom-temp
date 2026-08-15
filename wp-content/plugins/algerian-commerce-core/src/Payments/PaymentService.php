<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Commerce\WebhookEventRepository;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Order;

/**
 * Taking money, without knowing who from — roadmap §58 and §59, docs/PLAN.md
 * §17–§19.
 *
 * The one place that turns an order into a `PaymentRequest`, hands it to
 * whichever provider this shop uses, and writes down what came back. Adapters
 * never see a `WC_Order`; this class is the reason they do not have to
 * (docs/ARCHITECTURE.md §4).
 *
 * The dependency runs one way, as `Shipping\ShippingService`'s does: `Payments/`
 * reads `Orders/`, because a payment is *for* an order, and nothing in `Orders/`
 * knows this module exists.
 *
 * ## Three ways to learn that money moved, and one place they agree
 *
 * ```
 * verify()        an operator or the storefront asks
 * webhook()       Chargily says so, with a signature
 * PaymentPoller   nobody said anything for a while, so we ask
 * ```
 *
 * All three end in `applyReport()`, and **all three ask the provider directly**.
 * The webhook's payload is a trigger and not evidence: docs/SECURITY.md requires
 * amount *and currency* to be re-checked before an order is settled, and
 * Chargily's webhook body does not even carry a currency. A rule that cannot be
 * obeyed from the payload is a rule that has to be obeyed by re-fetching.
 *
 * ## Every attempt is written down before it is made
 *
 * docs/SECURITY.md: *every transaction attempt is recorded with its provider
 * reference and verification result*. So the row is inserted **before** the
 * gateway is called and closed as `failed` if the call does not come back. The
 * alternative — insert afterwards — loses the record of exactly the attempt
 * worth having: the one where a gateway may have taken money on a request this
 * side then dropped.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
        private readonly TransactionRepository $transactions,
        private readonly WebhookEventRepository $events,
        private readonly Logger $logger
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
     * The provider is called last among the things that can refuse, which is the
     * rule §53 arrived at for couriers and for the same reason: an error raised
     * *after* a gateway has opened a checkout leaves a payment this system has
     * no record of, and a customer looking at a payment page for it.
     *
     * The transaction row is the exception, and deliberately so — see the class
     * docblock. It is written first precisely so that the window where a gateway
     * knows something this shop does not never exists.
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
        $this->guardNotAlreadyPaid($order);

        $request = $this->buildRequest($order, $input);
        $transaction = $this->openTransaction($provider->name(), $request);

        try {
            $result = $provider->createPayment($request);
        } catch (ApiException $exception) {
            /*
             * The attempt failed and is recorded as having failed, with the
             * gateway's error code. Without this the row would sit `pending`
             * forever, and the poller would ask about a checkout that was never
             * created — an hourly 404 against somebody's rate limit.
             */
            $this->closeFailed($transaction, $exception);

            throw $exception;
        }

        $stored = $transaction->withProviderResult($result, self::now());

        if (!$this->transactions->update($stored)) {
            // The checkout exists at the gateway and this shop cannot record
            // which one it is. Nothing can verify it afterwards, so it is
            // shouted about rather than swallowed — but the customer still gets
            // their link, because refusing now would mean a payment page nobody
            // is looking at and an order nobody can pay for.
            $this->logger->error('Could not record a created payment', [
                'order_id' => $orderId,
                'provider' => $provider->name(),
                'transaction_id' => $transaction->id,
            ]);
        }

        $this->audit->record('payment.created', 'order', $orderId, [
            'provider' => $provider->name(),
            'transaction_id' => $transaction->id,
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
     * Ask the gateway what really happened, and write down the answer.
     *
     * This is the server-side confirmation docs/SECURITY.md demands. A client
     * callback, a return-URL query parameter and a customer saying "it went
     * through" are all worth exactly nothing here.
     *
     * @throws ApiException
     */
    public function verify(int $transactionId): PaymentReport
    {
        Permissions::assert(Capabilities::MANAGE_PAYMENTS);

        $transaction = $this->requireTransaction($transactionId);

        return $this->refresh($transaction);
    }

    /** @throws ApiException */
    public function find(int $transactionId): Transaction
    {
        Permissions::assert(Capabilities::MANAGE_PAYMENTS);

        return $this->requireTransaction($transactionId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<Transaction>, total: int}
     *
     * @throws ApiException
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        Permissions::assert(Capabilities::MANAGE_PAYMENTS);

        return [
            'items' => $this->transactions->paginate($filters, $page, $perPage),
            'total' => $this->transactions->count($filters),
        ];
    }

    /**
     * @return list<Transaction>
     *
     * @throws ApiException
     */
    public function forOrder(int $orderId): array
    {
        Permissions::assert(Capabilities::MANAGE_PAYMENTS);

        $this->requireOrder($orderId);

        return $this->transactions->forOrder($orderId);
    }

    /**
     * One inbound event, end to end — docs/SECURITY.md → "Webhooks".
     *
     * ```
     * verify signature   ← the adapter, on the raw bytes, before anything
     * identify event
     * claim              ← a write-once insert, never a read-then-write
     * re-fetch and act   ← the gateway's word, not the payload's
     * respond
     * ```
     *
     * **No permission check, and that is correct here.** There is no user: the
     * signature is the authentication. Nothing in this method takes a status,
     * an amount or an order from the caller — every value written comes from a
     * `verifyPayment()` call this side made.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers lower-cased header names
     * @return array{status: string, event_id: string}
     *
     * @throws ApiException 401 `webhook_unverified`, or 500 so the gateway retries
     */
    public function handleWebhook(string $providerName, array $payload, array $headers, string $rawBody): array
    {
        $provider = $this->providers->get($providerName);

        // Throws 401 webhook_unverified on anything that does not verify, and
        // says nothing about which check failed.
        $result = $provider->handleWebhook($payload, $headers, $rawBody);

        $eventType = (string) ($result->metadata['event_type'] ?? '');

        if (!$this->events->claim($provider->name(), $result->eventId, $eventType)) {
            // The unique key refused it: this event has already been handled.
            // 200 and dropped, so the gateway stops retrying.
            return ['status' => 'duplicate', 'event_id' => $result->eventId];
        }

        if (!$result->isActionable() || $result->providerPaymentId === '') {
            // Verified, and nothing we act on — an event type we do not handle.
            // §55: acknowledged and dropped, so the provider does not retry
            // forever over a gap on our side.
            return ['status' => 'ignored', 'event_id' => $result->eventId];
        }

        $transaction = $this->transactions->findByProviderId($provider->name(), $result->providerPaymentId);

        if ($transaction === null) {
            // A verified event about a payment this shop has no record of.
            // Also 200 and dropped — retrying will not make the row appear, and
            // the event is claimed so a genuine later delivery is not needed.
            $this->logger->warning('Webhook for an unknown payment', [
                'provider' => $provider->name(),
                'event_type' => $eventType,
            ]);

            return ['status' => 'unknown_payment', 'event_id' => $result->eventId];
        }

        /*
         * The payload said something; the gateway is asked anyway. Any failure
         * here is left to become a 500 so the gateway retries — the event has
         * been claimed, but a claim that was never acted on is exactly what
         * `sync-payments` sweeps up.
         */
        $this->refresh($transaction);

        return ['status' => 'processed', 'event_id' => $result->eventId];
    }

    /**
     * Ask the gateway about one transaction and apply what it says.
     *
     * The shared tail of verification, the webhook and the poller — one code
     * path, so the amount re-check cannot be present in one and missing from
     * another.
     *
     * @throws ApiException
     */
    public function refresh(Transaction $transaction): PaymentReport
    {
        $provider = $this->providers->get($transaction->provider);

        if ($transaction->providerTransactionId === '') {
            throw ApiException::conflict('This payment was never accepted by the provider.', [
                'transaction_id' => $transaction->id,
            ]);
        }

        $report = $provider->verifyPayment($transaction->providerTransactionId);

        $this->applyReport($transaction, $report);

        return $report;
    }

    /**
     * Write down what the gateway said, if it may be written.
     *
     * **The amount re-check is the point, not a formality.** A gateway reporting
     * `paid` says money arrived; it does not say *how much*, and an order marked
     * paid against a smaller sum is a shop shipping goods it was not paid for. A
     * mismatch is refused loudly and audited, because it is either an attack or
     * a bug and both need a human — and the transaction is left where it was
     * rather than settled.
     *
     * `PaymentStatus::accepts()` decides whether the state may be written at
     * all. It is what stops a late `pending` event walking a paid order back,
     * and it is why this method can be called by three code paths arriving in
     * any order.
     *
     * @throws ApiException
     */
    private function applyReport(Transaction $transaction, PaymentReport $report): void
    {
        $order = $this->orders->find($transaction->orderId);

        if (PaymentStatus::isSettled($report->status) && $order instanceof WC_Order) {
            $expectedAmount = (string) $order->get_total();
            $expectedCurrency = (string) $order->get_currency();

            if (!$report->matches($expectedAmount, $expectedCurrency)) {
                $this->audit->record('payment.amount_mismatch', 'order', $transaction->orderId, [
                    'provider' => $transaction->provider,
                    'transaction_id' => $transaction->id,
                    'provider_payment_id' => $transaction->providerTransactionId,
                    'reported_amount' => $report->amount,
                    'reported_currency' => $report->currency,
                    'order_total' => $expectedAmount,
                    'order_currency' => $expectedCurrency,
                ]);

                throw new ApiException(
                    'payment_amount_mismatch',
                    'The provider reported a payment that does not match this order.',
                    409,
                    ['provider' => $transaction->provider]
                );
            }
        }

        if (!PaymentStatus::accepts($transaction->status, $report->status)) {
            // Nothing to do: the same state again, a state we do not accept from
            // here, or a finished payment. All three are ordinary.
            return;
        }

        $updated = $transaction->withReport($report, self::now());

        if (!$this->transactions->update($updated)) {
            $this->logger->error('Could not record a payment status change', [
                'transaction_id' => $transaction->id,
                'from' => $transaction->status,
                'to' => $report->status,
            ]);

            throw ApiException::internal();
        }

        $this->audit->record('payment.status_changed', 'order', $transaction->orderId, [
            'provider' => $transaction->provider,
            'transaction_id' => $transaction->id,
            'provider_payment_id' => $transaction->providerTransactionId,
            'from' => $transaction->status,
            'to' => $report->status,
            'provider_status' => $report->providerStatus,
        ]);

        if ($updated->isSettled() && $order instanceof WC_Order) {
            /*
             * A payment moves the order, and this is the one place in the plugin
             * where a provider's answer does. A *parcel's* status never does
             * (CLAUDE.md) — but money arriving is the event WooCommerce's own
             * order lifecycle is built around, and `payment_complete()` is its
             * supported way to say so.
             */
            $this->orders->markPaid($order, $updated->providerTransactionId);

            $this->audit->record('payment.settled', 'order', $transaction->orderId, [
                'provider' => $transaction->provider,
                'transaction_id' => $transaction->id,
                'amount' => $updated->amount,
                'currency' => $updated->currency,
            ]);
        }
    }

    /**
     * The row that exists before the gateway is called.
     *
     * @throws ApiException
     */
    private function openTransaction(string $provider, PaymentRequest $request): Transaction
    {
        $now = self::now();
        $transaction = new Transaction(
            $request->orderId,
            $provider,
            '',
            $request->reference,
            $request->amount,
            $request->currency,
            PaymentStatus::PENDING,
            [],
            $now,
            $now
        );

        $id = $this->transactions->insert($transaction);

        if ($id === null) {
            // Refused *before* the gateway is called, which is the whole reason
            // the insert comes first: a shop that cannot record a payment must
            // not start one.
            $this->logger->error('Could not open a payment transaction', [
                'order_id' => $request->orderId,
                'provider' => $provider,
            ]);

            throw ApiException::internal();
        }

        return new Transaction(
            $transaction->orderId,
            $transaction->provider,
            '',
            $transaction->reference,
            $transaction->amount,
            $transaction->currency,
            $transaction->status,
            $transaction->metadata,
            $transaction->createdAt,
            $transaction->updatedAt,
            $id
        );
    }

    private function closeFailed(Transaction $transaction, ApiException $exception): void
    {
        $failed = $transaction->withStatus(PaymentStatus::FAILED, self::now(), [
            'error' => $exception->errorCode(),
        ]);

        $this->transactions->update($failed);

        $this->audit->record('payment.failed', 'order', $transaction->orderId, [
            'provider' => $transaction->provider,
            'transaction_id' => $transaction->id,
            'error' => $exception->errorCode(),
        ]);
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

    /** @throws ApiException */
    private function requireTransaction(int $transactionId): Transaction
    {
        $transaction = $this->transactions->find($transactionId);

        if ($transaction === null) {
            throw ApiException::notFound('No payment with that id.');
        }

        return $transaction;
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

    /**
     * An order that has already been paid for does not get a second checkout.
     *
     * Not concurrency control — see `TransactionRepository::settledForOrder()`
     * on why a lock would be answering a question this domain does not ask. It
     * is the guard against the ordinary case: a stale admin screen, a retried
     * request, a customer clicking an old link after the money already arrived.
     *
     * @throws ApiException
     */
    private function guardNotAlreadyPaid(WC_Order $order): void
    {
        $settled = $this->transactions->settledForOrder($order->get_id());

        if ($settled === null) {
            return;
        }

        throw new ApiException(
            'payment_already_settled',
            'This order has already been paid for.',
            409,
            ['transaction_id' => $settled->id, 'provider' => $settled->provider]
        );
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
