<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Geography\GeoImporter;
use WP_CLI;

/**
 * wp algerian-commerce import-algeria
 *
 * Loads data/algeria/*.json into the ac_geo_* tables — roadmap §51.
 *
 * A command rather than an activation hook: the dataset changes on its own
 * schedule, an activation hook only fires when someone reactivates the plugin,
 * and re-importing 1,500 communes on every activation is work nobody asked for.
 */
final class ImportAlgeriaCommand
{
    public function __construct(private readonly GeoImporter $importer)
    {
    }

    /**
     * Import the Algerian wilayas, communes and provider destinations.
     *
     * Nothing is deleted. A place missing from a newer dataset keeps its row
     * and its id, because a delivered order may still point at it.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Validate the datasets and report what would change, writing nothing.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce import-algeria --dry-run
     *     wp algerian-commerce import-algeria
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $dryRun = !empty($assocArgs['dry-run']);

        WP_CLI::log('Reading ' . $this->importer->dataPath());

        $result = $this->importer->import($dryRun);

        foreach ($result['skipped'] as $file) {
            WP_CLI::warning("{$file} is absent — nothing imported from it.");
        }

        if ($result['errors'] !== []) {
            foreach (array_slice($result['errors'], 0, 20) as $error) {
                WP_CLI::log('  ' . $error);
            }

            $extra = count($result['errors']) - 20;

            if ($extra > 0) {
                WP_CLI::log("  … and {$extra} more.");
            }

            /*
             * A validation failure writes nothing at all — see GeoImporter. The
             * exit code matters: this runs in deploy scripts, and a geography
             * that failed to load has to stop the deploy rather than leave a
             * shop taking addresses it cannot deliver to.
             */
            WP_CLI::error(sprintf(
                '%d problem(s) in the dataset. Nothing was imported.',
                count($result['errors'])
            ));
        }

        WP_CLI\Utils\format_items(
            'table',
            [
                $this->row('wilayas', $result['wilayas']),
                $this->row('communes', $result['communes']),
                $this->row('provider destinations', $result['destinations']),
            ],
            ['dataset', 'inserted', 'updated']
        );

        if ($dryRun) {
            WP_CLI::success('Datasets are valid. Nothing was written.');

            return;
        }

        if ($result['communes']['inserted'] + $result['communes']['updated'] === 0) {
            // The one state that looks like success and is not: wilayas load,
            // the endpoints answer, and every commune lookup comes back empty.
            WP_CLI::warning(
                'No communes were imported. Address forms will offer a wilaya and no commune. '
                . 'See data/algeria/communes.json.'
            );
        }

        WP_CLI::success('Algerian geography imported.');
    }

    /**
     * @param array{inserted: int, updated: int} $counts
     * @return array<string, string|int>
     */
    private function row(string $dataset, array $counts): array
    {
        return [
            'dataset' => $dataset,
            'inserted' => $counts['inserted'],
            'updated' => $counts['updated'],
        ];
    }
}
