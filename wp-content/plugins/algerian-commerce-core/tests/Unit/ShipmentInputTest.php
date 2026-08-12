<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ShipmentInput;
use PHPUnit\Framework\TestCase;

final class ShipmentInputTest extends TestCase
{
    public function testAValidRequest(): void
    {
        $input = ShipmentInput::fromPayload([
            'provider' => 'Manual',
            'wilaya_id' => 16,
            'commune_id' => 1234,
            'delivery_type' => 'DESK',
            'recipient' => '  Amina Benali ',
            'phone' => '0550123456',
            'address' => '12 Rue Didouche Mourad',
            'note' => 'Call on arrival',
        ]);

        self::assertSame('manual', $input->provider);
        self::assertSame(16, $input->destination->wilayaId);
        self::assertSame(1234, $input->destination->communeId);
        self::assertSame(Destination::DESK, $input->destination->deliveryType);
        self::assertSame('Amina Benali', $input->recipient);
        self::assertSame('Call on arrival', $input->note);
    }

    /**
     * The destination is required by id. Deriving it from the order's free-text
     * city would mean fuzzy-matching a name spelled several ways in two
     * languages, and getting that wrong sends a parcel to the wrong daira.
     */
    public function testTheDestinationIsRequired(): void
    {
        foreach ([[], ['wilaya_id' => 16], ['commune_id' => 1234]] as $payload) {
            try {
                ShipmentInput::fromPayload($payload);
                self::fail('a shipment must name a wilaya and a commune');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->statusCode());
            }
        }
    }

    public function testTheDestinationMustBePositiveIds(): void
    {
        foreach ([0, -1, 'sixteen', null] as $value) {
            try {
                ShipmentInput::fromPayload(['wilaya_id' => $value, 'commune_id' => 1234]);
                self::fail(var_export($value, true) . ' must not be a wilaya id');
            } catch (ApiException $exception) {
                self::assertArrayHasKey('wilaya_id', $exception->details()['fields']);
            }
        }
    }

    public function testEverythingButTheDestinationIsOptional(): void
    {
        $input = ShipmentInput::fromPayload(['wilaya_id' => 16, 'commune_id' => 1234]);

        // The service fills these from the order's shipping address — that is
        // not validation's job.
        self::assertSame('', $input->provider);
        self::assertSame('', $input->recipient);
        self::assertSame('', $input->phone);
        self::assertSame('', $input->address);
        self::assertSame(Destination::HOME, $input->destination->deliveryType);
    }

    public function testAnUnknownDeliveryTypeIsRefused(): void
    {
        try {
            ShipmentInput::fromPayload(['wilaya_id' => 16, 'commune_id' => 1234, 'delivery_type' => 'locker']);
            self::fail('an invented delivery type must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('delivery_type', $exception->details()['fields']);
        }
    }

    public function testUnknownFieldsAreRejected(): void
    {
        try {
            ShipmentInput::fromPayload([
                'wilaya_id' => 16,
                'commune_id' => 1234,
                'tracking_number' => 'MAN-1',
            ]);
            self::fail('an unknown field must be rejected');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('tracking_number', $exception->details()['fields']);
        }
    }

    /** The status of a parcel is not something a caller may assert. */
    public function testTheStatusCannotBeSetOnCreation(): void
    {
        try {
            ShipmentInput::fromPayload(['wilaya_id' => 16, 'commune_id' => 1234, 'status' => 'delivered']);
            self::fail('a caller must not be able to create a delivered shipment');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('status', $exception->details()['fields']);
        }
    }

    public function testOverlongTextIsRefused(): void
    {
        try {
            ShipmentInput::fromPayload([
                'wilaya_id' => 16,
                'commune_id' => 1234,
                'recipient' => str_repeat('a', ShipmentInput::MAX_TEXT + 1),
                'note' => str_repeat('b', ShipmentInput::MAX_NOTE + 1),
            ]);
            self::fail('over-length text must be refused');
        } catch (ApiException $exception) {
            self::assertArrayHasKey('recipient', $exception->details()['fields']);
            self::assertArrayHasKey('note', $exception->details()['fields']);
        }
    }

    public function testEveryFieldErrorIsReportedAtOnce(): void
    {
        try {
            ShipmentInput::fromPayload(['delivery_type' => 'locker', 'phone' => ['0550']]);
            self::fail('the payload is invalid');
        } catch (ApiException $exception) {
            // A form that has to be fixed one field per request is a form
            // nobody fills in.
            self::assertSame(
                ['wilaya_id', 'commune_id', 'delivery_type', 'phone'],
                array_keys($exception->details()['fields'])
            );
        }
    }
}
