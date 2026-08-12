<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ManualProvider;
use AlgerianCommerce\Shipping\ProviderRegistry;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentResult;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Shipping\ShippingProviderInterface;
use AlgerianCommerce\Shipping\StatusReport;
use PHPUnit\Framework\TestCase;

/**
 * A stand-in for a courier that does not exist yet.
 *
 * Writing one in ten lines is the property the abstraction exists for: if a
 * second provider were hard to fake, Yalidine would be hard to add.
 */
final class FakeCourier implements ShippingProviderInterface
{
    public function __construct(private readonly string $name = 'fake')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'Fake courier';
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return new ShipmentResult('FAKE-1', 'TRACK-1', ShipmentStatus::CREATED);
    }

    public function cancelShipment(string $providerShipmentId): bool
    {
        return true;
    }

    public function getShipmentStatus(string $providerShipmentId): StatusReport
    {
        return new StatusReport(ShipmentStatus::IN_TRANSIT, 'ON_THE_ROAD');
    }

    /** @return list<\AlgerianCommerce\Shipping\RateQuote> */
    public function getShippingRates(Destination $destination): array
    {
        return [];
    }
}

final class ProviderRegistryTest extends TestCase
{
    public function testTheFirstProviderRegisteredIsTheDefault(): void
    {
        $registry = new ProviderRegistry([new FakeCourier(), new ManualProvider()]);

        self::assertSame('fake', $registry->defaultName());
        self::assertSame('fake', $registry->get()->name());
        self::assertSame(['fake', 'manual'], $registry->names());
    }

    public function testAProviderIsResolvedByNameRegardlessOfCasing(): void
    {
        $registry = new ProviderRegistry([new ManualProvider()]);

        self::assertSame('manual', $registry->get('MANUAL')->name());
        self::assertSame('manual', $registry->get(' manual ')->name());
        self::assertTrue($registry->has('Manual'));
        self::assertFalse($registry->has('yalidine'));
    }

    /**
     * The caller sent a value this shop does not accept, so the response names
     * the ones it does — an admin UI can correct itself from that.
     */
    public function testAnUnknownProviderIsARequestErrorNamingTheAlternatives(): void
    {
        $registry = new ProviderRegistry([new ManualProvider()]);

        try {
            $registry->get('yalidine');
            self::fail('an unconfigured provider must be refused');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->statusCode());
            self::assertSame(['manual'], $exception->details()['available']);
        }
    }

    /**
     * Nothing is wrong with the request — the shop simply cannot ship anything
     * until a courier is configured, which is a 409.
     */
    public function testAShopWithNoProviderCannotShip(): void
    {
        try {
            (new ProviderRegistry())->get();
            self::fail('an empty registry must refuse');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->statusCode());
            self::assertSame('no_shipping_provider', $exception->errorCode());
        }
    }

    /**
     * Two live adapters for one courier would make which credentials get used
     * depend on array order.
     */
    public function testRegisteringTheSameProviderTwiceReplacesIt(): void
    {
        $registry = new ProviderRegistry([new FakeCourier('yalidine'), new FakeCourier('yalidine')]);

        self::assertSame(['yalidine'], $registry->names());
    }

    public function testDescribeMarksTheDefaultForAProviderPicker(): void
    {
        $registry = new ProviderRegistry([new ManualProvider(), new FakeCourier()]);

        self::assertSame(
            [
                ['name' => 'manual', 'label' => 'In-house delivery', 'is_default' => true],
                ['name' => 'fake', 'label' => 'Fake courier', 'is_default' => false],
            ],
            $registry->describe()
        );
    }

    public function testInHouseDeliveryAcceptsAParcelImmediately(): void
    {
        $result = (new ManualProvider())->createShipment(new ShipmentRequest(
            42,
            new Destination(16, 1234),
            'Amina Benali',
            '0550123456',
            '12 Rue Didouche Mourad',
            '3900.00',
            '',
            '42-2'
        ));

        // Ours, clearly not pretending to be a courier's code, and identifying
        // the second attempt at order 42 rather than the order — a re-send
        // after a failed delivery must not reuse the first parcel's number.
        self::assertSame('MAN-42-2', $result->trackingNumber);
        self::assertSame(ShipmentStatus::CREATED, $result->status);
        // The only record of what a driver was sent to collect.
        self::assertSame('3900.00', $result->metadata['cod_amount']);
    }

    /** A request built without a reference still gets a usable number. */
    public function testInHouseTrackingFallsBackToTheOrderId(): void
    {
        $result = (new ManualProvider())->createShipment(
            new ShipmentRequest(42, new Destination(16, 1234), 'Amina', '0550', 'Rue X')
        );

        self::assertSame('MAN-42', $result->trackingNumber);
    }

    /**
     * Answering "created" here would look like a successful sync and would walk
     * a shipment a person had already advanced back to the beginning.
     */
    public function testInHouseDeliveryRefusesToBeSyncedRatherThanInventingAStatus(): void
    {
        try {
            (new ManualProvider())->getShipmentStatus('MAN-42');
            self::fail('in-house delivery has no status API');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->statusCode());
            self::assertSame('sync_unsupported', $exception->errorCode());
        }
    }

    /**
     * Not "free" — unpriced. What a shop charges for its own delivery is §14's
     * zone and wilaya pricing, and a 0.00 here would decide that on its behalf.
     */
    public function testInHouseDeliveryQuotesNothing(): void
    {
        self::assertSame([], (new ManualProvider())->getShippingRates(new Destination(16, 1234)));
    }
}
