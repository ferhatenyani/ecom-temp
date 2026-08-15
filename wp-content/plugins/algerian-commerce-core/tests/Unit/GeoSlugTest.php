<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Geography\GeoSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeoSlugTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function nameProvider(): array
    {
        return [
            'plain' => ['Adrar', 'adrar'],
            'lowercased' => ['ORAN', 'oran'],
            'trimmed' => ['  Blida  ', 'blida'],
            'spaces become hyphens' => ['Oum El Bouaghi', 'oum-el-bouaghi'],
            'accents folded' => ['Béjaïa', 'bejaia'],
            'more accents' => ['Aïn Témouchent', 'ain-temouchent'],
            'cedilla' => ['Béchar', 'bechar'],
            'apostrophe' => ["M'Sila", 'm-sila'],
            'curly apostrophe' => ['M’Sila', 'm-sila'],
            'grave accent as apostrophe' => ['M`Sila', 'm-sila'],
            'circumflex' => ['Médéa', 'medea'],
            'digits kept' => ['Zone 4', 'zone-4'],
            'runs collapse' => ['El   Oued', 'el-oued'],
            'edges trimmed' => ['--Alger--', 'alger'],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testSlugging(string $name, string $expected): void
    {
        self::assertSame($expected, GeoSlug::make($name));
    }

    /**
     * The whole reason accents are folded: a dataset that corrects its spelling
     * next year must update the row rather than insert a second commune beside
     * the first.
     */
    public function testSpellingVariantsCollapseToOneKey(): void
    {
        self::assertSame(GeoSlug::make('Bejaia'), GeoSlug::make('Béjaïa'));
        self::assertSame(GeoSlug::make("M'Sila"), GeoSlug::make('M’Sila'));
        self::assertSame(GeoSlug::make('Ain Defla'), GeoSlug::make('Aïn Defla'));
    }

    public function testDistinctPlacesStayDistinct(): void
    {
        // Folding must not be so aggressive that two real places collide.
        self::assertNotSame(GeoSlug::make('Alger'), GeoSlug::make('Algiers'));
        self::assertNotSame(GeoSlug::make('Sétif'), GeoSlug::make('Saïda'));
        self::assertNotSame(GeoSlug::make('El Oued'), GeoSlug::make('El Tarf'));
    }

    /** Arabic has no Latin slug, which is why the Latin name is the key. */
    public function testANonLatinNameProducesNoSlug(): void
    {
        self::assertSame('', GeoSlug::make('الجزائر'));
        self::assertSame('', GeoSlug::make(''));
        self::assertSame('', GeoSlug::make('   '));
    }

    public function testItIsStableAcrossCalls(): void
    {
        // A natural key that changes between runs is not a key.
        $first = GeoSlug::make('Bordj Bou Arréridj');

        self::assertSame($first, GeoSlug::make('Bordj Bou Arréridj'));
        self::assertSame('bordj-bou-arreridj', $first);
    }
}
