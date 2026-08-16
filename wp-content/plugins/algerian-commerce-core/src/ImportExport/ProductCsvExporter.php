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
 * So this subclass exists to widen one method's visibility and nothing else. It
 * is the smallest possible amount of WooCommerce to reimplement: none of it.
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
     * The whole export as a string, with the BOM `CsvWriter` puts on ours.
     *
     * Excel reads a CSV as the system codepage unless the file says otherwise,
     * so without it an Arabic product name arrives as mojibake — and a shop
     * whose product export is unreadable and whose order export is fine would
     * reasonably conclude the product export is broken.
     */
    public function toCsv(): string
    {
        $this->prepare_data_to_export();

        return CsvWriter::BOM . $this->get_csv_data();
    }
}
