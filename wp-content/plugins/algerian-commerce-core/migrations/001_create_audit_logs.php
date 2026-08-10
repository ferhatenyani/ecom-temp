<?php

declare(strict_types=1);

use AlgerianCommerce\Core\Migrations\Migration;

/**
 * Audit log storage.
 *
 * A custom table rather than a post type: audit events are append-only, high
 * volume, and queried by actor/action/date ranges that wp_posts and its meta
 * table index badly (docs/PLAN.md §39, docs/ARCHITECTURE.md §7).
 *
 * Retention: rows are never updated. created_at is indexed so pruning is a
 * ranged DELETE; the retention window is a client policy decision and is not
 * enforced here.
 */
return new class implements Migration {
    public function description(): string
    {
        return 'Create the audit log table.';
    }

    public function up(wpdb $wpdb, string $charsetCollate): void
    {
        $table = $wpdb->prefix . 'ac_audit_logs';

        /*
         * dbDelta is picky: two spaces after PRIMARY KEY, one field per line,
         * and KEY names must match exactly on re-run or it will try to add
         * duplicate indexes.
         */
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_login varchar(60) NOT NULL DEFAULT '',
            action varchar(100) NOT NULL,
            resource_type varchar(50) NOT NULL DEFAULT '',
            resource_id varchar(64) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY actor_id (actor_id),
            KEY action (action),
            KEY resource (resource_type, resource_id),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
};
