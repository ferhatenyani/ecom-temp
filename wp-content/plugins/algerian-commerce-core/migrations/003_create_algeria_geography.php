<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Algerian geography — roadmap §51, docs/PLAN.md §10.
 *
 * Three tables in one migration because they are one change: a wilaya list with
 * no communes is half a dataset, and a provider mapping with nothing to map to
 * is none at all.
 *
 * Custom tables rather than WooCommerce's country/state machinery, which stores
 * a flat `state` string per address and has no concept below it. Communes are
 * the level Algerian couriers actually deliver to, there are roughly 1,500 of
 * them, and shipping rates and destination ids hang off them — that is a
 * genuinely custom, high-volume domain, which is docs/ARCHITECTURE.md §7's test
 * for a table of our own.
 *
 * **Provider destination ids live in their own table** (roadmap §51: "Store
 * mappings required by shipping providers separately"). Yalidine and ZR Express
 * renumber their destinations on their own schedule; keeping their ids in a
 * column on ac_geo_communes would make a provider's churn a migration of the
 * canonical Algerian data, and adding a third provider a schema change.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the Algerian wilaya, commune and provider destination tables.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $this->wilayas($wpdb, $charsetCollate);
        $this->communes($wpdb, $charsetCollate);
        $this->destinations($wpdb, $charsetCollate);
    }

    /**
     * The primary key is the official wilaya code, 1–69, not an auto-increment.
     *
     * It is a real natural key: every Algerian knows Alger is 16, it is printed
     * on number plates and identity documents, and every reform since — 48 to
     * 58 in 2019, then to 69 — has *added* codes rather than renumbering the
     * originals. An auto-increment would invent a second identity for something
     * that already has a stable one, and re-importing the dataset could quietly
     * change it.
     */
    private function wilayas(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_geo_wilayas';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it adds duplicates.
         */
        $sql = "CREATE TABLE {$table} (
            id smallint(5) unsigned NOT NULL,
            code varchar(4) NOT NULL DEFAULT '',
            slug varchar(120) NOT NULL DEFAULT '',
            name varchar(120) NOT NULL DEFAULT '',
            name_ar varchar(120) NOT NULL DEFAULT '',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            UNIQUE KEY slug (slug),
            KEY name (name),
            KEY is_active (is_active)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    /**
     * Communes get an auto-increment id and a natural unique key of
     * (wilaya_id, slug).
     *
     * Unlike wilayas they have no numbering anyone agrees on across sources, so
     * there is no natural key to promote. The slug is what makes a re-import
     * idempotent: it is accent-folded, so a dataset that later spells "Béjaïa"
     * where it once said "Bejaia" updates the row instead of inserting a second
     * one beside it.
     *
     * `postal_code` is a string and may be empty — PLAN.md §10 says "postal
     * codes where available", and a leading zero in an integer column is a
     * postal code that silently becomes a different postal code.
     */
    private function communes(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_geo_communes';

        /*
         * `daira` is the administrative level between a wilaya and a commune.
         * It is part of the canonical hierarchy and some couriers key their
         * destinations on it, so it is stored rather than flattened away.
         *
         * `national_code` is the commune's code in the national numbering. Its
         * first digits are the *pre-2019* wilaya, so it is an identifier to
         * reconcile other datasets against, never the wilaya to deliver to.
         *
         * Coordinates are carried for §53 shipping — distance banding and map
         * pins — rather than used now. They arrive with the same rows and
         * dropping them would cost a migration and a full re-import to undo.
         * Nullable: a dataset without them is still a usable dataset.
         */
        $sql = "CREATE TABLE {$table} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            wilaya_id smallint(5) unsigned NOT NULL,
            slug varchar(160) NOT NULL DEFAULT '',
            name varchar(160) NOT NULL DEFAULT '',
            name_ar varchar(160) NOT NULL DEFAULT '',
            daira varchar(160) NOT NULL DEFAULT '',
            daira_ar varchar(160) NOT NULL DEFAULT '',
            postal_code varchar(10) NOT NULL DEFAULT '',
            national_code varchar(16) NOT NULL DEFAULT '',
            latitude decimal(10,7) NULL DEFAULT NULL,
            longitude decimal(10,7) NULL DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wilaya_slug (wilaya_id,slug),
            KEY wilaya_id (wilaya_id),
            KEY name (name),
            KEY daira (daira),
            KEY postal_code (postal_code),
            KEY national_code (national_code),
            KEY is_active (is_active)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    /**
     * One row per (provider, place).
     *
     * `commune_id` is 0 for a wilaya-level destination, which is how the
     * cheaper "stopdesk" services are addressed — the courier names an office
     * per wilaya, not per commune. Zero rather than NULL so the unique key
     * behaves: MySQL treats NULLs as distinct, and two NULL commune rows for
     * the same provider and wilaya would both be allowed.
     *
     * `destination_id` is a varchar because it is the provider's identifier and
     * ours to store, not to interpret. Assuming it is an integer is how an
     * integration breaks the first time a provider ships a code like "16A".
     */
    private function destinations(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_geo_provider_destinations';

        $sql = "CREATE TABLE {$table} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL DEFAULT '',
            wilaya_id smallint(5) unsigned NOT NULL DEFAULT 0,
            commune_id int(10) unsigned NOT NULL DEFAULT 0,
            destination_id varchar(64) NOT NULL DEFAULT '',
            metadata longtext NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_place (provider,wilaya_id,commune_id),
            KEY provider_destination (provider,destination_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
