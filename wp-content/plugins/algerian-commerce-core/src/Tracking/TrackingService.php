<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tracking;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditRepository;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Geography\GeoRepository;
use AlgerianCommerce\Security\RateLimiter;
use AlgerianCommerce\Shipping\Shipment;
use AlgerianCommerce\Shipping\ShipmentRepository;
use AlgerianCommerce\Shipping\ShipmentStatus;
use WC_Order;
use WP_REST_Request;

/**
 * Order tracking — roadmap §84, docs/PLAN.md §13.
 *
 * **The data has existed since §55; the gap was exposure, and the whole risk is
 * authorization.** `ac_shipments` has held the tracking number, the status and
 * the timestamps all along, `ShipmentPoller` has kept them current and both
 * courier webhooks re-fetch into the same path — but the only route that read
 * any of it was `GET /orders/{id}/shipments` behind `ac_manage_shipping`, so a
 * customer could not see where their parcel was and neither could a storefront
 * on their behalf without the admin credential §44 forbids.
 *
 * ## Two doors, and they are not the same door
 *
 * ```
 * GET /account/orders/{id}   → a `shipment` block   session-owned   forOwner()
 * GET /orders/track          → status and history   token-owned     track()
 * ```
 *
 * The first is three lines because §59c did the work: the session resolves the
 * customer, `Permissions::assertOwnsOr()` already runs in `AccountService`, and
 * the parcel is a keyed repository read.
 *
 * **The second exists because guest checkout is built and supported.**
 * `AccountService::order()` refuses `customer_id = 0` outright and §59c's
 * reasoning was right — the only evidence linking a shopper to a guest order is
 * an email address, which would make it readable by anyone who could name it. But
 * a COD shop in Algeria takes a large share of its orders as guests, so tracking
 * that excludes them is not tracking. The token is what replaces the missing
 * account: see `TrackingToken` for why order-number-plus-phone is not an option.
 *
 * ## Two standing rules, unchanged
 *
 * **A parcel's status never moves the order.** True since §55 and a tracking view
 * does not get to be the exception: `order_status` and the parcel's `status` sit
 * side by side in the response and nothing merges them.
 *
 * **`ShipmentRepository` remains the only place a shipment row is read as a
 * `Shipment`.** This service asks it; `AnalyticsRepository` is the one aggregate
 * exception in the codebase and this is not a second one.
 */
final class TrackingService
{
    /**
     * How long a link keeps working after the parcel finished.
     *
     * §84 asks for "revocable, or at least expiring some fixed period after the
     * shipment reaches a terminal status", because a tracking link in an email
     * otherwise lives forever. Both halves exist: this is the expiry, and
     * `TrackingLink::revoke()` is the revocation.
     *
     * Ninety days rather than thirty: an Algerian COD return can take weeks, a
     * customer chasing a refund refers back to the same link, and the shop's own
     * support staff are usually reading the customer's forwarded link rather than
     * the admin. The window starts at the parcel's last movement, not at the
     * order date.
     */
    public const DAYS_AFTER_TERMINAL = 90;

    /** Enough steps for a parcel that went out, came back and went out again. */
    private const MAX_HISTORY = 50;

    public function __construct(
        private readonly TrackingLink $links,
        private readonly ShipmentRepository $shipments,
        private readonly AuditRepository $audit,
        private readonly GeoRepository $geography,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger
    ) {
    }

    /**
     * The public route.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 429 over the limit, 404 for any token that does not
     *                      verify, 410 for one that does but has expired
     */
    public function track(WP_REST_Request $request, string $token): array
    {
        unset($request);

        // Before anything is looked up, and on every call rather than only on a
        // failure. This is an unauthenticated read and `RateLimitGuard` watches
        // Application Password failures, which a token presented here is not —
        // §59c found exactly this gap for customer logins and closed it by having
        // the service count for itself.
        $this->guardRate();

        $order = $this->links->resolve($token);

        if ($order === null) {
            /*
             * One answer for malformed, wrong-MAC, unknown-order and
             * never-issued. Distinguishing them tells somebody guessing which
             * half of the guess was right, and there is nothing a legitimate
             * holder of a link learns from the difference.
             */
            $this->logger->info('A tracking token did not verify', [
                'presented_length' => strlen(trim($token)),
            ]);

            throw ApiException::notFound('No order matches that tracking link.');
        }

        $shipment = $this->newestShipment($order->get_id());

        $this->guardNotExpired($shipment);

        return TrackingPresenter::publicView(
            (string) $order->get_order_number(),
            (string) $order->get_status(),
            $shipment?->toArray(),
            $this->historyFor($order->get_id(), $shipment?->id ?? 0),
            $this->wilayaFor($order, $shipment)
        );
    }

