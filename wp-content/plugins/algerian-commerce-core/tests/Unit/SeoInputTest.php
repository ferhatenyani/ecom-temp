<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\SEO\SeoInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SEO write payload — roadmap §62.
 *
 * Two things carry weight here. Clearing a field has to be expressible, because
 * every one of them has a derived fallback that is usually better than a stale
 * override. And the errors have to land in the *caller's* list, so a bad
 * `seo.canonical` and a bad `sku` come back in one response.
 */
final class SeoInputTest extends TestCase
{
    public function testTheFiveFieldsAreAccepted(): void
    {
        $errors = [];
        $input = SeoInput::fromPayload([
            'title' => '  Tapis berbère  ',
            'description' => 'Fait main',
            'canonical' => 'https://boutique.dz/tapis',
            'robots' => 'noindex, nofollow',
            'image_id' => '42',
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame('Tapis berbère', $input->get('title'));
        self::assertSame('https://boutique.dz/tapis', $input->get('canonical'));
        self::assertSame('noindex, nofollow', $input->get('robots'));
        self::assertSame(42, $input->get('image_id'));
    }

    public function testAnAbsentPayloadIsEmptyRatherThanAnError(): void
    {
        $errors = [];

        self::assertTrue(SeoInput::fromPayload([], $errors)->isEmpty());
        self::assertSame([], $errors);
    }

    /** @return array<string, array{0: string}> */
    public static function clearableProvider(): array
    {
        return [
            'title' => ['title'],
            'description' => ['description'],
            'canonical' => ['canonical'],
            'robots' => ['robots'],
        ];
    }

    /**
     * Every field has a derived fallback, so "unset" is a real and often better
     * state than "set to something stale".
     */
    #[DataProvider('clearableProvider')]
    public function testNullClearsAFieldBackToTheDerivedDefault(string $field): void
    {
        $errors = [];
        $input = SeoInput::fromPayload([$field => null], $errors);

        self::assertSame([], $errors);
        self::assertTrue($input->has($field));
        self::assertSame('', $input->get($field));
    }

    public function testZeroClearsTheImage(): void
    {
        $errors = [];
        $input = SeoInput::fromPayload(['image_id' => null], $errors);

        self::assertSame(0, $input->get('image_id'));
    }

    /**
     * The read shape is an object, so a client that GETs, flips `index` and
     * PATCHes back must not have to know the storage is a directive string.
     */
    public function testRobotsRoundTripsFromItsReadShape(): void
    {
        $errors = [];
        $input = SeoInput::fromPayload([
            'robots' => ['index' => false, 'follow' => true, 'directive' => 'ignored'],
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame('noindex, follow', $input->get('robots'));
    }

    public function testRobotsIsNormalisedFromAString(): void
    {
        $errors = [];

        self::assertSame(
            'noindex, nofollow',
            SeoInput::fromPayload(['robots' => 'NOINDEX,NOFOLLOW'], $errors)->get('robots')
        );
    }

    // ------------------------------------------------------------- refusals --

    /** @return array<string, array{0: mixed}> */
    public static function badCanonicalProvider(): array
    {
        return [
            'plain http' => ['http://boutique.dz/tapis'],
            'a path' => ['/tapis'],
            'javascript' => ['javascript:alert(1)'],
            'not a url' => ['tapis'],
            'not a string' => [['https://boutique.dz']],
        ];
    }

    #[DataProvider('badCanonicalProvider')]
    public function testACanonicalMustBeAnAbsoluteHttpsUrl(mixed $canonical): void
    {
        $errors = [];
        SeoInput::fromPayload(['canonical' => $canonical], $errors);

        self::assertArrayHasKey('seo.canonical', $errors);
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $errors = [];
        SeoInput::fromPayload(['keyword' => 'tapis'], $errors);

        self::assertSame(['seo.keyword' => 'Unknown field.'], $errors);
    }

    /**
     * Namespaced, so the field list of a product write says which half of the
     * payload was wrong.
     */
    public function testErrorsAreNamespacedIntoTheCallersList(): void
    {
        $errors = ['sku' => 'Already in use.'];
        SeoInput::fromPayload(['canonical' => 'nope'], $errors);

        self::assertSame(['sku', 'seo.canonical'], array_keys($errors));
    }

    public function testANonObjectPayloadIsRefused(): void
    {
        $errors = [];
        SeoInput::fromPayload('a title', $errors);

        self::assertSame(['seo' => 'Must be an object.'], $errors);
    }

    public function testReadOnlyFieldsAreDroppedNotRefused(): void
    {
        $errors = [];
        $input = SeoInput::fromPayload([
            'og' => ['title' => 'x'],
            'image' => ['id' => 1],
            'structured_data' => [],
            'overrides' => ['title'],
            'title' => 'kept',
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(['title'], array_keys($input->fields));
    }

    public function testAnOverlongTitleIsRefused(): void
    {
        $errors = [];
        SeoInput::fromPayload(['title' => str_repeat('a', 201)], $errors);

        self::assertArrayHasKey('seo.title', $errors);
    }

    public function testAnImageIdMustBeAnAttachmentId(): void
    {
        $errors = [];
        SeoInput::fromPayload(['image_id' => -1], $errors);

        self::assertArrayHasKey('seo.image_id', $errors);
    }

    /** The standalone entry point throws instead of collecting. */
    public function testFromRequestThrows(): void
    {
        $this->expectException(ApiException::class);

        SeoInput::fromRequest(['canonical' => 'nope']);
    }

    public function testFromRequestReturnsTheInputWhenValid(): void
    {
        self::assertSame('Tapis', SeoInput::fromRequest(['title' => 'Tapis'])->get('title'));
    }
}
