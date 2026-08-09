<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core\Migrations;

use InvalidArgumentException;

/**
 * Works out which migrations still need to run.
 *
 * Pure string and array handling with no database and no WordPress, so the
 * ordering rules — the part that silently corrupts a schema when wrong — are
 * unit-testable on their own.
 */
final class MigrationPlan
{
    /** 001_create_audit_logs.php */
    public const FILENAME_PATTERN = '/^(\d{3})_([a-z0-9_]+)\.php$/';

    public static function parseVersion(string $filename): ?int
    {
        if (preg_match(self::FILENAME_PATTERN, basename($filename), $matches) !== 1) {
            return null;
        }

        $version = (int) $matches[1];

        // 000 would collide with "nothing applied yet".
        return $version > 0 ? $version : null;
    }

    public static function parseName(string $filename): ?string
    {
        if (preg_match(self::FILENAME_PATTERN, basename($filename), $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    /**
     * Migrations newer than $currentVersion, in ascending order.
     *
     * Files that do not match the naming convention are ignored rather than
     * guessed at — a stray README or .gitkeep in migrations/ must not become
     * a schema change.
     *
     * @param list<string> $filenames
     * @return list<string> filenames, ordered
     *
     * @throws InvalidArgumentException when two files claim the same version,
     *         which would make the applied order depend on directory listing.
     */
    public static function pending(array $filenames, int $currentVersion): array
    {
        $byVersion = [];

        foreach ($filenames as $filename) {
            $version = self::parseVersion($filename);

            if ($version === null) {
                continue;
            }

            if (isset($byVersion[$version])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate migration version %03d: %s and %s.',
                    $version,
                    basename($byVersion[$version]),
                    basename($filename)
                ));
            }

            $byVersion[$version] = $filename;
        }

        ksort($byVersion);

        $pending = [];
        foreach ($byVersion as $version => $filename) {
            if ($version > $currentVersion) {
                $pending[] = $filename;
            }
        }

        return $pending;
    }

    /**
     * Highest version present on disk — what the schema version becomes once
     * everything has run.
     *
     * @param list<string> $filenames
     */
    public static function latestVersion(array $filenames): int
    {
        $latest = 0;

        foreach ($filenames as $filename) {
            $version = self::parseVersion($filename);

            if ($version !== null && $version > $latest) {
                $latest = $version;
            }
        }

        return $latest;
    }
}
