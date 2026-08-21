<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Inventory\InventoryRepository;
use AlgerianCommerce\Inventory\InventoryService;
use AlgerianCommerce\Inventory\MovementReason;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use Throwable;

/**
 * CSV imports — roadmap §64, docs/PLAN.md §33.
 *
 * **The pipeline is stateless, and that is a decision with a trigger.** §64
 * describes upload → validate → preview → confirm → process → report, which
 * looks like it needs the server to hold a parsed job between two requests. It
 * does not: the client sends the file with `dry_run: true` and gets the preview
 * and the error report, then sends the same file with `dry_run: false` to apply
 * it. Nothing is stored, no job table exists, and no uploaded file is retained
 * on disk between requests — which matters because `docs/SECURITY.md` → "File
 * uploads" opens by observing that accepting a file is the most dangerous thing
 * this API does, and a file kept for later is that danger with a longer fuse.
 *
 * The cost is that the client uploads twice, which is nothing when the file is
 * already in its hands. The point at which this stops being the right answer is
 * named rather than left to be discovered: when a catalogue outgrows what one
 * request can chew — `CsvReader::MAX_ROWS` — and needs batching, there has to
 * be somewhere to record "three thousand rows in", and `ac_import_jobs` earns
 * its place. Until then it would be a table to keep clean for no benefit.
 *
 * **Every stock change goes through `InventoryService`.** An import must not be
 * a back door that writes quantities without a ledger movement and an audit
 * row — the whole argument for `ac_inventory_movements` is that every change to
 * a quantity has a reason and an actor, and "a spreadsheet said so" is a reason
 * like any other. Two thousand rows therefore produce two thousand movements,
 * on purpose.
 */
final class ImportService
{
    /** New products are created; a SKU that already exists is skipped. */
    public const MODE_CREATE = 'create';

    /** Existing products are updated; a SKU that is not there is skipped. */
    public const MODE_UPDATE = 'update';

