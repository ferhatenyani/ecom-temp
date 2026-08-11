<?php

declare(strict_types=1);

/**
 * Build data/algeria/*.json from a source commune CSV — roadmap §51.
 *
 *   php scripts/build-algeria-dataset.php <source.csv> [output-dir]
 *
 * There is no PHP on the host in this setup, and only the plugin directory is
 * mounted into the containers, so in practice it runs with a one-off mount:
 *
 *   docker compose run --rm -T -v "$PWD/scripts:/scripts" --entrypoint php wpcli \
 *     /scripts/build-algeria-dataset.php \
 *     /var/www/html/wp-content/plugins/algerian-commerce-core/data/algeria/sources/algeria_cities.csv \
 *     /var/www/html/wp-content/plugins/algerian-commerce-core/data/algeria
 *
 * Plain PHP, no WordPress: this is a developer tool run when the source data
 * changes, not part of a request. Its output is committed, and
 * `wp algerian-commerce import-algeria` is what loads it.
 *
 * It exists so the datasets have a *provenance* rather than an origin story.
 * Anyone can re-run it against the source and diff the result, which is the
 * only way a 1,541-row file is reviewable at all.
 *
 * Expected source columns (semicolon-separated):
 *
 *   commune_name  commune_name_fr  daira_name  daira_name_fr
 *   wilaya_code   wilaya_name      wilaya_name_fr
 *   code_commune  Lat  Long
 *
 * Algeria has **69 wilayas**: the 58 of the 2019 reform plus the eleven former
 * circonscriptions administratives — Aflou, Barika, El Kantara, Bir El Ater,
 * El Aricha, Ksar Chellala, Ain Oussera, Messaad, Ksar El Boukhari, Boussaâda
 * and El Abiodh Sidi Cheikh — since promoted in full. Codes 1-69 are all real
 * and are kept as they are.
 *
 * One normalisation is applied, and it is derived from the file rather than
 * from anybody's memory, and reported with its evidence: rows whose
 * `wilaya_name_fr` disagrees with their `wilaya_code` follow the name. That
 * catches the half-applied 2019 split, where eleven of Touggourt's communes
 * still carry Ouargla's code 30 while being named Touggourt.
 *
 * Codes beyond WILAYA_COUNT, if a future source carries any, are folded into
 * the parent their `code_commune` implies rather than inventing a wilaya.
 */

const WILAYA_COUNT = 69;

$source = $argv[1] ?? null;

if ($source === null || !is_readable($source)) {
    fwrite(STDERR, "usage: php scripts/build-algeria-dataset.php <source.csv>\n");
    exit(1);
}

$outputDir = rtrim(
    $argv[2] ?? __DIR__ . '/../wp-content/plugins/algerian-commerce-core/data/algeria',
    '/'
);

if (!is_dir($outputDir)) {
    fwrite(STDERR, "output directory {$outputDir} does not exist\n");
    exit(1);
}

// ---------------------------------------------------------------- read source

$handle = fopen($source, 'r');
$header = fgetcsv($handle, 0, ';');

if ($header === false) {
    fwrite(STDERR, "the source file is empty\n");
    exit(1);
}

$header = array_map('trim', $header);
$rows = [];

while (($line = fgetcsv($handle, 0, ';')) !== false) {
    if (count($line) !== count($header)) {
        continue;
    }

    $rows[] = array_combine($header, array_map('trim', $line));
}

fclose($handle);

printf("read %d rows from %s\n\n", count($rows), $source);

// ------------------------------------------------- derive the parent wilayas

/**
 * The wilaya encoded in a national commune code.
 *
 * The code is WWCC — two digits of wilaya, two of commune — with the leading
 * zero dropped by whatever produced the CSV, so it arrives 3 or 4 characters
 * long. Anything else is unusable and returns null rather than a guess.
 */
$codedWilaya = static function (string $code): ?int {
    $code = ltrim($code, '0');

    if (!preg_match('/^\d{3,4}$/', $code)) {
        return null;
    }

    $wilaya = (int) substr($code, 0, -2);

    return $wilaya >= 1 && $wilaya <= WILAYA_COUNT ? $wilaya : null;
};

