<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * The notification queue — docs/PLAN.md §29, §30, roadmap step 34.
 *
 * **The claim and the queue are one table, on purpose**, exactly as migration
 * 009 argued for marketing events: a claim in one table and a job in another can
 * disagree, and the disagreement is a customer told twice that their order
 * shipped, or never told at all. `UNIQUE (channel, dedupe_key)` is what makes
 * "this event, on this channel, once" a database guarantee rather than a check.
 *
 * `payload` is frozen when the row is written, which matters here for the same
 * reason it did for marketing: an order that is refunded between the queueing
 * of its confirmation and the sending of it must still send the confirmation
 * that was true when it was placed. A drain that re-read the order would send a
 * message describing a state the customer never saw.
 *
 * **Why a queue at all**, when §63 refused a rollup table for wanting a driver
 * WP-Cron cannot provide: the alternative is worse. Sending inline puts an SMTP
 * connection on the checkout path, where a slow mail server becomes a slow
 * checkout and a dead one becomes a failed order. The driver problem is real
 * and is answered the way §62b answered it — `wp algerian-commerce
 * send-notifications` is the reliable drain and the cron is a convenience, not
 * the mechanism.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the notification queue and claim table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_notifications';

        /*
         * `recipient` is 191 rather than 255 so it fits a utf8mb4 index if one
         * is ever needed; an email address longer than that is not deliverable
         * in practice.
         *
         * `dedupe_key` carries the event and its subject — "order.placed:1234"
         * — rather than a hash, so an operator reading the table can see what a
         * row is for. It is unique per channel, not globally: the same order
         * legitimately produces an email and, later, an SMS.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            channel varchar(32) NOT NULL DEFAULT '',
            event varchar(64) NOT NULL DEFAULT '',
            dedupe_key varchar(191) NOT NULL DEFAULT '',
            audience varchar(16) NOT NULL DEFAULT 'customer',
            recipient varchar(191) NOT NULL DEFAULT '',
            subject_type varchar(32) NOT NULL DEFAULT '',
            subject_id bigint(20) unsigned DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY channel_dedupe (channel,dedupe_key),
            KEY status_created (status,created_at),
            KEY subject (subject_type,subject_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
