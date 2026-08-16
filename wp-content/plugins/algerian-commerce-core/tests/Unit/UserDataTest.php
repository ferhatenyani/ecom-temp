<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Marketing\UserData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hashing and normalisation that decide whether a conversion matches a real
 * person — roadmap §62b, docs/SECURITY.md.
 *
 * Two different things are being protected here, and both fail silently:
 *
 *  - **Correctness.** Meta matches on the hash, so a hash of `Ali@Example.COM `
 *    matches nobody while a hash of `ali@example.com` matches a customer. A
 *    normalisation bug does not raise; it quietly reports a zero match rate and
 *    somebody spends a month wondering why their ads look unprofitable.
 *  - **Privacy.** Raw PII must never survive construction. A test that only
 *    checked the hashes would pass just as happily on a class that kept the
 *    email address in a property beside them.
 */
final class UserDataTest extends TestCase
{
    /** The reference vector from Meta's own documentation. */
    private const EMAIL = 'john_smith@gmail.com';

    public function testEmailIsTrimmedLowercasedAndHashed(): void
    {
        $expected = hash('sha256', self::EMAIL);

        foreach ([self::EMAIL, '  ' . self::EMAIL . ' ', 'John_Smith@Gmail.COM'] as $variant) {
            $user = UserData::fromCustomer(['email' => $variant]);

            self::assertSame($expected, $user->hashed['em'], "\"{$variant}\" must normalise to the same hash");
        }
    }

    /** A wrong hash matches nobody and tells Meta the shop has bad data. */
    public function testAMalformedEmailIsDroppedRatherThanHashed(): void
    {
        self::assertArrayNotHasKey('em', UserData::fromCustomer(['email' => 'not-an-address'])->hashed);
        self::assertArrayNotHasKey('em', UserData::fromCustomer(['email' => ''])->hashed);
    }

    /**
     * Hashing the empty string is a valid SHA-256 that matches nobody — and
     * sending it for every customer without a surname would tell Meta that
     * thousands of different people share one.
     */
    public function testAbsentFieldsAreOmittedNotHashedAsEmpty(): void
    {
        $user = UserData::fromCustomer(['email' => self::EMAIL]);

        self::assertSame(['em'], array_keys($user->hashed));
        self::assertNotContains(hash('sha256', ''), $user->hashed);
    }

    // ------------------------------------------------------------------ phones --

    /**
     * The Algerian case, and the reason `DEFAULT_CALLING_CODE` exists: a shop
     * stores `0551020304`, and merely stripping the leading zero yields
     * `551020304`, which is not a phone number anywhere.
     *
     * @return array<string, array{0: string}>
     */
    public static function algerianPhoneProvider(): array
    {
        return [
            'national' => ['0551020304'],
            'spaced' => ['0551 02 03 04'],
            'punctuated' => ['0551-02-03-04'],
            'international plus' => ['+213551020304'],
            'international spaced' => ['+213 551 02 03 04'],
            'double zero' => ['00213551020304'],
            'bare' => ['551020304'],
        ];
    }

    #[DataProvider('algerianPhoneProvider')]
    public function testEveryWayOfWritingOneNumberIsOnePerson(string $written): void
    {
        self::assertSame('213551020304', UserData::normalizePhone($written));
    }

    public function testAnotherCountryCodeIsHonoured(): void
    {
        self::assertSame('33612345678', UserData::normalizePhone('0612345678', '33'));
    }

    /** A truncated field is worse than a missing one: a wrong hash is a wrong match. */
    public function testAnImplausiblyShortNumberIsDropped(): void
    {
        self::assertSame('', UserData::normalizePhone('123'));
        self::assertSame('', UserData::normalizePhone(''));
        self::assertSame('', UserData::normalizePhone('not a phone'));
    }

    // ------------------------------------------------------- names and places --

