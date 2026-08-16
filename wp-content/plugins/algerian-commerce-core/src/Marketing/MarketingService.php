<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Order;

/**
 * The marketing event layer — roadmap §62b, docs/PLAN.md §26.
 *
 * Depends on the order repository, one way: a purchase event is *about* an
 * order. Nothing in `Orders/` knows marketing exists, exactly as nothing in it
 * knows about payments or shipping.
 *
 * ## Two rules shape every method here
 *
 * **Nothing is sent on the request path.** `recordPurchase()` builds the event,
 * claims it and returns; the send happens in `drain()`, on cron or on the
 * command line. A Meta outage must never fail or delay an order, which is the
 * same rule §57 applies when it refuses to let a read-back fail a parcel that
 * already exists.
 *
 * **The id is derived and claimed once.** `MarketingEvent::idFor()` produces the
 * same string for the same order forever, and the claim is a write-once insert.
 * A retried request, a refreshed confirmation page and a second browser tab
 * therefore produce one conversion rather than three.
 */
final class MarketingService
{
    public function __construct(
        private readonly MarketingProviderRegistry $providers,
        private readonly MarketingEventRepository $repository,
        private readonly OrderRepository $orders,
        private readonly AuditLogger $audit,
        private readonly Logger $logger
    ) {
    }

    /**
     * What a storefront may put in browser JavaScript.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $providers = [];

        foreach ($this->providers->all() as $provider) {
            $providers[] = [
                'name' => $provider->name(),
                'label' => $provider->label(),
            ] + $provider->publicConfig();
        }

        return [
            // A shop without an ad account is the normal case, so this is a
            // flag to branch on rather than an error to handle.
            'enabled' => !$this->providers->isEmpty(),
            'providers' => $providers,
            // The browser sends these itself; naming them here keeps the
            // storefront and this side agreeing on one vocabulary.
            'browser_events' => MarketingEvent::ALL,
            'server_events' => MarketingEvent::SERVER_SIDE,
        ];
    }

    /**
     * Mint and queue the Purchase event for an order.
     *
     * Returns the `event_id` the storefront must pass to
     * `fbq('track', 'Purchase', {...}, {eventID})`. That is the direction §62b
     * requires — the backend mints it and the storefront is told, never the
     * reverse — because two systems cannot each invent the same id and Meta
     * deduplicates on nothing else.
     *
     * Answering with the id even when the event was already claimed is
     * deliberate: the second tab still has to render the pixel with the *same*
     * id, and telling it "already done" would make it either skip the browser
     * event or invent a new id.
     *
     * @param array<string, string> $context client_ip_address, client_user_agent, fbc, fbp
     * @return array<string, mixed>
     */
    public function recordPurchase(int $orderId, array $context = []): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $order = $this->orders->find($orderId);

        if ($order === null) {
            throw ApiException::notFound('No order with that id.');
        }

        $eventId = MarketingEvent::idFor(MarketingEvent::PURCHASE, $orderId);
        $event = new MarketingEvent(
            MarketingEvent::PURCHASE,
            $eventId,
            $this->orderTimestamp($order),
            $this->userDataFor($order, $context),
            $this->customDataFor($order),
            (string) ($context['event_source_url'] ?? ''),
            MarketingEvent::SOURCE_WEBSITE,
            $orderId
        );

        $queued = [];
        $duplicates = [];

        foreach ($this->providers->all() as $provider) {
            if ($this->repository->claim($provider->name(), $event) > 0) {
                $queued[] = $provider->name();
                continue;
            }

            $duplicates[] = $provider->name();
        }

        if ($queued !== []) {
            $this->audit->record('marketing.purchase_queued', 'order', $orderId, [
                'event_id' => $eventId,
                'providers' => $queued,
                // The value is the interesting number in an audit trail about
                // conversions; the customer identifiers are not, and are hashed
                // before they reach this object at all.
                'value' => $event->custom['value'] ?? null,
                'currency' => $event->custom['currency'] ?? null,
            ]);
        }