$byCode = [];

foreach ($rows as $row) {
    $byCode[(int) $row['wilaya_code']][] = $row;
}

ksort($byCode);

$parents = [];

foreach ($byCode as $code => $group) {
    if ($code <= WILAYA_COUNT) {
        continue;
    }

    $votes = [];

    foreach ($group as $row) {
        $implied = $codedWilaya($row['code_commune']);

        if ($implied !== null) {
            $votes[$implied] = ($votes[$implied] ?? 0) + 1;
        }
    }

    if ($votes === []) {
        fwrite(STDERR, "code {$code} has no usable national codes; cannot place its communes\n");
        exit(1);
    }

    arsort($votes);
    $parent = (int) array_key_first($votes);
    $parents[$code] = $parent;

    $evidence = [];
    foreach ($votes as $w => $n) {
        $evidence[] = "{$w}×{$n}";
    }

    printf(
        "  %-24s code %2d -> wilaya %2d   (national codes say: %s)\n",
        $group[0]['wilaya_name_fr'],
        $code,
        $parent,
        implode(', ', $evidence)
    );
}

if ($parents !== []) {
    printf("\n%d unknown code(s) folded into their parent wilaya.\n\n", count($parents));
}

// --------------------------------------------- names that disagree with codes

$namesByCode = [];

foreach ($rows as $row) {
    $code = (int) $row['wilaya_code'];

    if ($code <= WILAYA_COUNT) {
        $namesByCode[$code][$row['wilaya_name_fr']] = ($namesByCode[$code][$row['wilaya_name_fr']] ?? 0) + 1;
    }
}

/** A wilaya's name, taken to be the one most of its rows agree on. */
$canonicalName = [];

foreach ($namesByCode as $code => $names) {
    arsort($names);
    $canonicalName[$code] = (string) array_key_first($names);
}

$nameToCode = [];

foreach ($canonicalName as $code => $name) {
    $nameToCode[mb_strtolower($name)] = $code;
}

$renamed = [];

foreach ($namesByCode as $code => $names) {
    foreach ($names as $name => $count) {
        $key = mb_strtolower((string) $name);

        // The row is named after a *different* wilaya that also exists in this
        // file under its own code. The name is the more current signal: it is
        // how the 2019 split reaches a dataset whose codes were never updated.
        if (isset($nameToCode[$key]) && $nameToCode[$key] !== $code) {
            $renamed[$code . '|' . $name] = ['from' => $code, 'to' => $nameToCode[$key], 'count' => $count];

            printf(
                "  %d rows carry code %d but are named \"%s\", which is wilaya %d -> moved\n",
                $count,
                $code,
                $name,
                $nameToCode[$key]
            );
        }
    }
}

if ($renamed !== []) {
    echo "\n";
}

// ------------------------------------------------ documented source fixes

/**
 * Corrections the file cannot make for itself.
 *
 * Kept as an explicit list rather than dressed up as a derivation: each one is
 * a judgement about Algerian geography, and a judgement should be visible and
 * checkable rather than hidden inside a fuzzy string match that might fire on
 * something else next time the source changes.
 */
$corrections = [
    [
        'daira' => 'Bousaada',
        'from' => 28,
        'to' => 68,
        // A wilaya is named after its chef-lieu, and the diagnostic below shows
        // ten of the eleven new wilayas contain their own. Boussaâda is the
        // only one that does not: its whole daira — Bou Saada, El Hamel and
        // Oulteme — is still filed under M'Sila, where it sat before the
        // promotion.
        'why' => 'the wilaya of Boussaâda must contain the town of Bou Saada',
    ],
];

$corrected = [];

// ------------------------------------------------------------ build communes

$communes = [];
$counts = [];

