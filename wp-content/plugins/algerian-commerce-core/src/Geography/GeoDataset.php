<?php

declare(strict_types=1);

namespace AlgerianCommerce\Geography;

/**
 * Validates a decoded geography dataset before any of it reaches the database.
 *
 * Pure — no WordPress, no database — so the rules that decide whether a
 * supplied file is fit to import are testable without a shop.
 *
 * **All-or-nothing, and that is the design.** Every row is checked and every
 * problem collected before a single one is written. A half-imported geography
 * is the worst outcome available here: addresses would validate in some wilayas
 * and be rejected in others, and nobody looking at a working checkout would
 * know which. So the importer is handed either a complete set of clean rows or
 * a list of everything wrong with the file.
 *
 * The dataset is data, not code (roadmap §51: "Do not bury thousands of
 * geographic records inside arbitrary PHP files"), which means it arrives from
 * outside and is treated as untrusted input — the same as a request body.
 */
final class GeoDataset
{
    /**
     * Algeria has 69 wilayas: the 58 of the 2019 reform plus the eleven former
     * circonscriptions administratives since promoted in full. Codes run 1–69.
     */
    public const MIN_WILAYA = 1;
    public const MAX_WILAYA = 69;

    public const MAX_NAME = 120;
    public const MAX_COMMUNE_NAME = 160;
    public const MAX_POSTAL_CODE = 10;
    public const MAX_NATIONAL_CODE = 16;
    public const MAX_PROVIDER = 32;
    public const MAX_DESTINATION_ID = 64;

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public static function wilayas(mixed $decoded): array
    {
        $entries = self::entries($decoded, 'wilayas', $errors);

        if ($entries === null) {
            return ['rows' => [], 'errors' => $errors];
        }

        $rows = [];
        $seenCodes = [];
        $seenSlugs = [];

        foreach ($entries as $index => $entry) {
            $at = "wilayas[{$index}]";

            if (!is_array($entry)) {
                $errors[] = "{$at}: must be an object.";
                continue;
            }

            $code = self::wilayaCode($entry['code'] ?? null, $at, $errors);
            $name = self::name($entry['name'] ?? null, $at, self::MAX_NAME, $errors);

            if ($code === null || $name === null) {
                continue;
            }

            $slug = GeoSlug::make($name);

            if ($slug === '') {
                $errors[] = "{$at}: \"{$name}\" does not produce a usable slug; a Latin name is required.";
                continue;
            }

            if (isset($seenCodes[$code])) {
                $errors[] = "{$at}: wilaya code {$code} appears more than once.";
                continue;
            }

            if (isset($seenSlugs[$slug])) {
                $errors[] = "{$at}: \"{$name}\" collides with an earlier wilaya once accents are folded.";
                continue;
            }

            $seenCodes[$code] = true;
            $seenSlugs[$slug] = true;

            $rows[] = [
                'id' => $code,
                // Zero-padded, because that is how a wilaya code is written
                // everywhere in Algeria: 16, not 6, and 01, not 1.
                'code' => str_pad((string) $code, 2, '0', STR_PAD_LEFT),
                'slug' => $slug,
                'name' => $name,
                'name_ar' => self::optionalName($entry['name_ar'] ?? null, self::MAX_NAME),
                'is_active' => self::flag($entry['is_active'] ?? true),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param array<int, true> $knownWilayaCodes keyed by code
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public static function communes(mixed $decoded, array $knownWilayaCodes): array
    {
        $entries = self::entries($decoded, 'communes', $errors);

        if ($entries === null) {
            return ['rows' => [], 'errors' => $errors];
        }

        $rows = [];
        $seen = [];

        foreach ($entries as $index => $entry) {
            $at = "communes[{$index}]";

            if (!is_array($entry)) {
                $errors[] = "{$at}: must be an object.";
                continue;
            }

            $code = self::wilayaCode($entry['wilaya_code'] ?? null, $at, $errors);
            $name = self::name($entry['name'] ?? null, $at, self::MAX_COMMUNE_NAME, $errors);

            if ($code === null || $name === null) {
                continue;
            }

            /*
             * A commune in a wilaya that is not in the wilaya file is a broken
             * reference, and importing it would produce a commune nobody can
             * reach through /locations/wilayas/{id}/communes.
             */
            if (!isset($knownWilayaCodes[$code])) {
                $errors[] = "{$at}: wilaya {$code} is not in the wilaya dataset.";
                continue;
            }

            $slug = GeoSlug::make($name);

            if ($slug === '') {
                $errors[] = "{$at}: \"{$name}\" does not produce a usable slug; a Latin name is required.";
                continue;
            }

            $key = $code . '/' . $slug;

            if (isset($seen[$key])) {
                $errors[] = "{$at}: \"{$name}\" appears twice in wilaya {$code}.";
                continue;
            }

            $seen[$key] = true;

            $postal = self::postalCode($entry['postal_code'] ?? null, $at, $errors);

            if ($postal === null) {
                continue;
            }

            $latitude = self::coordinate($entry['latitude'] ?? null, 90, $at, 'latitude', $errors);
            $longitude = self::coordinate($entry['longitude'] ?? null, 180, $at, 'longitude', $errors);

            if ($latitude === false || $longitude === false) {
                continue;
            }

            $rows[] = [
                'wilaya_id' => $code,
                'slug' => $slug,
                'name' => $name,
                'name_ar' => self::optionalName($entry['name_ar'] ?? null, self::MAX_COMMUNE_NAME),
                // The daira is the level between a wilaya and a commune. Kept
                // as a label, never as a key: dairas are renamed and merged far
                // more often than communes are.
                'daira' => self::optionalName($entry['daira'] ?? null, self::MAX_COMMUNE_NAME),
                'daira_ar' => self::optionalName($entry['daira_ar'] ?? null, self::MAX_COMMUNE_NAME),
                'postal_code' => $postal,
                'national_code' => self::optionalName($entry['national_code'] ?? null, self::MAX_NATIONAL_CODE),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'is_active' => self::flag($entry['is_active'] ?? true),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Provider destination mappings, kept out of the canonical data.
     *
     * `commune` is optional: omitting it maps the whole wilaya, which is how
     * stopdesk services are addressed.
     *
     * @param array<int, true>    $knownWilayaCodes keyed by code
     * @param array<string, int>  $communeIds       keyed by "code/slug"
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public static function destinations(mixed $decoded, array $knownWilayaCodes, array $communeIds): array
    {
        $entries = self::entries($decoded, 'destinations', $errors);

        if ($entries === null) {
            return ['rows' => [], 'errors' => $errors];
        }

        $rows = [];
        $seen = [];

        foreach ($entries as $index => $entry) {
            $at = "destinations[{$index}]";

            if (!is_array($entry)) {
                $errors[] = "{$at}: must be an object.";
                continue;
            }

            $provider = self::name($entry['provider'] ?? null, $at, self::MAX_PROVIDER, $errors, 'provider');
            $code = self::wilayaCode($entry['wilaya_code'] ?? null, $at, $errors);

            if ($provider === null || $code === null) {
                continue;
            }

            if (!isset($knownWilayaCodes[$code])) {
                $errors[] = "{$at}: wilaya {$code} is not in the wilaya dataset.";
                continue;
            }

            $destination = self::name(
                $entry['destination_id'] ?? null,
                $at,
                self::MAX_DESTINATION_ID,
                $errors,
                'destination_id'
            );

            if ($destination === null) {
                continue;
            }

            $communeId = 0;
            $commune = $entry['commune'] ?? null;

            if ($commune !== null && $commune !== '') {
                if (!is_scalar($commune)) {
                    $errors[] = "{$at}: commune must be a name.";
                    continue;
                }

                $key = $code . '/' . GeoSlug::make((string) $commune);

                if (!isset($communeIds[$key])) {
                    $errors[] = "{$at}: commune \"{$commune}\" is not in wilaya {$code}.";
                    continue;
                }

                $communeId = $communeIds[$key];
            }

            $placeKey = GeoSlug::make($provider) . '/' . $code . '/' . $communeId;

            if (isset($seen[$placeKey])) {
                $errors[] = "{$at}: that provider already has a destination for this place.";
                continue;
            }

            $seen[$placeKey] = true;

            $rows[] = [
                'provider' => GeoSlug::make($provider),
                'wilaya_id' => $code,
                'commune_id' => $communeId,
                'destination_id' => $destination,
                'metadata' => is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [],
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Unwrap `{"version": …, "<key>": [...]}` or a bare array.
     *
     * @param list<string> $errors
     * @return list<mixed>|null null when the shape is wrong at the top level,
     *         where per-row errors would be noise
     */
    private static function entries(mixed $decoded, string $key, ?array &$errors): ?array
    {
        $errors = [];

        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        if (!is_array($decoded)) {
            $errors[] = 'The dataset must be a JSON object or array.';

            return null;
        }

        if (!isset($decoded[$key]) || !is_array($decoded[$key]) || !array_is_list($decoded[$key])) {
            $errors[] = "The dataset must contain a \"{$key}\" array.";

            return null;
        }

        return $decoded[$key];
    }

    /** @param list<string> $errors */
    private static function wilayaCode(mixed $value, string $at, array &$errors): ?int
    {
        // Accepts 16, "16" and "06": the file is written by people, and a
        // zero-padded code in quotes is the normal way to write one.
        if (!is_scalar($value) || !preg_match('/^\d{1,2}$/', trim((string) $value))) {
            $errors[] = "{$at}: wilaya code must be a number from "
                . self::MIN_WILAYA . ' to ' . self::MAX_WILAYA . '.';

            return null;
        }

        $code = (int) trim((string) $value);

        if ($code < self::MIN_WILAYA || $code > self::MAX_WILAYA) {
            $errors[] = "{$at}: wilaya code {$code} is outside 1–" . self::MAX_WILAYA . '.';

            return null;
        }

        return $code;
    }

    /** @param list<string> $errors */
    private static function name(mixed $value, string $at, int $max, array &$errors, string $field = 'name'): ?string
    {
        if (!is_scalar($value)) {
            $errors[] = "{$at}: {$field} is required.";

            return null;
        }

        $name = trim((string) $value);

        if ($name === '') {
            $errors[] = "{$at}: {$field} is required.";

            return null;
        }

        if (mb_strlen($name) > $max) {
            $errors[] = "{$at}: {$field} is longer than {$max} characters.";

            return null;
        }

        return $name;
    }

    private static function optionalName(mixed $value, int $max): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }

    /** @param list<string> $errors */
    private static function postalCode(mixed $value, string $at, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            // PLAN.md §10: "postal codes where available".
            return '';
        }

        if (!is_scalar($value)) {
            $errors[] = "{$at}: postal_code must be a string.";

            return null;
        }

        $postal = trim((string) $value);

        if (!preg_match('/^\d{1,' . self::MAX_POSTAL_CODE . '}$/', $postal)) {
            $errors[] = "{$at}: postal_code \"{$postal}\" is not a run of digits.";

            return null;
        }

        return $postal;
    }

    /**
     * A coordinate, or null when absent.
     *
     * Returned as a string so the decimal the dataset supplied is the decimal
     * that gets stored — casting through a float and back is how 36.7538 turns
     * into 36.75379999999999.
     *
     * Range-checked rather than merely type-checked, because the failure being
     * guarded against is a file with latitude and longitude the wrong way
     * round: 2.15 and 36.75 are both valid floats, and only the range says
     * which of them cannot be a latitude in Algeria's half of the world.
     *
     * @param list<string> $errors
     * @return string|null|false false when invalid
     */
    private static function coordinate(mixed $value, float $limit, string $at, string $field, array &$errors): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value) || !is_numeric($value)) {
            $errors[] = "{$at}: {$field} must be a number.";

            return false;
        }

        $number = (float) $value;

        if ($number < -$limit || $number > $limit) {
            $errors[] = "{$at}: {$field} {$number} is outside ±{$limit}.";

            return false;
        }

        return trim((string) $value);
    }

    private static function flag(mixed $value): int
    {
        return $value === false || $value === 0 || $value === '0' || $value === 'false' ? 0 : 1;
    }
}
