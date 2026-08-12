<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\Shipment;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentResult;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Shipping\StatusReport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    private const NOW = '2026-08-12 09:15:00';
    private const LATER = '2026-08-12 18:40:00';

    private static function shipment(string $status = ShipmentStatus::CREATED): Shipment
    {
        return new Shipment(
            42,
            'manual',
            'MAN-42',
            'MAN-42',
            $status,
            ['delivery_type' => 'home'],
            self::NOW,
            self::NOW,
            7
        );
    }

    public function testAShipmentBelongsToAnOrder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Shipment(0, 'manual');
    }

    public function testAShipmentNamesAProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Shipment(42, '   ');
    }

    /**
     * A status outside our vocabulary would reach the table and be found weeks
     * later by an operations screen with a blank column.
     */
    public function testAnUnknownStatusIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Shipment(42, 'manual', '', '', 'en_preparation');
    }

    /**
     * MySQL in strict mode rejects an over-length value outright, which would
     * lose the record of a parcel that has already been handed over.
     */
    public function testOverLongProviderValuesAreTruncatedRatherThanRejected(): void
    {
        $shipment = new Shipment(
            42,
            str_repeat('p', Shipment::MAX_PROVIDER + 10),
            str_repeat('s', Shipment::MAX_PROVIDER_SHIPMENT_ID + 10),
            str_repeat('t', Shipment::MAX_TRACKING + 10)
        );

        self::assertSame(Shipment::MAX_PROVIDER, mb_strlen($shipment->provider));
        self::assertSame(Shipment::MAX_PROVIDER_SHIPMENT_ID, mb_strlen($shipment->providerShipmentId));
        self::assertSame(Shipment::MAX_TRACKING, mb_strlen($shipment->trackingNumber));
    }

    public function testTheRowMatchesTheMigrationColumns(): void
    {
        $row = self::shipment()->toRow();

        self::assertSame(
            ['order_id', 'provider', 'provider_shipment_id', 'tracking_number',
                'status', 'metadata', 'created_at', 'updated_at'],
            array_keys($row)
        );

        self::assertCount(count($row), self::shipment()->rowFormats());
    }

    public function testARowSurvivesARoundTrip(): void
    {
        $before = self::shipment();
        $after = Shipment::fromRow(['id' => 7] + $before->toRow());

        self::assertEquals($before, $after);
    }

    /** Empty metadata stores as '' rather than as the string "[]". */
    public function testEmptyMetadataIsStoredAsNothing(): void
    {
        self::assertSame('', (new Shipment(42, 'manual'))->encodedMetadata());
    }

    public function testCorruptStoredMetadataDoesNotBreakReading(): void
    {
        $shipment = Shipment::fromRow([
            'id' => 3,
            'order_id' => 42,
            'provider' => 'manual',
            'status' => ShipmentStatus::CREATED,
            'metadata' => '{not json',
        ]);

        self::assertSame([], $shipment->metadata);
        self::assertSame(42, $shipment->orderId);
    }

    public function testAStatusReportMovesTheShipmentAndKeepsTheProvidersWording(): void
    {
        $updated = self::shipment()->withReport(
            new StatusReport(ShipmentStatus::IN_TRANSIT, 'EN ROUTE', ['hub' => 'Alger']),
            self::LATER
        );

        self::assertSame(ShipmentStatus::IN_TRANSIT, $updated->status);
        self::assertSame('EN ROUTE', $updated->metadata['provider_status']);
        self::assertSame('Alger', $updated->metadata['hub']);
        // What was already known about the parcel is not lost on an update.
        self::assertSame('home', $updated->metadata['delivery_type']);
        self::assertSame(self::LATER, $updated->updatedAt);
        self::assertSame(self::NOW, $updated->createdAt);
    }

    public function testTheIdentitySurvivesAStatusChange(): void
    {
        $updated = self::shipment()->withStatus(ShipmentStatus::DELIVERED, self::LATER);

        self::assertSame(7, $updated->id);
        self::assertSame(42, $updated->orderId);
        self::assertSame('MAN-42', $updated->trackingNumber);
        self::assertFalse($updated->isLive());
    }

    public function testTheWireFormatSaysWhetherTheShopIsStillWaiting(): void
    {
        self::assertTrue(self::shipment()->toArray()['is_live']);
        self::assertFalse(self::shipment(ShipmentStatus::RETURNED)->toArray()['is_live']);
    }

    public function testTheWireFormatPresentsTimestampsAsIso(): void
    {
        $wire = self::shipment()->toArray();

        self::assertSame('2026-08-12T09:15:00+00:00', $wire['created_at']);
        self::assertNull((new Shipment(42, 'manual'))->toArray()['created_at']);
    }

    public function testAProviderCannotReturnAnUnmappedStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShipmentResult('ABC', 'ABC', 'chez_le_livreur');
    }

    public function testAProviderCannotReportAnUnmappedStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StatusReport('livré', 'livré');
    }

    public function testADestinationCarriesOnlyWhatACourierPricesOn(): void
    {
        $destination = new Destination(16, 1234, Destination::DESK);

        self::assertTrue($destination->isDesk());
        self::assertSame(
            ['wilaya_id' => 16, 'commune_id' => 1234, 'delivery_type' => 'desk'],
            $destination->toArray()
        );
    }

    public function testHomeDeliveryIsTheDefault(): void
    {
        self::assertSame(Destination::HOME, (new Destination(16, 1234))->deliveryType);
        self::assertTrue(Destination::isKnownDeliveryType('DESK'));
        self::assertFalse(Destination::isKnownDeliveryType('locker'));
    }

    /**
     * The amount the driver collects at the door is the one number on a request
     * that a customer feels if it is wrong.
     */
    public function testARequestKnowsWhetherThereIsMoneyToCollect(): void
    {
        $destination = new Destination(16, 1234);

        $cod = new ShipmentRequest(42, $destination, 'Amina', '0550', 'Rue X', '3900.00');
        $paid = new ShipmentRequest(42, $destination, 'Amina', '0550', 'Rue X');

        self::assertTrue($cod->isCashOnDelivery());
        self::assertFalse($paid->isCashOnDelivery());
        self::assertSame('0', $paid->codAmount);
        self::assertSame('3900.00', $cod->toArray()['cod_amount']);
    }
}
