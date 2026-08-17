<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\CMS\PageInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The page write payload — §89.
 *
 * The decision this class exists to carry is the split between the **address**
 * and the **fields that change it**: the URL captures `path`, the body renames
 * with `slug` and moves with `parent_path`. `tests/Api/cms.php` asserts the
 * behaviour end to end; this asserts the shape.
 */
final class PageInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function errors(array $payload, bool $create = true): array
    {
        try {
            $create ? PageInput::forCreate($payload) : PageInput::forUpdate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsAPage(): void
    {
        $input = PageInput::forCreate([
            'title' => '  Mentions légales  ',
            'slug' => 'Mentions-Legales',
            'parent_path' => 'Legal',
            'status' => 'draft',
            'menu_order' => 3,
            'image_id' => 41,
        ]);

        self::assertSame('Mentions légales', $input->get('title'));
        // Lower-cased, because a path is an address and `Legal` and `legal`
        // must not be two of them.
        self::assertSame('mentions-legales', $input->get('slug'));
        self::assertSame('legal', $input->get('parent_path'));
        self::assertSame('draft', $input->get('status'));
        self::assertSame(3, $input->get('menu_order'));
        self::assertSame(41, $input->get('image_id'));
    }

    public function testTitleIsRequiredOnCreate(): void
    {
        self::assertArrayHasKey('title', $this->errors(['slug' => 'terms']));
    }

    public function testUpdateNeedsNothing(): void
    {
        self::assertTrue(PageInput::forUpdate([])->isEmpty());
    }

    /**
     * The whole point of the `path` capture, at the input layer: a slug is one
     * segment, and a payload that tried to rename *and* move in one string is
     * refused rather than split.
     */
    public function testASlugIsOneSegment(): void
    {
        $errors = $this->errors(['title' => 'X', 'slug' => 'legal/terms']);

        self::assertArrayHasKey('slug', $errors);
        self::assertStringContainsString('parent_path', $errors['slug']);
    }

    #[DataProvider('badSlugs')]
    public function testBadSlugs(string $slug): void
    {
        self::assertArrayHasKey('slug', $this->errors(['title' => 'X', 'slug' => $slug]));
    }

    /** @return array<string, array{string}> */
    public static function badSlugs(): array
    {
        return [
            'empty' => [''],
            'a space' => ['mentions legales'],
            'traversal' => ['../wp-config'],
            'a dot' => ['terms.php'],
            'a percent' => ['terms%20'],
        ];
    }

    public function testTheRootIsExpressibleTwoWays(): void
    {
        self::assertSame('', PageInput::forUpdate(['parent_path' => ''])->get('parent_path'));
        self::assertSame('', PageInput::forUpdate(['parent_path' => null])->get('parent_path'));
    }

    public function testAParentPathMayBeNested(): void
    {
        self::assertSame('legal/fr', PageInput::forUpdate(['parent_path' => 'legal/fr'])->get('parent_path'));
    }

    public function testStatusIsAnEnum(): void
    {
        self::assertArrayHasKey('status', $this->errors(['title' => 'X', 'status' => 'trash']));
        self::assertSame(['draft', 'publish'], PageInput::STATUSES);
    }

    /**
     * Emitted by the presenter, so dropped rather than refused — `GET` → edit →
     * `PATCH` must round-trip. `path` and `parent_id` are the two that would
     * otherwise look writable.
     */
    #[DataProvider('readOnly')]
    public function testReadOnlyFieldsAreDroppedNotRefused(string $field, mixed $value): void
    {
        $input = PageInput::forUpdate([$field => $value, 'title' => 'X']);

        self::assertFalse($input->has($field));
        self::assertSame('X', $input->get('title'));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function readOnly(): array
    {
        return [
            'id' => ['id', 12],
            'path' => ['path', 'legal/terms'],
            'parent_id' => ['parent_id', 41],
            'image' => ['image', ['id' => 1]],
            'date_created' => ['date_created', '2026-08-17T00:00:00'],
            'date_modified' => ['date_modified', '2026-08-17T00:00:00'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusedFieldsAreNamedWithAReason(string $field, string $reason): void
    {
        $errors = $this->errors(['title' => 'X', $field => 'anything']);

        self::assertArrayHasKey($field, $errors);
        self::assertStringContainsString($reason, $errors[$field]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refused(): array
    {
        return [
            'author' => ['author', 'audit trail'],
            'post_type' => ['post_type', 'pages only'],
            'template' => ['template', 'theme'],
            'password' => ['password', 'headless'],
            'comment_status' => ['comment_status', 'not part of this API'],
        ];
    }

    public function testAnUnknownFieldIsRefusedToo(): void
    {
        self::assertArrayHasKey('whatever', $this->errors(['title' => 'X', 'whatever' => 1]));
    }

    /**
     * §62's rule: a page's SEO is written through the page, and an SEO error
     * lands in the **same** `fields` list. A second, differently shaped error
     * response is what this avoids.
     */
    public function testSeoErrorsLandInTheSameFieldList(): void
    {
        $errors = $this->errors(['title' => 'X', 'seo' => ['canonical' => 'javascript:alert(1)']]);

        self::assertArrayHasKey('seo.canonical', $errors);
    }

    public function testSeoIsCarriedAsItsOwnInput(): void
    {
        $input = PageInput::forUpdate(['seo' => ['title' => 'Conditions générales']]);

        self::assertInstanceOf(\AlgerianCommerce\SEO\SeoInput::class, $input->get('seo'));
    }

    /** Clearing is a real state: a body sending null empties the field. */
    public function testContentAndExcerptAcceptNullAsClear(): void
    {
        $input = PageInput::forUpdate(['content' => null, 'excerpt' => null]);

        self::assertSame('', $input->get('content'));
        self::assertSame('', $input->get('excerpt'));
    }
}
