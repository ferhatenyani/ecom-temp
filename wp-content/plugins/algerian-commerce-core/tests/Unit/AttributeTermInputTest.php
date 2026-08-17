<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\AttributeTermInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeTermInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function createErrors(array $payload): array
    {
        try {
            AttributeTermInput::forCreate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsATerm(): void
    {
        $input = AttributeTermInput::forCreate([
            'name' => ' Moyen ',
            'slug' => 'm',
            'description' => ' Taille moyenne ',
            'menu_order' => 2,
        ]);

        self::assertSame('Moyen', $input->get('name'));
        self::assertSame('m', $input->get('slug'));
        self::assertSame('Taille moyenne', $input->get('description'));
        self::assertSame(2, $input->get('menu_order'));
    }

    public function testNameIsRequiredOnCreate(): void
    {
        self::assertArrayHasKey('name', $this->createErrors(['slug' => 'm']));
    }

    public function testUpdateNeedsNothing(): void
    {
        self::assertTrue(AttributeTermInput::forUpdate([])->isEmpty());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedFieldProvider(): array
    {
        return [
            'term_id' => ['term_id', 'in the URL'],
            'parent' => ['parent', 'flat'],
            'products' => ['products', 'writing the product'],
        ];
    }

    #[DataProvider('refusedFieldProvider')]
    public function testRefusesByNameWithAReason(string $field, string $needle): void
    {
        $errors = $this->createErrors(['name' => 'Moyen', $field => 'anything']);

        self::assertArrayHasKey($field, $errors);
        self::assertNotSame('Unknown field.', $errors[$field]);
        self::assertStringContainsString($needle, $errors[$field]);
    }

    /**
     * `count` and `id` are emitted by the presenter, so they are dropped rather
     * than refused — the round trip `docs/API.md` promises.
     */
    public function testDropsReadOnlyFieldsInsteadOfRefusingThem(): void
    {
        $input = AttributeTermInput::forUpdate([
            'id' => 33,
            'count' => 7,
            'taxonomy' => 'pa_size',
            'attribute_id' => 4,
            'name' => 'Moyen',
        ]);

        self::assertSame(['name' => 'Moyen'], $input->fields);
    }

    public function testNameAndSlugAreNotEmptiable(): void
    {
        self::assertArrayHasKey('name', $this->createErrors(['name' => '   ']));
        self::assertArrayHasKey('slug', $this->createErrors(['name' => 'M', 'slug' => '']));
    }

    /** A description is the one field a shop legitimately clears. */
    public function testDescriptionIsEmptiable(): void
    {
        self::assertSame('', AttributeTermInput::forUpdate(['description' => null])->get('description'));
        self::assertSame('', AttributeTermInput::forUpdate(['description' => ''])->get('description'));
    }

    public function testMenuOrderMustBeNumeric(): void
    {
        self::assertArrayHasKey('menu_order', $this->createErrors(['name' => 'M', 'menu_order' => 'first']));
        self::assertSame(0, AttributeTermInput::forUpdate(['menu_order' => '0'])->get('menu_order'));
    }

    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        $errors = $this->createErrors(['name' => '', 'slug' => '', 'menu_order' => 'x']);

        self::assertCount(3, $errors);
    }
}
