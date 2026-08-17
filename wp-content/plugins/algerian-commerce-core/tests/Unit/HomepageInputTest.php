<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\CMS\HomepageInput;
use AlgerianCommerce\CMS\HomepageSections;
use PHPUnit\Framework\TestCase;

/**
 * The homepage write payload — §89.
 *
 * The asymmetry with `HomepageSections::fromStored()` is the section, so it is
 * asserted directly: **the same malformed document is dropped-and-reported by
 * the reader and refused by the writer.** An option edited by hand with
 * `wp option update` must degrade; a form filled in by a person must not lose
 * their work quietly.
 */
final class HomepageInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function errors(array $payload): array
    {
        try {
            HomepageInput::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsADocument(): void
    {
        $input = HomepageInput::fromPayload(['sections' => [
            ['type' => 'hero', 'data' => ['title' => 'Tapis & Kilims']],
            ['type' => 'featured_products', 'data' => ['limit' => 8]],
        ]]);

        self::assertCount(2, $input->sections);
        self::assertSame('hero', $input->sections[0]['type']);
        // The measurement that decided `ContentHtml::looksLikeMarkup()`: an
        // ordinary string must not pass through `wp_kses`, which would make
        // this `Tapis &amp; Kilims`.
        self::assertSame('Tapis & Kilims', $input->sections[0]['data']['title']);
        self::assertSame(['sections' => $input->sections], $input->toArray());
    }

    /**
     * The whole point of the class. The reader drops section 1 and reports it;
     * the writer names it and refuses the document.
     */
    public function testTheReaderDropsWhatTheWriterRefuses(): void
    {
        $document = [
            ['type' => 'hero', 'data' => []],
            ['type' => 'carousel', 'data' => []],
        ];

        $read = HomepageSections::fromStored(['sections' => $document]);
        self::assertCount(1, $read->sections);
        self::assertCount(1, $read->problems);

        $errors = $this->errors(['sections' => $document]);
        self::assertArrayHasKey('sections[1].type', $errors);
        self::assertStringContainsString('carousel', $errors['sections[1].type']);
    }

    public function testTheIndexIsTheOneTheCallerSent(): void
    {
        $errors = $this->errors(['sections' => [
            ['type' => 'hero', 'data' => []],
            ['type' => 'text', 'data' => []],
            ['type' => 'nope', 'data' => []],
        ]]);

        self::assertArrayHasKey('sections[2].type', $errors);
        self::assertArrayNotHasKey('sections[1].type', $errors);
    }

    public function testEverySectionProblemIsNamedAtOnce(): void
    {
        $errors = $this->errors(['sections' => [
            ['type' => 'nope', 'data' => []],
            ['type' => 'hero', 'data' => 'not an object'],
        ]]);

        self::assertArrayHasKey('sections[0].type', $errors);
        self::assertArrayHasKey('sections[1].data', $errors);
    }

    /**
     * `fromStored()` accepts a bare list because that is what somebody types
     * into `wp option update`. A writer states one shape, and the one it states
     * is the one it emits.
     */
    public function testTheBareListTheReaderToleratesIsRefused(): void
    {
        self::assertCount(1, HomepageSections::fromStored([['type' => 'hero', 'data' => []]])->sections);
        self::assertArrayHasKey('sections', $this->errors([['type' => 'hero', 'data' => []]]));
    }

    public function testSectionsIsRequired(): void
    {
        self::assertArrayHasKey('sections', $this->errors([]));
    }

    public function testAnUnknownTopLevelFieldIsRefused(): void
    {
        self::assertArrayHasKey('layout', $this->errors(['sections' => [], 'layout' => 'wide']));
    }

    public function testAnUnknownSectionFieldIsRefused(): void
    {
        self::assertArrayHasKey(
            'sections[0].id',
            $this->errors(['sections' => [['type' => 'hero', 'data' => [], 'id' => 3]]])
        );
    }

    public function testTheCapIsSharedWithTheReader(): void
    {
        $errors = $this->errors(['sections' => array_fill(
            0,
            HomepageSections::MAX_SECTIONS + 1,
            ['type' => 'text', 'data' => []]
        )]);

        self::assertArrayHasKey('sections', $errors);
        self::assertStringContainsString((string) HomepageSections::MAX_SECTIONS, $errors['sections']);
    }

    public function testTheVocabularyIsSharedWithTheReader(): void
    {
        foreach (HomepageSections::TYPES as $type) {
            $input = HomepageInput::fromPayload(['sections' => [['type' => $type, 'data' => []]]]);

            self::assertSame($type, $input->sections[0]['type']);
        }
    }

    public function testAnEmptyDocumentIsLegitimate(): void
    {
        self::assertSame([], HomepageInput::fromPayload(['sections' => []])->sections);
    }

    /** Absent `data` is an empty object, not a refusal — a hero may carry none. */
    public function testDataMayBeOmitted(): void
    {
        $input = HomepageInput::fromPayload(['sections' => [['type' => 'newsletter']]]);

        self::assertSame([], $input->sections[0]['data']);
    }
}
