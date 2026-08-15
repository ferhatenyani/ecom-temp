<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Integrations\Yalidine\YalidineParcel;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ProviderDestination;
use AlgerianCommerce\Shipping\ShipmentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What we send Yalidine, field by field.
 *
 * With no sandbox account (roadmap §56), this is the only place the payload can
 * be checked at all before the first live call — and the payload is where an
 * unverifiable integration goes wrong: a misspelled commune is rejected with an
 * empty array and no message.
 */
final class YalidineParcelTest extends TestCase
{
    private function request(string $codAmount = '4500.00', string $deliveryType = Destination::HOME): ShipmentRequest
    {
        return new ShipmentRequest(
            42,
            new Destination(16, 512, $deliveryType),
            'Ahmed Ben Salah',
            '+213555112233',
            '12 rue Didouche Mourad',
            $codAmount,
            '',
            '42-2',
            'Chemise bleue x2, Ceinture'
        );
    }

    private function destination(int $wilaya, int $commune, string $id, string $name, array $extra = []): ProviderDestination
    {
        return new ProviderDestination('yalidine', $wilaya, $commune, $id, ['name' => $name] + $extra);
    }

    private function settings(array $overrides = []): YalidineSettings
    {
        return YalidineSettings::fromArray($overrides + ['origin_wilaya_id' => 6]);
    }

    public function testThePayloadCarriesTheCouriersOwnSpellingOfEveryPlace(): void
    {
        $payload = YalidineParcel::payload(
            $this->request(),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        // Not our spelling, and not a table of names in PHP: what the sync
        // recorded from Yalidine itself. This is the field their exact-match
        // rejects a parcel over.
        self::assertSame('Béjaïa', $payload['from_wilaya_name']);
        self::assertSame('Alger', $payload['to_wilaya_name']);
        self::assertSame('Bouzaréah', $payload['to_commune_name']);
    }

    public function testTheMerchantReferenceIsSentAsTheOrderId(): void
    {
        $payload = YalidineParcel::payload(
            $this->request(),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        // "42-2" — the second parcel for order 42, not the order id. It is also
        // the key the create response comes back under.
        self::assertSame('42-2', $payload['order_id']);
    }

    public function testTheAmountToCollectIsWholeDinars(): void
    {
        $payload = YalidineParcel::payload(
            $this->request('4500.60'),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        // Money is a decimal string everywhere else here; their field is an
        // integer, so the rounding happens once, at the edge.
        self::assertSame(4501, $payload['price']);
        self::assertIsInt($payload['price']);
        self::assertSame(4501, $payload['declared_value']);
    }

    /** A prepaid order still becomes a parcel; the driver collects nothing. */
    public function testAPrepaidOrderIsSentAtZero(): void
    {
        $payload = YalidineParcel::payload(
            $this->request('0'),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        self::assertSame(0, $payload['price']);
    }

    public function testAHomeDeliveryCarriesNoStopDesk(): void
    {
        $payload = YalidineParcel::payload(
            $this->request(),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        self::assertFalse($payload['is_stopdesk']);
        self::assertArrayNotHasKey('stopdesk_id', $payload);
    }

    public function testACollectedParcelNamesTheDesk(): void
    {
        $payload = YalidineParcel::payload(
            $this->request('4500.00', Destination::DESK),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings(),
            '88'
        );

        self::assertTrue($payload['is_stopdesk']);
        self::assertSame('88', $payload['stopdesk_id']);
    }

    /**
     * Dimensions are a per-client setting and are omitted when unset, rather
     * than sent as zeros — a parcel declared 0×0×0 is a lie about the parcel.
     */
    public function testParcelDimensionsAreSentOnlyWhenConfigured(): void
    {
        $bare = YalidineParcel::payload(
            $this->request(),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        foreach (['length', 'width', 'height', 'weight'] as $field) {
            self::assertArrayNotHasKey($field, $bare);
        }

        $sized = YalidineParcel::payload(
            $this->request(),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings(['weight' => 2, 'length' => 30])
        );

        self::assertSame(2, $sized['weight']);
        self::assertSame(30, $sized['length']);
        self::assertArrayNotHasKey('width', $sized);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function nameProvider(): array
    {
        return [
            'two words' => ['Ahmed Benali', 'Ahmed', 'Benali'],
            'family name of two words' => ['Ahmed Ben Salah', 'Ahmed', 'Ben Salah'],
            'one word' => ['Ahmed', 'Ahmed', ''],
            'padded' => ['  Ahmed   Benali  ', 'Ahmed', 'Benali'],
            'empty' => ['', '', ''],
        ];
    }

    /**
     * Algerian checkouts take one name field and Yalidine wants two. Everything
     * after the first word is the family name: a two-word family name is
     * ordinary here, a two-word first name is not.
     */
    #[DataProvider('nameProvider')]
    public function testTheRecipientsNameIsSplitAtTheFirstSpace(string $full, string $first, string $family): void
    {
        self::assertSame([$first, $family], YalidineParcel::splitName($full));
    }

    public function testAlgerianNumbersAreSentInTheNationalForm(): void
    {
        self::assertSame('0555112233', YalidineParcel::phone('+213555112233'));
        self::assertSame('0555112233', YalidineParcel::phone('00213555112233'));
        self::assertSame('0555112233', YalidineParcel::phone('213555112233'));
        self::assertSame('0555112233', YalidineParcel::phone('0555 11 22 33'));
        self::assertSame('0555112233', YalidineParcel::phone(' 0555112233 '));
    }

    /**
     * A number that does not look Algerian is passed through as typed. A
     * "corrected" phone number reaches a stranger; a wrong-looking one is
     * rejected by Yalidine, which is the better failure.
     */
    public function testAnUnrecognisedNumberIsNotInvented(): void
    {
        self::assertSame('+33123456789', YalidineParcel::phone('+33 1 23 45 67 89'));
        self::assertSame('', YalidineParcel::phone(''));
    }

    /**
     * `product_list` is what a driver reads out on the phone. Roadmap §53
     * deferred parcel contents to the first adapter whose docs required them —
     * this is it.
     */
    public function testTheProductListComesFromTheOrdersContents(): void
    {
        $payload = YalidineParcel::payload(
            $this->request(),
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        self::assertSame('Chemise bleue x2, Ceinture', $payload['product_list']);
    }

    public function testAnEmptyManifestFallsBackRatherThanBeingBlank(): void
    {
        $request = new ShipmentRequest(
            42,
            new Destination(16, 512),
            'Ahmed Benali',
            '0555112233',
            'Adresse',
            '0',
            'Fragile',
            '42-1',
            ''
        );

        $payload = YalidineParcel::payload(
            $request,
            $this->destination(6, 0, '6', 'Béjaïa'),
            $this->destination(16, 0, '16', 'Alger'),
            $this->destination(16, 512, '1601', 'Bouzaréah'),
            $this->settings()
        );

        // The note, then a plain "Colis" — never an empty label.
        self::assertSame('Fragile', $payload['product_list']);
    }
}
