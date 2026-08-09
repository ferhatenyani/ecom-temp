<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core\Migrations;

use AlgerianCommerce\Core\Logger;
use RuntimeException;
use wpdb;

/**
 * Applies pending migrations and records how far the schema has got.
 *
 * The stored version is bumped after each individual migration, not once at
 * the end: if 003 fails, 001 and 002 stay applied and the next run resumes at
 * 003 instead of replaying everything.
 *
 * MySQL does not roll DDL back inside a transaction, so there is no attempt to
 * wrap the batch — resumability is the mitigation.
 */
final class MigrationRunner
{
    public const VERSION_OPTION = 'ac_core_db_version';

    public function __construct(
        private readonly string $directory,
        private readonly Logger $logger,
        private readonly wpdb $wpdb
    ) {
    }

    public function currentVersion(): int
    {
        return (int) get_option(self::VERSION_OPTION, 0);
    }

    /** @return list<string> absolute paths, ordered */
    public function pendingFiles(): array
    {
        return MigrationPlan::pending($this->allFiles(), $this->currentVersion());
    }

    public function isUpToDate(): bool
    {
        return $this->pendingFiles() === [];
    }

    /**
     * Run everything outstanding.
     *
     * @param bool $dryRun report what would run without touching the database.
     * @return list<array{version: int, name: string, description: string}>
     */
    public function run(bool $dryRun = false): array
    {
        $applied = [];

        foreach ($this->pendingFiles() as $file) {
            $version = (int) MigrationPlan::parseVersion($file);
            $name = (string) MigrationPlan::parseName($file);

            $migration = $this->load($file);

            if (!$dryRun) {
                $this->ensureDbDelta();
                $migration->up($this->wpdb, $this->wpdb->get_charset_collate());

                if ($this->wpdb->last_error !== '') {
                    throw new RuntimeException(sprintf(
                        'Migration %03d_%s failed: %s',
                        $version,
                        $name,
                        $this->wpdb->last_error
                    ));
                }

                update_option(self::VERSION_OPTION, $version);
            }

            $this->logger->info($dryRun ? 'Migration pending' : 'Migration applied', [
                'version' => $version,
                'name' => $name,
            ]);

            $applied[] = [
                'version' => $version,
                'name' => $name,
                'description' => $migration->description(),
            ];
        }

        return $applied;
    }

    /** @return list<string> */
    private function allFiles(): array
    {
        $found = glob(rtrim($this->directory, '/') . '/*.php');

        return $found === false ? [] : array_values($found);
    }

    private function load(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new RuntimeException(sprintf(
                'Migration %s must return an instance of %s.',
                basename($file),
                Migration::class
            ));
        }

        return $migration;
    }

    /** dbDelta() lives in an admin include that is not loaded for REST or CLI. */
    private function ensureDbDelta(): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
    }
}
