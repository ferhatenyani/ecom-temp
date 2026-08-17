<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\GlobalAttributeInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GlobalAttributeInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function createErrors(array $payload): array
    {
        try {
            GlobalAttributeInput::forCreate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsAnAttribute(): void
    {
        $input = GlobalAttributeInput::forCreate([
            'name' => ' Taille ',
            'slug' => 'taille',
            'order_by' => 'name',
            'has_archives' => true,
        ]);

        self::assertSame('Taille', $input->get('name'));
        self::assertSame('taille', $input->get('slug'));
        self::assertSame('name', $input->get('order_by'));
        self::assertTrue($input->get('has_archives'));
    }

    public function testNameIsRequiredOnCreate(): void
    {
        $errors = $this->createErrors(['slug' => 'taille']);

        self::assertArrayHasKey('name', $errors);
        self::assertStringContainsString('label', $errors['name']);
    }

    public function testUpdateNeedsNothing(): void
    {
        self::assertTrue(GlobalAttributeInput::forUpdate([])->isEmpty());
    }

    /**
     * §82 accepts `pa_size` and `size` alike, and this API publishes the
     * prefixed form as `taxonomy`. Refusing the form the API itself emits
     * would be the round-trip failure the read-only list exists to prevent.
     */
    public function testStripsThePaPrefixRatherThanRefusingIt(): void
    {
        self::assertSame('size', GlobalAttributeInput::forUpdate(['slug' => 'pa_size'])->get('slug'));
        self::assertSame('size', GlobalAttributeInput::forUpdate(['slug' => 'PA_Size'])->get('slug'));
    }

    /**
     * WordPress caps a taxonomy name at 32 bytes and `pa_` takes three.
     * WooCommerce reported 29 on 11.0.1 and is the authority; this is the
     * message, and the two are checked against each other by the API suite.
     */
    public function testRefusesASlugOverTheByteBudget(): void
    {
        $errors = $this->createErrors(['name' => 'Long', 'slug' => str_repeat('a', 30)]);

        self::assertArrayHasKey('slug', $errors);
        self::assertStringContainsString('32', $errors['slug']);

        self::assertSame(
            str_repeat('a', 29),
            GlobalAttributeInput::forUpdate(['slug' => str_repeat('a', 29)])->get('slug')
        );
    }

    /** @return array<string, array{0: string}> */
    public static function refusedFieldProvider(): array
    {
        return [
            'terms' => ['terms'],
            'attribute_id' => ['attribute_id'],
            'attribute_name' => ['attribute_name'],
        ];
    }

    #[DataProvider('refusedFieldProvider')]
    public function testRefusesByNameWithAReason(string $field): void
    {
        $errors = $this->createErrors(['name' => 'Taille', $field => 'anything']);

        self::assertArrayHasKey($field, $errors);
        self::assertNotSame('Unknown field.', $errors[$field]);
    }

    public function testDropsReadOnlyFieldsInsteadOfRefusingThem(): void
    {
        $input = GlobalAttributeInput::forUpdate([
            'id' => 4,
            'taxonomy' => 'pa_size',
            'term_count' => 3,
            'product_count' => 12,
            'name' => 'Taille',
        ]);

        self::assertSame(['name' => 'Taille'], $input->fields);
    }

    public function testOrderByIsAnEnum(): void
    {
        foreach (GlobalAttributeInput::ORDER_BY as $orderBy) {
            self::assertSame($orderBy, GlobalAttributeInput::forUpdate(['order_by' => $orderBy])->get('order_by'));
        }

        self::assertArrayHasKey('order_by', $this->createErrors(['name' => 'X', 'order_by' => 'sideways']));
    }

    /**
     * Shape here, vocabulary in the service: `wc_get_attribute_types()` is a
     * filtered list a plugin can extend, so a hard-coded enum in a pure class
     * would refuse a type the platform accepts.
     */
    public function testTypeIsCheckedForShapeOnly(): void
    {
        self::assertSame('hologram', GlobalAttributeInput::forUpdate(['type' => 'hologram'])->get('type'));
        self::assertArrayHasKey('type', $this->createErrors(['name' => 'X', 'type' => '']));
    }

    public function testHasArchivesMustBeABoolean(): void
    {
        self::assertArrayHasKey('has_archives', $this->createErrors(['name' => 'X', 'has_archives' => 'yes']));
    }

    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        $errors = $this->createErrors(['name' => '', 'order_by' => 'sideways', 'has_archives' => 'yes']);

        self::assertCount(3, $errors);
    }
}