    /** @var list<string> */
    public const MODES = [self::MODE_CREATE, self::MODE_UPDATE];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InventoryRepository $products,
        private readonly AuditLogger $audit,
        private readonly Logger $logger
    ) {
    }

    /**
     * A stock take — roadmap §64's "inventory import".
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function inventory(string $contents, array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        $dryRun = (bool) ($params['dry_run'] ?? true);
        $csv = CsvReader::parse($contents);
        $csv->requireColumns(InventoryRow::REQUIRED_COLUMNS);

        $report = new ImportReport($dryRun);
        $seen = [];

        foreach ($csv->rows as $row) {
            $line = $row['line'];
            $errors = [];
            $parsed = InventoryRow::parse($row['values'], $errors);

            if ($parsed === null) {
                $report->fail($line, 'The row is invalid.', $errors);

                continue;
            }

            /*
             * A SKU twice in one file is a mistake with two plausible readings
             * — the second is a correction, or one of them belongs to a
             * different product — and applying both makes the result depend on
             * row order. Refusing names the earlier line so it can be found.
             */
            if (isset($seen[$parsed->sku])) {
                $report->fail($line, 'This SKU appears earlier in the file.', [
                    InventoryRow::SKU => "Also on line {$seen[$parsed->sku]}.",
                ]);

                continue;
            }

            $seen[$parsed->sku] = $line;

            $product = $this->products->findBySku($parsed->sku);

            if ($product === null) {
                /*
                 * An import never creates a product. A stock take is about
                 * things the shop already has, and a typo'd SKU that silently
                 * created a product with no name or price would be far worse
                 * than a reported failure.
                 */
                $report->fail($line, 'No product with that SKU.', [
                    InventoryRow::SKU => 'Not found. An inventory import never creates products.',
                ]);

                continue;
            }

            $before = $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null;

            $detail = [
                InventoryRow::SKU => $parsed->sku,
                'product_id' => $product->get_id(),
                'from' => $before,
                'to' => $parsed->quantity,
            ];

            if ($before === $parsed->quantity && $parsed->status === null && $parsed->manageStock === null) {
                // Nothing to do, and saying so beats an "updated" count that
                // includes rows where the number never moved.
                $report->record(ImportReport::SKIPPED, $line, $detail + ['reason' => 'unchanged']);

                continue;
            }

            if ($dryRun) {
                $report->record(ImportReport::UPDATED, $line, $detail);

                continue;
            }

            try {
                $this->applyRow($product->get_id(), $parsed);
                $report->record(ImportReport::UPDATED, $line, $detail);
            } catch (ApiException $exception) {
                // The service's own refusal, in its own words — "this product
                // does not manage stock" is more useful than "failed".
                $report->fail($line, $exception->getMessage(), [
                    InventoryRow::SKU => $parsed->sku,
                ]);
            } catch (Throwable $throwable) {
                $this->logger->error('Inventory import row failed', [
                    'line' => $line,
                    'sku' => $parsed->sku,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);

                $report->fail($line, 'The row could not be applied.');
            }
        }

        $this->auditRun('inventory', $report, $csv);

        return $report->toArray();
    }

    /**
     * Settings first, then the quantity.
     *
     * The order is load-bearing: `InventoryService::adjust()` refuses a product
     * that does not manage stock, so a row that turns management *on* and sets
     * a count in the same line only works if the switch is thrown first.
     */
    private function applyRow(int $productId, InventoryRow $row): void
    {
        $settings = [];

        if ($row->manageStock !== null) {
            $settings[InventoryRow::MANAGE] = $row->manageStock;
        }

        if ($row->status !== null) {
            $settings[InventoryRow::STATUS] = $row->status;
        }

        if ($settings !== []) {
            $this->inventory->updateSettings($productId, $settings);
        }

        $this->inventory->adjust($productId, [
            // `set`, not a delta: a stock take states what is on the shelf. A
            // delta would be wrong the moment the file is uploaded twice.
            'mode' => 'set',
            'quantity' => $row->quantity,
            'reason' => MovementReason::CORRECTION,
            'note' => 'CSV inventory import',
        ]);
    }

    /**
     * Products, through WooCommerce's own importer — roadmap §64.
     *
     * **A dry run here is a parse and a lookup, not a rehearsal, and that limit
     * is real.** `WC_Product_CSV_Importer` has no dry-run mode; it parses and
     * writes. Simulating one would mean reimplementing forty columns of
     * mapping, which is exactly the fork `WooCsv` explains why we refuse. So a
     * dry run runs WooCommerce's *own parser* — the same one the real run uses,
     * so a column it cannot read fails here too — and reports how many rows
     * parsed and which SKUs already exist, which is what tells a shop owner
     * whether they are about to create 500 products or update 500.
     *
     * What it cannot promise is that every write will succeed. That is stated
     * in the response as `preview_only`, rather than left for someone to infer
     * from a report that turned out to be optimistic.
     *
     * **`mode` is ours; WooCommerce's flag is called `update_existing` and does
     * not mean what it says.** Measured on 2026-08-16, it is a mode switch and
     * neither setting does both halves of the job:
     *
     * ``` text
     * update_existing   new SKU                      existing SKU
     * false             imported (created)           skipped, unchanged
     * true              skipped, "No matching        updated
     *                     product exists to update"
     * ```
     *
     * Passed through under its own name it would be a trap in both directions —
     * `true` reads as "create and also update" and creates nothing, `false`
     * reads as "do not touch existing" and is the only setting that creates. So
     * the API says `create` or `update`, which is what the two settings
     * actually do, and `create` is the default because a first import is the
     * common case and silently updating nothing is the worse surprise.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function productsImport(string $contents, array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        WooCsv::load();

        $dryRun = (bool) ($params['dry_run'] ?? true);
        $updateExisting = ($params['mode'] ?? self::MODE_CREATE) === self::MODE_UPDATE;

        // Parsed once here purely to refuse an unusable file with our own error
        // shape before WooCommerce sees it, and to bound the row count the same
        // way the inventory import is bounded.
        //
        // This is the lenient half of the `sku` check and it is not sufficient
        // on its own — `requireMappableHeader()` below says why, and cost a dry
        // run that reported 33 creations from a file it had read nothing out of.
        $csv = CsvReader::parse($contents);
        $csv->requireColumns(['sku']);

        $path = $this->writeTempFile($contents);

        try {
            /** @var \WC_Product_CSV_Importer $importer */
            $importer = new (WooCsv::IMPORTER)($path, [
                'parse' => true,
                'update_existing' => $updateExisting,
                'delimiter' => CsvWriter::DELIMITER,
                'lines' => CsvReader::MAX_ROWS,
            ]);

            self::requireMappableHeader($importer);

            $report = new ImportReport($dryRun);

            if ($dryRun) {
                $this->previewProducts($importer, $report, $updateExisting);
            } else {
                $this->runProductImport($importer, $report);
            }
        } finally {
            // Always, on every path. A temp file that outlives a failed import
            // is the retained-file surface this design exists to avoid.
            @unlink($path);
        }

        $payload = $report->toArray();

        if ($dryRun) {
            $payload['preview_only'] = 'WooCommerce\'s product importer has no dry-run mode. '
                . 'This parsed the file with its own parser and looked each SKU up; it does not '
                . 'guarantee every write will succeed.';
        }

        $this->auditRun('products', $report, $csv);

        return $payload;
    }

    /**
     * The second `sku` check, which is not the first one again.
     *
     * `CsvReader::requireColumns(['sku'])` above asks *our* reader, which
     * lower-cases and trims the header on purpose — a spreadsheet that has been
     * through Excel and a colleague should not be rejected over `SKU`. This asks
     * **WooCommerce's** reader, which does not: `map_headers()` is
     * `isset($mapping[$key]) ? $mapping[$key] : $key`, and with no mapping
     * passed the header must already *be* the field names, matched exactly.
     *
     * > **Corrected in the build: the two questions were not the same question,
     * > and only the lenient one was asked.** Measured 2026-08-22, a products
     * > export — whose header was WooCommerce's display labels until the same
     * > branch fixed it — passed `requireColumns` on the strength of `SKU`
     * > lower-casing to `sku`, and then WooCommerce mapped nothing off it. The
     * > dry run answered `rows 33, created 33, updated 0, skipped 0, failed 0`
     * > with `sku` and `name` **empty on every preview row**: an operator was
     * > told 33 products were about to be created from a file out of which not
     * > one field had been read.
     * >
     * > A silent partial read is the failure worth refusing here. The tempting
     * > alternative — hand WooCommerce a `mapping` that lower-cases the header —
     * > would resolve `SKU` and `Name` and still drop `Regular price`, `Stock`
     * > and forty others, so the same file would import as products with names
     * > and no prices and report success. Refusing names the problem while the
     * > operator can still act on it.
     *
     * It asks the importer rather than re-deriving the answer, so the check
     * cannot drift from the parse it is guarding: `get_mapped_keys()` is the
     * very array `parse_data()` reads the rows with.
     */
    private static function requireMappableHeader(object $importer): void
    {
        /** @var list<string> $mapped */
        $mapped = array_map('strval', (array) $importer->get_mapped_keys());

        if (in_array('sku', $mapped, true)) {
            return;
        }

        throw ApiException::invalidRequest('The header does not name WooCommerce\'s import fields.', [
            'fields' => [
                'file' => 'Missing: sku. WooCommerce\'s importer matches column names exactly and reads '
                    . 'field names — sku, name, regular_price, stock_quantity — not the display labels '
                    . '"SKU" and "Regular price" that a wp-admin product export writes. '
                    . 'GET /export/products writes a header this route can read.',
            ],
            'columns_found' => $mapped,
            'columns_required' => ['sku'],
        ]);
    }

    /**
     * What the real run would do, given the mode.
     *
     * The mode decides which rows are work and which are skipped, so a preview
     * that ignored it would tell a shop owner "500 creations" for an `update`
     * run that is about to skip all 500.
     */
    private function previewProducts(object $importer, ImportReport $report, bool $updateExisting): void
    {
        $parsed = $importer->get_parsed_data();
        $line = 1;

        foreach (is_array($parsed) ? $parsed : [] as $row) {
            $line++;
            $sku = is_array($row) ? trim((string) ($row['sku'] ?? '')) : '';
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;

            $exists = ($sku !== '' && $this->products->findBySku($sku) !== null)
                || ($id > 0 && $this->products->find($id) !== null);

            $detail = ['sku' => $sku, 'name' => is_array($row) ? (string) ($row['name'] ?? '') : ''];

            if ($updateExisting) {
                $report->record(
                    $exists ? ImportReport::UPDATED : ImportReport::SKIPPED,
                    $line,
                    $exists ? $detail : $detail + ['reason' => 'no product with that SKU to update']
                );

                continue;
            }

            $report->record(
                $exists ? ImportReport::SKIPPED : ImportReport::CREATED,
                $line,
                $exists ? $detail + ['reason' => 'a product with that SKU already exists'] : $detail
            );
        }
    }

    /**
     * WooCommerce reports four buckets, and two of them hold `WP_Error`s.
     *
     * `imported` and `updated` are product ids; `skipped` and `failed` are
     * errors explaining why. Casting the whole lot to int — which an earlier
     * version of this did — turns a `WP_Error` into 1 and reports product id 1
     * as having been skipped.
     */
    private function runProductImport(object $importer, ImportReport $report): void
    {
        $result = $importer->import();

        foreach (['imported' => ImportReport::CREATED, 'updated' => ImportReport::UPDATED] as $bucket => $action) {
            foreach ((array) ($result[$bucket] ?? []) as $index => $id) {
                // +2: WooCommerce indexes parsed rows from zero, and line 1 is
                // the header — so a report points at the line a person sees.
                $report->record($action, (int) $index + 2, ['product_id' => (int) $id]);
            }
        }

        foreach ((array) ($result['skipped'] ?? []) as $index => $reason) {
            $report->record(ImportReport::SKIPPED, (int) $index + 2, [
                'reason' => self::errorMessage($reason, 'The row was skipped.'),
            ]);
        }

        foreach ((array) ($result['failed'] ?? []) as $index => $failure) {
            $report->fail((int) $index + 2, self::errorMessage($failure, 'The row could not be imported.'));
        }
    }

    private static function errorMessage(mixed $error, string $fallback): string
    {
        if (is_object($error) && method_exists($error, 'get_error_message')) {
            $message = (string) $error->get_error_message();

            return $message === '' ? $fallback : $message;
        }

        return $fallback;
    }

    /**
     * A temp file, because WooCommerce's importer takes a path and not a string.
     *
     * `get_temp_dir()` rather than `wp-content/uploads` — an import file is
     * never web-servable even for the moment it exists, so §61's
     * non-executable uploads rule is a second layer here rather than the only
     * one.
     *
     * **The extension must be `.csv`.** WooCommerce's importer checks the file
     * type before it reads a byte and refuses anything else, so
     * `wp_tempnam()` — which appends `.tmp` — produces a file its own importer
     * rejects with "Invalid file type". The name is otherwise random, so two
     * concurrent imports cannot collide and nothing can pre-create the path.
     */
    private function writeTempFile(string $contents): string
    {
        $path = rtrim(get_temp_dir(), '/\\') . '/ac-import-' . wp_generate_password(16, false) . '.csv';

        if (file_put_contents($path, $contents) === false) {
            throw ApiException::internal('The upload could not be staged for import.');
        }

        return $path;
    }

    private function auditRun(string $subject, ImportReport $report, CsvReader $csv): void
    {
        $summary = $report->toArray();

        $this->audit->record('import.' . $subject, 'import', 0, [
            'dry_run' => $report->dryRun,
            'rows' => count($csv->rows),
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
        ]);
    }
}
