<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Tracking\TrackingPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §84's disclosure list, asserted field by field.
 *
 * The failure mode this file exists for is not a leak today — it is a presenter
 * that grows a field in six months and nobody notices. So the assertions are
 * written against the *shape of the output* rather than against a list of leaks
 * somebody thought of: the top-level keys must be exactly `PUBLIC_FIELDS`, and
 * the encoded response must not contain any refused name or any value from the
 * hostile fixture below.
 *
 * That fixture is the point. `publicView()` is handed a whole shipment row —
 * metadata included, with a Yalidine `label` URL in it, which is a credential to
 * one customer's name, phone and address (§55) — and must publish none of it.
 * Filtering the *input contract* instead would depend on every future caller
 * reading a docblock.
 */
final class TrackingPresenterTest extends TestCase
{
    /**
     * A shipment row exactly as `Shipment::toArray()` returns one, with the
     * metadata a live Yalidine parcel actually carries.
     *
     * @return array<string, mixed>
     */
    private function hostileShipment(): array
    {
        return [
            'id' => 77,
            'order_id' => 4211,
            'provider' => 'yalidine',
            'provider_shipment_id' => 'yal-16-ABCDEF',
            'tracking_number' => 'yal-16-ABCDEF',
            'status' => ShipmentStatus::IN_TRANSIT,
            'is_live' => true,
            'metadata' => [
                // The one field §84 says must never appear under any circumstance.
                'label' => 'https://api.yalidine.app/labels/ABCDEF?token=secret-bearer-value',
                'labels' => 'https://api.yalidine.app/labels/bulk?token=secret-bearer-value',
                'to_wilaya_name' => 'Alger',
                'to_commune_name' => 'Ouled Fayet',
                'reference' => '4211-1',
                'cod_amount' => '26350.00',
                'phone' => '0551020304',
                'address' => '12 rue des Freres Bouadou',
            ],
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-03T14:30:00+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function view(): array
    {
        return TrackingPresenter::publicView(
            '4211',
            'processing',
            $this->hostileShipment(),
            TrackingPresenter::history([
                [
                    'action' => 'shipment.status_changed',
                    'created_at' => '2026-08-03 14:30:00',
                    'metadata' => ['shipment_id' => 77, 'from' => 'picked_up', 'to' => 'in_transit'],
                ],
                [
                    'action' => 'shipment.created',
                    'created_at' => '2026-08-01 09:00:00',
                    'metadata' => ['shipment_id' => 77, 'status' => 'created', 'tracking_number' => 'yal-16-ABCDEF'],
                ],
            ], 77),
            ['id' => 16, 'code' => '16', 'name' => 'Alger', 'name_ar' => 'الجزائر']
        );
    }

    public function testEmitsExactlyTheAllowedTopLevelFields(): void
    {
        self::assertSame(TrackingPresenter::PUBLIC_FIELDS, array_keys($this->view()));
    }

    /**
     * §84's allowed list, one assertion each. Written out rather than looped so
     * a failure names the field that went missing.
     */
    public function testPublishesEverythingItIsAllowedTo(): void
    {
        $view = $this->view();

        self::assertSame('4211', $view['order_number']);
        self::assertSame('processing', $view['order_status']);
        self::assertSame('yalidine', $view['shipment']['courier']);
        self::assertSame('yal-16-ABCDEF', $view['shipment']['tracking_number']);
        self::assertSame(ShipmentStatus::IN_TRANSIT, $view['shipment']['status']);
        self::assertTrue($view['shipment']['is_live']);
        self::assertSame(16, $view['destination']['wilaya_id']);
        self::assertSame('Alger', $view['destination']['wilaya']);
        self::assertCount(2, $view['history']);
        self::assertSame('2026-08-01T09:00:00+00:00', $view['history'][0]['at']);
    }

    /**
     * **The parcel's status and the order's status sit side by side and nothing
     * merges them** — §55's rule, which a tracking view does not get to be the
     * exception to. A parcel `in_transit` under an order still `processing` is
     * the ordinary case, and a presenter that "helpfully" reported the order as
     * shipped would be inventing an order status this API does not have.
     */
    public function testTheParcelStatusDoesNotBecomeTheOrderStatus(): void
    {
        $view = $this->view();

        self::assertSame('processing', $view['order_status']);
        self::assertNotSame($view['order_status'], $view['shipment']['status']);
    }

    #[DataProvider('refusedFields')]
    public function testRefusesTheDisclosureListByName(string $field): void
    {
        self::assertArrayNotHasKey($field, $this->view());
        self::assertArrayNotHasKey($field, $this->view()['shipment']);
    }

    /** @return list<array{string}> */
    public static function refusedFields(): array
    {
        return array_map(
            static fn (string $field): array => [$field],
            TrackingPresenter::REFUSED_FIELDS
        );
    }

    /**
     * The value check, which is the one that survives a field being renamed.
     *
     * A presenter that emitted `courier_label` instead of `label` would pass the
     * key assertions above and fail this.
     */
    public function testNoRefusedValueSurvivesAnywhereInTheResponse(): void
    {
        $encoded = (string) json_encode($this->view());

        foreach ([
            'secret-bearer-value',
            'api.yalidine.app/labels',
            '12 rue des Freres Bouadou',
            '0551020304',
            '26350.00',
            'Ouled Fayet',
            'yal-16-ABCDEF/label',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded, "\"{$forbidden}\" reached a public tracking page");
        }
    }

    /**
     * §84 stops at the wilaya, and the commune is what a tracking page must not
     * narrow down to. `to_commune_name` is right there in the metadata.
     */
    public function testTheDestinationStopsAtTheWilaya(): void
    {
        $destination = $this->view()['destination'];

        self::assertSame(['wilaya_id', 'wilaya_code', 'wilaya', 'wilaya_ar'], array_keys($destination));
    }

    /**
     * An order that never went through checkout and whose parcel recorded no
     * wilaya is reported as absent — never guessed out of the address, which is
     * the guess `ShipmentInput` refuses and §63 refused again.
     */
    public function testAnUnknownDestinationIsNullRatherThanInvented(): void
    {
        $view = TrackingPresenter::publicView('4211', 'pending', null, [], null);

        self::assertNull($view['destination']);
        self::assertNull($view['shipment']);
        self::assertSame([], $view['history']);
        self::assertSame(TrackingPresenter::PUBLIC_FIELDS, array_keys($view));
    }

    public function testTheOwnerViewCarriesNoMetadataEither(): void
    {
        $view = TrackingPresenter::ownerView($this->hostileShipment(), []);
        $encoded = (string) json_encode($view);

        self::assertArrayNotHasKey('metadata', $view);
        self::assertStringNotContainsString('secret-bearer-value', $encoded);
        self::assertSame('yalidine', $view['courier']);
        self::assertArrayHasKey('history', $view);
    }

    // ------------------------------------------------------------- history --

    public function testHistoryReadsOldestFirst(): void
    {
        $history = TrackingPresenter::history([
            ['action' => 'shipment.status_changed', 'created_at' => '2026-08-05 08:00:00', 'metadata' => ['to' => 'delivered']],
            ['action' => 'shipment.status_changed', 'created_at' => '2026-08-03 08:00:00', 'metadata' => ['to' => 'in_transit']],
            ['action' => 'shipment.created', 'created_at' => '2026-08-01 08:00:00', 'metadata' => ['status' => 'created']],
        ]);

        self::assertSame(['created', 'in_transit', 'delivered'], array_column($history, 'status'));
    }

    /**
     * An order re-sent after a failed delivery has two parcels, and interleaving
     * them shows a customer their new parcel going backwards.
     */
    public function testHistoryIsScopedToOneParcel(): void
    {
        $events = [
            ['action' => 'shipment.status_changed', 'created_at' => '2026-08-09 08:00:00', 'metadata' => ['shipment_id' => 78, 'to' => 'in_transit']],
            ['action' => 'shipment.created', 'created_at' => '2026-08-08 08:00:00', 'metadata' => ['shipment_id' => 78, 'status' => 'created']],
            ['action' => 'shipment.status_changed', 'created_at' => '2026-08-05 08:00:00', 'metadata' => ['shipment_id' => 77, 'to' => 'returned']],
        ];

        self::assertCount(2, TrackingPresenter::history($events, 78));
        self::assertCount(1, TrackingPresenter::history($events, 77));
        self::assertCount(3, TrackingPresenter::history($events), 'an unscoped call keeps every parcel');
    }

    /**
     * `shipment.record_failed` means the parcel exists at the courier and this
     * shop could not write it down. That is an operator alarm — and the audit
     * metadata is the only surviving copy of the tracking number — not a step on
     * a customer's journey.
     */
    public function testHistoryDropsTheOperatorAlarm(): void
    {
        $history = TrackingPresenter::history([
            ['action' => 'shipment.record_failed', 'created_at' => '2026-08-01 08:00:00', 'metadata' => ['tracking_number' => 'yal-1']],
            ['action' => 'order.status_changed', 'created_at' => '2026-08-01 08:00:00', 'metadata' => ['to' => 'processing']],
            ['action' => 'cod.attempt_recorded', 'created_at' => '2026-08-01 08:00:00', 'metadata' => ['outcome' => 'no_answer']],
        ]);

        self::assertSame([], $history);
    }

    public function testHistoryDropsAStatusThisSystemCannotReasonAbout(): void
    {
        $history = TrackingPresenter::history([
            ['action' => 'shipment.status_changed', 'created_at' => '2026-08-01 08:00:00', 'metadata' => ['to' => 'Retour vers centre']],
            ['action' => 'shipment.status_changed', 'created_at' => '2026-08-02 08:00:00', 'metadata' => ['to' => 'delivered']],
        ]);

        self::assertSame(['delivered'], array_column($history, 'status'));
    }

    public function testACancellationIsItsOwnStep(): void
    {
        $history = TrackingPresenter::history([
            ['action' => 'shipment.cancelled', 'created_at' => '2026-08-02 08:00:00', 'metadata' => ['from' => 'created']],
        ]);

        self::assertSame([['status' => 'cancelled', 'at' => '2026-08-02T08:00:00+00:00']], $history);
    }

    // -------------------------------------------------- estimated delivery --

    public function testAnEstimateIsPublishedWhenACourierGivesOne(): void
    {
        $shipment = $this->hostileShipment();
        $shipment['metadata']['estimated_delivery'] = '2026-08-06';

        $view = TrackingPresenter::publicView('4211', 'processing', $shipment, [], null);

        self::assertSame('2026-08-06', $view['shipment']['estimated_delivery']);
    }

    /** No adapter supplies one today, so the ordinary answer is null. */
    public function testNoEstimateIsNullRatherThanADate(): void
    {
        self::assertNull($this->view()['shipment']['estimated_delivery']);
    }

    /**
     * The key allowlist alone is not enough: a provider that put a URL under a
     * plausible name would otherwise publish it. Both gates are needed.
     *
     */
    #[DataProvider('notDates')]
    public function testAnEstimateThatIsNotADateIsRefused(string $value): void
    {
        $shipment = $this->hostileShipment();
        $shipment['metadata']['estimated_delivery'] = $value;

        $view = TrackingPresenter::publicView('4211', 'processing', $shipment, [], null);

        self::assertNull($view['shipment']['estimated_delivery']);
        self::assertStringNotContainsString($value, (string) json_encode($view));
    }

    /** @return array<string, array{string}> */
    public static function notDates(): array
    {
        return [
            'a label url' => ['https://api.yalidine.app/labels/X?token=secret-bearer-value'],
            'an address' => ['12 rue des Freres Bouadou, Ouled Fayet'],
            'a phone' => ['0551020304'],
            'markup' => ['<script>alert(1)</script>'],
            'a loose sentence' => ['probably next Tuesday'],
        ];
    }

    public function testAnEstimateUnderAnUnknownKeyIsIgnored(): void
    {
        $shipment = $this->hostileShipment();
        $shipment['metadata']['delivery_estimate'] = '2026-08-06';

        self::assertNull(
            TrackingPresenter::publicView('4211', 'processing', $shipment, [], null)['shipment']['estimated_delivery']
        );
    }

    // ------------------------------------------------------------- timings --

    public function testAnUnreadableTimestampBecomesEmptyRatherThanNineteenSeventy(): void
    {
        $shipment = $this->hostileShipment();
        $shipment['created_at'] = 'not a date';
        $shipment['updated_at'] = '';

        $view = TrackingPresenter::publicView('4211', 'processing', $shipment, [], null);

        self::assertSame('', $view['shipment']['created_at']);
        self::assertSame('', $view['shipment']['updated_at']);
    }

    public function testAnUnknownShipmentStatusIsNotEchoedBack(): void
    {
        $shipment = $this->hostileShipment();
        $shipment['status'] = '<script>alert(1)</script>';

        $view = TrackingPresenter::publicView('4211', 'processing', $shipment, [], null);

        self::assertSame('', $view['shipment']['status']);
        self::assertFalse($view['shipment']['is_live']);
    }
}
