<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Email marketing campaigns — roadmap §85, docs/PLAN.md §27.
 *
 * **A campaign is not a notification, and the difference is the unique key.**
 * `ac_notifications` (migration 010) carries `UNIQUE (channel, dedupe_key)`, and
 * that index *is* that module's guarantee: eight order hooks produce one email,
 * enforced by the database rather than by a comparison that has to be right in
 * eight places.
 *
 * A campaign has the opposite requirement. One message, thousands of recipients,
 * each needing its own status, its own attempt count and its own error — so that
 * a drain interrupted at recipient 3,000 resumes at 3,001 instead of starting
 * again. Squeezing that into a table designed to collapse duplicates would break
 * the index or defeat it.
 *
 * **And the reason that decides it: a 5,000-recipient campaign sharing the
 * transactional queue delays every order confirmation behind it.** A customer
 * waiting to learn their order was received is not going to wait out a
 * newsletter. So: two tables, two drains.
 *
 * This table holds the message and the counts. Migration 012 holds one row per
 * recipient, which is where the addresses live and what gets purged.
 *
 * ## Why the body is stored rather than looked up
 *
 * `template_id` names an `ac_email_template` post and `body_html`/`body_text`
 * hold what was actually composed. Both, because the second is **frozen at send
 * time**: a template edited mid-drain would otherwise change the message
 * half-way through a send, and the shop could not afterwards say what it had
 * mailed. Migrations 009 and 010 freeze a payload at queue time for the same
 * reason, one level down.
 *
 * ## The counts are the record that survives the purge
 *
 * §85 requires recipient rows to be purged some fixed period after a campaign
 * completes — they hold real email addresses and they outlive the request. What
 * remains is `recipients_total`, `recipients_sent` and `recipients_failed`, which
 * is what "how did that campaign do" actually needs, and which is why they are
 * columns here rather than a `COUNT(*)` over a table that will not be there.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the email marketing campaign table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_campaigns';

        /*
         * `audience_ids` is JSON rather than a join table. An explicit list is a
         * one-off the admin picked in the admin app — it is not queried, filtered
         * or joined, only read once when the send is claimed and frozen into
         * migration 012 — so a table for it would be a table nothing selects
         * from. A saved *segment*, which is queried and reused, does get one
         * (migration 013).
         *
         * `claimed_at` is what makes the send idempotent at the campaign level:
         * `UPDATE ... WHERE status = 'draft'` sets it, so a second POST arriving
         * at the same moment changes no rows and is told the campaign is already
         * sending. The same write-once-insert-as-the-answer discipline as
         * migrations 008, 009 and 010, expressed as an update because the row
         * already exists.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            template_id bigint(20) unsigned NOT NULL DEFAULT 0,
            body_html longtext NOT NULL,
            body_text longtext NOT NULL,
            audience_type varchar(16) NOT NULL DEFAULT 'segment',
            audience_ids longtext NULL,
            segment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(16) NOT NULL DEFAULT 'draft',
            recipients_total int(10) unsigned NOT NULL DEFAULT 0,
            recipients_sent int(10) unsigned NOT NULL DEFAULT 0,
            recipients_failed int(10) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            claimed_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            purged_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status,created_at),
            KEY segment (segment_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
