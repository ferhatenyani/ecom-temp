<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Order;

/**
 * Cash-on-delivery business rules — roadmap §52, docs/PLAN.md §12.
 *
 * Four rules shape everything here:
 *
 *  - **A COD outcome never changes the order's status.** PLAN.md §8 asks for
 *    metadata and events rather than redundant statuses, so confirming an order
 *    records that the customer said yes and nothing else. Whether that order
 *    then moves to `processing` is the shop's decision, made through
 *    `PATCH /orders/{id}`, and shipment preparation is §53's. A confirmation
 *    that quietly moved an order would also quietly move stock.
 *  - **The reverse direction is wired up**, because it has to be: an order
 *    cancelled anywhere — this API, wp-admin, WP-CLI, a gateway — closes its
 *    COD state through CodSubscriber. A confirmation queue still calling
 *    customers about cancelled orders is the failure this prevents.
 *  - Every outcome is a **transition checked against CodStatus**, so a rejected
 *    order cannot be re-confirmed and the attempt counter cannot be inflated
 *    against an order nobody is still calling.
 *  - Every write is audited. The audit trail is the confirmation history — it
 *    is append-only, it already carries the actor, and the order timeline
 *    already merges it, which is why this module stores no history of its own.
 *
 * **`ENABLE_COD` is deliberately not consulted here.** That flag decides
 * whether checkout *offers* cash on delivery, which is the payment abstraction
 * in §58; these endpoints are the operational handling of orders that already
 * exist. A shop that stops taking new COD orders still has hundreds of them in
 * flight, and a flag that froze their confirmation queue would strand exactly
 * the orders that most need finishing.
 *
 * Authorization is asserted here as well as on the route, for the case where a
 * WP-CLI command or another service calls in without passing through REST
 * (docs/SECURITY.md, "Authorization"). The capability is `ac_manage_orders` for
 * everything that touches one order, and `ac_view_analytics` for the funnel —
 * reading how the shop is performing is not the same job as phoning customers,
 * and the roles that need one often do not need the other.
 */
final class CodService
{
    public function __construct(
        private readonly CodRepository $repository,
        private readonly AuditLogger $audit
    ) {
    }

    public function state(int $orderId): CodState
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        return $this->repository->state($this->requireOrder($orderId));
    }

    /**
     * Turn COD on or off for one order.
     *
     * Deliberately separate from the payment method. An order taken over the
     * phone may be paid COD without anyone wanting it in the confirmation
     * queue, and a shop that switches an order to a card payment should stop
     * calling about it — neither is a reason to rewrite how the order was paid.
     *
     * @param array<string, mixed> $payload
     */
    public function setEnabled(int $orderId, array $payload): CodState
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $order = $this->requireOrder($orderId);
        $input = CodSettingsInput::fromPayload($payload);
        $state = $this->repository->state($order);

        if ($state->enabled === $input->enabled) {
            // Nothing changed, so nothing is written and nothing is audited: a
            // retried request must not fill the trail with events that did not
            // happen.
            return $state;
        }

        $saved = $this->repository->save($order, $state->withEnabled($input->enabled));

        $this->audit->record('cod.settings_changed', 'order', $orderId, [
            'enabled' => $saved->enabled,
            'previous' => $state->enabled,
            'status' => $saved->status,
        ]);

        return $saved;
    }

    /**
     * Record what a confirmation call concluded with.
     *
     * The one write in this module that an operator makes many times a day, and
     * the reason the attempt counter exists: two failed calls are two attempts,
     * and "unreachable customers" is a count of people, not of orders.
     *
     * @param array<string, mixed> $payload
     */
    public function recordAttempt(int $orderId, array $payload): CodState
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $order = $this->requireOrder($orderId);
        $input = CodAttemptInput::fromPayload($payload);
        $state = $this->repository->state($order);

        $this->guardOrderIsOpen($order);
        $this->guardEnabled($state, $orderId);
        $this->guardTransition($state, $input->outcome);

        $saved = $this->repository->save(
            $order,
            $state->record($input->outcome, $input->reason, self::now())
        );

        /*
         * The event, not the state: this row is one phone call, and the
         * append-only trail of them is the confirmation history this module
         * deliberately does not duplicate into a table of its own. The reason
         * is recorded here as well as on the order, because the state keeps
         * only the most recent one.
         */
        $this->audit->record('cod.attempt_recorded', 'order', $orderId, [
            'outcome' => $saved->status,
            'from' => $state->status,
            'attempt' => $saved->attempts,
            'reason' => $input->reason,
        ]);

        return $saved;
    }

    /**
     * The COD funnel — confirmation, cancellation, delivery and return rates.
     *
     * `customer_id` is how PLAN.md §9's "COD history" on a customer is
     * answered: the same funnel, scoped to one buyer. It is a risk *signal* and
     * nothing more. Roadmap §52 is explicit — do not automatically ban a
     * customer on a single weak signal — so nothing in this module blocks,
     * flags or bans anybody; it reports, and a person decides.
     *
     * @param array{customer_id: int, date_from: string, date_to: string} $criteria
     * @return array<string, mixed>
     */
    public function statistics(array $criteria): array
    {
        Permissions::assert(Capabilities::VIEW_ANALYTICS);

        return CodStatistics::compute($this->repository->counts($criteria));
    }

    private function requireOrder(int $orderId): WC_Order
    {
        $order = $this->repository->find($orderId);

        if ($order === null) {
            throw ApiException::notFound('No order with that id.');
        }

        return $order;
    }

    private function guardEnabled(CodState $state, int $orderId): void
    {
        if ($state->enabled) {
            return;
        }

        throw ApiException::conflict('Cash on delivery is not enabled for this order.', [
            'order_id' => $orderId,
        ]);
    }

    /**
     * Nobody phones a customer to confirm an order that has been cancelled or
     * refunded. A cancelled order's COD state is already closed by
     * CodSubscriber, so this guard is what covers `refunded`, which is terminal
     * for the order and says the money has already gone back.
     */
    private function guardOrderIsOpen(WC_Order $order): void
    {
        $status = $order->get_status();

        if (!OrderStatus::isTerminal($status)) {
            return;
        }

        throw ApiException::conflict('A confirmation cannot be recorded against an order in this status.', [
            'order_status' => $status,
        ]);
    }

    /**
     * A refused outcome is a conflict, not a validation error: the payload is
     * well formed and the outcome exists — this order is simply not in a state
     * that can reach it. The response names what is reachable, so a client can
     * render the buttons that will work.
     */
    private function guardTransition(CodState $state, string $outcome): void
    {
        if (CodStatus::canTransition($state->status, $outcome)) {
            return;
        }

        throw ApiException::conflict(
            "A COD order cannot move from \"{$state->status}\" to \"{$outcome}\".",
            [
                'from' => $state->status,
                'to' => $outcome,
                'allowed' => $state->allowedOutcomes(),
            ]
        );
    }

    /** UTC, in the format every other table in this plugin stores a time in. */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