    public function testNamesAreLowercasedAndStrippedOfPunctuation(): void
    {
        self::assertSame('mohamed', UserData::normalizeName("  Mohamed.  "));
        // A hyphen is removed rather than spaced — see the ASSUMPTION on
        // UserData::normalizeName. The two hash differently and compound names
        // are common here, so this is pinned rather than left to drift.
        self::assertSame('elhadi', UserData::normalizeName('El-Hadi'));
        self::assertSame('el hadi', UserData::normalizeName('El Hadi'));
        // Accents survive: Meta hashes UTF-8, and folding them would change
        // the hash of a name that is spelled that way on the card.
        self::assertSame('boualem', UserData::normalizeName('Boualem'));
        self::assertSame('rené', UserData::normalizeName('René'));
    }

    /** Meta wants a city with no spaces at all. */
    public function testCitiesLoseTheirSpaces(): void
    {
        self::assertSame('bordjbouarreridj', UserData::normalizeCity('Bordj Bou Arreridj'));
        self::assertSame('alger', UserData::normalizeCity(' Alger '));
    }

    public function testZipsLoseSpacesAndDashes(): void
    {
        self::assertSame('16000', UserData::normalizeZip('16 000'));
        self::assertSame('16000', UserData::normalizeZip('16-000'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function countryProvider(): array
    {
        return [
            'already correct' => ['dz', 'dz'],
            'uppercase' => ['DZ', 'dz'],
            'padded' => [' dz ', 'dz'],
            'a whole name is not a code' => ['Algeria', ''],
            'three letters' => ['dza', ''],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('countryProvider')]
    public function testCountryMustBeTwoLetters(string $given, string $expected): void
    {
        self::assertSame($expected, UserData::normalizeCountry($given));
    }

    // ------------------------------------------------------------- the boundary --

    /**
     * The privacy property, asserted structurally: no raw value survives
     * anywhere on the object, not in a property and not in a serialisation.
     */
    public function testNoRawPersonalDataSurvivesConstruction(): void
    {
        $user = UserData::fromCustomer([
            'email' => self::EMAIL,
            'phone' => '0551020304',
            'first_name' => 'Mohamed',
            'last_name' => 'Bensalem',
            'city' => 'Alger',
            'country' => 'DZ',
        ]);

        $serialised = (string) json_encode([$user->toArray(), $user->hashed, $user->plain]);

        foreach ([self::EMAIL, '0551020304', 'Mohamed', 'mohamed', 'Bensalem', 'Alger'] as $raw) {
            self::assertStringNotContainsString($raw, $serialised, "\"{$raw}\" reached the wire");
        }
    }

    /**
     * Meta matches these literally, so hashing them is not a weaker signal but
     * a dead one — and it looks exactly like a field that is working.
     */
    public function testClientContextIsNeverHashed(): void
    {
        $user = UserData::fromCustomer([], [
            'client_ip_address' => '41.100.1.2',
            'client_user_agent' => 'Mozilla/5.0',
            'fbc' => 'fb.1.1755300000.AbCd',
            'fbp' => 'fb.1.1755300000.123456789',
        ]);

        self::assertSame('41.100.1.2', $user->plain['client_ip_address']);
        self::assertSame('Mozilla/5.0', $user->plain['client_user_agent']);
        self::assertSame([], $user->hashed);
        self::assertSame(0, $user->strength());
    }

    public function testStrengthCountsMatchableIdentifiers(): void
    {
        $user = UserData::fromCustomer(
            ['email' => self::EMAIL, 'phone' => '0551020304', 'first_name' => 'Mohamed'],
            ['client_ip_address' => '41.100.1.2']
        );

        // Three hashed identifiers; the IP is context, not an identifier.
        self::assertSame(3, $user->strength());
        self::assertFalse($user->isEmpty());
    }

    /**
     * The queue stores hashes, and a drain must not hash them again — that
     * would double-hash on every retry and match nobody.
     */
    public function testRehydrationDoesNotHashAgain(): void
    {
        $original = UserData::fromCustomer(['email' => self::EMAIL], ['fbp' => 'fb.1.1.1']);
        $restored = UserData::fromStored($original->hashed, $original->plain);

        self::assertSame($original->toArray(), $restored->toArray());
        self::assertSame(hash('sha256', self::EMAIL), $restored->hashed['em']);
    }

    public function testAnEmptyUserDataIsEmpty(): void
    {
        self::assertTrue(UserData::empty()->isEmpty());
        self::assertSame([], UserData::empty()->toArray());
    }
}
