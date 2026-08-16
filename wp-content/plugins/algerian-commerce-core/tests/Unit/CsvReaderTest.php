<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\ImportExport\CsvReader;
use AlgerianCommerce\ImportExport\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    public function testRowsComeBackKeyedByColumnName(): void
    {
        $csv = CsvReader::parse("sku,stock_quantity\nA-1,5\nB-2,7\n");

        self::assertSame(['sku', 'stock_quantity'], $csv->columns);
        self::assertCount(2, $csv->rows);
        self::assertSame(['sku' => 'A-1', 'stock_quantity' => '5'], $csv->rows[0]['values']);
    }

    /** The line number is what an error report points a person at. */
    public function testRowsCarryTheirLineNumberCountingTheHeader(): void
    {
        $csv = CsvReader::parse("sku\nA-1\nB-2\n");

        self::assertSame(2, $csv->rows[0]['line']);
        self::assertSame(3, $csv->rows[1]['line']);
    }

    public function testHeadersAreCaseAndSpaceInsensitive(): void
    {
        $csv = CsvReader::parse(" SKU , Stock_Quantity \nA-1,5\n");

        self::assertSame(['sku', 'stock_quantity'], $csv->columns);
        self::assertTrue($csv->hasColumn('sku'));
    }

    /** A file we exported must survive being edited in Excel and sent back. */
    public function testAByteOrderMarkDoesNotBecomePartOfTheFirstColumnName(): void
    {
        $csv = CsvReader::parse(CsvWriter::BOM . "sku,stock_quantity\nA-1,5\n");

        self::assertSame('sku', $csv->columns[0]);
    }

    public function testAFullRoundTripThroughTheWriterSurvives(): void
    {
        $writer = new CsvWriter(['sku', 'name']);
        $writer->append(['sku' => 'A-1', 'name' => '=Lamp, large']);

        $csv = CsvReader::parse($writer->toString());

        self::assertSame('A-1', $csv->rows[0]['values']['sku']);
        // The escaping quote is removed, and the comma survived the quoting.
        self::assertSame('=Lamp, large', $csv->rows[0]['values']['name']);
    }

    /**
     * Only the quote *we* add is removed. An apostrophe is not rare in a
     * product name, and stripping it unconditionally would corrupt data.
     */
    public function testALeadingApostropheOnOrdinaryTextIsKept(): void
    {
        self::assertSame("'Ain Defla", CsvReader::unescape("'Ain Defla"));
        self::assertSame('=1+1', CsvReader::unescape("'=1+1"));
        self::assertSame("'", CsvReader::unescape("'"));
    }

    public function testBlankLinesAreNotRows(): void
    {
        $csv = CsvReader::parse("sku\nA-1\n\n\nB-2\n");

        self::assertCount(2, $csv->rows);
    }

    public function testAQuotedFieldMayContainANewline(): void
    {
        $csv = CsvReader::parse("sku,note\nA-1,\"two\nlines\"\n");

        self::assertCount(1, $csv->rows);
        self::assertSame("two\nlines", $csv->rows[0]['values']['note']);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CsvReader::parse("   \n");
    }

    public function testTooManyRowsIsRefusedWithTheLimitNamed(): void
    {
        $body = "sku\n" . str_repeat("A-1\n", 5);

        try {
            CsvReader::parse($body, 3);
            self::fail('a file over the row cap must be refused');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->statusCode());
            self::assertStringContainsString('3', (string) ($exception->details()['fields']['file'] ?? ''));
        }
    }

    public function testTooManyBytesIsRefused(): void
    {
        $this->expectException(ApiException::class);

        CsvReader::parse("sku\n" . str_repeat('x', CsvReader::MAX_BYTES + 1));
    }

    /** Every missing column at once — not the first one, then the next upload. */
    public function testMissingColumnsAreAllNamedTogether(): void
    {
        $csv = CsvReader::parse("name\nLamp\n");

        try {
            $csv->requireColumns(['sku', 'stock_quantity']);
            self::fail('missing columns must be refused');
        } catch (ApiException $exception) {
            $message = (string) ($exception->details()['fields']['file'] ?? '');

            self::assertStringContainsString('sku', $message);
            self::assertStringContainsString('stock_quantity', $message);
            self::assertSame(['name'], $exception->details()['columns_found'] ?? null);
        }
    }

    public function testRequiredColumnsThatArePresentPass(): void
    {
        $csv = CsvReader::parse("sku,stock_quantity\nA-1,5\n");

        $csv->requireColumns(['sku', 'stock_quantity']);

        self::assertTrue(true, 'no exception');
    }

    /** A short row is missing values, not shifted ones. */
    public function testAShortRowFillsTheMissingColumnsWithEmpty(): void
    {
        $csv = CsvReader::parse("sku,name,price\nA-1,Lamp\n");

        self::assertSame('', $csv->rows[0]['values']['price']);
        self::assertSame('Lamp', $csv->rows[0]['values']['name']);
    }
}
