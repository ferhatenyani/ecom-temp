<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\ImportExport\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    /**
     * The reason this class exists. A cell starting with one of these is a
     * formula to Excel, LibreOffice and Google Sheets, and the attacker needs
     * nothing more than one product name or one customer's first name.
     */
    public function testEveryFormulaTriggerIsNeutralised(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tcmd", "\rcmd"] as $payload) {
            $field = CsvWriter::field($payload);

            self::assertStringStartsWith("'", ltrim($field, '"'), "{$payload} was not escaped");
        }
    }

    public function testTheClassicCommandInjectionPayloadIsInert(): void
    {
        $writer = new CsvWriter(['name']);
        $writer->append(['name' => '=cmd|\' /C calc\'!A0']);

        // The quote comes first, so the spreadsheet reads the cell as text.
        self::assertStringContainsString("'=cmd", $writer->toString());
        self::assertStringNotContainsString(',=cmd', $writer->toString());
    }

    public function testOrdinaryTextIsNotMangled(): void
    {
        self::assertSame('Tapis berbère', CsvWriter::field('Tapis berbère'));
        self::assertSame('AC-LAMP-01', CsvWriter::field('AC-LAMP-01'));
    }

    /**
     * Quoting a number turns a price column into strings and breaks the shop
     * owner's own SUM().
     */
    public function testNumbersPassThroughUnquoted(): void
    {
        self::assertSame('1500', CsvWriter::field(1500));
        self::assertSame('12.5', CsvWriter::field(12.5));
    }

    public function testNullAndBooleansHaveAStableRendering(): void
    {
        self::assertSame('', CsvWriter::field(null));
        self::assertSame('', CsvWriter::field(false));
        self::assertSame('1', CsvWriter::field(true));
    }

    /** RFC 4180: enclose when needed, and double an embedded quote. */
    public function testFieldsAreQuotedOnlyWhenTheyNeedToBe(): void
    {
        self::assertSame('plain', CsvWriter::field('plain'));
        self::assertSame('"a,b"', CsvWriter::field('a,b'));
        self::assertSame('"say ""hi"""', CsvWriter::field('say "hi"'));
        self::assertSame("\"two\nlines\"", CsvWriter::field("two\nlines"));
    }

    /**
     * A newline inside a value must not become a new record — that is how an
     * export gains rows that were never in the shop.
     */
    public function testAnEmbeddedNewlineDoesNotCreateARow(): void
    {
        $writer = new CsvWriter(['note']);
        $writer->append(['note' => "line one\nline two"]);

        self::assertSame(1, $writer->rowCount());
    }

    /**
     * Keyed by column name, so inserting a column cannot shift every value one
     * place to the left.
     */
    public function testValuesFollowTheirColumnRatherThanTheirPosition(): void
    {
        $writer = new CsvWriter(['sku', 'name', 'price']);
        $writer->append(['price' => 100, 'sku' => 'A-1', 'name' => 'Lamp']);

        self::assertStringContainsString('A-1,Lamp,100', $writer->toString());
    }

    public function testAMissingColumnBecomesEmptyRatherThanShiftingTheRow(): void
    {
        $writer = new CsvWriter(['sku', 'name', 'price']);
        $writer->append(['sku' => 'A-1', 'price' => 100]);

        self::assertStringContainsString('A-1,,100', $writer->toString());
    }

    /** Excel reads the system codepage without it, and Arabic arrives broken. */
    public function testTheFileCarriesAUtf8ByteOrderMarkOnce(): void
    {
        $writer = new CsvWriter(['name']);
        $writer->append(['name' => 'أدرار']);

        $out = $writer->toString();

        self::assertStringStartsWith(CsvWriter::BOM, $out);
        self::assertSame(1, substr_count($out, CsvWriter::BOM));
        self::assertStringNotContainsString(CsvWriter::BOM, CsvWriter::field('أدرار'));
    }

    public function testTheHeaderIsNotCountedAsARow(): void
    {
        $writer = new CsvWriter(['a']);

        self::assertSame(0, $writer->rowCount());

        $writer->append(['a' => 1]);

        self::assertSame(1, $writer->rowCount());
    }

    public function testAHeaderColumnIsItselfEscaped(): void
    {
        // A column name can come from a provider or a plugin, so it is data too.
        $writer = new CsvWriter(['=evil']);

        self::assertStringContainsString("'=evil", $writer->toString());
    }

    public function testTheFileEndsWithANewlineSoParsersKeepTheLastRecord(): void
    {
        $writer = new CsvWriter(['a']);
        $writer->append(['a' => 'z']);

        self::assertStringEndsWith("\r\n", $writer->toString());
    }
}
