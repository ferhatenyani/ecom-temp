<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\AttributeInput;
use PHPUnit\Framework\TestCase;

final class AttributeInputTest extends TestCase
{
    /** @return array<string, string> */
    private function errors(mixed $raw): array
    {
        try {
            AttributeInput::listFromPayload($raw);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the attributes to be rejected.');
    }

    public function testParsesACustomAttribute(): void
    {
        [$attribute] = AttributeInput::listFromPayload([
            ['name' => 'Size', 'options' => ['S', 'M'], 'variation' => true],
        ]);

        self::assertNull($attribute->id);
        self::assertFalse($attribute->isGlobal());
        self::assertSame('Size', $attribute->name);
        self::assertSame(['S', 'M'], $attribute->options);
        self::assertTrue($attribute->variation);
        self::assertTrue($attribute->visible, 'visible defaults to true');
        self::assertSame(0, $attribute->position);
    }

    public function testParsesAGlobalAttribute(): void
    {
        [$attribute] = AttributeInput::listFromPayload([
            ['id' => 3, 'options' => ['red'], 'position' => 2],
        ]);

        self::assertTrue($attribute->isGlobal());
        self::assertSame(3, $attribute->id);
        self::assertSame(2, $attribute->position);
        self::assertFalse($attribute->variation, 'variation defaults to false');
    }

    public function testRequiresAnIdOrAName(): void
    {
        self::assertArrayHasKey('attributes[0]', $this->errors([['options' => ['x']]]));
    }

    public function testRequiresAtLeastOneOption(): void
    {
        // An attribute with no options cannot produce a variation and cannot
        // be displayed; WooCommerce accepts it and it simply vanishes.
        self::assertArrayHasKey('attributes[0].options', $this->errors([['name' => 'Size', 'options' => []]]));
        self::assertArrayHasKey('attributes[0].options', $this->errors([['name' => 'Size']]));
    }

    public function testRejectsBlankOptions(): void
    {
        self::assertArrayHasKey(
            'attributes[0].options',
            $this->errors([['name' => 'Size', 'options' => ['S', '  ']]])
        );
    }

    public function testDeduplicatesOptions(): void
    {
        [$attribute] = AttributeInput::listFromPayload([
            ['name' => 'Size', 'options' => ['S', 'S', 'M']],
        ]);

        self::assertSame(['S', 'M'], $attribute->options);
    }

    public function testRejectsDuplicateAttributes(): void
    {
        // WooCommerce keys attributes by name, so the second silently wins.
        $errors = $this->errors([
            ['name' => 'Size', 'options' => ['S']],
            ['name' => 'size', 'options' => ['M']],
        ]);

        self::assertArrayHasKey('attributes[1]', $errors);
    }

    public function testRejectsUnknownKeys(): void
    {
        self::assertArrayHasKey(
            'attributes[0]',
            $this->errors([['name' => 'Size', 'options' => ['S'], 'variatoin' => true]])
        );
    }

    public function testRejectsANonArrayPayload(): void
    {
        self::assertArrayHasKey('attributes', $this->errors('Size'));
    }

    public function testRejectsAnInvalidId(): void
    {
        self::assertArrayHasKey('attributes[0].id', $this->errors([['id' => -1, 'options' => ['x']]]));
        self::assertArrayHasKey('attributes[0].id', $this->errors([['id' => 'abc', 'options' => ['x']]]));
    }

    public function testIdZeroMeansCustomBecauseThatIsWhatWeEmit(): void
    {
        // ProductPresenter emits id: 0 for a custom attribute. Rejecting it
        // would break GET → edit → PATCH.
        [$attribute] = AttributeInput::listFromPayload([
            ['id' => 0, 'name' => 'Size', 'options' => ['S'], 'visible' => true, 'variation' => false, 'position' => 0],
        ]);

        self::assertFalse($attribute->isGlobal());
        self::assertNull($attribute->id);
        self::assertSame('Size', $attribute->name);
    }

    public function testIdZeroWithoutANameIsStillRejected(): void
    {
        self::assertArrayHasKey('attributes[0]', $this->errors([['id' => 0, 'options' => ['x']]]));
    }

    public function testCoercesBooleans(): void
    {
        [$attribute] = AttributeInput::listFromPayload([
            ['name' => 'Size', 'options' => ['S'], 'visible' => 'false', 'variation' => 'true'],
        ]);

        self::assertFalse($attribute->visible);
        self::assertTrue($attribute->variation);
    }

    public function testPositionDefaultsToTheListOrder(): void
    {
        $attributes = AttributeInput::listFromPayload([
            ['name' => 'Size', 'options' => ['S']],
            ['name' => 'Colour', 'options' => ['Red']],
        ]);

        self::assertSame(0, $attributes[0]->position);
        self::assertSame(1, $attributes[1]->position);
    }

    public function testAnEmptyListIsValid(): void
    {
        // Clearing every attribute is a legitimate edit.
        self::assertSame([], AttributeInput::listFromPayload([]));
    }
}
