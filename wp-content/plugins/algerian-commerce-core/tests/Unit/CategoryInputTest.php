<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\CategoryInput;
use PHPUnit\Framework\TestCase;

final class CategoryInputTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function errors(array $payload, bool $create = true): array
    {
        try {
            $create ? CategoryInput::fromPayload($payload) : CategoryInput::fromPatch($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsAMinimalCreatePayload(): void
    {
        $input = CategoryInput::fromPayload(['name' => 'Tapis']);

        self::assertSame('Tapis', $input->name);
        self::assertNull($input->slug);
        self::assertNull($input->parent);
        self::assertSame('', $input->description);
    }

    public function testCreateRequiresAName(): void
    {
        self::assertArrayHasKey('name', $this->errors([]));
        self::assertArrayHasKey('name', $this->errors(['name' => '   ']));
    }

    public function testCreateNameHasALengthCap(): void
    {
        self::assertArrayHasKey('name', $this->errors(['name' => str_repeat('a', 201)]));
    }

    public function testSlugMustBeKebab(): void
    {
        self::assertArrayHasKey('slug', $this->errors(['name' => 'x', 'slug' => 'Bad Slug']));
        self::assertArrayHasKey('slug', $this->errors(['name' => 'x', 'slug' => 'UPPER']));

        $input = CategoryInput::fromPayload(['name' => 'Berber Rugs', 'slug' => 'tapis-berbere']);
        self::assertSame('tapis-berbere', $input->slug);
    }

    public function testEmptySlugMeansAuto(): void
    {
        // The controller derives one from the name if slug is absent or empty.
        self::assertNull(CategoryInput::fromPayload(['name' => 'x', 'slug' => ''])->slug);
    }

    public function testParentMustBeANonNegativeInteger(): void
    {
        self::assertArrayHasKey('parent', $this->errors(['name' => 'x', 'parent' => -1]));
        self::assertArrayHasKey('parent', $this->errors(['name' => 'x', 'parent' => 'nope']));

        self::assertSame(0, CategoryInput::fromPayload(['name' => 'x', 'parent' => 0])->parent);
        self::assertSame(4, CategoryInput::fromPayload(['name' => 'x', 'parent' => '4'])->parent);
    }

    public function testDescriptionHasALengthCap(): void
    {
        self::assertArrayHasKey('description', $this->errors([
            'name' => 'x', 'description' => str_repeat('x', 5001),
        ]));
    }

    public function testPatchTracksWhichFieldsWereSent(): void
    {
        $input = CategoryInput::fromPatch(['description' => 'Berber wool rugs']);

        self::assertTrue($input->has('description'));
        self::assertFalse($input->has('name'));
        self::assertFalse($input->has('parent'));
        self::assertSame('Berber wool rugs', $input->description);
    }

    public function testPatchRejectsAnEmptyName(): void
    {
        self::assertArrayHasKey('name', $this->errors(['name' => ''], false));
    }

    public function testPatchAcceptsAnEmptyPayload(): void
    {
        $input = CategoryInput::fromPatch([]);

        self::assertFalse($input->has('name'));
        self::assertFalse($input->has('slug'));
        self::assertFalse($input->has('parent'));
        self::assertFalse($input->has('description'));
    }
}
