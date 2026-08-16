<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * The marketing event queue and its claim — roadmap §62b.
 *
 * Two jobs in one table, and they are the same job.
 *
 * **The claim.** "Fire Purchase once" is the whole point of §62b, and the
 * mechanism is migration 008's, unchanged: a write-once insert whose
 * duplicate-key failure *is* the answer. `UNIQUE (provider, event_id)` is what
 * makes a retried checkout, a refreshed confirmation page and a second browser
 * tab produce one conversion instead of three. Nothing reads this table to
 * decide whether to send — a read-then-write races exactly when a customer
 * double-clicks, which is the case it exists for.
 *
 * **The queue.** §62b forbids calling Meta on the checkout path: a Meta outage
 * must never fail or delay an order. So the row is written during the request
 * and drained afterwards by `wp algerian-commerce sync-marketing` or the cron
 * event. That makes the claim and the outbox the same insert, which is the
 * reason they are one table rather than two: a claim in one table and a job in
 * another can disagree, and the disagreement is a conversion sent twice or
 * never.
 *
 * Keyed by provider as well as by event id, for migration 008's reason — a
 * TikTok event id has no obligation to avoid a Meta one, and one collision
 * would silently drop a real conversion.
 *
 * `payload` is the event as it will be sent, frozen at claim time. Rebuilding it
 * at drain time from the order would send whatever the order says *then* — a
 * refund, an edited line item, a changed address — instead of what actually
 * happened at the moment of purchase, and the conversion value would quietly
 * stop matching the sale.
 *
 * `order_id` is nullable and deliberately not a foreign key: WooCommerce owns
 * orders (HPOS), an event may not concern one at all, and a deleted order must
 * not take the record of a conversion that really was reported with it.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the marketing event queue and claim table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_marketing_events';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it adds duplicates.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL DEFAULT '',
            event_name varchar(64) NOT NULL DEFAULT '',
            event_id varchar(191) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned DEFAULT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event (provider,event_id),
            KEY status_created (status,created_at),
            KEY order_id (order_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