foreach ($rows as $row) {
    $code = (int) $row['wilaya_code'];
    $key = $code . '|' . $row['wilaya_name_fr'];

    if (isset($renamed[$key])) {
        $code = $renamed[$key]['to'];
    }

    $code = $parents[$code] ?? $code;

    foreach ($corrections as $index => $fix) {
        if ($code === $fix['from'] && $row['daira_name_fr'] === $fix['daira']) {
            $code = $fix['to'];
            $corrected[$index] = ($corrected[$index] ?? 0) + 1;
        }
    }

    if ($code < 1 || $code > WILAYA_COUNT) {
        fwrite(STDERR, "row \"{$row['commune_name_fr']}\" landed on wilaya {$code}, outside 1-" . WILAYA_COUNT . "\n");
        exit(1);
    }

    $counts[$code] = ($counts[$code] ?? 0) + 1;

    $communes[] = [
        'wilaya_code' => str_pad((string) $code, 2, '0', STR_PAD_LEFT),
        'name' => $row['commune_name_fr'],
        'name_ar' => $row['commune_name'],
        'daira' => $row['daira_name_fr'],
        'daira_ar' => $row['daira_name'],
        // Deliberately not mapped to postal_code: this is the national commune
        // code, three or four digits, while an Algerian postal code is five.
        // Calling it a postal code would put a wrong one on every address.
        'national_code' => $row['code_commune'],
        'latitude' => $row['Lat'],
        'longitude' => $row['Long'],
    ];
}

usort($communes, static function (array $a, array $b): int {
    return [$a['wilaya_code'], $a['name']] <=> [$b['wilaya_code'], $b['name']];
});

foreach ($corrected as $index => $moved) {
    printf(
        "  daira \"%s\": %d commune(s) moved from wilaya %d to %d — %s\n",
        $corrections[$index]['daira'],
        $moved,
        $corrections[$index]['from'],
        $corrections[$index]['to'],
        $corrections[$index]['why']
    );
}

if ($corrected !== []) {
    echo "\n";
}

$missing = [];

for ($code = 1; $code <= WILAYA_COUNT; $code++) {
    if (!isset($counts[$code])) {
        $missing[] = $code;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'no communes for wilaya(s): ' . implode(', ', $missing) . "\n");
    exit(1);
}

// ----------------------------------------------- enrich the wilaya dataset

$wilayaFile = $outputDir . '/wilayas.json';
$wilayas = json_decode((string) file_get_contents($wilayaFile), true);

if (!is_array($wilayas) || !isset($wilayas['wilayas'])) {
    fwrite(STDERR, "{$wilayaFile} is missing or malformed\n");
    exit(1);
}

$arabic = [];

foreach ($rows as $row) {
    $code = (int) $row['wilaya_code'];

    if ($code <= WILAYA_COUNT && $row['wilaya_name'] !== '') {
        $arabic[$code] ??= $row['wilaya_name'];
    }
}

$latin = [];

foreach ($rows as $row) {
    $code = (int) $row['wilaya_code'];
    $key = $code . '|' . $row['wilaya_name_fr'];
    $code = isset($renamed[$key]) ? $renamed[$key]['to'] : $code;

    if ($code <= WILAYA_COUNT) {
        $latin[$code][$row['wilaya_name_fr']] = ($latin[$code][$row['wilaya_name_fr']] ?? 0) + 1;
    }
}

$enriched = 0;

foreach ($wilayas['wilayas'] as &$wilaya) {
    $code = (int) $wilaya['code'];

    if (($arabic[$code] ?? '') !== '' && $wilaya['name_ar'] !== $arabic[$code]) {
        $wilaya['name_ar'] = $arabic[$code];
        $enriched++;
    }
}
unset($wilaya);

/*
 * Codes 59-69 are not in WooCommerce's DZ state list, which still reflects the
 * 2019 map, so their names come from the source dataset. Appended rather than
 * regenerating the whole file: the 1-58 entries are ISO 3166-2 spellings that
 * match what WooCommerce stores in an order's billing_state, and rewriting them
 * from the CSV would swap "Algiers" for "Alger" across data already in use.
 */
$known = array_map(static fn (array $w): int => (int) $w['code'], $wilayas['wilayas']);
$added = 0;

foreach ($counts as $code => $_) {
    if (in_array($code, $known, true)) {
        continue;
    }

    $names = $latin[$code] ?? [];
    arsort($names);

    $wilayas['wilayas'][] = [
        'code' => str_pad((string) $code, 2, '0', STR_PAD_LEFT),
        'name' => (string) array_key_first($names),
        'name_ar' => $arabic[$code] ?? '',
    ];
    $added++;
}

