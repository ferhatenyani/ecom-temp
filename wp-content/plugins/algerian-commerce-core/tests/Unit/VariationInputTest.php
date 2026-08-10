<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\VariationInput;
use PHPUnit\Framework\TestCase;

final class VariationInputTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function errors(array $payload, bool $create = true): array
    {
        try {
            $create ? VariationInput::forCreate($payload) : VariationInput::forUpdate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsAMinimalCreatePayload(): void
    {
        $input = VariationInput::forCreate([
            'attributes' => ['size' => 'M'],
            'regular_price' => '1500',
        ]);

        self::assertSame(['size' => 'M'], $input->attributes);
        self::assertSame('1500', $input->get('regular_price'));
    }

    public function testCreateRequiresAnAttributeCombination(): void
    {
        // Without one, WooCommerce cannot match the variation to a selection.
        self::assertArrayHasKey('attributes', $this->errors(['regular_price' => '10']));
        self::assertArrayHasKey('attributes', $this->errors(['attributes' => []]));
    }

    public function testUpdateDoesNotRequireAttributes(): void
    {
        $input = VariationInput::forUpdate(['regular_price' => '10']);

        self::assertNull($input->attributes);
        self::assertFalse($input->isEmpty());
    }

    public function testAttributeKeysAreLowercasedAndTrimmed(): void
    {
        $input = VariationInput::forUpdate(['attributes' => ['  Size ' => ' M ']]);

        self::assertSame(['size' => 'M'], $input->attributes);
    }

    public function testAnEmptyAttributeValueMeansAny(): void
    {
        $input = VariationInput::forUpdate(['attributes' => ['size' => '']]);

        self::assertSame(['size' => ''], $input->attributes);
    }

    public function testRejectsUnknownFields(): void
    {
        self::assertArrayHasKey('colour', $this->errors(['attributes' => ['size' => 'M'], 'colour' => 'red']));
    }

    public function testPricesFollowTheSameRulesAsProducts(): void
    {
        self::assertArrayHasKey('regular_price', $this->errors([
            'attributes' => ['size' => 'M'], 'regular_price' => -1,
        ]));

        self::assertArrayHasKey('sale_price', $this->errors([
            'attributes' => ['size' => 'M'], 'regular_price' => '100', 'sale_price' => '200',
        ]));
    }

    public function testPricesStayStrings(): void
    {
        $input = VariationInput::forCreate(['attributes' => ['size' => 'M'], 'regular_price' => 1999.5]);

        self::assertIsString($input->get('regular_price'));
    }

    public function testStatusIsLimitedToPublishAndPrivate(): void
    {
        // A variation has no "draft" concept in WooCommerce.
        self::assertArrayHasKey('status', $this->errors(['attributes' => ['s' => 'M'], 'status' => 'draft']));

        $input = VariationInput::forCreate(['attributes' => ['s' => 'M'], 'status' => 'private']);
        self::assertSame('private', $input->get('status'));
    }

    public function testStockRules(): void
    {
        self::assertArrayHasKey('stock_quantity', $this->errors([
            'attributes' => ['size' => 'M'], 'stock_quantity' => -2,
        ]));

        self::assertArrayHasKey('stock_status', $this->errors([
            'attributes' => ['size' => 'M'], 'stock_status' => 'perhaps',
        ]));

        self::assertNull(VariationInput::forUpdate(['stock_quantity' => null])->get('stock_quantity'));
    }

    public function testRejectsANonObjectAttributeMap(): void
    {
        self::assertArrayHasKey('attributes', $this->errors(['attributes' => 'size=M']));
    }

    public function testAnEmptyUpdateIsEmpty(): void
    {
        self::assertTrue(VariationInput::forUpdate([])->isEmpty());
    }
}
