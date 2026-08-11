<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Inventory movement ledger.
 *
 * A table of its own rather than rows in ac_audit_logs, for three reasons:
 *
 *  1. Movements are *data*. They are filtered and SUMmed by product, reason
 *     and date range, which needs real typed columns and indexes — not JSON
 *     metadata that has to be decoded in PHP before it can be added up.
 *  2. They are machine-generated, one or more per order line. Mixed into the
 *     audit trail they would bury the human actions it exists to record.
 *  3. The two have different retention policies. An audit trail is kept for
 *     as long as compliance requires; a stock ledger is pruned or rolled up
 *     once the period it covers is closed.
 *
 * A *manual* adjustment writes to both: a movement row (what happened to the
 * stock) and an audit row (a person changed stock). Order-driven movements
 * write only the movement row.
 *
 * Quantities are signed bigints. WooCommerce's wc_stock_amount() casts to int
 * unless a store filters it, negative values are legitimate under backorders,
 * and integers keep SUM() exact. A store that enables fractional stock needs a
 * forward migration to widen these columns.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the inventory movement ledger.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_inventory_movements';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it will try to add
         * duplicate indexes.
         *
         * product_id is the id whose stock actually moved. For a variation
         * that inherits stock management from its parent that is the *parent*
         * id, because that is the row WooCommerce decrements.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            delta bigint(20) NOT NULL DEFAULT 0,
            quantity_before bigint(20) NOT NULL DEFAULT 0,
            quantity_after bigint(20) NOT NULL DEFAULT 0,
            reason varchar(40) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY created_at (created_at),
            KEY reason (reason),
            KEY order_id (order_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
