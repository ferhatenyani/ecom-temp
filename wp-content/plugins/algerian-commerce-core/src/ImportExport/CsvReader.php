<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

use AlgerianCommerce\API\ApiException;

/**
 * Reads an uploaded CSV into rows keyed by column name — roadmap §64.
 *
 * Pure — no WordPress, no filesystem — so every malformed file a shop can
 * produce is a unit test rather than something discovered during an import.
 *
 * **The header is the contract.** Rows come back keyed by column name, never by
 * position: a positional reader silently misreads the moment somebody inserts a
 * column in Excel, and an import that writes prices into the stock column is
 * the failure this whole section's dry run exists to prevent.
 *
 * **A file this shop exported must survive being edited and sent back.** Two
 * things make that true. The UTF-8 BOM `CsvWriter` adds for Excel is stripped
 * here, or the first column would be named `\xEF\xBB\xBFsku` and match nothing.
 * And the single quote `CsvWriter` prefixes onto a formula is removed — but
 * *only* where the next character is a formula trigger, which is exactly the
 * pattern we produce. Stripping a leading quote unconditionally would corrupt
 * every legitimate value that starts with one, and an apostrophe is not a rare
 * character in a product name.
 *
 * Row count and byte size are both capped. §64's pipeline is deliberately
 * stateless — the file is parsed inside the request that carries it and never
 * stored — so "how much can one request chew" is a real limit rather than a
 * theoretical one, and the answer has to be a refusal a person can act on
 * rather than a timeout.
 */
final class CsvReader
{
    /**
     * The most rows one request will accept.
     *
     * A catalogue import beyond this is a batching problem, which is the point
     * at which the `ac_import_jobs` table §64 declined starts to earn its
     * place — see the README. Refusing at a stated number is what makes that
     * threshold visible instead of arriving as a 504.
     */
    public const MAX_ROWS = 2000;

    /** 8 MiB, matching the media cap's order of magnitude. */
    public const MAX_BYTES = 8388608;

    private function __construct(
        /** @var list<string> */
        public readonly array $columns,
        /** @var list<array{line: int, values: array<string, string>}> */
        public readonly array $rows
    ) {
    }

    /**
     * @throws ApiException 400 when the file is not a usable CSV at all.
     */
    public static function parse(string $contents, int $maxRows = self::MAX_ROWS): self
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw ApiException::invalidRequest('The file is too large.', [
                'fields' => ['file' => 'At most ' . self::MAX_BYTES . ' bytes.'],
            ]);
        }

        $contents = self::stripBom(trim($contents) === '' ? '' : $contents);

        if (trim($contents) === '') {
            throw ApiException::invalidRequest('The file is empty.', [
                'fields' => ['file' => 'A CSV with a header row is required.'],
            ]);
        }

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw ApiException::internal();
        }

        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, 0, CsvWriter::DELIMITER, CsvWriter::ENCLOSURE, '');

        if (!is_array($header) || $header === [null]) {
            fclose($handle);

            throw ApiException::invalidRequest('The file has no header row.', [
                'fields' => ['file' => 'The first line must name the columns.'],
            ]);
        }

        $columns = self::normalizeHeader($header);
        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, 0, CsvWriter::DELIMITER, CsvWriter::ENCLOSURE, '')) !== false) {
            $line++;

            // fgetcsv reports a blank line as [null]; a trailing newline is not
            // a row, and neither is the blank line somebody left in the middle.
            if ($values === [null] || $values === [] || self::isBlank($values)) {
                continue;
            }

            if (count($rows) >= $maxRows) {
                fclose($handle);

                throw ApiException::invalidRequest('The file has too many rows.', [
                    'fields' => ['file' => "At most {$maxRows} rows in one request."],
                ]);
            }

            $rows[] = ['line' => $line, 'values' => self::combine($columns, $values)];
        }

        fclose($handle);

        return new self($columns, $rows);
    }

    public function hasColumn(string $name): bool
    {
        return in_array($name, $this->columns, true);
    }

    /**
     * @param list<string> $required
     * @throws ApiException 400 naming every column that is missing, not just the first
     */
    public function requireColumns(array $required): void
    {
        $missing = array_values(array_diff($required, $this->columns));

        if ($missing === []) {
            return;
        }

        throw ApiException::invalidRequest('The file is missing required columns.', [
            'fields' => [
                'file' => 'Missing: ' . implode(', ', $missing) . '.',
            ],
            'columns_found' => $this->columns,
            'columns_required' => $required,
        ]);
    }

    /**
     * Lower-cased and trimmed, so `SKU`, `sku` and ` Sku ` are one column.
     *
     * A shop owner's spreadsheet has been through Excel, Google Sheets and at
     * least one colleague; insisting on exact case would reject files that are
     * correct in every way that matters.
     *
     * @param list<mixed> $header
     * @return list<string>
     */
    private static function normalizeHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $name) {
            $columns[] = strtolower(trim((string) $name));
        }

        return $columns;
    }

    /**
     * @param list<string> $columns
     * @param list<mixed>  $values
     * @return array<string, string>
     */
    private static function combine(array $columns, array $values): array
    {
        $row = [];

        foreach ($columns as $index => $column) {
            if ($column === '') {
                continue;
            }

            $row[$column] = self::unescape(trim((string) ($values[$index] ?? '')));
        }

        return $row;
    }

    /**
     * Undo `CsvWriter::escape()`, and nothing else.
     *
     * Only a quote immediately followed by a formula trigger is removed, which
     * is precisely the sequence we emit. `'Hello` keeps its apostrophe.
     */
    public static function unescape(string $value): string
    {
        if (!str_starts_with($value, "'") || $value === "'") {
            return $value;
        }

        return in_array(mb_substr($value, 1, 1), CsvWriter::FORMULA_TRIGGERS, true)
            ? substr($value, 1)
            : $value;
    }

    private static function stripBom(string $contents): string
    {
        return str_starts_with($contents, CsvWriter::BOM)
            ? substr($contents, strlen(CsvWriter::BOM))
            : $contents;
    }

    /** @param list<mixed> $values */
    private static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
