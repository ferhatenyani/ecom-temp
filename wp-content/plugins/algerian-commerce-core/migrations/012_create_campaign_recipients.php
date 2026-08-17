<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * One row per campaign recipient — roadmap §85.
 *
 * **This table is why the campaign queue could not be `ac_notifications`.** Each
 * row carries its own status, attempt count and error, which is what makes a
 * resume *correct*: a drain interrupted at recipient 3,000 picks up at 3,001
 * because rows 1–3,000 say `sent`. Without them, the failure mode of a half-sent
 * campaign is a shop that cannot answer "who got this?" and re-sends to
 * everybody.
 *
 * **`UNIQUE (campaign_id, customer_id)` is the idempotency guarantee**, and it is
 * a database one rather than a check. Resolving the same audience twice — a
 * retried request, a `send` that raced itself — inserts nothing the second time,
 * so nobody is queued twice for one campaign. That is migration 010's argument
 * in a different shape: the index, not a comparison somebody has to remember.
 *
 * ## This table holds real email addresses, and that is unavoidable
 *
 * `Marketing\UserData` (§62b) hashes PII on the way in, so nothing en route to an
 * ad network holds a customer's address. **It cannot work that way here** — an
 * SMTP server needs a real address, and this row outlives the request. So it is
 * stated plainly rather than pretended away:
 *
 *  - The table is covered by §66's backup rules and by `backups/.gitignore`,
 *    which is why a backup is never committed.
 *  - **Rows are purged some fixed period after the campaign completes**, keeping
 *    the aggregate counts on `ac_campaigns` and dropping the addresses. See
 *    `RecipientRepository::purge()`.
 *  - No address reaches a log. `EmailChannel` already declines to log recipients,
 *    with the reason, and the campaign drain inherits that discipline.
 *
 * `context` is the merge data for this one recipient, **frozen when the send is
 * claimed** — the name and last order number the template will render. Frozen for
 * migration 009's and 010's reason: a customer who places another order mid-drain
 * must not receive a message describing a state the admin never previewed. It is
 * also what keeps the drain from running a query per recipient.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the per-recipient campaign delivery table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_campaign_recipients';

        /*
         * `email` is 191 rather than 255 so it fits a utf8mb4 index if one is
         * ever needed, matching `ac_notifications.recipient`; an address longer
         * than that is not deliverable in practice.
         *
         * `status_campaign` is the drain's index — "the pending rows of this
         * campaign, oldest first" is the only query that runs per batch, and it
         * has to stay an index lookup at five thousand rows.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            email varchar(191) NOT NULL DEFAULT '',
            name varchar(191) NOT NULL DEFAULT '',
            context longtext NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY campaign_customer (campaign_id,customer_id),
            KEY status_campaign (campaign_id,status,id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