usort($wilayas['wilayas'], static fn (array $a, array $b): int => (int) $a['code'] <=> (int) $b['code']);

if ($added > 0) {
    printf("  added %d wilaya(s) absent from WooCommerce's DZ list\n\n", $added);
}

/*
 * The Latin names stay as they are — ISO 3166-2, matching WooCommerce's own DZ
 * state list, which is what an order's billing_state carries. The source CSV
 * spells some differently (Alger for Algiers, Tipaza for Tipasa); a client who
 * prefers those edits wilayas.json and re-imports, which is what "keep the
 * dataset updateable" is for.
 */
$wilayas['note'] = 'Latin names follow ISO 3166-2, matching WooCommerce\'s DZ state list. '
    . 'Arabic names come from the commune source dataset.';
$wilayas['source'] = 'Codes 01-58 from WooCommerce i18n/states.php (DZ), ISO 3166-2:DZ. '
    . 'Codes 59-69 — the former circonscriptions administratives, now full wilayas — '
    . 'and all Arabic names from ' . basename($source);

// ------------------------------------------------------- chef-lieu check

/**
 * A wilaya is named after its chef-lieu, so each should contain a commune of
 * its own name. This is the check that caught Boussaâda holding thirteen
 * communes while the town of Bou Saada sat in M'Sila.
 *
 * Run against the names actually written to wilayas.json, and with repeated
 * letters collapsed so Boussaâda matches Bou Saada. Reported, never enforced:
 * a few wilayas genuinely spell their capital differently from themselves —
 * Algiers/Alger Centre, El Abiodh/Labiodh — and that is naming, not misfiling.
 * The list is short enough to read every time.
 */
$loose = static function (string $name): string {
    $folded = strtr(mb_strtolower(trim($name)), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);

    $stripped = (string) preg_replace('/[^a-z0-9]+/', '', $folded);

    // Collapse runs: "boussaada" and "bousaada" are the same place.
    return (string) preg_replace('/(.)\\1+/', '$1', $stripped);
};

$chefLieu = [];

foreach ($communes as $commune) {
    $chefLieu[(int) $commune['wilaya_code']][] = $loose($commune['name']);
}

$unnamed = [];

foreach ($wilayas['wilayas'] as $wilaya) {
    $code = (int) $wilaya['code'];

    if (!in_array($loose($wilaya['name']), $chefLieu[$code] ?? [], true)) {
        $unnamed[] = $wilaya['code'] . ' ' . $wilaya['name'];
    }
}

if ($unnamed !== []) {
    printf(
        "  no commune shares its wilaya's name — check, do not assume:\n    %s\n\n",
        implode("\n    ", $unnamed)
    );
}

// ------------------------------------------------------------------- write

$json = static fn (array $data): string => json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";

/**
 * Write, or stop.
 *
 * file_put_contents() only warns when it cannot write, so without this the
 * script printed its summary and exited 0 having produced nothing — which is
 * exactly the silent success this whole pipeline exists to avoid.
 */
$write = static function (string $path, string $contents): void {
    $bytes = @file_put_contents($path, $contents);

    if ($bytes === false || $bytes !== strlen($contents)) {
        fwrite(STDERR, "could not write {$path} — check the file's ownership\n");
        exit(1);
    }
};

$write($wilayaFile, $json($wilayas));

$write($outputDir . '/communes.json', $json([
    'version' => date('Y-m-d', (int) filemtime($source)),
    'source' => basename($source) . ' — built by scripts/build-algeria-dataset.php',
    'note' => 'national_code is the national commune code, not a postal code. '
        . 'Postal codes are absent from this source and are left empty.',
    'communes' => $communes,
]));

printf("wilayas.json   %d wilayas, %d Arabic names filled in\n", count($wilayas['wilayas']), $enriched);
printf("communes.json  %d communes across %d wilayas\n", count($communes), count($counts));
printf("\nnow run: docker compose run --rm wpcli wp algerian-commerce import-algeria --dry-run\n");
