<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Geography\GeoDataset;
use PHPUnit\Framework\TestCase;

final class GeoDatasetTest extends TestCase
{
    /** @return array<int, true> */
    private static function knownCodes(int ...$codes): array
    {
        return array_fill_keys($codes, true);
    }

    // ---------------------------------------------------------------- wilayas

    public function testAcceptsAWrappedDataset(): void
    {
        $result = GeoDataset::wilayas([
            'version' => '2019-12-05',
            'wilayas' => [['code' => '16', 'name' => 'Alger']],
        ]);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function testAcceptsABareArray(): void
    {
        $result = GeoDataset::wilayas([['code' => '16', 'name' => 'Alger']]);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function testNormalizesTheCode(): void
    {
        // 16, "16" and "06" are all how a wilaya code gets written by hand.
        $result = GeoDataset::wilayas([
            ['code' => 16, 'name' => 'Alger'],
            ['code' => '06', 'name' => 'Béjaïa'],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(16, $result['rows'][0]['id']);
        self::assertSame('16', $result['rows'][0]['code']);
        self::assertSame(6, $result['rows'][1]['id']);
        // Zero-padded, because that is how it is written everywhere in Algeria.
        self::assertSame('06', $result['rows'][1]['code']);
    }

    public function testDerivesAnAccentFoldedSlug(): void
    {
        $result = GeoDataset::wilayas([['code' => '06', 'name' => 'Béjaïa']]);

        self::assertSame('bejaia', $result['rows'][0]['slug']);
    }

    /** Codes 59-69 are the former circonscriptions, now full wilayas. */
    public function testAcceptsTheFullSixtyNine(): void
    {
        $result = GeoDataset::wilayas([
            ['code' => '59', 'name' => 'Aflou'],
            ['code' => '69', 'name' => 'El Abiodh Sidi Cheikh'],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame([59, 69], array_column($result['rows'], 'id'));
    }

    public function testRejectsCodesOutsideTheRange(): void
    {
        $result = GeoDataset::wilayas([
            ['code' => '00', 'name' => 'Nowhere'],
            ['code' => '70', 'name' => 'Nowhere Else'],
        ]);

        self::assertCount(2, $result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testRejectsANonNumericCode(): void
    {
        $result = GeoDataset::wilayas([['code' => 'DZ-16', 'name' => 'Alger']]);

        self::assertNotSame([], $result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testRejectsADuplicateCode(): void
    {
        $result = GeoDataset::wilayas([
            ['code' => '16', 'name' => 'Alger'],
            ['code' => '16', 'name' => 'Algiers'],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('more than once', $result['errors'][0]);
        self::assertCount(1, $result['rows']);
    }

    /** Two spellings of one place would fight over the same natural key. */
    public function testRejectsNamesThatCollideOnceFolded(): void
    {
        $result = GeoDataset::wilayas([
            ['code' => '06', 'name' => 'Béjaïa'],
            ['code' => '07', 'name' => 'Bejaia'],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('collides', $result['errors'][0]);
    }

    public function testRejectsAMissingName(): void
    {
        $result = GeoDataset::wilayas([['code' => '16'], ['code' => '17', 'name' => '  ']]);

        self::assertCount(2, $result['errors']);
    }

    public function testRejectsANameWithNoLatinCharacters(): void
    {
        // The Latin name is the key; an Arabic-only row has none.
        $result = GeoDataset::wilayas([['code' => '16', 'name' => 'الجزائر']]);

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('usable slug', $result['errors'][0]);
    }

    public function testArabicNameIsCarriedAsALabel(): void
    {
        $result = GeoDataset::wilayas([['code' => '16', 'name' => 'Alger', 'name_ar' => 'الجزائر']]);

        self::assertSame([], $result['errors']);
        self::assertSame('الجزائر', $result['rows'][0]['name_ar']);
    }

    public function testIsActiveDefaultsToOn(): void
    {
        $result = GeoDataset::wilayas([
            ['code' => '16', 'name' => 'Alger'],
            ['code' => '17', 'name' => 'Djelfa', 'is_active' => false],
        ]);

        self::assertSame(1, $result['rows'][0]['is_active']);
        self::assertSame(0, $result['rows'][1]['is_active']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badTopLevelProvider(): array
    {
        return [
            'a string' => ['nope'],
            'a number' => [42],
            'null' => [null],
            'wrong key' => [['states' => []]],
            'key is not a list' => [['wilayas' => ['16' => 'Alger']]],
        ];
    }

    /** @dataProvider badTopLevelProvider */
    public function testRejectsABrokenTopLevelShape(mixed $decoded): void
    {
        $result = GeoDataset::wilayas($decoded);

        // One clear message about the file, not one per row.
        self::assertCount(1, $result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testEveryProblemIsReportedNotJustTheFirst(): void
    {
        // The importer is all-or-nothing, so the operator has to see the whole
        // list at once rather than fixing 1,500 rows one run at a time.
        $result = GeoDataset::wilayas([
            ['code' => '99', 'name' => 'Too high'],
            ['code' => '16'],
            ['code' => 'x', 'name' => 'Bad code'],
        ]);

        self::assertCount(3, $result['errors']);
    }

    // --------------------------------------------------------------- communes

    public function testAcceptsCommunes(): void
    {
        $result = GeoDataset::communes([
            'communes' => [
                ['wilaya_code' => '16', 'name' => 'Bab El Oued', 'postal_code' => '16008'],
            ],
        ], self::knownCodes(16));

        self::assertSame([], $result['errors']);
        self::assertSame(16, $result['rows'][0]['wilaya_id']);
        self::assertSame('bab-el-oued', $result['rows'][0]['slug']);
        self::assertSame('16008', $result['rows'][0]['postal_code']);
    }

    /** A commune in a wilaya nobody loaded is unreachable through the API. */
    public function testRejectsACommuneInAnUnknownWilaya(): void
    {
        $result = GeoDataset::communes([
            'communes' => [['wilaya_code' => '31', 'name' => 'Es Senia']],
        ], self::knownCodes(16));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('not in the wilaya dataset', $result['errors'][0]);
        self::assertSame([], $result['rows']);
    }

    public function testRejectsADuplicateCommuneInTheSameWilaya(): void
    {
        $result = GeoDataset::communes([
            'communes' => [
                ['wilaya_code' => '16', 'name' => 'Bab El Oued'],
                ['wilaya_code' => '16', 'name' => 'bab el oued'],
            ],
        ], self::knownCodes(16));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('twice', $result['errors'][0]);
    }

    /** The same name in two wilayas is normal — there are many Ain Beidas. */
    public function testTheSameNameInDifferentWilayasIsFine(): void
    {
        $result = GeoDataset::communes([
            'communes' => [
                ['wilaya_code' => '16', 'name' => 'Aïn Beïda'],
                ['wilaya_code' => '04', 'name' => 'Aïn Beïda'],
            ],
        ], self::knownCodes(4, 16));

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);
    }

    public function testPostalCodeIsOptional(): void
    {
        $result = GeoDataset::communes([
            'communes' => [
                ['wilaya_code' => '16', 'name' => 'A'],
                ['wilaya_code' => '16', 'name' => 'B', 'postal_code' => null],
                ['wilaya_code' => '16', 'name' => 'C', 'postal_code' => ''],
            ],
        ], self::knownCodes(16));

        self::assertSame([], $result['errors']);
        self::assertSame(['', '', ''], array_column($result['rows'], 'postal_code'));
    }

    /** A leading zero in an integer column is a different postal code. */
    public function testPostalCodeKeepsItsLeadingZero(): void
    {
        $result = GeoDataset::communes([
            'communes' => [['wilaya_code' => '16', 'name' => 'A', 'postal_code' => '01000']],
        ], self::knownCodes(16));

        self::assertSame('01000', $result['rows'][0]['postal_code']);
    }

    public function testRejectsANonNumericPostalCode(): void
    {
        $result = GeoDataset::communes([
            'communes' => [['wilaya_code' => '16', 'name' => 'A', 'postal_code' => '16A']],
        ], self::knownCodes(16));

        self::assertCount(1, $result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testCarriesTheDairaAndNationalCode(): void
    {
        $result = GeoDataset::communes([
            'communes' => [[
                'wilaya_code' => '16',
                'name' => 'Bab El Oued',
                'name_ar' => 'باب الوادي',
                'daira' => 'Bab El Oued',
                'daira_ar' => 'باب الوادي',
                'national_code' => '1605',
            ]],
        ], self::knownCodes(16));

        self::assertSame([], $result['errors']);
        self::assertSame('Bab El Oued', $result['rows'][0]['daira']);
        self::assertSame('باب الوادي', $result['rows'][0]['name_ar']);
        self::assertSame('1605', $result['rows'][0]['national_code']);
    }

    public function testCoordinatesAreOptional(): void
    {
        $result = GeoDataset::communes([
            'communes' => [
                ['wilaya_code' => '16', 'name' => 'A'],
                ['wilaya_code' => '16', 'name' => 'B', 'latitude' => null, 'longitude' => ''],
            ],
        ], self::knownCodes(16));

        self::assertSame([], $result['errors']);
        self::assertNull($result['rows'][0]['latitude']);
        self::assertNull($result['rows'][1]['longitude']);
    }

    /** Kept as the string the dataset supplied — a float round trip loses it. */
    public function testCoordinatePrecisionSurvives(): void
    {
        $result = GeoDataset::communes([
            'communes' => [[
                'wilaya_code' => '16',
                'name' => 'Ain Benian',
                'latitude' => '36.7919440',
                'longitude' => '-0.297222',
            ]],
        ], self::knownCodes(16));

        self::assertSame('36.7919440', $result['rows'][0]['latitude']);
        self::assertSame('-0.297222', $result['rows'][0]['longitude']);
    }

    /**
     * The failure being guarded against is a file with latitude and longitude
     * swapped. Both are valid floats; only the range says which cannot be a
     * latitude.
     */
    public function testCoordinatesAreRangeChecked(): void
    {
        $result = GeoDataset::communes([
            'communes' => [
                ['wilaya_code' => '16', 'name' => 'A', 'latitude' => '191.5', 'longitude' => '2.0'],
                ['wilaya_code' => '16', 'name' => 'B', 'latitude' => '36.0', 'longitude' => '-500'],
                ['wilaya_code' => '16', 'name' => 'C', 'latitude' => 'north', 'longitude' => '2.0'],
            ],
        ], self::knownCodes(16));

        self::assertCount(3, $result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testALongitudeBeyondNinetyIsStillValid(): void
    {
        // ±90 is the latitude limit, not the longitude one; sharing a bound
        // would reject half the planet.
        $result = GeoDataset::communes([
            'communes' => [['wilaya_code' => '16', 'name' => 'A', 'latitude' => '36.0', 'longitude' => '120.0']],
        ], self::knownCodes(16));

        self::assertSame([], $result['errors']);
    }

    public function testAnEmptyCommuneListIsValid(): void
    {
        // The shipped file is empty on purpose; that must not be an error.
        $result = GeoDataset::communes(['communes' => []], self::knownCodes(16));

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['rows']);
    }

    // ----------------------------------------------------------- destinations

    public function testAcceptsAProviderDestination(): void
    {
        $result = GeoDataset::destinations([
            'destinations' => [
                ['provider' => 'Yalidine', 'wilaya_code' => '16', 'commune' => 'Bab El Oued', 'destination_id' => '16-08'],
            ],
        ], self::knownCodes(16), ['16/bab-el-oued' => 42]);

        self::assertSame([], $result['errors']);
        self::assertSame('yalidine', $result['rows'][0]['provider']);
        self::assertSame(42, $result['rows'][0]['commune_id']);
        // Stored as given: a provider id is ours to keep, not to parse.
        self::assertSame('16-08', $result['rows'][0]['destination_id']);
    }

    /** Omitting the commune maps the whole wilaya — how stopdesk is addressed. */
    public function testACommuneIsOptional(): void
    {
        $result = GeoDataset::destinations([
            'destinations' => [['provider' => 'zedair', 'wilaya_code' => '16', 'destination_id' => 'ALG']],
        ], self::knownCodes(16), []);

        self::assertSame([], $result['errors']);
        self::assertSame(0, $result['rows'][0]['commune_id']);
    }

    public function testRejectsAnUnknownCommune(): void
    {
        $result = GeoDataset::destinations([
            'destinations' => [
                ['provider' => 'yalidine', 'wilaya_code' => '16', 'commune' => 'Nowhere', 'destination_id' => 'X'],
            ],
        ], self::knownCodes(16), ['16/bab-el-oued' => 42]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('not in wilaya 16', $result['errors'][0]);
    }

    public function testRejectsTwoDestinationsForOnePlace(): void
    {
        $result = GeoDataset::destinations([
            'destinations' => [
                ['provider' => 'yalidine', 'wilaya_code' => '16', 'destination_id' => 'A'],
                ['provider' => 'yalidine', 'wilaya_code' => '16', 'destination_id' => 'B'],
            ],
        ], self::knownCodes(16), []);

        self::assertCount(1, $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function testTwoProvidersMayMapTheSamePlace(): void
    {
        $result = GeoDataset::destinations([
            'destinations' => [
                ['provider' => 'yalidine', 'wilaya_code' => '16', 'destination_id' => 'A'],
                ['provider' => 'zedair', 'wilaya_code' => '16', 'destination_id' => 'B'],
            ],
        ], self::knownCodes(16), []);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);
    }

    public function testRejectsAMissingDestinationId(): void
    {
        $result = GeoDataset::destinations([
            'destinations' => [['provider' => 'yalidine', 'wilaya_code' => '16']],
        ], self::knownCodes(16), []);

        self::assertCount(1, $result['errors']);
    }
}
