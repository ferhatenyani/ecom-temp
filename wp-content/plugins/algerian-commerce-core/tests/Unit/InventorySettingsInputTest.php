<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Inventory\InventorySettingsInput;
use PHPUnit\Framework\TestCase;

final class InventorySettingsInputTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function reject(array $payload): ApiException
    {
        try {
            InventorySettingsInput::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception;
        }

        self::fail('Expected the settings payload to be rejected.');
    }

    public function testAcceptsEverySetting(): void
    {
        $input = InventorySettingsInput::fromPayload([
            'manage_stock' => true,
            'stock_status' => 'instock',
            'backorders' => 'notify',
            'low_stock_amount' => 5,
        ]);

        self::assertTrue($input->get('manage_stock'));
        self::assertSame('instock', $input->get('stock_status'));
        self::assertSame('notify', $input->get('backorders'));
        self::assertSame(5, $input->get('low_stock_amount'));
    }

    public function testAnEmptyPayloadIsEmptyRatherThanAnError(): void
    {
        // The service turns this into "No supported fields were provided."
        self::assertTrue(InventorySettingsInput::fromPayload([])->isEmpty());
    }

    /**
     * The rule the whole ledger depends on: a quantity moves only through an
     * adjustment, so this endpoint has to say so rather than shrug.
     */
    public function testRejectsStockQuantityWithAPointerToTheAdjustEndpoint(): void
    {
        $fields = $this->reject(['stock_quantity' => 12])->details()['fields'];

        self::assertStringContainsString('/inventory/{id}/adjust', $fields['stock_quantity']);
    }

    public function testRejectsUnknownFields(): void
    {
        $fields = $this->reject(['low_stock_amonut' => 5])->details()['fields'];

        self::assertSame('Unknown field.', $fields['low_stock_amonut']);
    }

    /**
     * Read-only fields are dropped, not rejected, so GET → edit → PATCH of the
     * whole object works.
     */
    public function testIgnoresTheFieldsWeOurselvesEmit(): void
    {
        $input = InventorySettingsInput::fromPayload([
            'id' => 12,
            'parent_id' => 0,
            'type' => 'simple',
            'name' => 'Djellaba',
            'sku' => 'DJ-1',
            'stock_managed_by_id' => 12,
            'managing_stock' => true,
            'low_stock' => false,
            'backorders' => 'yes',
        ]);

        self::assertSame(['backorders' => 'yes'], $input->fields);
    }

    public function testRejectsAnUnknownStockStatus(): void
    {
        self::assertArrayHasKey('stock_status', $this->reject(['stock_status' => 'maybe'])->details()['fields']);
    }

    public function testRejectsAnUnknownBackorderPolicy(): void
    {
        self::assertArrayHasKey('backorders', $this->reject(['backorders' => 'sure'])->details()['fields']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function booleanProvider(): array
    {
        return [
            'true' => [true],
            'string true' => ['true'],
            'one' => [1],
            'yes' => ['yes'],
        ];
    }

    /** @dataProvider booleanProvider */
    public function testAcceptsTheUsualBooleanSpellings(mixed $value): void
    {
        self::assertTrue(InventorySettingsInput::fromPayload(['manage_stock' => $value])->get('manage_stock'));
    }

    public function testRejectsANonBooleanManageStock(): void
    {
        self::assertArrayHasKey('manage_stock', $this->reject(['manage_stock' => 'perhaps'])->details()['fields']);
    }

    public function testNullClearsThePerProductThreshold(): void
    {
        // WooCommerce stores "no threshold" as an empty string and falls back
        // to the store-wide setting.
        self::assertSame('', InventorySettingsInput::fromPayload(['low_stock_amount' => null])->get('low_stock_amount'));
        self::assertSame('', InventorySettingsInput::fromPayload(['low_stock_amount' => ''])->get('low_stock_amount'));
    }

    public function testAcceptsAZeroThreshold(): void
    {
        self::assertSame(0, InventorySettingsInput::fromPayload(['low_stock_amount' => 0])->get('low_stock_amount'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function badThresholdProvider(): array
    {
        return [
            'negative' => [-1],
            'fractional' => [2.5],
            'text' => ['five'],
        ];
    }

    /** @dataProvider badThresholdProvider */
    public function testRejectsAnInvalidThreshold(mixed $value): void
    {
        self::assertArrayHasKey('low_stock_amount', $this->reject(['low_stock_amount' => $value])->details()['fields']);
    }

    public function testReportsEveryProblemAtOnce(): void
    {
        $fields = $this->reject([
            'stock_status' => 'maybe',
            'backorders' => 'sure',
            'low_stock_amount' => -1,
        ])->details()['fields'];

        self::assertCount(3, $fields);
    }

    public function testOnlyTouchesTheFieldsProvided(): void
    {
        $input = InventorySettingsInput::fromPayload(['backorders' => 'no']);

        self::assertTrue($input->has('backorders'));
        self::assertFalse($input->has('manage_stock'));
        self::assertFalse($input->has('low_stock_amount'));
    }
}
