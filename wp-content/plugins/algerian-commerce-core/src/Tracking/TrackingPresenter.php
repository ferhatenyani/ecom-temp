<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tracking;

use AlgerianCommerce\Shipping\ShipmentStatus;

/**
 * What a tracking page may say — roadmap §84.
 *
 * **This class is the disclosure list, and it is an allowlist rather than a set
 * of omissions.** Pure — arrays in, arrays out, no WordPress, no WooCommerce, no
 * `WC_Order` and no `Shipment` object — so §84's list is a unit test asserted
 * field by field, which is the shape it has to be: the failure mode is not a
 * field leaking today, it is a presenter growing one in six months while
 * everybody's attention is elsewhere.
 *
 * ```
 * allowed:  order number, order status, shipment status, status history with
 *           timestamps, courier name, tracking number, destination WILAYA only,
 *           estimated delivery if the courier gives one
 * refused:  full address, commune, phone, email, customer name, line items,
 *           quantities, totals, payment method, order notes
 * ```
 *
 * **A tracking page that echoes the delivery address turns a leaked link into a
 * doxxing tool**, and tracking links leak — they are forwarded, pasted into
 * chats and screenshotted. A wilaya is enough for a customer to recognise their
 * own parcel and nowhere near enough to find anybody: there are 69 of them and
 * the smallest holds tens of thousands of people.
 *
 * **`publicView()` filters what it is handed instead of trusting it.** It takes
 * the shipment as a plain array and reads only the keys named below, so a caller
 * that passes `Shipment::toArray()` whole — metadata included — still cannot
 * publish a Yalidine `label`, which is a URL carrying an access token to one
 * customer's name, phone and address (§55, `Logger::SENSITIVE_EXACT`). §84 says
 * that URL must never appear under any circumstance; a presenter that filtered
 * its *input contract* rather than its input would depend on every future caller
 * reading this docblock.
 *
 * The two views differ, and the difference is who is asking:
 *
 *   publicView()  a token, held by whoever has the link — the list above
 *   ownerView()   a session, already reading their own order in full — the same
 *                 shipment fields plus the history, still no metadata
 */
final class TrackingPresenter
{
    /**
     * Every key `publicView()` may emit at the top level.
     *
     * Named so `tests/Unit/TrackingPresenterTest` can assert the output's keys
     * are exactly this and nothing else, rather than checking for the handful of
     * leaks somebody thought of.
     *
     * @var list<string>
     */
    public const PUBLIC_FIELDS = [
        'order_number',
        'order_status',
        'destination',
        'shipment',
        'history',
    ];

    /**
     * Fields that must never appear anywhere in a public tracking response, at
     * any depth.
     *
     * A test walks the encoded response for each of these. `label` and `labels`
     * are here for the §55 reason and `commune` because §84 draws the line at
     * the wilaya.
     *
     * @var list<string>
     */
    public const REFUSED_FIELDS = [
        'address', 'address_1', 'address_2', 'billing', 'shipping',
        'commune', 'commune_id', 'city', 'postcode',
        'phone', 'email', 'customer_name', 'first_name', 'last_name',
        'items', 'line_items', 'quantity', 'total', 'subtotal', 'currency',
        'payment_method', 'notes', 'customer_note',
        'label', 'labels', 'metadata',
    ];

    /**
     * Metadata keys a courier's estimated delivery date may arrive under.
     *
     * **No adapter supplies one today** — neither `StatusReport` nor
     * `ShipmentResult` has such a field, and Yalidine and ZR Express do not
     * publish one in the responses §56 and §57 recorded. So this is the hook
     * §84 asks for ("estimated delivery if the courier gives one") rather than a
     * feature, and it reads from a two-key allowlist with a shape check on the
     * value, because the alternative — passing a provider-controlled string
     * through to a public page — is how a label URL would eventually get there
     * under a friendly name.
     *
     * @var list<string>
     */
    private const ESTIMATE_KEYS = ['estimated_delivery', 'expected_delivery_date'];

    /**
     * Audit actions that describe a parcel moving.
     *
     * `shipment.record_failed` is deliberately absent: it means the parcel
     * exists at the courier and this shop could not write it down, which is an
     * operator alarm rather than a step on a customer's journey.
     *
     * @var array<string, string>
     */
    private const HISTORY_ACTIONS = [
        'shipment.created' => 'status',
        'shipment.status_changed' => 'to',
        'shipment.cancelled' => '',
    ];

