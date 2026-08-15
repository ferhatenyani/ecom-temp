<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * The idempotency claim — docs/SECURITY.md → "Webhooks", roadmap §60.
 *
 * This table exists for one line in that rule: **the event id is claimed, not
 * checked.** A read-then-write test — "have we seen this event? no, then
 * process it" — races precisely when a provider retries in parallel, which is
 * the one case it exists for. The same defect migration 006 fixed for shipments,
 * and the same answer: let the database refuse the duplicate.
 *
 * So the unique key across (provider, event_id) *is* the mechanism. A handler
 * inserts a row before doing anything; the insert either succeeds, and the event
 * is ours to process, or it fails on the duplicate key, and the event has
 * already been handled by whoever won. Nothing reads this table to decide.
 *
 * Keyed by provider as well as by event id because ids are only unique inside
 * the provider that issued them — Chargily's ULIDs, Svix's `msg_…`, and whatever
 * comes next have no reason to avoid each other, and one collision would drop a
 * real event silently.
 *
 * `event_id` is a varchar(191) rather than a longer one so it fits a unique key
 * under `utf8mb4` (191 × 4 = 764 bytes, inside InnoDB's 767-byte prefix limit on
 * older row formats). Every id in play is far shorter — Chargily's is 26
 * characters — and where a provider sends none, the adapter substitutes a
 * SHA-256 of the signed material, which is 64.
 *
 * `received_at` is not decoration: it is what a cleanup job would prune by, and
 * what answers "did we ever get told about this?" during a reconciliation. It
 * is the *arrival* time, not the provider's `created_at`, because the two
 * differing is itself the interesting case.
 *
 * Deliberately provider-generic rather than payment-specific. Yalidine's and ZR
 * Express's webhooks are unblocked by the same §55 review and land in this same
 * table; a `payment_events` table would have to be joined by a second one the
 * week after.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the webhook event claim table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_webhook_events';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it adds duplicates.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL DEFAULT '',
            event_id varchar(191) NOT NULL DEFAULT '',
            event_type varchar(64) NOT NULL DEFAULT '',
            received_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event (provider,event_id),
            KEY received_at (received_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
