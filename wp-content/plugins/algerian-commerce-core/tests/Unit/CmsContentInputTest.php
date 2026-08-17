<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\CMS\BannerInput;
use AlgerianCommerce\CMS\FaqCategoryInput;
use AlgerianCommerce\CMS\FaqInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Banners, FAQs and FAQ categories — §89's three smaller write payloads.
 *
 * One file rather than three, because what each asserts is the same three
 * decisions and reading them side by side is what shows they were made the same
 * way: **the field names are the presenter's** (so a read body writes back),
 * **a field the presenter emits is dropped and a field it does not is refused
 * by name**, and **a URL is checked against `MenuInput::isSafeUrl()` rather
 * than against a second opinion**.
 */
final class CmsContentInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function errors(callable $factory, array $payload): array
    {
        try {
            $factory($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    // ------------------------------------------------------------- banners --

    public function testABannerTakesThePresentersFieldNames(): void
    {
        $input = BannerInput::forCreate([
            'title' => '  Soldes  ',
            'link' => 'https://example.test/soldes',
            'placement' => 'Home_Hero',
            'position' => 2,
            'image_id' => 41,
            'status' => 'draft',
        ]);

        self::assertSame('Soldes', $input->get('title'));
        self::assertSame('home_hero', $input->get('placement'));
        self::assertSame(2, $input->get('position'));
        self::assertSame(41, $input->get('image_id'));
        self::assertSame('draft', $input->get('status'));
    }

    public function testABannerTitleIsRequiredOnCreate(): void
    {
        self::assertArrayHasKey('title', $this->errors(BannerInput::forCreate(...), ['link' => '/a']));
    }

    /**
     * §71's rule, shared with the menu writer rather than restated: a banner is
     * a link a shopper clicks, and `javascript:` is a valid URL.
     */
    #[DataProvider('bannerLinks')]
    public function testABannerLink(string $link, bool $accepted): void
    {
        if ($accepted) {
            self::assertSame($link, BannerInput::forUpdate(['link' => $link])->get('link'));

            return;
        }

        self::assertArrayHasKey('link', $this->errors(BannerInput::forUpdate(...), ['link' => $link]));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function bannerLinks(): array
    {
        return [
            'https' => ['https://example.test/soldes', true],
            'a storefront path' => ['/soldes', true],
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,x', false],
            'scheme-relative' => ['//evil.test', false],
        ];
    }

    public function testABannerLinkClearsWithNull(): void
    {
        self::assertSame('', BannerInput::forUpdate(['link' => null])->get('link'));
    }

    public function testAPlacementIsAKey(): void
    {
        self::assertArrayHasKey(
            'placement',
            $this->errors(BannerInput::forUpdate(...), ['placement' => 'Home Hero!'])
        );
    }

    #[DataProvider('bannerRefusals')]
    public function testABannerRefusesByNameWithTheReplacement(string $field, string $replacement): void
    {
        $errors = $this->errors(BannerInput::forUpdate(...), [$field => 'x']);

        self::assertArrayHasKey($field, $errors);
        self::assertStringContainsString($replacement, $errors[$field]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function bannerRefusals(): array
    {
        return [
            'menu_order' => ['menu_order', 'position'],
            'content' => ['content', 'caption'],
            'url' => ['url', 'link'],
            'image_url' => ['image_url', 'image_id'],
        ];
    }

    public function testABannerDropsWhatThePresenterEmits(): void
    {
        $input = BannerInput::forUpdate(['id' => 7, 'image' => ['id' => 1], 'date_modified' => 'x', 'position' => 1]);

        self::assertFalse($input->has('id'));
        self::assertFalse($input->has('image'));
        self::assertSame(1, $input->get('position'));
    }

    // ---------------------------------------------------------------- faqs --

    public function testAnFaqTakesThePresentersFieldNames(): void
    {
        $input = FaqInput::forCreate([
            'question' => '  Combien de temps ?  ',
            'position' => 4,
            'status' => 'publish',
        ]);

        self::assertSame('Combien de temps ?', $input->get('question'));
        self::assertSame(4, $input->get('position'));
    }

    /**
     * Three accepted shapes, because the presenter emits the third: a bare
     * slug, a bare id, and the `{id, slug, name}` object a read returns. An id
     * wins when both are present, since an id cannot be ambiguous.
     */
    public function testCategoriesAcceptSlugsIdsAndTheReadShape(): void
    {
        $input = FaqInput::forUpdate(['categories' => [
            'livraison',
            21,
            ['id' => 22, 'slug' => 'retours', 'name' => 'Retours'],
            ['slug' => 'Paiement'],
        ]]);

        self::assertSame(
            [['slug' => 'livraison'], ['id' => 21], ['id' => 22], ['slug' => 'paiement']],
            $input->get('categories')
        );
    }

    public function testCategoriesClearWithAnEmptyList(): void
    {
        self::assertSame([], FaqInput::forUpdate(['categories' => []])->get('categories'));
        self::assertSame([], FaqInput::forUpdate(['categories' => null])->get('categories'));
    }

    public function testABadCategoryEntryIsNamedByIndex(): void
    {
        self::assertArrayHasKey(
            'categories[1]',
            $this->errors(FaqInput::forUpdate(...), ['categories' => ['livraison', 'Not A Slug!']])
        );
    }

    public function testCategoriesMustBeAList(): void
    {
        self::assertArrayHasKey(
            'categories',
            $this->errors(FaqInput::forUpdate(...), ['categories' => ['a' => 'livraison']])
        );
    }

    #[DataProvider('faqRefusals')]
    public function testAnFaqRefusesByNameWithTheReplacement(string $field, string $replacement): void
    {
        $errors = $this->errors(FaqInput::forUpdate(...), [$field => 'x']);

        self::assertArrayHasKey($field, $errors);
        self::assertStringContainsString($replacement, $errors[$field]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function faqRefusals(): array
    {
        return [
            'category' => ['category', 'categories'],
            'title' => ['title', 'question'],
            'content' => ['content', 'answer'],
            'menu_order' => ['menu_order', 'position'],
        ];
    }

    // ---------------------------------------------------------- categories --

    public function testAnFaqCategory(): void
    {
        $input = FaqCategoryInput::forCreate(['name' => '  Livraison  ', 'slug' => 'Livraison']);

        self::assertSame('Livraison', $input->get('name'));
        self::assertSame('livraison', $input->get('slug'));
    }

    public function testAnFaqCategoryNameIsRequiredOnCreate(): void
    {
        self::assertArrayHasKey('name', $this->errors(FaqCategoryInput::forCreate(...), ['slug' => 'x']));
    }

    public function testAnFaqCategorySlugIsASlug(): void
    {
        self::assertArrayHasKey(
            'slug',
            $this->errors(FaqCategoryInput::forUpdate(...), ['slug' => 'Livraison Express'])
        );
    }

    public function testAnFaqCategoryRefusesParentBecauseTheTaxonomyIsFlat(): void
    {
        $errors = $this->errors(FaqCategoryInput::forUpdate(...), ['parent' => 3]);

        self::assertArrayHasKey('parent', $errors);
        self::assertStringContainsString('flat', $errors['parent']);
    }

    public function testAnFaqCategoryDropsCount(): void
    {
        $input = FaqCategoryInput::forUpdate(['id' => 21, 'count' => 4, 'name' => 'Livraison']);

        self::assertFalse($input->has('count'));
        self::assertSame('Livraison', $input->get('name'));
    }

    /** Every one of the three needs nothing at all on update. */
    public function testUpdatesNeedNothing(): void
    {
        self::assertTrue(BannerInput::forUpdate([])->isEmpty());
        self::assertTrue(FaqInput::forUpdate([])->isEmpty());
        self::assertTrue(FaqCategoryInput::forUpdate([])->isEmpty());
    }
}
