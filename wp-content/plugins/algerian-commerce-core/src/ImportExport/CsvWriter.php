<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

/**
 * Writes CSV — roadmap §64, docs/PLAN.md §33.
 *
 * Pure — no WordPress, no filesystem — so the rule that stops an export
 * executing commands on the shop owner's laptop is a unit test.
 *
 * **A CSV is a document that a spreadsheet will happily treat as code.** A cell
 * beginning `=`, `+`, `-`, `@`, a tab or a carriage return is a *formula* to
 * Excel, LibreOffice and Google Sheets, and formulas can call out to the shell
 * or exfiltrate the rest of the sheet over HTTP. The attacker does not need
 * access to the shop: they need one product name, one customer's first name,
 * one order note. The shop owner exports their own data, opens it, and runs it.
 *
 * So every field is escaped on the way out, by prefixing a single quote —
 * which spreadsheets read as "this cell is text". That is exactly what
 * `WC_CSV_Exporter::escape_data()` does, and matching it is deliberate: the
 * product export reuses WooCommerce's own exporter (see `WooCsv`), so a shop
 * that opens a product CSV and an order CSV side by side must not find one of
 * them escaped and the other not.
 *
 * That class is not extended for our own exports because it is a batch-export
 * state machine bound to file paths, upload directories and admin AJAX; the
 * eight lines below are the whole of what we need from it. The two are asserted
 * to agree in `tests/Api/import-export.php` — it takes a loaded WooCommerce, so
 * it cannot live in the unit suite — and that check is what stops the
 * duplication drifting silently after a WooCommerce upgrade.
 *
 * Quoting is RFC 4180: a field is enclosed when it contains a delimiter, a
 * quote or a newline, and an embedded quote is doubled.
 */
final class CsvWriter
{
    /**
     * Characters that begin a formula.
     *
     * 0x09 is tab and 0x0d is carriage return — both start a formula in at
     * least one major spreadsheet, and both are invisible in a bug report.
     */
    public const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public const DELIMITER = ',';
    public const ENCLOSURE = '"';

    /**
     * A UTF-8 byte-order mark.
     *
     * Excel on Windows reads a CSV as the system's legacy codepage unless the
     * file says otherwise, so without this every Arabic wilaya name in an
     * export — and every accented commune — arrives as mojibake. It is
     * prepended once, to the file and never to a row.
     */
    public const BOM = "\xEF\xBB\xBF";

    /** @var list<string> */
    private array $lines = [];

    /** @param list<string> $columns */
    public function __construct(private readonly array $columns)
    {
        $this->lines[] = $this->row($columns);
    }

    /**
     * Append one record, in the column order given to the constructor.
     *
     * Keyed by column name rather than positional: a positional writer breaks
     * silently when a column is inserted, and an export that shifts every value
     * one place to the left is the kind of bug nobody notices until an
     * accountant does.
     *
     * @param array<string, scalar|null> $record
     */
    public function append(array $record): void
    {
        $values = [];

        foreach ($this->columns as $column) {
            $values[] = $record[$column] ?? '';
        }

        $this->lines[] = $this->row($values);
    }

    public function rowCount(): int
    {
        // The header is not a row of data.
        return max(0, count($this->lines) - 1);
    }

    public function toString(bool $withBom = true): string
    {
        // A trailing newline: POSIX text files end with one, and a CSV without
        // it makes some parsers drop the final record.
        return ($withBom ? self::BOM : '') . implode("\r\n", $this->lines) . "\r\n";
    }

    /** @param list<scalar|null> $values */
    private function row(array $values): string
    {
        return implode(self::DELIMITER, array_map([self::class, 'field'], $values));
    }

    /**
     * One field: neutralised, then quoted if it needs to be.
     *
     * The order matters. Escaping after quoting would put the quote before the
     * `=`, leaving the formula first inside the cell and the protection
     * outside it.
     */
    public static function field(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return '1';
        }

        // Numbers cannot form a formula, and quoting them makes a spreadsheet
        // treat them as text — which turns a price column into strings and
        // breaks the shop owner's own SUM().
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return self::enclose(self::escape((string) $value));
    }

    /**
     * Prefix a formula trigger with a single quote.
     *
     * Public because the import side needs the same vocabulary to *strip* it
     * from a value this shop exported and someone edited and sent back.
     */
    public static function escape(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // mb_substr, not $value[0]: a multi-byte first character sliced by byte
        // gives a partial code point, and comparing that to '=' is meaningless.
        return in_array(mb_substr($value, 0, 1), self::FORMULA_TRIGGERS, true)
            ? "'" . $value
            : $value;
    }

    private static function enclose(string $value): string
    {
        $needsQuotes = $value === ''
            ? false
            : strpbrk($value, self::DELIMITER . self::ENCLOSURE . "\r\n") !== false;

        if (!$needsQuotes) {
            return $value;
        }

        return self::ENCLOSURE
            . str_replace(self::ENCLOSURE, self::ENCLOSURE . self::ENCLOSURE, $value)
            . self::ENCLOSURE;
    }
}
