<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Commerce\PaymentMethod;
use AlgerianCommerce\Commerce\WebhookEventRepository;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Geography\GeoRepository;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Order;

/**
 * Shipping business rules — roadmap §53, docs/PLAN.md §13.
 *
 * This service says *create shipment*. It never says *call the Yalidine
 * endpoint*, and it cannot: it only holds a ProviderRegistry of things that
 * implement ShippingProviderInterface, and everything it hands them is a value
 * object of ours (docs/ARCHITECTURE.md §4). That is what lets one codebase
 * serve clients on different couriers.
 *
 * Four rules shape it:
 *
 *  - **A shipment never changes the order's status.** Same reasoning as COD:
 *    PLAN.md §8 lists "Shipping Prepared", "Shipped" and "Delivered" among the
 *    operational states and then says not to create redundant statuses when
 *    metadata will do. A parcel's progress is a fact about the parcel; whether
 *    the order is then completed is the shop's decision, and one taken with
 *    money in mind on a COD order.
 *  - **One live shipment per order.** Two parcels for one order is two vans and
 *    one of them delivering to a customer who has already been served
 *    (docs/SECURITY.md: duplicate delivery must never duplicate a shipment). A
 *    *finished* shipment does not block a new one, so a re-send after a failed
 *    delivery works without deleting history.
 *  - **The destination is validated against the §51 dataset** before a provider
 *    ever sees it, so an adapter can assume the commune exists and belongs to
 *    the wilaya it was sent with.
 *  - Every write is audited, and a status that a provider reports is written
 *    only when ShipmentStatus::accepts() allows it — a replayed webhook must
 *    not walk a delivered parcel backwards.
 *
 * Authorization is asserted here as well as on the route, for callers that
 * arrive from WP-CLI or another service rather than through REST. The
 * capability is `ac_manage_shipping` throughout: Admin, Manager and Order
 * Manager hold it, Support Agent does not.
 */
