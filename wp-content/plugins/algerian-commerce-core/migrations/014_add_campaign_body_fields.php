<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * The composer's own answers, kept beside the HTML they generate.
 *
 * ## Why the HTML alone is not enough
 *
 * Migration 011 stores `body_html` and argues, correctly, that it must be
 * **frozen**: a template edited mid-drain would otherwise change the message
 * half-way through a send. That reasoning is about the *output*, and it left the
 * *input* nowhere to live.
 *
 * The panel's campaign composer is a form — a headline, some blocks, a button
 * label, a colour — and it renders that form's answers into `body_html`. Without
 * somewhere to keep the answers, the form is **single-use**: an admin saves a
 * campaign, closes the tab, reopens it, and gets back a wall of generated markup
 * with no way to change the headline except by editing the `<td style="…">` that
 * happens to contain it. "Undo back to the template" cannot survive a reload
 * either, because there is nothing to undo *to*.
 *
 * So: a second column, holding what was answered, beside the column holding what
 * was produced. `body_html` remains the single source of truth for what is
 * *sent* — nothing here is ever rendered, mailed or merged — and this column is
 * the source of truth for what is *re-editable*.
 *
 * ## Why a column rather than a JSON key inside `body_html`, or a meta table
 *
 * Inside `body_html` it would be inside the thing `EmailHtml` sanitises, and the
 * sanitiser would eat it — an HTML comment carrying JSON is exactly what
 * `wp_kses` strips. A meta table (`ac_campaign_meta`) would be a table with one
 * key in it, joined on every campaign read, to store a document nothing selects
 * on; migration 013 refused that shape for `criteria` and the argument is the
 * same here.
 *
 * ## Why `mediumtext` rather than `text` or `longtext`
 *
 * `text` (65,535 bytes) is very nearly the policy bound
 * `CampaignInput::MAX_FIELDS_BYTES` sets, and the two must not be the same
 * number. **A JSON document is the one field here that cannot be truncated to
 * fit** — `Campaign` truncates an over-long `name` to its column width on
 * purpose, but half a JSON document is not a JSON document, it is a parse error
 * that reads back as no answers at all. So the bound is enforced by a 400 on the
 * way in, and the column is given room above it: sanitising the document's
 * string leaves can *grow* them (`wp_kses` entity-encodes `&`), and a column
 * sized exactly at the bound would turn that growth into a strict-mode write
 * failure after the campaign had already been accepted.
 *
 * `longtext` would be room for four gigabytes, which is not a bound at all and
 * would quietly invite this column to become the panel's general-purpose store.
 * `mediumtext` is 16MB: unreachable behind a 64 KiB check, and small enough to
 * say out loud that this is the lesser of the two body columns.
 *
 * ## NULL is a claim, and it is not the same claim as `{}`
 *
 * The column is `NULL` by default and every campaign that predates this
 * migration keeps that value. That is deliberate and it is the whole reason the
 * column is nullable rather than `NOT NULL DEFAULT '{}'`:
 *
 *   NULL  no answers were ever recorded — this campaign's HTML was written by
 *         hand, inherited from a template, or composed before the form existed.
 *         The panel opens the HTML editor.
 *   {}    the form *was* used and every answer is currently blank. The panel
 *         opens the form.
 *
 * Defaulting to `{}` would tell the panel that every campaign in the shop's
 * history was composed with a form that did not exist when they were written,
 * and the panel would then regenerate empty HTML over bodies somebody wrote by
 * hand. The distinction is not pedantry; it is the difference between reopening
 * a campaign and destroying it.
 *
 * Not dbDelta: this alters a table rather than creating one, and migration 006
 * established the shape — `SHOW COLUMNS` first, so the migration is re-runnable
 * by hand on an install whose version option has drifted.
 */
return new class implements Migration {
    private const COLUMN = 'body_fields';

    public function description(): string
    {
        return 'Keep the campaign composer\'s own answers beside the HTML they generate.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_campaigns';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", self::COLUMN));

        if ($exists !== null) {
            return;
        }

        /*
         * `AFTER body_text` so the three body columns read together in a
         * `DESCRIBE`, and so it is obvious at a glance that this one sits beside
         * the bodies rather than among the counts. There is deliberately **no
         * index**: nothing filters, sorts or joins on a form's answers — they are
         * read exactly once, by id, when the panel reopens the campaign — and an
         * index on a `mediumtext` would have to be a prefix index over a JSON
         * document, which is an index over whatever the first field happens to be.
         */
        $wpdb->query(
            "ALTER TABLE {$table} ADD COLUMN " . self::COLUMN . ' mediumtext NULL DEFAULT NULL AFTER body_text'
        );

        unset($charsetCollate);
    }
};
