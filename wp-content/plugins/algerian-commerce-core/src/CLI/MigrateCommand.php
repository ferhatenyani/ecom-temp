<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Core\Migrations\MigrationRunner;
use Throwable;
use WP_CLI;

/**
 * wp algerian-commerce migrate
 *
 * Migrations also run on plugin activation, but a deploy that updates files
 * without reactivating would otherwise leave the schema behind. This is the
 * explicit trigger for that case.
 */
final class MigrateCommand
{
    public function __construct(private readonly MigrationRunner $runner)
    {
    }

    /**
     * Apply pending database migrations.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List what would run without touching the database.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce migrate
     *     wp algerian-commerce migrate --dry-run
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $dryRun = (bool) ($assocArgs['dry-run'] ?? false);
        $from = $this->runner->currentVersion();

        try {
            $applied = $this->runner->run($dryRun);
        } catch (Throwable $throwable) {
            WP_CLI::error($throwable->getMessage());

            return;
        }

        if ($applied === []) {
            WP_CLI::success(sprintf('Schema is up to date (version %d).', $from));

            return;
        }

        foreach ($applied as $migration) {
            WP_CLI::log(sprintf(
                '%s %03d_%s — %s',
                $dryRun ? 'would apply' : 'applied',
                $migration['version'],
                $migration['name'],
                $migration['description']
            ));
        }

        $to = $this->runner->currentVersion();

        WP_CLI::success($dryRun
            ? sprintf('%d migration(s) pending. Schema stays at version %d.', count($applied), $from)
            : sprintf('%d migration(s) applied. Schema %d -> %d.', count($applied), $from, $to));
    }
}
