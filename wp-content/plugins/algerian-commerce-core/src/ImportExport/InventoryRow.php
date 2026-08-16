<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

/**
 * One row of an inventory CSV — roadmap §64's "inventory import".
 *
 * Pure — no WordPress — so every way a warehouse spreadsheet can be wrong is a
 * unit test.
 *
 * **WooCommerce has no inventory CSV**, only a product one, so this format is
 * ours and is deliberately tiny: a stock count is `sku` and a number. The
 * product CSV can already carry stock, and anyone changing prices and stock
 * together should use that; this exists because the person holding the counting
 * sheet is not the person who owns the catalogue, and handing them a 40-column
 * file to edit is how a stock take overwrites a price.
 *
 * ``` text
 * sku,stock_quantity[,stock_status][,manage_stock]
 * ```
 *
 * **Errors are collected, never thrown.** Every problem with a row is reported
 * at once — `ImportReport` shows the shop owner all of them before anything is
 * written, and a validator that stopped at the first would make them re-upload
 * once per mistake.
 */
final class InventoryRow
{
    public const SKU = 'sku';
    public const QUANTITY = 'stock_quantity';
    public const STATUS = 'stock_status';
    public const MANAGE = 'manage_stock';

    /** @var list<string> */
    public const REQUIRED_COLUMNS = [self::SKU, self::QUANTITY];

    /** WooCommerce's own vocabulary, unchanged. */
    public const STATUSES = ['instock', 'outofstock', 'onbackorder'];

    /**
     * A sanity bound on a single cell.
     *
     * Not a business rule about how much stock a shop may hold: it is the guard
     * against a spreadsheet that put a phone number or a barcode in the
     * quantity column, which is a mistake that reads as plausible until the
     * shop is holding 5,514,203 lamps.
     */
    public const MAX_QUANTITY = 1000000;

    private function __construct(
        public readonly string $sku,
        public readonly int $quantity,
        public readonly ?string $status,
        public readonly ?bool $manageStock
    ) {
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors filled with per-column messages
     */
    public static function parse(array $values, array &$errors): ?self
    {
        $errors = [];

        $sku = trim($values[self::SKU] ?? '');

        if ($sku === '') {
            $errors[self::SKU] = 'Required.';
        }

        $quantity = self::quantity($values[self::QUANTITY] ?? '', $errors);
        $status = self::status($values[self::STATUS] ?? '', $errors);
        $manage = self::boolean($values[self::MANAGE] ?? '', $errors);

        if ($errors !== []) {
            return null;
        }

        return new self($sku, (int) $quantity, $status, $manage);
    }

    /** @param array<string, string> $errors */
    private static function quantity(string $raw, array &$errors): ?int
    {
        $raw = trim($raw);

        if ($raw === '') {
            $errors[self::QUANTITY] = 'Required.';

            return null;
        }

        /*
         * A spreadsheet writes 12.0 for a whole number and "1 234" or "1,234"
         * once a locale gets involved. The separators are stripped before the
         * shape is checked, because rejecting "1,234" as non-numeric sends
         * somebody looking for a typo that is really Excel being helpful.
         */
        $clean = str_replace([' ', ',', "\u{00A0}"], '', $raw);

        if (preg_match('/^-?\d+(\.0+)?$/', $clean) !== 1) {
            $errors[self::QUANTITY] = 'Must be a whole number.';

            return null;
        }

        $value = (int) (float) $clean;

        if ($value < 0) {
            // Negative stock is a WooCommerce state (a backorder), but it is
            // never something a stock take *counts*, so it is refused on the
            // way in rather than written and wondered about later.
            $errors[self::QUANTITY] = 'Must be zero or more.';

            return null;
        }

        if ($value > self::MAX_QUANTITY) {
            $errors[self::QUANTITY] = 'Must be at most ' . self::MAX_QUANTITY . '.';

            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    private static function status(string $raw, array &$errors): ?string
    {
        $raw = strtolower(trim($raw));

        if ($raw === '') {
            return null;
        }

        if (!in_array($raw, self::STATUSES, true)) {
            $errors[self::STATUS] = 'Must be one of: ' . implode(', ', self::STATUSES) . '.';

            return null;
        }

        return $raw;
    }

    /**
     * A spreadsheet's idea of a boolean.
     *
     * `TRUE`, `yes`, `1` and `y` all arrive from real files, and a column left
     * blank means "do not change this", which is why the absent case is null
     * rather than false — false would switch stock management *off* for every
     * row of a file that simply did not mention it.
     *
     * @param array<string, string> $errors
     */
    private static function boolean(string $raw, array &$errors): ?bool
    {
        $raw = strtolower(trim($raw));

        if ($raw === '') {
            return null;
        }

        if (in_array($raw, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        $errors[self::MANAGE] = 'Must be true or false.';

        return null;
    }
}