    /**
     * The `shipment` block for a shopper reading their own order.
     *
     * No authorization here on purpose: the caller is `AccountService::order()`,
     * which has already required a session and run
     * `Permissions::assertOwnsOr()`. Duplicating that check would suggest this
     * method were safe to call from somewhere that had not, which it is not.
     *
     * Null when the order has no parcel yet, which is the ordinary state of a
     * `pending` order and not an error.
     *
     * @return array<string, mixed>|null
     */
    public function forOwner(int $orderId): ?array
    {
        $shipment = $this->newestShipment($orderId);

        if ($shipment === null) {
            return null;
        }

        return TrackingPresenter::ownerView(
            $shipment->toArray(),
            $this->historyFor($orderId, $shipment->id)
        );
    }

    /**
     * The parcel a customer is asking about — the newest.
     *
     * Newest rather than "the live one": a delivered parcel is exactly what
     * somebody opening a tracking link a week later wants to see, and
     * `liveForOrder()` would answer null for it.
     */
    private function newestShipment(int $orderId): ?Shipment
    {
        // forOrder() is ordered newest first, which is the order this wants.
        return $this->shipments->forOrder($orderId)[0] ?? null;
    }

    /**
     * @return list<array{status: string, at: string}>
     */
    private function historyFor(int $orderId, int $shipmentId): array
    {
        $events = $this->audit->paginate(
            ['resource_type' => 'order', 'resource_id' => $orderId],
            1,
            self::MAX_HISTORY
        );

        return TrackingPresenter::history($events, $shipmentId);
    }

    /**
     * The destination wilaya, from the two places that hold a canonical id.
     *
     * **Never from the address**, which is the guess `ShipmentInput` refuses to
     * make and §63 refused again for reporting: an order's `state` and `city` are
     * free text in two languages, and Algeria has several communes of the same
     * name in different wilayas.
     *
     * Two sources, in order. `_ac_wilaya_id` is what §59b's checkout wrote — the
     * id the shopper picked from `GET /locations/*` and the tariff was quoted
     * against. A parcel's metadata is the fallback, and `ManualProvider` is the
     * adapter that records one. Either way the id is resolved against the §51
     * dataset, so a value that is not a wilaya becomes `null` rather than
     * reaching the response.
     *
     * @return array<string, mixed>|null
     */
    private function wilayaFor(WC_Order $order, ?Shipment $shipment): ?array
    {
        $candidates = [
            (int) $order->get_meta('_ac_wilaya_id'),
            (int) ($shipment?->metadata['wilaya_id'] ?? 0),
        ];

        foreach ($candidates as $id) {
            if ($id <= 0) {
                continue;
            }

            $wilaya = $this->geography->findWilaya($id);

            if ($wilaya !== null) {
                return $wilaya;
            }
        }

        return null;
    }

    /**
     * 410 once a finished parcel has been finished long enough.
     *
     * **A verified-but-expired token is told so, while an unverifiable one is a
     * 404**, and the asymmetry is deliberate rather than sloppy: reaching this
     * check at all requires a valid 128-bit MAC, so a caller who gets 410 already
     * held the link and learns nothing they did not know. Answering 404 instead
     * would send a customer with a three-month-old email to support asking why
     * the shop lost their order.
     *
     * An order with **no** parcel never expires, and that is named rather than
     * hidden: nothing has happened to it yet, so there is no stale delivery
     * address behind the link, and a link that must die today is what
     * `TrackingLink::revoke()` is for.
     *
     * @throws ApiException 410
     */
    private function guardNotExpired(?Shipment $shipment): void
    {
        if ($shipment === null || !ShipmentStatus::isTerminal($shipment->status)) {
            return;
        }

        $finishedAt = strtotime($shipment->updatedAt . ' UTC');

        if ($finishedAt === false) {
            // A row with an unreadable timestamp is not evidence that the link
            // has expired, and refusing on it would break tracking for a
            // migration artefact.
            return;
        }

        if ($finishedAt >= time() - (self::DAYS_AFTER_TERMINAL * DAY_IN_SECONDS)) {
            return;
        }

        throw new ApiException(
            'tracking_link_expired',
            sprintf(
                'This tracking link expired %d days after the parcel was %s.',
                self::DAYS_AFTER_TERMINAL,
                $shipment->status
            ),
            410
        );
    }

    /**
     * §84's own rate-limit group, per IP.
     *
     * Its own group rather than the read limit it also passes through: the read
     * limit is 600 a minute and was sized for an admin dashboard holding a
     * credential, which is not the right allowance for an unauthenticated route
     * whose key is a MAC somebody could try to guess.
     *
     * Deliberately **not** the failed-authentication counter, which
     * `PasswordResetService` does use. That counter locks an IP out of signing in
     * for fifteen minutes, and a customer clicking a three-month-old tracking
     * link has done nothing that should stop them logging in.
     *
     * @throws ApiException 429
     */
    private function guardRate(): void
    {
        $ip = $this->clientIp();

        if ($ip === '') {
            return;
        }

        $this->rateLimiter->enforce($this->rateLimiter->trackingLimit(), 'ip:' . $ip, time(), 'tracking');
    }

    /**
     * REMOTE_ADDR only — the same rule as `RateLimitGuard` and the audit trail.
     * `X-Forwarded-For` is client-controlled, and trusting it here would hand an
     * attacker a fresh allowance on every request.
     */
    private function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $remote;
    }
}
