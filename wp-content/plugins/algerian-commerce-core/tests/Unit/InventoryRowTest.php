<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\ImportExport\InventoryRow;
use PHPUnit\Framework\TestCase;

final class InventoryRowTest extends TestCase
{
    /** @param array<string, string> $values */
    private static function parse(array $values, ?array &$errors = null): ?InventoryRow
    {
        $errors = [];

        return InventoryRow::parse($values, $errors);
    }

    public function testTheMinimalRowIsASkuAndACount(): void
    {
        $row = self::parse(['sku' => 'A-1', 'stock_quantity' => '12']);

        self::assertNotNull($row);
        self::assertSame('A-1', $row->sku);
        self::assertSame(12, $row->quantity);
        self::assertNull($row->status);
        self::assertNull($row->manageStock);
    }

    public function testBothRequiredColumnsAreReportedTogether(): void
    {
        self::parse(['sku' => '', 'stock_quantity' => ''], $errors);

        self::assertArrayHasKey('sku', $errors);
        self::assertArrayHasKey('stock_quantity', $errors);
    }

    /**
     * A spreadsheet writes 12.0 for a whole number, and a locale turns 1234
     * into "1 234" or "1,234". Rejecting those sends someone looking for a typo
     * that is really Excel being helpful.
     */
    public function testSpreadsheetNumberFormattingIsAccepted(): void
    {
        foreach (['12', '12.0', '1,234', '1 234', "1\u{00A0}234"] as $raw) {
            $row = self::parse(['sku' => 'A-1', 'stock_quantity' => $raw]);

            self::assertNotNull($row, "{$raw} should parse");
        }

        self::assertSame(1234, self::parse(['sku' => 'A-1', 'stock_quantity' => '1,234'])->quantity);
    }

    public function testAFractionalCountIsRefused(): void
    {
        self::parse(['sku' => 'A-1', 'stock_quantity' => '12.5'], $errors);

        self::assertArrayHasKey('stock_quantity', $errors);
    }

    public function testTextInTheQuantityColumnIsRefused(): void
    {
        self::parse(['sku' => 'A-1', 'stock_quantity' => 'plenty'], $errors);

        self::assertArrayHasKey('stock_quantity', $errors);
    }

    /** Negative stock is a WooCommerce state, never something a count produces. */
    public function testANegativeCountIsRefused(): void
    {
        self::parse(['sku' => 'A-1', 'stock_quantity' => '-3'], $errors);

        self::assertArrayHasKey('stock_quantity', $errors);
    }

    /** The guard against a barcode landing in the quantity column. */
    public function testAnAbsurdCountIsRefused(): void
    {
        self::parse(['sku' => 'A-1', 'stock_quantity' => '5514203000'], $errors);

        self::assertArrayHasKey('stock_quantity', $errors);
    }

    public function testZeroIsAValidCount(): void
    {
        $row = self::parse(['sku' => 'A-1', 'stock_quantity' => '0']);

        self::assertNotNull($row);
        self::assertSame(0, $row->quantity);
    }

    public function testStockStatusUsesWooCommercesVocabulary(): void
    {
        // "available" is what a person writes; it is not what WooCommerce
        // stores, and guessing which of the three it meant is not our job.
        self::parse(['sku' => 'A-1', 'stock_quantity' => '1', 'stock_status' => 'available'], $errors);

        self::assertArrayHasKey('stock_status', $errors);
    }

    /** A spreadsheet capitalises; the vocabulary is still WooCommerce's. */
    public function testStockStatusIsMatchedCaseInsensitively(): void
    {
        foreach (['OutOfStock', 'OUTOFSTOCK', ' outofstock '] as $raw) {
            $row = self::parse(['sku' => 'A-1', 'stock_quantity' => '1', 'stock_status' => $raw]);

            self::assertSame('outofstock', $row?->status, "{$raw} should be understood");
        }
    }

    public function testASpreadsheetsIdeaOfABooleanIsUnderstood(): void
    {
        foreach (['1', 'TRUE', 'yes', 'Y'] as $raw) {
            self::assertTrue(self::parse(['sku' => 'A', 'stock_quantity' => '1', 'manage_stock' => $raw])?->manageStock);
        }

        foreach (['0', 'false', 'NO', 'n'] as $raw) {
            self::assertFalse(self::parse(['sku' => 'A', 'stock_quantity' => '1', 'manage_stock' => $raw])?->manageStock);
        }
    }

    /**
     * Blank means "leave it alone". False would switch stock management off for
     * every row of a file that simply did not mention the column.
     */
    public function testABlankBooleanMeansNoChangeRatherThanFalse(): void
    {
        $row = self::parse(['sku' => 'A-1', 'stock_quantity' => '1', 'manage_stock' => '']);

        self::assertNull($row?->manageStock);
    }

    public function testAnUnreadableBooleanIsRefused(): void
    {
        self::parse(['sku' => 'A-1', 'stock_quantity' => '1', 'manage_stock' => 'maybe'], $errors);

        self::assertArrayHasKey('manage_stock', $errors);
    }

    public function testEveryProblemInARowIsReportedAtOnce(): void
    {
        self::parse([
            'sku' => '',
            'stock_quantity' => 'lots',
            'stock_status' => 'nope',
            'manage_stock' => 'maybe',
        ], $errors);

        self::assertCount(4, $errors, 'a validator that stops at the first error costs an upload per mistake');
    }
}
