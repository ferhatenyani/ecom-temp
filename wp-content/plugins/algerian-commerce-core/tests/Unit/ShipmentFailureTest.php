<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Shipping\ShipmentFailure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Why no parcel appeared — backend step 2, item 5.
 *
 * `ShipmentSubscriber` cannot be unit-tested: it is a WordPress hook that
 * writes order meta, and it lives in `tests/Api/shipping.php` against a real
 * install. What *is* pure is the value it records, and the rule that value
 * enforces is the one worth testing on its own — an operator sees what somebody
 * decided they should see, and a courier or a PHP runtime does not get to write
 * into a response by throwing an interesting message.
 */
final class ShipmentFailureTest extends TestCase
{
    private const NOW = '2026-08-31 09:15:00';

    public function testACourierRefusalCarriesItsCodeAndItsOwnSentence(): void
    {
        $failure = ShipmentFailure::fromApiException(
            'yalidine',
            new ApiException(
                'yalidine_parcel_refused',
                'Yalidine would not create this parcel.',
                400,
                ['provider' => 'yalidine', 'provider_message' => 'commune introuvable']
            ),
            self::NOW
        );

        self::assertSame('yalidine_parcel_refused', $failure->code, 'the code is what a panel branches on');
        self::assertSame('Yalidine would not create this parcel.', $failure->message);
        self::assertSame(
            'commune introuvable',
            $failure->providerMessage,
            "the courier's own sentence is the half that tells the operator which field to fix"
        );
    }

    /**
     * Both adapters publish `provider_message` and neither always has one to
     * publish — `YalidineProvider::createShipment()` runs the whole details
     * array through `array_filter()`, so an empty message is simply absent.
     */
    public function testACourierThatSaidNothingOfItsOwnPublishesNull(): void
    {
        $failure = ShipmentFailure::fromApiException(
            'zrexpress',
            new ApiException('provider_unreachable', 'ZR Express could not be reached.', 502),
            self::NOW
        );

        self::assertSame('', $failure->providerMessage);
        self::assertNull(
            $failure->toArray()['provider_message'],
            'absent is null on the wire, never the empty string'
        );
    }

    /**
     * The `Throwable` branch is what makes "never throws" safe rather than
     * merely quiet: a provider is third-party code, and its exceptions carry
     * file paths, SQL and occasionally a credential (docs/SECURITY.md).
     */
    public function testAnUnexpectedFailureNamesItsClassAndRepeatsNothingItSaid(): void
    {
        $failure = ShipmentFailure::fromThrowable(
            'yalidine',
            new RuntimeException('SQLSTATE[28000] password for user wp_prod is bad'),
            self::NOW
        );

        self::assertSame(ShipmentFailure::UNEXPECTED, $failure->code);
        self::assertStringNotContainsString('password', $failure->message, 'nothing it said is repeated');
        self::assertStringNotContainsString('SQLSTATE', $failure->message);
        self::assertStringContainsString(
            RuntimeException::class,
            $failure->message,
            'the class name survives, because it is the safe part and the part a developer asks for'
        );
    }

    /**
     * The shape a provider can produce without any network at all: an adapter
     * returning a status `ShipmentResult`'s constructor refuses.
     */
    public function testAProviderReturningAShapeWeRefuseIsTheSameKindOfFailure(): void
    {
        $failure = ShipmentFailure::fromThrowable(
            'acfake',
            new InvalidArgumentException('A provider returned the unmapped shipment status "en cours".'),
            self::NOW
        );

        self::assertSame(ShipmentFailure::UNEXPECTED, $failure->code);
        self::assertSame('', $failure->providerMessage, 'a runtime error is not a courier speaking');
    }

    /**
     * Not a courier failure, and the code says so because the fix is different:
     * no amount of re-confirming will address a parcel to a commune the order
     * does not record.
     */
    public function testAnOrderWithNoDestinationSaysWhichRouteTakesOne(): void
    {
        $failure = ShipmentFailure::noDestination('manual', self::NOW);

        self::assertSame(ShipmentFailure::NO_DESTINATION, $failure->code);
        self::assertStringContainsString(
            'POST /orders/{id}/shipments',
            $failure->message,
            'the route is named rather than described, because the operator has to go there'
        );
    }

    /**
     * `Shipment` truncates so MySQL cannot refuse a write after a parcel is
     * real; this truncates so a courier answering with a page of HTML cannot
     * put a page of HTML into every list response the order appears in.
     */
    public function testEveryStringIsBounded(): void
    {
        $failure = new ShipmentFailure(
            str_repeat('p', 100),
            str_repeat('c', 200),
            str_repeat('m', 5000),
            str_repeat('x', 5000),
            self::NOW
        );

        self::assertSame(32, mb_strlen($failure->provider), "the provider column's own width");
        self::assertSame(64, mb_strlen($failure->code));
        self::assertSame(ShipmentFailure::MAX_MESSAGE, mb_strlen($failure->message));
        self::assertSame(ShipmentFailure::MAX_MESSAGE, mb_strlen($failure->providerMessage));
    }

    public function testItSurvivesBeingStoredAndReadBack(): void
    {
        $original = ShipmentFailure::fromApiException(
            'yalidine',
            new ApiException('yalidine_parcel_refused', 'Refused.', 400, ['provider_message' => 'bad commune']),
            self::NOW
        );

        $restored = ShipmentFailure::fromMeta($original->toMeta());

        self::assertNotNull($restored);
        self::assertSame($original->toArray(), $restored->toArray(), 'the round trip is lossless');
    }

    /**
     * Order meta is a public store — another plugin, a WP-CLI script or a hand
     * edit can put anything under our key, and a failure this system did not
     * record is not a failure it should publish.
     *
     * @param mixed $stored
     */
    #[DataProvider('unrecognisableMeta')]
    public function testAnythingItDidNotWriteIsNotAFailure(mixed $stored): void
    {
        self::assertNull(ShipmentFailure::fromMeta($stored));
    }

    /** @return array<string, array{mixed}> */
    public static function unrecognisableMeta(): array
    {
        return [
            'a key never written' => [''],
            'a word somebody left' => ['broken'],
            'an array with no code' => [['provider' => 'yalidine', 'message' => 'something']],
            'a code that is only whitespace' => [['code' => '   ']],
            'a code that is not scalar' => [['code' => ['nested']]],
            'null' => [null],
        ];
    }

    /**
     * ISO-8601 on the wire and `'Y-m-d H:i:s'` UTC in the meta, which is what
     * every other timestamp in this plugin does — `Shipment::iso()` makes the
     * identical conversion.
     *
     * The time is the whole reason this is an object rather than EL's bare
     * string: the value persists, so an operator has to be able to tell last
     * Tuesday's refusal from this morning's.
     */
    public function testTheTimeIsIsoOnTheWireAndStoredAsUtc(): void
    {
        $failure = ShipmentFailure::noDestination('manual', self::NOW);

        self::assertSame(self::NOW, $failure->toMeta()['at'], 'stored the way the tables store a time');
        self::assertSame('2026-08-31T09:15:00+00:00', $failure->toArray()['at']);
    }

    public function testAFailureWithNoTimeReportsNoneRatherThanTheEpoch(): void
    {
        self::assertNull(ShipmentFailure::noDestination('manual', '')->toArray()['at']);
    }
}
