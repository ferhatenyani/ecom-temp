<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Provider shipment records — docs/PLAN.md §15's field list, roadmap §53.
 *
 * A table of its own, for the reason CLAUDE.md reserves custom tables: this is
 * a genuinely custom, high-volume domain that WooCommerce has no data model
 * for. WooCommerce knows what a customer *paid* for shipping; it has no concept
 * of a parcel handed to Yalidine, its tracking number, or the last status that
 * courier reported. Order meta could hold one shipment badly — it cannot be
 * queried by tracking number, grouped by provider, or filtered by status across
 * the order book, which is exactly what an operations screen does all day.
 *
 * The columns are deliberately the ones every courier has, and no more. What is
 * particular to one provider — pickup desks, label URLs, parcel dimensions,
 * whatever a courier invents next — goes in `metadata` as JSON, so adding a
 * third provider is a data change rather than a migration.
 *
 * `provider_shipment_id` and `tracking_number` are varchars, not integers, for
 * the same reason the provider destination ids are: they are the provider's
 * identifiers, ours to store and not to interpret. Assuming an integer is how
 * an integration breaks the first time a courier ships a code like "16A".
 *
 * There is no unique key across (provider, provider_shipment_id). It is
 * tempting — it looks like the idempotency guard — but a shipment that has been
 * created locally and not yet accepted by a provider has an empty id, and MySQL
 * treats '' as a value rather than as absent, so the second such row would be
 * refused. "One live shipment per order" is a business rule with a reason to
 * give the caller, so ShippingService enforces it and answers 409.
 *
 * No cost column. What a shipment costs is roadmap §14's shipping rules —
 * zones, wilaya pricing, free-shipping thresholds — and a nullable money column
 * that nothing writes would be an invitation to write it inconsistently later.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the provider shipment records table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_shipments';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it adds duplicates.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            provider varchar(32) NOT NULL DEFAULT '',
            provider_shipment_id varchar(64) NOT NULL DEFAULT '',
            tracking_number varchar(64) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY provider_shipment (provider,provider_shipment_id),
            KEY tracking_number (tracking_number),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
