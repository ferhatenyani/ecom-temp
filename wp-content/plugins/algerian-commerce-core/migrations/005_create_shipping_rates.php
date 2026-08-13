<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * What the shop charges to deliver — roadmap §4 step 28b, docs/PLAN.md §14.
 *
 * **Not WooCommerce shipping zones**, and the reason is not preference.
 * WooCommerce keys a zone on country, state and *postcode*; Algeria's commune
 * dataset has no postal codes at all (§51 — the source has none, and inventing
 * them would put a wrong code on every address in the country), and pricing
 * here is routinely per commune, which WooCommerce has no level for. On top of
 * that the storefront is headless: WC's cart never computes this, our API does.
 * Modelling 1,541 communes as postcode lists inside a data structure designed
 * for a different shape would be forking a WooCommerce model rather than using
 * it — which is exactly what CLAUDE.md forbids. A custom table for a genuinely
 * custom domain is the sanctioned answer.
 *
 * One row is one price for one kind of destination. Breadth comes from `0`
 * meaning "any":
 *
 *   wilaya_id 0, commune_id 0    the whole country — a shop's flat rate
 *   wilaya_id 16, commune_id 0   all of Alger
 *   wilaya_id 16, commune_id 12  that commune only
 *
 * The most specific matching row wins, so a country-wide rate plus a handful of
 * exceptions is three rows rather than sixty-nine. RateResolver decides which
 * row that is, and it is pure, so the precedence is testable without a database.
 *
 * Money is `decimal(12,2)`, never a float: a fee that arrives as a float has
 * lost the last dinar before it reaches a total. `free_over` is nullable
 * because "no threshold" and "free from zero" are different rules and a
 * sentinel like 0 would conflate them.
 *
 * The unique key is what stops a shop writing two prices for the same
 * destination and then wondering which one a customer was charged.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the shipping rate rules table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_shipping_rates';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it adds duplicates.
         *
         * provider '' means the rule applies whichever courier is quoting, and
         * delivery_type '' means it applies to home delivery and desk pickup
         * alike — the same "any" convention as the zero ids above.
         */
        $sql = "CREATE TABLE {$table} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL DEFAULT '',
            wilaya_id smallint(5) unsigned NOT NULL DEFAULT 0,
            commune_id int(10) unsigned NOT NULL DEFAULT 0,
            delivery_type varchar(10) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            free_over decimal(12,2) NULL DEFAULT NULL,
            estimated_days smallint(5) unsigned NULL DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY rule_scope (provider,wilaya_id,commune_id,delivery_type),
            KEY wilaya_id (wilaya_id),
            KEY commune_id (commune_id),
            KEY is_active (is_active)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
