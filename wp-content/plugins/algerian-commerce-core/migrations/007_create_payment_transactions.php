<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Payment transaction records — docs/PLAN.md §19's field list, roadmap §59.
 *
 * Deferred from §58 on purpose, and this is the reason: the columns are shaped
 * by what a real provider actually returns, and §56's rule is that designing an
 * integration against an imagined one is how it comes out wrong. Chargily
 * answers a checkout with a ULID, a status word, an amount in **dinars**, a
 * currency and a URL — so `provider_transaction_id` is a varchar, `amount` is
 * decimal, and everything particular to one gateway is JSON in `metadata`.
 *
 * A table of its own, for the reason CLAUDE.md reserves custom tables:
 * WooCommerce records that an order *has* a payment method, not that a checkout
 * was opened at 14:02, expired at 14:32, was retried, and settled on the second
 * attempt. Order meta could hold the last attempt badly — it cannot be queried
 * by provider reference, grouped by status across the order book, or reconciled
 * against a gateway's own ledger, which is what a finance screen does all day.
 *
 * **Several rows per order is the design, not a leak.** `PaymentRequest` already
 * says so: a card is refused, the customer tries again, and both attempts are
 * facts. docs/SECURITY.md is more explicit still — *every transaction attempt is
 * recorded with its provider reference and verification result* — so a row is
 * written **before** the provider is called and closed as `failed` if that call
 * never comes back. A gateway that took money on a request we then lost is the
 * one outcome no reconstruction can fix.
 *
 * That is also why there is no unique key across (provider,
 * provider_transaction_id): the column is empty for the moment between the
 * insert and the provider's answer, and MySQL treats '' as a value rather than
 * as absent — the same trap migration 004 documented for shipments.
 *
 * **And no "one open transaction per order" index, deliberately** — the mirror
 * of migration 006 is *not* wanted here. A duplicated parcel is a van driving
 * somewhere; a duplicated checkout is a link nobody clicks, which expires by
 * itself in thirty minutes. The customer is redirected once and pays once. What
 * protects the money is elsewhere and does not need a lock: a settled
 * transaction refuses a second payment for the same order
 * (`PaymentService::createPayment()`), `PaymentStatus::accepts()` refuses to
 * un-pay a paid one, and the webhook event claim refuses to apply the same event
 * twice.
 *
 * `reference` is *our* identifier for the attempt — "42-2", the second payment
 * started for order 42 — kept beside the provider's so a support conversation
 * can start from either end.
 *
 * No fee column. Chargily reports `fees`, `fees_on_merchant` and
 * `fees_on_customer`, and they land in `metadata` where a second provider's
 * differently-shaped fees can land too; promoting one gateway's arithmetic to a
 * column would be §56's mistake with a decimal point.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the payment transaction records table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_payment_transactions';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it adds duplicates.
         *
         * Money is `decimal(12,2)`, never a float, as migration 005 already
         * settled for the shipping tariff — an amount that round-trips through
         * a float has lost the last centime before anyone compares it to what
         * the gateway says it collected.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            provider varchar(32) NOT NULL DEFAULT '',
            provider_transaction_id varchar(64) NOT NULL DEFAULT '',
            reference varchar(64) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY provider_transaction (provider,provider_transaction_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
