<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\CMS\HomepageSections;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The homepage document — roadmap §61, docs/PLAN.md §23.
 *
 * It is an option, edited by hand or by `wp option update`, which means every
 * malformed shape below is something a person will actually type one afternoon.
 * The contract is that none of them 500s and none of them is silently accepted:
 * a bad section is dropped **and reported**.
 */
final class HomepageSectionsTest extends TestCase
{
    public function testTheVocabularyIsTheOneThePlanDefines(): void
    {
        self::assertTrue(HomepageSections::isKnownType('hero'));
        self::assertTrue(HomepageSections::isKnownType('featured_products'));
        self::assertTrue(HomepageSections::isKnownType('custom'));
        self::assertFalse(HomepageSections::isKnownType('carousel'));
        self::assertFalse(HomepageSections::isKnownType(''));
    }

    public function testTheDocumentedShapeIsRead(): void
    {
        $sections = HomepageSections::fromStored([
            'sections' => [
                ['type' => 'hero', 'data' => ['title' => 'Soldes']],
                ['type' => 'featured_products', 'data' => ['ids' => [1, 2]]],
            ],
        ]);

        self::assertSame([], $sections->problems);
        self::assertSame(
            [
                ['type' => 'hero', 'data' => ['title' => 'Soldes']],
                ['type' => 'featured_products', 'data' => ['ids' => [1, 2]]],
            ],
            $sections->toArray()['sections']
        );
    }

    /** A bare list is what somebody types when they skip the wrapper. */
    public function testAListWithoutTheWrapperIsAlsoRead(): void
    {
        $sections = HomepageSections::fromStored([['type' => 'text', 'data' => []]]);

        self::assertSame([], $sections->problems);
        self::assertCount(1, $sections->sections);
    }

    public function testOrderIsPreserved(): void
    {
        $sections = HomepageSections::fromStored(['sections' => [
            ['type' => 'newsletter'],
            ['type' => 'hero'],
            ['type' => 'faq'],
        ]]);

        self::assertSame(
            ['newsletter', 'hero', 'faq'],
            array_column($sections->sections, 'type')
        );
    }

    public function testAMissingDataKeyBecomesAnEmptyObject(): void
    {
        $sections = HomepageSections::fromStored(['sections' => [['type' => 'newsletter']]]);

        self::assertSame([], $sections->problems);
        self::assertSame([], $sections->sections[0]['data']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function emptyProvider(): array
    {
        return [
            'never set' => [false],
            'null' => [null],
            'empty string' => [''],
            'empty array' => [[]],
            'empty sections' => [['sections' => []]],
        ];
    }

    /** A shop that has not written a homepage yet is not a broken shop. */
    #[DataProvider('emptyProvider')]
    public function testAnAbsentDocumentIsEmptyRatherThanAnError(mixed $stored): void
    {
        $sections = HomepageSections::fromStored($stored);

        self::assertTrue($sections->isEmpty());
        self::assertSame([], $sections->problems);
        self::assertSame(['sections' => []], $sections->toArray());
    }

    public function testAnUnknownTypeIsDroppedAndReported(): void
    {
        $sections = HomepageSections::fromStored(['sections' => [
            ['type' => 'hero'],
            ['type' => 'carousel'],
            ['type' => 'faq'],
        ]]);

        self::assertSame(['hero', 'faq'], array_column($sections->sections, 'type'));
        self::assertCount(1, $sections->problems);
        self::assertStringContainsString('carousel', $sections->problems[0]);
    }

    public function testAMalformedSectionIsDroppedAndReported(): void
    {
        $sections = HomepageSections::fromStored(['sections' => [
            'just a string',
            ['data' => ['x' => 1]],
            ['type' => 'text', 'data' => 'not an object'],
            ['type' => 'text', 'data' => ['body' => 'kept']],
        ]]);

        self::assertCount(1, $sections->sections);
        self::assertSame(['body' => 'kept'], $sections->sections[0]['data']);
        self::assertCount(3, $sections->problems);
    }

    public function testAScalarOptionIsReportedRatherThanThrown(): void
    {
        $sections = HomepageSections::fromStored('{"sections":[]}');

        self::assertTrue($sections->isEmpty());
        self::assertCount(1, $sections->problems);
    }

    public function testASectionsKeyThatIsNotAListIsReported(): void
    {
        $sections = HomepageSections::fromStored(['sections' => 'hero']);

        self::assertTrue($sections->isEmpty());
        self::assertCount(1, $sections->problems);
    }

    /** One mangled option must not turn one GET into an unbounded response. */
    public function testTheSectionCountIsCapped(): void
    {
        $sections = HomepageSections::fromStored([
            'sections' => array_fill(0, HomepageSections::MAX_SECTIONS + 10, ['type' => 'text']),
        ]);

        self::assertCount(HomepageSections::MAX_SECTIONS, $sections->sections);
        self::assertCount(1, $sections->problems);
        self::assertStringContainsString('dropped', $sections->problems[0]);
    }
}
