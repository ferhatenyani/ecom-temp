<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

use AlgerianCommerce\API\ApiException;

/**
 * Loads WooCommerce's own CSV importer and exporter — roadmap §64.
 *
 * **The product CSV format is reused, not reimplemented, and that is the whole
 * argument of this file.** WooCommerce's product CSV is forty columns wide and
 * carries variations, global and local attributes, cross-sells, downloads, tax
 * classes and arbitrary meta, with a published column vocabulary that every
 * other WooCommerce tool on the market already speaks. Writing our own would
 * fork the fiddliest data contract WooCommerce has — which CLAUDE.md forbids
 * outright — and would produce a file no other tool could read, at a shop whose
 * owner's most likely reason for exporting is to give it to something else.
 *
 * **This is not another §61.** The CMS, SEO and pixel plugins were rejected
 * because the half of them that runs is a rendering concern that never executes
 * headless, and §63 rejected WooCommerce Admin's analytics tables because the
 * importer that fills them is scheduled and never runs here. The CSV engine is
 * none of those things: it is plain PHP that reads a file and calls the product
 * CRUD. Only its *loader* is admin-gated — the classes live in
 * `includes/import/` and `includes/export/`, outside `admin/`, and are simply
 * never required in a non-admin request. Measured on 2026-08-16: with
 * `is_admin()` false, requiring these five files produced a valid 40-column
 * export. So the fix is five `require_once`s, not a reimplementation.
 *
 * They are required lazily rather than at boot: a shop that never imports
 * should not pay for parsing them on every REST request, and a WooCommerce
 * upgrade that moves a file must fail on `/import/products` rather than on
 * `/health`.
 */
final class WooCsv
{
    /**
     * In dependency order — the abstracts before the classes that extend them.
     *
     * @var list<string>
     */
    private const FILES = [
        'includes/export/abstract-wc-csv-exporter.php',
        'includes/export/abstract-wc-csv-batch-exporter.php',
        'includes/export/class-wc-product-csv-exporter.php',
        'includes/import/abstract-wc-product-importer.php',
        'includes/import/class-wc-product-csv-importer.php',
    ];

    public const EXPORTER = 'WC_Product_CSV_Exporter';
    public const IMPORTER = 'WC_Product_CSV_Importer';

    private static bool $loaded = false;

    private function __construct()
    {
    }

    /**
     * @throws ApiException 501 when WooCommerce is not where we expect it
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        if (!defined('WP_PLUGIN_DIR')) {
            throw self::unavailable('WordPress is not fully loaded.');
        }

        $base = WP_PLUGIN_DIR . '/woocommerce/';

        foreach (self::FILES as $file) {
            $path = $base . $file;

            /*
             * Checked rather than assumed. These paths are internal to
             * WooCommerce and a version that moves one of them would otherwise
             * produce a fatal error inside a REST handler — a 500 with nothing
             * in it — where this gives a 501 naming the file that moved.
             */
            if (!is_readable($path)) {
                throw self::unavailable("WooCommerce's CSV support is not available at {$file}.");
            }

            require_once $path;
        }

        foreach ([self::EXPORTER, self::IMPORTER] as $class) {
            if (!class_exists($class)) {
                throw self::unavailable("WooCommerce's {$class} did not load.");
            }
        }

        self::$loaded = true;
    }

    private static function unavailable(string $message): ApiException
    {
        return new ApiException('csv_engine_unavailable', $message, 501);
    }
}