        return [
            'event_id' => $eventId,
            'event_name' => MarketingEvent::PURCHASE,
            'queued' => $queued,
            'already_queued' => $duplicates,
        ];
    }

    /**
     * Send what is waiting.
     *
     * Called by `wp algerian-commerce sync-marketing` and by the cron event —
     * never by a request that a customer is waiting on.
     *
     * @return array<string, int>
     */
    public function drain(int $limit = 50, ?string $providerName = null): array
    {
        $report = ['considered' => 0, 'sent' => 0, 'retryable' => 0, 'rejected' => 0, 'skipped' => 0];

        foreach ($this->repository->pending($limit, $providerName) as $row) {
            $report['considered']++;

            $provider = $this->providers->get((string) $row['provider']);

            if ($provider === null) {
                /*
                 * The provider was switched off between the claim and the
                 * drain. Left pending rather than failed: turning a pixel back
                 * on should resume the queue, not require a database edit.
                 */
                $report['skipped']++;
                continue;
            }

            $payload = json_decode((string) $row['payload'], true);

            if (!is_array($payload)) {
                $this->repository->markFailed((int) $row['id'], 'The stored payload is not readable.', false);
                $report['rejected']++;
                continue;
            }

            $result = $provider->send(MarketingEvent::fromArray($payload));

            if ($result->isSent()) {
                $this->repository->markSent((int) $row['id'], $result->reference);
                $report['sent']++;
                continue;
            }

            $this->repository->markFailed((int) $row['id'], $result->message, $result->isRetryable());
            $report[$result->isRetryable() ? 'retryable' : 'rejected']++;
        }

        if ($report['considered'] > 0) {
            $this->logger->info('Marketing queue drained', $report);
        }

        return $report;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return $this->repository->summary();
    }

    /**
     * The customer's identifiers, hashed on the way in.
     *
     * Reads the order's billing details through `OrderRepository`, which is the
     * only class allowed to touch an order object. Nothing raw survives the
     * call: `UserData::fromCustomer()` hashes and forgets.
     *
     * @param array<string, string> $context
     */
    private function userDataFor(WC_Order $order, array $context): UserData
    {
        return UserData::fromCustomer(
            [
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'zip' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                // The customer id where there is an account; a guest checkout
                // has none, and an empty value is omitted rather than hashed.
                'external_id' => (string) ($order->get_customer_id() ?: ''),
            ],
            $context
        );
    }

    /**
     * The conversion itself.
     *
     * `value` and `currency` are what Meta requires for a Purchase, and both
     * come from the order rather than from the caller — a client that could
     * state its own conversion value could inflate a campaign's reported
     * return, and the request comes from a storefront the shop does not control
     * as tightly as it controls this.
     *
     * @return array<string, mixed>
     */
    private function customDataFor(WC_Order $order): array
    {
        $contents = [];
        $items = 0;

        foreach ($order->get_items() as $item) {
            $productId = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $quantity = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;

            if ($productId <= 0) {
                continue;
            }

            $items += $quantity;
            $contents[] = [
                'id' => (string) $productId,
                'quantity' => $quantity,
                'item_price' => (float) $order->get_item_subtotal($item, false, true),
            ];
        }

        return [
            'value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'order_id' => (string) $order->get_id(),
            'content_type' => 'product',
            'content_ids' => array_column($contents, 'id'),
            'contents' => $contents,
            'num_items' => $items,
        ];
    }

    /**
     * When the purchase happened, from the order rather than from `time()`.
     *
     * The queue drains later — minutes on cron, longer after an outage — and an
     * event stamped at drain time would report every conversion as happening
     * whenever the cron last ran.
     */
    private function orderTimestamp(WC_Order $order): int
    {
        $created = $order->get_date_created();

        return $created !== null ? $created->getTimestamp() : time();
    }
}
