<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core\Migrations;

use wpdb;

/**
 * A single schema change.
 *
 * Each file in migrations/ returns one of these. Version and name come from
 * the filename (001_create_audit_logs.php), so a migration cannot disagree
 * with its own ordering.
 *
 * There is no down() by design: roadmap §43 forbids a production migration
 * that depends on destroying existing data, and a rollback path invites
 * exactly that. Reverse a mistake with a new forward migration.
 */
interface Migration
{
    /**
     * Apply the change. Must be safe to re-run — dbDelta() is idempotent for
     * table creation, which is why it is preferred over raw CREATE TABLE.
     */
    public function up(wpdb $wpdb, string $charsetCollate): void;

    /** Human-readable summary, used in logs and WP-CLI output. */
    public function description(): string;
}
