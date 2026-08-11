<?php

declare(strict_types=1);

namespace AlgerianCommerce\Geography;

use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use RuntimeException;

/**
 * Loads the Algerian geography datasets into the database — roadmap §51.
 *
 * Validate everything, then write. A dataset with one bad row imports nothing,
 * because a half-loaded geography is the worst state this can be in: addresses
 * would validate in some wilayas and be rejected in others, and a working
 * checkout would give no sign which. See GeoDataset.
 *
 * The order is fixed and load-bearing — communes reference wilayas by code, and
 * provider destinations reference communes by (wilaya, slug), so each stage
 * validates against what the one before it actually wrote.
 */
final class GeoImporter
{
    public const WILAYAS = 'wilayas.json';
    public const COMMUNES = 'communes.json';
    public const DESTINATIONS = 'provider-destinations.json';

    public function __construct(
        private readonly GeoRepository $repository,
        private readonly Logger $logger,
        private readonly AuditLogger $audit,
        /** Absolute path to the directory holding the JSON datasets. */
        private readonly string $dataPath
    ) {
    }

    public function dataPath(): string
    {
        return $this->dataPath;
    }

    /**
     * @return array{
     *     wilayas: array{inserted: int, updated: int},
     *     communes: array{inserted: int, updated: int},
     *     destinations: array{inserted: int, updated: int},
     *     errors: list<string>,
     *     skipped: list<string>
     * }
     */
    public function import(bool $dryRun = false): array
    {
        $empty = ['inserted' => 0, 'updated' => 0];
        $result = [
            'wilayas' => $empty,
            'communes' => $empty,
            'destinations' => $empty,
            'errors' => [],
            'skipped' => [],
        ];

        $wilayaFile = $this->read(self::WILAYAS, true, $result);

        if ($result['errors'] !== []) {
            return $result;
        }

        $wilayas = GeoDataset::wilayas($wilayaFile);

        if ($wilayas['errors'] !== []) {
            $result['errors'] = $wilayas['errors'];

            return $result;
        }

        /*
         * Communes and destinations are validated against the wilaya *codes in
         * the file*, not against the database. A dry run must be able to report
         * on a complete dataset before anything has been written, and a real
         * run must not accept a commune whose wilaya only exists because a
         * previous import created it.
         */
        $knownCodes = [];

        foreach ($wilayas['rows'] as $row) {
            $knownCodes[(int) $row['id']] = true;
        }

        $communeFile = $this->read(self::COMMUNES, false, $result);
        $communes = $communeFile === null
            ? ['rows' => [], 'errors' => []]
            : GeoDataset::communes($communeFile, $knownCodes);

        if ($communes['errors'] !== []) {
            $result['errors'] = $communes['errors'];

            return $result;
        }

        if ($dryRun) {
            // Reported as inserts: a dry run has nothing to compare against
            // without writing, and overstating the change is the safe error.
            $result['wilayas'] = ['inserted' => count($wilayas['rows']), 'updated' => 0];
            $result['communes'] = ['inserted' => count($communes['rows']), 'updated' => 0];
            $result['destinations'] = $this->dryRunDestinations($knownCodes, $communes['rows'], $result);

            return $result;
        }

        $result['wilayas'] = $this->repository->upsertWilayas($wilayas['rows']);
        $result['communes'] = $this->repository->upsertCommunes($communes['rows']);

        // Resolved after the communes are written, because a destination names
        // its commune and the ids only exist once the rows do.
        $destinationFile = $this->read(self::DESTINATIONS, false, $result);

        if ($destinationFile !== null) {
            $destinations = GeoDataset::destinations(
                $destinationFile,
                $knownCodes,
                $this->repository->communeIdsBySlug()
            );

            if ($destinations['errors'] !== []) {
                /*
                 * The geography is already written and is correct on its own.
                 * Reporting the provider errors and keeping it beats rolling
                 * back a valid wilaya and commune list because a provider file
                 * is stale — the two are separate datasets by design.
                 */
                $result['errors'] = $destinations['errors'];

                return $result;
            }

            $result['destinations'] = $this->repository->upsertDestinations($destinations['rows']);
        }

        $this->audit->record('geography.imported', 'geography', '', [
            'wilayas' => $result['wilayas'],
            'communes' => $result['communes'],
            'destinations' => $result['destinations'],
        ]);

        $this->logger->info('Algerian geography imported', [
            'wilayas' => array_sum($result['wilayas']),
            'communes' => array_sum($result['communes']),
        ]);

        return $result;
    }

    /**
     * @param array<int, true>           $knownCodes
     * @param list<array<string, mixed>> $communeRows
     * @param array<string, mixed>       $result
     * @return array{inserted: int, updated: int}
     */
    private function dryRunDestinations(array $knownCodes, array $communeRows, array &$result): array
    {
        $file = $this->read(self::DESTINATIONS, false, $result);

        if ($file === null) {
            return ['inserted' => 0, 'updated' => 0];
        }

        // Built from the file being validated rather than from the database, so
        // a dry run of a fresh dataset resolves its own communes. The ids are
        // placeholders; only presence is checked.
        $ids = [];

        foreach ($communeRows as $index => $row) {
            $ids[$row['wilaya_id'] . '/' . $row['slug']] = $index + 1;
        }

        $destinations = GeoDataset::destinations($file, $knownCodes, $ids);

        if ($destinations['errors'] !== []) {
            $result['errors'] = $destinations['errors'];
        }

        return ['inserted' => count($destinations['rows']), 'updated' => 0];
    }

    /**
     * @param array<string, mixed> $result
     * @return mixed decoded JSON, or null when an optional file is absent
     */
    private function read(string $filename, bool $required, array &$result): mixed
    {
        $path = rtrim($this->dataPath, '/') . '/' . $filename;

        if (!is_readable($path)) {
            if ($required) {
                $result['errors'][] = "{$filename} is missing or unreadable at {$path}.";
            } else {
                $result['skipped'][] = $filename;
            }

            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            $result['errors'][] = "{$filename} could not be read.";

            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (RuntimeException $exception) {
            // json_decode throws JsonException, a RuntimeException. Reported
            // with the parser's own message, which names the byte offset —
            // far more use than "the file is invalid" on a 1,500-row dataset.
            $result['errors'][] = "{$filename} is not valid JSON: " . $exception->getMessage();

            return null;
        }

        return $decoded;
    }
}