final class ShippingService
{
    public function __construct(
        private readonly ShipmentRepository $repository,
        private readonly ProviderRegistry $providers,
        private readonly OrderRepository $orders,
        private readonly GeoRepository $geography,
        private readonly AuditLogger $audit,
        private readonly ShippingRuleRepository $rules,
        /*
         * The webhook event claim — roadmap §60. Optional so that every existing
         * construction of this service keeps working; a service built without it
         * simply has no webhook pipeline, and the route is not registered
         * either.
         */
        private readonly ?WebhookEventRepository $events = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * One inbound courier event, end to end — docs/SECURITY.md → "Webhooks".
     *
     * ```
     * verify signature/token   ← the adapter, on the raw bytes, before anything
     * identify event
     * claim                    ← a write-once insert, never a read-then-write
     * re-fetch and act         ← getShipmentStatus(), the poller's own path
     * respond
     * ```
     *
     * **No permission check, and that is correct here.** There is no user: the
     * signature — or, for Yalidine, the shared secret — is the authentication.
     * Nothing in this method takes a status, a tracking number or an order from
     * the caller: the only value ever written comes from asking the courier.
     *
     * **A parcel's status still never moves the order**, webhook or not
     * (CLAUDE.md). This does exactly what a poll does, and nothing a poll would
     * not — which is the point of ending both in the same call.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers lower-cased header names
     * @return array{status: string, event_id: string}
     *
     * @throws ApiException 401 `webhook_unverified`, or 500 so the courier retries
     */
    public function handleWebhook(string $providerName, array $payload, array $headers, string $rawBody): array
    {
        $provider = $this->providers->get($providerName);

        // Throws 401 webhook_unverified on anything that does not verify, and
        // says nothing about which check failed.
        $result = $provider->handleWebhook($payload, $headers, $rawBody);

        if ($this->events === null) {
            // No claim store means no replay protection, and processing without
            // it would be worse than not processing at all.
            throw ApiException::internal('Webhook handling is not configured.');
        }

        if (!$this->events->claim($provider->name(), $result->eventId, $result->eventType)) {
            // The unique key refused it: this event has already been handled.
            // 200 and dropped, so the courier stops retrying.
            return ['status' => 'duplicate', 'event_id' => $result->eventId];
        }

        if (!$result->identifiesAParcel()) {
            return ['status' => 'ignored', 'event_id' => $result->eventId];
        }

        $shipment = $this->repository->findByProviderReference(
            $provider->name(),
            $result->providerShipmentId,
            $result->trackingNumber
        );

        if ($shipment === null) {
            // A verified event about a parcel this shop has no record of — a
            // parcel created from the courier's own dashboard, typically. 200
            // and dropped: retrying will not make the row appear.
            $this->logger?->warning('Webhook for an unknown parcel', [
                'provider' => $provider->name(),
                'event_type' => $result->eventType,
            ]);

            return ['status' => 'unknown_parcel', 'event_id' => $result->eventId];
        }

        if (!$shipment->isLive()) {
            // A finished parcel is not reopened by a late event, which is the
            // same refusal ShipmentStatus::accepts() would make one line later —
            // made here so it costs no courier call.
            return ['status' => 'finished', 'event_id' => $result->eventId];
        }

        $report = $provider->getShipmentStatus($shipment->providerShipmentId);

        if (!ShipmentStatus::accepts($shipment->status, $report->status)) {
            // Most events find a parcel exactly where the last one left it.
            return ['status' => 'unchanged', 'event_id' => $result->eventId];
        }

        $this->store(
            $shipment->withReport($report, self::now()),
            'shipment.status_changed',
            [
                'from' => $shipment->status,
                'to' => $report->status,
                'provider_status' => $report->providerStatus,
                // Distinguishable from a manual update, a one-off sync and the
                // poller, so "who moved this parcel" has an answer.
                'source' => 'webhook',
            ]
        );

        return ['status' => 'processed', 'event_id' => $result->eventId];
    }

    /** @return list<array{name: string, label: string, is_default: bool}> */
    public function availableProviders(): array
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        return $this->providers->describe();
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<Shipment>, total: int}
     */
    public function list(array $criteria, int $page, int $perPage): array
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        return [
            'items' => $this->repository->paginate($criteria, $page, $perPage),
            'total' => $this->repository->count($criteria),
        ];
    }

    public function get(int $id): Shipment
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        return $this->requireShipment($id);
    }

    /** @return list<Shipment> newest first */
    public function forOrder(int $orderId): array
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $this->requireOrder($orderId);

        return $this->repository->forOrder($orderId);
    }

    /**
     * Hand an order's parcel to a courier, by hand — `POST /orders/{id}/shipments`.
     *
     * ## This route stays, and step 2 is the reason it had to be argued for
     *
     * Backend step 2 makes confirmation create the parcel by itself
     * (`ShipmentSubscriber`), which demotes this route from *the* way a parcel
     * is created to *the other* way. Item 6 of that step says to keep it, and
     * keeping something is a decision that rots unless the reason is written
     * next to it. There are five, and each is a case the automatic path cannot
     * reach even in principle:
     *
     *  - **The order the automatic step refused.** A courier that rejects a
     *    commune leaves no shipment row at all — the provider is called before
     *    anything is written, which is `createClaimed()`'s deliberate ordering —
     *    so the operator has an order with a recorded failure and no parcel.
     *    Confirming again is one way back and it needs the order to leave
     *    `processing` first; this route is the way back that does not.
     *  - **The order that never transitions.** A shop that ships from `on-hold`,
     *    or that dispatches an order already sitting in `processing`, fires no
     *    transition and therefore no hook. Nothing is wrong with that shop.
     *  - **The re-send.** A parcel that failed to deliver is finished, so the
     *    live-shipment rule permits a second — but the order is already
     *    `processing` and will not transition into it again, so the automatic
     *    path will never run for it. Every re-send in this system comes through
     *    here.
     *  - **The destination the order does not carry.** `ShipmentInput` takes a
     *    wilaya and a commune by id and refuses to guess them from a free-text
     *    address, and `POST /orders` has no field that writes them, so a
     *    back-office order has none to be confirmed with. This is the route that
     *    takes the two ids.
     *  - **Everything the order cannot say.** A desk collection on an order
     *    quoted for home delivery, a recipient who is not the buyer, a phone
     *    number for the neighbour who will actually be in. All are fields here
     *    and none of them exists on an order.
     *
     * The shop running in-house delivery is *not* on that list, which is worth
     * saying because item 6 names it: `ManualProvider::createShipment()` is pure
     * and cannot fail, so a `manual` order confirmed with a destination gets its
     * parcel automatically like any other. It needs this route for the five
     * reasons above and for no reason peculiar to itself.
     *
     * @param array<string, mixed> $payload
     */
    public function create(int $orderId, array $payload): Shipment
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        return $this->createFor($orderId, $payload);
    }

    /**
     * The same thing, for the confirmation hook — backend step 2, item 5.
     *
     * **No permission check, and that is correct here**, for the reason
     * `handleWebhook()` gives above: there is no user. A status transition
     * arrives from cron, from WP-CLI, from a payment gateway completing a
     * payment and from wp-admin, and `Permissions::assert()` throws
     * `unauthenticated` for the first three — so calling `create()` from the
     * hook would mean a shop's parcels being created only when a signed-in
     * human happened to be the one who moved the order. That is not a security
     * boundary being crossed, it is one being applied to the wrong actor.
     *
     * What replaces it is that nothing here is caller-supplied. The order id
     * comes from WooCommerce's own transition, the courier is read off the
     * order, and the destination is read off the order's meta;
     * `ShipmentSubscriber` builds the payload and no request touches it.
     *
     * Every other rule is unchanged and deliberately shared rather than
     * re-stated: the same claim, the same one-live-shipment guard, the same
     * destination validation, the same audit row. A second code path that
     * created parcels under slightly different rules is precisely how a shop
     * ends up with two vans.
     *
     * @param array<string, mixed> $payload
     */
    public function createOnConfirmation(int $orderId, array $payload): Shipment
    {
        return $this->createFor($orderId, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function createFor(int $orderId, array $payload): Shipment
    {
        $order = $this->requireOrder($orderId);
        $input = ShipmentInput::fromPayload($payload);
        $provider = $this->providers->get($input->provider);

        /*
         * From here to the `finally`, this order belongs to this request.
         *
         * `guardNoLiveShipment()` is a read, and a read cannot enforce
         * "one live shipment per order" on its own: two requests arriving
         * together both find none, both hand a parcel to the courier, and the
         * customer receives two while the shop pays twice. Migration 006's
         * unique index refuses the second row — but only after the second
         * parcel is already real, which is the outcome worth preventing rather
         * than recording.
         *
         * So the claim is taken before the read and held across the courier
         * call, and a second request is told no immediately instead of
         * queueing behind a call that may take a minute.
         */
        if (!$this->repository->claimOrder($orderId)) {
            throw ApiException::conflict(
                'A shipment for this order is already being created.',
                ['order_id' => $orderId]
            );
        }

        try {
            return $this->createClaimed($orderId, $order, $input, $provider);
        } finally {
            $this->repository->releaseOrder($orderId);
        }
    }

    /**
     * The body of `create()`, with this order's claim already held.
     *
     * Split out so the claim is released by exactly one `finally` on every
     * path — a return, a provider refusal, a failed write — rather than by a
     * reader remembering that a long method has an exit halfway down.
     */
    private function createClaimed(
        int $orderId,
        WC_Order $order,
        ShipmentInput $input,
        ShippingProviderInterface $provider
    ): Shipment {
        $this->guardOrderIsShippable($order);
        $this->guardNoLiveShipment($orderId);

        $destination = $this->validatedDestination($input->destination);

        // The provider is called last, after everything that can be refused has
        // been refused. A 400 that arrives *after* a courier has accepted a
        // parcel leaves a real van carrying an order this system has no record
        // of.
        $result = $provider->createShipment($this->buildRequest($order, $input, $destination, $this->nextAttempt($orderId)));

        $now = self::now();

        $id = $this->repository->insert(new Shipment(
            $orderId,
            $provider->name(),
            $result->providerShipmentId,
            $result->trackingNumber,
            $result->status,
            $result->metadata,
            $now,
            $now
        ));

        if ($id === null) {
            /*
             * The parcel exists at the courier and we could not write it down.
             * The audit trail is a different table with a different failure
             * mode, so it is the one place this can still be recorded — losing
             * the tracking number silently is how a shop discovers a shipment
             * only when the customer calls.
             */
            $this->audit->record('shipment.record_failed', 'order', $orderId, [
                'provider' => $provider->name(),
                'provider_shipment_id' => $result->providerShipmentId,
                'tracking_number' => $result->trackingNumber,
            ]);

            throw ApiException::internal('The shipment was created at the provider but could not be saved.');
        }

        $this->audit->record('shipment.created', 'order', $orderId, [
            'shipment_id' => $id,
            'provider' => $provider->name(),
            'tracking_number' => $result->trackingNumber,
            'status' => $result->status,
            'destination' => $destination->toArray(),
        ]);

        return $this->repository->find($id) ?? throw ApiException::internal('The shipment could not be read back.');
    }

    /**
     * Call a parcel off at the courier.
     *
     * The provider is asked first and the row is only written if it agrees: a
     * shipment marked cancelled here while a van still carries the parcel is
     * worse than a refusal, because nobody goes looking for it.
     */
    public function cancel(int $id): Shipment
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $shipment = $this->requireShipment($id);

        if (!$shipment->isLive()) {
            throw ApiException::conflict('This shipment has already finished.', [
                'status' => $shipment->status,
            ]);
        }

        $provider = $this->providerFor($shipment);

        if (!$provider->cancelShipment($shipment->providerShipmentId)) {
            throw ApiException::conflict('The provider will not cancel this shipment.', [
                'provider' => $shipment->provider,
                'status' => $shipment->status,
            ]);
        }

        return $this->store(
            $shipment->withStatus(ShipmentStatus::CANCELLED, self::now()),
            'shipment.cancelled',
            ['from' => $shipment->status]
        );
    }

    /**
     * Ask the courier where the parcel is.
     *
     * A finished shipment is returned untouched without calling anyone: there
     * is nothing left to learn, and polling a courier about a parcel it
     * delivered last month is how rate limits get spent.
     */
    public function sync(int $id): Shipment
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $shipment = $this->requireShipment($id);

        if (!$shipment->isLive()) {
            return $shipment;
        }

        $report = $this->providerFor($shipment)->getShipmentStatus($shipment->providerShipmentId);

        if (!ShipmentStatus::accepts($shipment->status, $report->status)) {
            // Not an error, and deliberately not a write: most polls find a
            // parcel exactly where it was.
            return $shipment;
        }

        return $this->store(
            $shipment->withReport($report, self::now()),
            'shipment.status_changed',
            ['from' => $shipment->status, 'to' => $report->status, 'source' => 'provider']
        );
    }

    /**
     * Move a shipment on by hand.
     *
     * This is how in-house delivery works — there is no API to poll, only the
     * person who drove the van — and it is also the escape hatch for a courier
     * whose webhook was missed.
     *
     * @param array<string, mixed> $payload
     */
    public function updateStatus(int $id, array $payload): Shipment
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $shipment = $this->requireShipment($id);
        $status = $this->validatedStatus($payload);

        if (!ShipmentStatus::accepts($shipment->status, $status)) {
            throw ApiException::conflict("This shipment cannot move from \"{$shipment->status}\" to \"{$status}\".", [
                'from' => $shipment->status,
                'to' => $status,
                'is_live' => $shipment->isLive(),
            ]);
        }

        return $this->store(
            $shipment->withStatus($status, self::now()),
            'shipment.status_changed',
            ['from' => $shipment->status, 'to' => $status, 'source' => 'manual']
        );
    }

    /**
     * What it costs to deliver to a destination.
     *
     * Two sources, both real and routinely in disagreement: this shop's own
     * tariff (roadmap §4 step 28b) and whatever the courier's rate API says.
     * The shop is charging the customer; the courier is charging the shop. Both
     * are returned, each labelled with its `source`, rather than one silently
     * winning — a client pricing a checkout wants the first, and a manager
     * deciding which courier to use wants to see both.
     *
     * With no `provider`, every configured courier is quoted, which is what
     * PLAN.md §14's "provider selection" needs to be possible at all.
     *
     * @param array<string, mixed> $criteria
     * @return list<array<string, mixed>>
     */
    public function rates(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $destination = $this->validatedDestination(new Destination(
            (int) ($criteria['wilaya_id'] ?? 0),
            (int) ($criteria['commune_id'] ?? 0),
            (string) ($criteria['delivery_type'] ?? Destination::HOME)
        ));

        $requested = (string) ($criteria['provider'] ?? '');
        $subtotal = (string) ($criteria['subtotal'] ?? '');

        // Resolved once for every courier: a shop's tariff rarely depends on
        // which van carries the parcel, and reading the table per provider
        // would make the answer depend on how many are configured.
        $rules = $this->rules->active();
        $decimals = self::priceDecimals();
        $currency = self::currency();

        $names = $requested === ''
            ? $this->providers->names()
            : [$this->providers->get($requested)->name()];

        $quotes = [];

        foreach ($names as $name) {
            $rule = RateResolver::resolve($rules, $destination, $name);

            if ($rule !== null) {
                $quotes[] = ['provider' => $name]
                    + RateResolver::quote($rule, $subtotal, $decimals, $currency, $destination->deliveryType)->toArray();
            }

            foreach ($this->providers->get($name)->getShippingRates($destination) as $quote) {
                $quotes[] = ['provider' => $name] + $quote->toArray();
            }
        }

        return $quotes;
    }

    /**
     * The shop's tariff, narrowest rule first.
     *
     * @param array<string, mixed> $filters
     * @return list<ShippingRule>
     */
    public function listRules(array $filters): array
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        return $this->rules->all($filters);
    }

    public function getRule(int $id): ShippingRule
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        return $this->requireRule($id);
    }

    /** @param array<string, mixed> $payload */
    public function createRule(array $payload): ShippingRule
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $rule = ShippingRuleInput::forCreate($payload)->toRule(self::now());

        $this->guardRulePlaces($rule);
        $this->guardRuleProvider($rule);
        $this->guardNoDuplicateScope($rule);

        $id = $this->rules->insert($rule);

        if ($id === null) {
            throw ApiException::internal('The shipping rule could not be saved.');
        }

        $this->audit->record('shipping.rule_created', 'shipping_rule', $id, $rule->toArray());

        return $this->rules->find($id) ?? throw ApiException::internal('The rule could not be read back.');
    }

    /** @param array<string, mixed> $payload */
    public function updateRule(int $id, array $payload): ShippingRule
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $existing = $this->requireRule($id);
        $input = ShippingRuleInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $updated = $input->applyTo($existing, self::now());

        $this->guardRulePlaces($updated);
        $this->guardRuleProvider($updated);
        $this->guardNoDuplicateScope($updated);

        if (!$this->rules->update($updated)) {
            throw ApiException::internal('The shipping rule could not be updated.');
        }

        $this->audit->record('shipping.rule_updated', 'shipping_rule', $id, [
            'fields' => array_keys($input->fields),
            'before' => $existing->toArray(),
            'after' => $updated->toArray(),
        ]);

        return $this->rules->find($id) ?? $updated;
    }

    /**
     * Delete a rule.
     *
     * Genuinely deleted, unlike an order or a shipment: a tariff row is
     * configuration, not the record of something that happened, and a shop
     * changing its prices is not rewriting history. The audit row keeps what
     * the rule said, which is what anyone asking "why was this customer charged
     * that" actually needs.
     */
    public function deleteRule(int $id): void
    {
        Permissions::assert(Capabilities::MANAGE_SHIPPING);

        $rule = $this->requireRule($id);

        if (!$this->rules->delete($id)) {
            throw ApiException::internal('The shipping rule could not be deleted.');
        }

        $this->audit->record('shipping.rule_deleted', 'shipping_rule', $id, $rule->toArray());
    }

    private function requireRule(int $id): ShippingRule
    {
        $rule = $this->rules->find($id);

        if ($rule === null) {
            throw ApiException::notFound('No shipping rule with that id.');
        }

        return $rule;
    }

    /**
     * A rule may only name places that exist.
     *
     * Zero is not checked, because zero is how a rule says "anywhere" — that is
     * the national fallback, not a missing id.
     */
    private function guardRulePlaces(ShippingRule $rule): void
    {
        if ($rule->wilayaId !== 0 && $this->geography->findWilaya($rule->wilayaId) === null) {
            throw ApiException::invalidRequest('The shipping rule is invalid.', [
                'fields' => ['wilaya_id' => "No wilaya with id {$rule->wilayaId}."],
            ]);
        }

        if ($rule->communeId === 0) {
            return;
        }

        $commune = $this->geography->findCommune($rule->communeId);

        if ($commune === null) {
            throw ApiException::invalidRequest('The shipping rule is invalid.', [
                'fields' => ['commune_id' => "No commune with id {$rule->communeId}."],
            ]);
        }

        if ((int) $commune['wilaya_id'] !== $rule->wilayaId) {
            throw ApiException::invalidRequest('The shipping rule is invalid.', [
                'fields' => ['wilaya_id' => 'That commune belongs to a different wilaya.'],
                'commune_wilaya_id' => (int) $commune['wilaya_id'],
            ]);
        }
    }

    /**
     * A rule naming a courier must name one this shop has.
     *
     * Otherwise the rule is dead on arrival — it can never match — and it looks
     * exactly like a rule that works.
     */
    private function guardRuleProvider(ShippingRule $rule): void
    {
        if ($rule->provider === '' || $this->providers->has($rule->provider)) {
            return;
        }

        throw ApiException::invalidRequest('The shipping rule is invalid.', [
            'fields' => ['provider' => "Unknown provider \"{$rule->provider}\"."],
            'available' => $this->providers->names(),
        ]);
    }

    /**
     * Two prices for the same destination is a question with two answers.
     *
     * The table's unique key would refuse it anyway; this exists so the caller
     * is told which rule collides instead of being handed a database error.
     */
    private function guardNoDuplicateScope(ShippingRule $rule): void
    {
        $conflict = $this->rules->findConflict($rule);

        if ($conflict === null) {
            return;
        }

        throw ApiException::conflict('Another rule already covers exactly that destination.', [
            'rule_id' => $conflict->id,
            'amount' => $conflict->amount,
        ]);
    }

    private static function priceDecimals(): int
    {
        return function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
    }

    private static function currency(): string
    {
        return function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'DZD';
    }

    /** @param array<string, mixed> $metadata */
    private function store(Shipment $shipment, string $action, array $metadata): Shipment
    {
        if (!$this->repository->update($shipment)) {
            throw ApiException::internal('The shipment could not be updated.');
        }

        $this->audit->record($action, 'order', $shipment->orderId, [
            'shipment_id' => $shipment->id,
            'provider' => $shipment->provider,
            ...$metadata,
        ]);

        return $this->repository->find($shipment->id) ?? $shipment;
    }

    private function requireShipment(int $id): Shipment
    {
        $shipment = $this->repository->find($id);

        if ($shipment === null) {
            throw ApiException::notFound('No shipment with that id.');
        }

        return $shipment;
    }

    private function requireOrder(int $orderId): WC_Order
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            throw ApiException::notFound('No order with that id.');
        }

        return $order;
    }

    /**
     * The provider that created this shipment, not the current default.
     *
     * A shop that switches couriers still has parcels in the air with the old
     * one, and cancelling or tracking those has to go back to whoever is
     * actually carrying them. When that adapter is no longer configured this is
     * a 409 rather than a 400: the request is fine, the store's configuration
     * is what cannot answer it.
     */
    private function providerFor(Shipment $shipment): ShippingProviderInterface
    {
        if (!$this->providers->has($shipment->provider)) {
            throw ApiException::conflict('The provider that created this shipment is not configured.', [
                'provider' => $shipment->provider,
                'available' => $this->providers->names(),
            ]);
        }

        return $this->providers->get($shipment->provider);
    }

    /** Nobody ships a cancelled or refunded order. */
    private function guardOrderIsShippable(WC_Order $order): void
    {
        if (!OrderStatus::isTerminal($order->get_status())) {
            return;
        }

        throw ApiException::conflict('An order in this status cannot be shipped.', [
            'order_status' => $order->get_status(),
        ]);
    }

    private function guardNoLiveShipment(int $orderId): void
    {
        $live = $this->repository->liveForOrder($orderId);

        if ($live === null) {
            return;
        }

        throw ApiException::conflict('This order already has a shipment in progress.', [
            'shipment_id' => $live->id,
            'provider' => $live->provider,
            'status' => $live->status,
        ]);
    }

    /**
     * The commune must exist and must belong to the wilaya it arrived with.
     *
     * The pair is checked rather than each half: a commune id from the right
     * dropdown and a wilaya id left over from the previous selection is the
     * mistake an address form actually makes, and it routes a parcel to a
     * commune of the same name in another wilaya — of which Algeria has
     * several.
     */
    private function validatedDestination(Destination $destination): Destination
    {
        $commune = $this->geography->findCommune($destination->communeId);

        if ($commune === null) {
            throw ApiException::invalidRequest('The shipment data is invalid.', [
                'fields' => ['commune_id' => "No commune with id {$destination->communeId}."],
            ]);
        }

        if ((int) $commune['wilaya_id'] !== $destination->wilayaId) {
            throw ApiException::invalidRequest('The shipment data is invalid.', [
                'fields' => ['wilaya_id' => 'That commune belongs to a different wilaya.'],
                'commune_wilaya_id' => (int) $commune['wilaya_id'],
            ]);
        }

        return $destination;
    }

    /** @param array<string, mixed> $payload */
    private function validatedStatus(array $payload): string
    {
        foreach (array_diff(array_keys($payload), ['status']) as $field) {
            throw ApiException::invalidRequest('The shipment data is invalid.', [
                'fields' => [(string) $field => 'Unknown field.'],
            ]);
        }

        $status = is_scalar($payload['status'] ?? null) ? (string) $payload['status'] : '';

        if (!ShipmentStatus::isKnown($status)) {
            throw ApiException::invalidRequest('The shipment data is invalid.', [
                'fields' => ['status' => 'Must be one of: ' . implode(', ', ShipmentStatus::ALL) . '.'],
            ]);
        }

        return ShipmentStatus::normalize($status);
    }

    /**
     * Fill the request from the order where the caller left a field blank.
     *
     * The recipient, phone and street come off the shipping address — which is
     * what that address is good at — while the destination came from the
     * dataset, which is what routing needs. `codAmount` is the order total when
     * the order was placed cash on delivery, and '0' otherwise: it is what the
     * driver must collect at the door, and the one number on this request a
     * mistake in is felt by a customer.
     */
    /**
     * Which attempt this is at delivering the order — 1 for the first parcel.
     *
     * Counted from the shipments already on record rather than kept as a
     * column: a cancelled or returned parcel is still an attempt that happened,
     * and the count is the number of rows that exist.
     */
    private function nextAttempt(int $orderId): int
    {
        return count($this->repository->forOrder($orderId)) + 1;
    }

    private function buildRequest(
        WC_Order $order,
        ShipmentInput $input,
        Destination $destination,
        int $attempt
    ): ShipmentRequest {
        $recipient = $input->recipient !== ''
            ? $input->recipient
            : trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());

        if ($recipient === '') {
            $recipient = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        $phone = $input->phone !== ''
            ? $input->phone
            : ($order->get_shipping_phone() ?: $order->get_billing_phone());

        $address = $input->address !== ''
            ? $input->address
            : trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());

        if ($address === '') {
            $address = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        }

        return new ShipmentRequest(
            $order->get_id(),
            $destination,
            $recipient,
            (string) $phone,
            $address,
            $order->get_payment_method() === PaymentMethod::COD ? (string) $order->get_total() : '0',
            $input->note,
            sprintf('%d-%d', $order->get_id(), $attempt),
            self::contentsOf($order)
        );
    }

    /**
     * The order's line items as one line of text, for a courier's label.
     *
     * Names and quantities only. A parcel manifest is what a driver reads out
     * on the phone and what a customer sees on the label, so prices and SKUs
     * are deliberately left off it — and the string is bounded, because an
     * order with thirty lines still has to fit in one field.
     */
    private static function contentsOf(WC_Order $order): string
    {
        $parts = [];

        foreach ($order->get_items() as $item) {
            $name = trim((string) $item->get_name());

            if ($name === '') {
                continue;
            }

            $quantity = (int) $item->get_quantity();
            $parts[] = $quantity > 1 ? sprintf('%s x%d', $name, $quantity) : $name;
        }

        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /** UTC, in the format every other table in this plugin stores a time in. */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
