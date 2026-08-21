<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

use WC_Product_CSV_Exporter;

/**
 * WooCommerce's product exporter, with the CSV handed back as a string.
 *
 * `WC_CSV_Exporter` offers exactly two ways out and neither suits an API.
 * `export()` sends its own headers and calls `die()`, which would end the
 * request before `FileDownload` or CORS ever ran. `generate_file()` writes the
 * catalogue into `wp-content/uploads` under a predictable name for a later
 * request to collect — and however briefly it sits there, it is the shop's
 * whole product list at a guessable URL, which is the retained-file surface
 * §64's stateless design exists to avoid. `get_csv_data()` is the third thing,
 * and it is protected.
 *
 * So this subclass widens one method's visibility, and renames the header row
 * to the field names WooCommerce's importer reads — see `get_column_names()`.
 * It is still the smallest possible amount of WooCommerce to reimplement: none
 * of it. Both methods delegate; neither knows what a product is.
 *
 * **It must not be referenced before `WooCsv::load()` has run.** The parent
 * class is not autoloaded in a non-admin request, and PHP resolves a parent at
 * the moment the subclass is loaded — so touching this first is a fatal error,
 * not a graceful one. `ExportService` calls `WooCsv::load()` first for that
 * reason.
 */
final class ProductCsvExporter extends WC_Product_CSV_Exporter
{
    /**
     * The two columns whose export id is not the importer's field name.
     *
     * `WC_CSV_Exporter` keeps its columns as `id => label`; the ids are the
     * importer's field names *almost* everywhere, and composing WooCommerce's
     * own two tables — this exporter's `id => label` against the admin
     * importer's `label => field` — named the exceptions rather than leaving
     * them to be discovered. Measured 2026-08-22 across all 52 columns of a
     * real export, exactly two diverge:
     *
     * - `stock` is the export id and `stock_quantity` is the field. Emitting
     *   `stock` would produce a file whose quantity column WooCommerce's
     *   importer silently ignores, which is the same class of failure this
     *   whole change is about — a file that looks right and imports nothing.
     * - `global_unique_id` has **no** entry in the admin importer's label
     *   table, so WooCommerce's own admin importer maps that column to the
     *   lowercased label `gtin, upc, ean, or isbn` and drops it. The field
     *   name is `global_unique_id` — `WC_Product::set_global_unique_id()`
     *   exists and `set_props()` reaches it — so the id is already right and
     *   this file's header is the one that carries the GTIN through.
     *
     * The table is deliberately a list of *exceptions* and not a copy of
     * WooCommerce's mapping. A copy would be the label-to-field fork `WooCsv`
     * exists to refuse; two entries are a correction to two ids.
     *
     * @var array<string, string>
     */
    private const IMPORTER_FIELD_NAMES = [
        'stock' => 'stock_quantity',
    ];

    /**
     * The same columns, named the way WooCommerce's *importer* reads them.
     *
     * > **Corrected in the build: the header was display labels, and no
     * > importer outside `wp-admin` can read them.** Measured 2026-08-22, a
     * > products export fed straight back into `POST /import/products` reported
     * > `rows 33, created 33, updated 0, skipped 0, failed 0` with `sku` and
     * > `name` **empty on every preview row** — a dry run telling an operator
     * > that 33 products were about to be created out of a file from which
     * > nothing had been read.
     * >
     * > `WC_Product_CSV_Importer::map_headers()` is `isset($mapping[$key]) ?
     * > $mapping[$key] : $key` — with no mapping passed, the header **is** the
     * > field names, matched exactly. The table that turns `SKU` into `sku`
     * > lives in `includes/admin/importers/mappings/` and is applied by the
     * > admin *controller*, not by the importer; `WooCsv` does not load
     * > `admin/`, and that is the whole argument of that class.
     * >
     * > So the header is written as field names. `export_column_headers()`
     * > emits the *values* of this map and `export_row()`/`generate_row_data()`
     * > key off the ids, so replacing the values renames the header and moves
     * > no data.
     * >
     * > **This costs nothing in WooCommerce's own admin importer, which was the
     * > reason the labels were kept.** Measured 2026-08-22 against
     * > `WC_Product_CSV_Importer_Controller::auto_map_columns()` and the
     * > `<select>` the mapping screen builds from `get_mapping_options()`: a
     * > field-name header arrives already equal to an option value and is
     * > preselected — 40 of 52 columns, against 39 of 52 for the label header,
     * > the twelve attribute columns resolving identically in both. The one
     * > column that differs is the GTIN, which the label header loses and this
     * > one keeps.
     */
    public function get_column_names()
    {
        $names = [];

        foreach (array_keys(parent::get_column_names()) as $id) {
            $id = (string) $id;
            $names[$id] = self::IMPORTER_FIELD_NAMES[$id] ?? $id;
        }

        return $names;
    }

    /**
     * The whole export as a string, with the BOM `CsvWriter` puts on ours.
     *
     * Excel reads a CSV as the system codepage unless the file says otherwise,
     * so without it an Arabic product name arrives as mojibake — and a shop
     * whose product export is unreadable and whose order export is fine would
     * reasonably conclude the product export is broken.
     *
     * > **Corrected in the build: `get_csv_data()` is the rows and not the
     * > file.** It was called on its own here, and `WC_CSV_Exporter` splits the
     * > two — `export()` sends `export_column_headers() . get_csv_data()` and
     * > this called only the second half. So **the product export had no header
     * > row**: measured 2026-08-21, it began `10,simple,AC-TAP-001,…` where
     * > `/export/orders`, `/export/inventory` and `/export/customers` all begin
     * > with their column names.
     * >
     * > That is not cosmetic. A 52-column file with no column names is not
     * > readable by a person, and it is **not re-importable by this API**:
     * > `POST /import/products` reads the first line as the header, so the file
     * > answered *"The file is missing required columns. Missing: sku."* with
     * > `columns_found` listing one product's own values as though they were
     * > column names. Export, edit, re-import is the entire reason both routes
     * > exist, and the products half of it could not complete.
     * >
     * > Found while building the admin panel's import and export screen, where
     * > that round trip is what the screen is for.
     */
    public function toCsv(): string
    {
        $this->prepare_data_to_export();

        return CsvWriter::BOM . $this->export_column_headers() . $this->get_csv_data();
    }
}
