<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;
use AlgerianCommerce\Shipping\ShipmentStatus;

/**
 * Make "one live shipment per order" a rule the database keeps.
 *
 * Until now it was a rule `ShippingService` checked and then hoped about: it
 * reads the table, finds no live shipment, calls the courier, and writes the
 * row. Two requests arriving together — a double-clicked button, a retrying
 * client, a future bulk-ship — both read "none", both call the courier, and the
 * customer gets two parcels and the shop two delivery charges.
 *
 * Migration 004 considered a unique key and rejected it, correctly, for the
 * shape it had in mind: a key across (provider, provider_shipment_id) refuses a
 * second locally-created row because MySQL treats the empty id as a value
 * rather than as absent. This is the shape that works instead.
 *
 * `live_order_id` holds the order id **while the shipment is live** and NULL
 * once it is finished. A unique index ignores NULLs, so the column reads as:
 * one live shipment per order, and any number of finished ones. A re-send after
 * a failed delivery still works, which is the behaviour 004 was protecting.
 *
 * The value is written by `Shipment::toRow()` from `ShipmentStatus::isLive()`,
 * so the live/finished decision stays in the one place that already owns it
 * rather than being restated in SQL — which matters, because §56 added
 * `returning` to that vocabulary and will not be the last to change it.
 *
 * **The index is the guarantee, not the defence.** It rejects the duplicate
 * *row*, which on its own would arrive after the courier had already accepted
 * the duplicate *parcel* — a tidy table and a real van carrying an order twice.
 * `ShipmentRepository::claimOrder()` is what makes the second request stop
 * before it calls anyone; this is what holds when something bypasses it.
 *
 * Not dbDelta: this alters a table rather than creating one, and dbDelta cannot
 * be told to backfill between adding a column and indexing it. Each step checks
 * whether it has already been done, so the migration is re-runnable by hand.
 */
return new class implements Migration {
    private const COLUMN = 'live_order_id';
    private const INDEX = 'live_order';

    public function description(): string
    {
        return 'Enforce one live shipment per order in the schema.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_shipments';

        $this->addColumn($wpdb, $table);
        $this->backfill($wpdb, $table);
        $this->retireOlderDuplicates($wpdb, $table);
        $this->addIndex($wpdb, $table);
    }

    private function addColumn(wpdb $wpdb, string $table): void
    {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", self::COLUMN));

        if ($exists !== null) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN " . self::COLUMN . ' bigint(20) unsigned NULL DEFAULT NULL AFTER status'
        );
    }

    /** Every shipment still in the air claims its order. */
    private function backfill(wpdb $wpdb, string $table): void
    {
        $placeholders = implode(', ', array_fill(0, count(ShipmentStatus::TERMINAL), '%s'));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET " . self::COLUMN . ' = order_id
             WHERE ' . self::COLUMN . " IS NULL AND status NOT IN ({$placeholders})",
            ShipmentStatus::TERMINAL
        ));
    }

    /**
     * An install that already has two live shipments on one order cannot have
     * the index added over them, and there is no answer here that is ours to
     * pick: both parcels may be real, and one of them may be on a van.
     *
     * So the newest keeps the claim and the older ones release it. **Nothing is
     * deleted and nothing changes status** — those rows stay live, stay visible,
     * and stay pollable; they are simply outside a constraint that could not
     * have been true of them anyway. The rule holds from here forward, which is
     * what a schema constraint can honestly promise about data it arrived after.
     */
    private function retireOlderDuplicates(wpdb $wpdb, string $table): void
    {
        $column = self::COLUMN;

        $wpdb->query(
            "UPDATE {$table} SET {$column} = NULL
             WHERE {$column} IS NOT NULL
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT MAX(id) AS id FROM {$table}
                       WHERE {$column} IS NOT NULL
                       GROUP BY order_id
                   ) AS newest
               )"
        );
    }

    private function addIndex(wpdb $wpdb, string $table): void
    {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", self::INDEX));

        if ($exists !== null) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY " . self::INDEX . ' (' . self::COLUMN . ')');
    }
};
