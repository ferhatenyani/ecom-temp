<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\CMS\MenuInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The menu write payload — §89.
 *
 * Two properties carry this class. **The shape is the shop's, not
 * WordPress's** — `{label, type, …, children}` rather than
 * `_menu_item_object_id` — and **it still accepts what a read returns**, so
 * "GET the menu, drag one item, PUT it back" works without the panel learning
 * WordPress's vocabulary.
 */
final class MenuInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function errors(array $payload): array
    {
        try {
            MenuInput::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsTheShapeSpecifiedInAdminPanelMd(): void
    {
        $input = MenuInput::fromPayload(['items' => [
            ['label' => 'Tapis', 'type' => 'category', 'object_id' => 21, 'children' => []],
            ['label' => 'Conditions', 'type' => 'page', 'path' => 'legal/terms', 'children' => []],
            ['label' => 'Instagram', 'type' => 'url', 'url' => 'https://example.test/ig', 'children' => []],
        ]]);

        self::assertCount(3, $input->items);
        self::assertSame(21, $input->items[0]['object_id']);
        self::assertSame('legal/terms', $input->items[1]['path']);
        self::assertSame('https://example.test/ig', $input->items[2]['url']);
    }

    /**
     * The read shape, normalised. `CmsPresenter::menu()` publishes WordPress's
     * `post_type` / `taxonomy` / `custom` and a `title`, because that was §61's
     * contract; without this every menu screen would have to translate.
     */
    #[DataProvider('readShape')]
    public function testItAcceptsWhatAReadReturns(string $type, string $object, string $expected): void
    {
        $input = MenuInput::fromPayload(['items' => [[
            'id' => 91,
            'title' => 'Tapis',
            'url' => 'https://example.test/tapis',
            'target' => '',
            'type' => $type,
            'object' => $object,
            'object_id' => 21,
            'position' => 1,
            'classes' => [],
            'children' => [],
        ]]]);

        self::assertSame($expected, $input->items[0]['type']);
        self::assertSame('Tapis', $input->items[0]['label']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function readShape(): array
    {
        return [
            'a page' => ['post_type', 'page', 'page'],
            'a product' => ['post_type', 'product', 'product'],
            'a category' => ['taxonomy', 'product_cat', 'category'],
            'a custom link' => ['custom', 'custom', 'url'],
        ];
    }

    public function testLabelWinsOverTitleWhenBothArePresent(): void
    {
        $input = MenuInput::fromPayload(['items' => [
            ['label' => 'Nouveau', 'title' => 'Ancien', 'type' => 'url', 'url' => 'https://example.test'],
        ]]);

        self::assertSame('Nouveau', $input->items[0]['label']);
    }

    public function testALabelIsRequired(): void
    {
        self::assertArrayHasKey(
            'items[0].label',
            $this->errors(['items' => [['type' => 'url', 'url' => 'https://example.test']]])
        );
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        self::assertArrayHasKey(
            'items[0].type',
            $this->errors(['items' => [['label' => 'X', 'type' => 'widget']]])
        );
        self::assertSame(['page', 'category', 'product', 'url'], MenuInput::TYPES);
    }

    public function testAPageNeedsAPathOrAnId(): void
    {
        self::assertArrayHasKey(
            'items[0].object_id',
            $this->errors(['items' => [['label' => 'X', 'type' => 'page']]])
        );

        $byId = MenuInput::fromPayload(['items' => [['label' => 'X', 'type' => 'page', 'object_id' => 7]]]);
        self::assertSame(7, $byId->items[0]['object_id']);
    }

    public function testACategoryCannotBeAddressedByPath(): void
    {
        // A category has no path in this API, so the path form is not offered
        // and the id is required.
        self::assertArrayHasKey(
            'items[0].object_id',
            $this->errors(['items' => [['label' => 'X', 'type' => 'category', 'path' => 'tapis']]])
        );
    }

    /** §89: two levels. A third is a site map, not a navigation menu. */
    public function testAThirdLevelIsRefusedAndTheDepthIsNamed(): void
    {
        $errors = $this->errors(['items' => [
            ['label' => 'A', 'type' => 'url', 'url' => 'https://example.test/a', 'children' => [
                ['label' => 'B', 'type' => 'url', 'url' => 'https://example.test/b', 'children' => [
                    ['label' => 'C', 'type' => 'url', 'url' => 'https://example.test/c'],
                ]],
            ]],
        ]]);

        self::assertArrayHasKey('items[0].children[0].children', $errors);
        self::assertSame(2, MenuInput::MAX_DEPTH);
    }

    public function testTwoLevelsAreFine(): void
    {
        $input = MenuInput::fromPayload(['items' => [
            ['label' => 'A', 'type' => 'url', 'url' => 'https://example.test/a', 'children' => [
                ['label' => 'B', 'type' => 'url', 'url' => 'https://example.test/b'],
            ]],
        ]]);

        self::assertCount(1, $input->items[0]['children']);
    }

    /** The cap counts every node, at both levels — §89 says fifty items. */
    public function testTheCapCountsBothLevels(): void
    {
        $children = array_fill(0, MenuInput::MAX_ITEMS, [
            'label' => 'X', 'type' => 'url', 'url' => 'https://example.test/x',
        ]);

        $errors = $this->errors(['items' => [
            ['label' => 'A', 'type' => 'url', 'url' => 'https://example.test/a', 'children' => $children],
        ]]);

        self::assertArrayHasKey('items', $errors);
        self::assertStringContainsString((string) MenuInput::MAX_ITEMS, $errors['items']);
    }

    #[DataProvider('urls')]
    public function testWhatCountsAsASafeUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, MenuInput::isSafeUrl($url));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function urls(): array
    {
        return [
            'https' => ['https://example.test/a', true],
            'http' => ['http://example.test/a', true],
            'a storefront path' => ['/soldes', true],
            // §71's rule: `javascript:` is a valid URL, so the check is an
            // allowlist of schemes rather than a search for bad ones.
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,<script>alert(1)</script>', false],
            'vbscript' => ['vbscript:msgbox(1)', false],
            // Scheme-relative inherits the page's scheme and reads as a path to
            // everyone who is not thinking about it.
            'scheme-relative' => ['//evil.test/a', false],
            'empty' => ['', false],
            'a bare word' => ['soldes', false],
        ];
    }

    public function testAUrlItemNeedsAUrl(): void
    {
        self::assertArrayHasKey(
            'items[0].url',
            $this->errors(['items' => [['label' => 'X', 'type' => 'url']]])
        );
    }

    public function testAnUnknownItemFieldIsRefused(): void
    {
        self::assertArrayHasKey(
            'items[0].onclick',
            $this->errors(['items' => [
                ['label' => 'X', 'type' => 'url', 'url' => 'https://example.test', 'onclick' => 'alert(1)'],
            ]])
        );
    }

    public function testAnUnknownTopLevelFieldIsRefused(): void
    {
        self::assertArrayHasKey('location', $this->errors(['items' => [], 'location' => 'primary']));
    }

    public function testItemsIsRequired(): void
    {
        self::assertArrayHasKey('items', $this->errors([]));
    }

    /** Emptying a menu is a legitimate thing to want. */
    public function testAnEmptyMenuIsAccepted(): void
    {
        self::assertSame([], MenuInput::fromPayload(['items' => []])->items);
    }
}