    /**
     * The public payload — §84's allowed list, and nothing else.
     *
     * @param array<string, mixed>      $shipment    a shipment row as an array, filtered here
     * @param list<array<string, string>> $history   from `history()`
     * @param array<string, mixed>|null $wilaya      the §51 dataset row, or null
     * @return array<string, mixed>
     */
    public static function publicView(
        string $orderNumber,
        string $orderStatus,
        ?array $shipment,
        array $history,
        ?array $wilaya
    ): array {
        return [
            'order_number' => $orderNumber,
            'order_status' => $orderStatus,
            // A wilaya and nothing narrower. `null` when the order never went
            // through checkout and no parcel recorded one — reported as absent
            // rather than guessed out of the address, which is the guess
            // `ShipmentInput` refuses and §63 refused again.
            'destination' => $wilaya === null ? null : [
                'wilaya_id' => (int) ($wilaya['id'] ?? 0),
                'wilaya_code' => (string) ($wilaya['code'] ?? ''),
                'wilaya' => (string) ($wilaya['name'] ?? ''),
                'wilaya_ar' => (string) ($wilaya['name_ar'] ?? ''),
            ],
            'shipment' => $shipment === null ? null : self::shipmentView($shipment),
            'history' => $history,
        ];
    }

    /**
     * The block that hangs off `GET /account/orders/{id}`.
     *
     * The caller holds a session and is already being served their own order in
     * full, so there is nothing here the same response does not already carry —
     * except the parcel, which is the point. Metadata is still excluded, because
     * §84's rule about the label URL has no exception for the customer whose
     * address is on it: the field is a bearer credential, and a storefront that
     * rendered it would put it in a browser's history and its referrers.
     *
     * @param array<string, mixed>        $shipment
     * @param list<array<string, string>> $history
     * @return array<string, mixed>
     */
    public static function ownerView(array $shipment, array $history): array
    {
        return self::shipmentView($shipment) + ['history' => $history];
    }

    /**
     * Audit rows to a parcel's journey — oldest first.
     *
     * Oldest first because a tracking page reads as a journey rather than as a
     * feed; `AuditRepository` answers newest first, as the order timeline wants,
     * so the reversal happens here rather than in a second query.
     *
     * **Only statuses this system knows are published.** The audit metadata is
     * written by `ShippingService` and is always a validated status today, so
     * this filter costs nothing and closes the one route by which an arbitrary
     * string could reach a public page.
     *
     * `$shipmentId` scopes the feed to one parcel. An order re-sent after a
     * failed delivery has two, and interleaving them would show a customer their
     * new parcel going backwards.
     *
     * @param list<array<string, mixed>> $events AuditRepository rows
     * @return list<array{status: string, at: string}>
     */
    public static function history(array $events, int $shipmentId = 0): array
    {
        $entries = [];

        foreach ($events as $event) {
            $action = (string) ($event['action'] ?? '');

            if (!array_key_exists($action, self::HISTORY_ACTIONS)) {
                continue;
            }

            $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

            if ($shipmentId > 0 && (int) ($metadata['shipment_id'] ?? 0) !== $shipmentId) {
                continue;
            }

            $key = self::HISTORY_ACTIONS[$action];
            $status = $key === ''
                ? ShipmentStatus::CANCELLED
                : (string) ($metadata[$key] ?? '');

            if (!ShipmentStatus::isKnown($status)) {
                continue;
            }

            $entries[] = [
                'status' => ShipmentStatus::normalize($status),
                'at' => self::iso((string) ($event['created_at'] ?? '')),
            ];
        }

        return array_values(array_reverse($entries));
    }

    /**
     * The shipment fields both views share.
     *
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    private static function shipmentView(array $shipment): array
    {
        $status = (string) ($shipment['status'] ?? '');

        return [
            // The courier's name as this system knows it — `yalidine`,
            // `zr-express`, `manual`. Not its API response, not its credentials.
            'courier' => (string) ($shipment['provider'] ?? ''),
            'tracking_number' => (string) ($shipment['tracking_number'] ?? ''),
            'status' => ShipmentStatus::isKnown($status) ? ShipmentStatus::normalize($status) : '',
            'is_live' => ShipmentStatus::isLive($status),
            'estimated_delivery' => self::estimate($shipment),
            'created_at' => self::iso((string) ($shipment['created_at'] ?? '')),
            'updated_at' => self::iso((string) ($shipment['updated_at'] ?? '')),
        ];
    }

    /**
     * A courier's own estimate, if it gave one in a form worth publishing.
     *
     * Two gates, and both are needed: the key has to be one of two names, and
     * the value has to look like a date. Either alone would let a provider put
     * something else on a public page under a plausible name.
     *
     * @param array<string, mixed> $shipment
     */
    private static function estimate(array $shipment): ?string
    {
        $metadata = is_array($shipment['metadata'] ?? null) ? $shipment['metadata'] : [];

        foreach (self::ESTIMATE_KEYS as $key) {
            $value = $metadata[$key] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            // Y-m-d, or Y-m-d with a time. Anything else is not a date and does
            // not get published because a courier called it one.
            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T][\d:]{5,8}Z?)?$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * `'Y-m-d H:i:s'` in UTC — how every table here stores a time — as
     * ISO-8601. An unparseable value becomes `''` rather than `1970-01-01`,
     * which would read as a real date somebody could act on.
     */
    private static function iso(string $stored): string
    {
        if (trim($stored) === '') {
            return '';
        }

        $timestamp = strtotime($stored . ' UTC');

        return $timestamp === false ? '' : gmdate('c', $timestamp);
    }
}
