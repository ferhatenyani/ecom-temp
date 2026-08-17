<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Saved audience definitions — roadmap §85.
 *
 * **A segment is a stored query, not a stored membership list**, and that is the
 * whole reason this table has a `criteria` column and no join table. "Customers in
 * Alger who ordered in the last 90 days" is a *definition*; materialising it into
 * a list means it is wrong the next day, and every campaign that used it is
 * quietly wrong with it. Storing criteria means the admin edits one thing and
 * every campaign that names it follows.
 *
 * The resolved list is frozen exactly once, into `ac_campaign_recipients`, when a
 * send is claimed — see migration 012. A segment that grows mid-drain would
 * otherwise mail people the admin never previewed and never counted.
 *
 * ## Manual tags are deferred, with the trigger named
 *
 * A stored query cannot express "the people who phoned us about the delayed
 * shipment". When somebody asks to mail exactly that group, `ac_customer_tags`
 * earns its place; until then a query covers the cases a shop actually has, and
 * a tag table nobody writes to is a column on every customer that is always
 * empty.
 *
 * ## Why JSON rather than columns per criterion
 *
 * `criteria` is validated by `SegmentCriteria`, which is pure and refuses an
 * unknown key by name, so the document cannot drift into something the resolver
 * does not understand. Columns would mean a migration for each new criterion and
 * a table of mostly-NULLs; `HomepageSections` (§61) and `OptionSet` (§83) both
 * settled the same question the same way — a validated document beats a wide
 * sparse row when the shape is a definition rather than a set of facts to query.
 * Nothing selects *on* a criterion: segments are listed and read by id.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the saved customer segment table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_customer_segments';

        /*
         * `UNIQUE (name)` because a segment is referred to by name in every
         * conversation about it, and two audiences called "Alger regulars" is a
         * campaign sent to the wrong one. 191 characters so the index fits
         * utf8mb4.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL DEFAULT '',
            description varchar(255) NOT NULL DEFAULT '',
            criteria longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
