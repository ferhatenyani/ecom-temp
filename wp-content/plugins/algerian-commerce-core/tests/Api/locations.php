<?php
/**
 * Location endpoints and the geography importer against a real install —
 * roadmap §51, §65.
 *
 * Two things this pins that unit tests cannot: that the endpoints are
 * **public**, which is a deliberate decision and the only place in this plugin
 * a permission_callback returns true, and that the importer is idempotent —
 * re-running it updates rows in place instead of duplicating a 1,500-row
 * dataset or renumbering ids that orders point at.
 *
 * The commune fixtures are written to a temporary directory and removed from
 * the database at the end. The shipped communes.json is empty on purpose, and
 * this suite must not be the thing that quietly fills the canonical table with
 * invented place names.
 *
 *   scripts/test.sh                                  # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/locations.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

use AlgerianCommerce\Core\Plugin;
use AlgerianCommerce\Geography\GeoImporter;

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array $query = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    $response = rest_do_request($request);

    return [$response->get_status(), $response->get_data()];
}

function ac_check(string $label, array $result, int $expect, ?callable $extra = null): mixed
{
    [$status, $data] = $result;

    $ok = $status === $expect;
    $detail = '';

    if ($ok && $extra !== null) {
        $verdict = $extra($data);
        if ($verdict !== true) {
            $ok = false;
            $detail = ' — ' . (is_string($verdict) ? $verdict : 'body check failed');
        }
    }

    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;

    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 60), ' ', str_pad((string) $status, 4);

    if (!$ok) {
        echo "(expected {$expect}){$detail} ", substr((string) wp_json_encode($data), 0, 300);
    }

    echo PHP_EOL;

    return $data;
}

function ac_assert(string $label, $verdict): void
{
    $ok = $verdict === true;
    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;

    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 60);
    echo $ok ? '' : '     ' . (is_string($verdict) ? $verdict : 'failed');
    echo PHP_EOL;
}

/**
 * A throwaway dataset directory the importer can be pointed at.
 *
 * One directory per fixture set, named. Sharing a single directory made the
 * "missing file" case delete the files the other cases still needed, and the
 * suite only passed because of the order the sets happened to be written in.
 */
function ac_fixture_dir(string $name, array $files): string
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/ac-geo-fixture/' . $name;

    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }

    // Cleared, so a set is exactly what this call describes and never carries
    // a file left over from a previous run.
    foreach (glob($dir . '/*.json') ?: [] as $stale) {
        unlink($stale);
    }

    foreach ($files as $file => $contents) {
        file_put_contents($dir . '/' . $file, wp_json_encode($contents));
    }

    return $dir;
}

function ac_importer(string $dir): GeoImporter
{
    $plugin = Plugin::instance();

    return new GeoImporter($plugin->geoRepository(), $plugin->logger(), $plugin->auditLogger(), $dir);
}

/**
 * Remove everything this suite wrote; the canonical tables keep no fiction.
 *
 * Provider rows are deleted by *provider*, not by commune. A wilaya-level
 * mapping carries commune_id 0 by design — that is how stopdesk destinations
 * are addressed — so deleting by commune left it behind, and the next run
 * counted it as an update instead of an insert. Hence the unmistakable
 * fixture provider slug: it can never collide with real Yalidine or Zedair
 * data, so deleting all of its rows is safe.
 */
function ac_drop_fixtures(array $slugs, string $provider): int
{
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}ac_geo_provider_destinations WHERE provider = %s",
        $provider
    ));

    if ($slugs === []) {
        return 0;
    }

    $table = $wpdb->prefix . 'ac_geo_communes';
    $in = implode(',', array_fill(0, count($slugs), '%s'));

    return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE slug IN ({$in})", $slugs));
}

// The two wilayas the fixtures hang off. Real codes and real names, so the
// upsert cannot drift the shipped data.
$WILAYAS = [
    'wilayas' => [
        ['code' => '16', 'name' => 'Algiers'],
        ['code' => '31', 'name' => 'Oran'],
    ],
];

// Deliberately unmistakable, and deliberately including an accent and an
// apostrophe so the slug folding is exercised end to end.
$FIXTURE_COMMUNES = [
    ['wilaya_code' => '16', 'name' => 'Zz Test Béjaïa Ville', 'postal_code' => '16000'],
    ['wilaya_code' => '16', 'name' => "Zz Test M'Sila Ville"],
    ['wilaya_code' => '31', 'name' => 'Zz Test Oran Ville', 'postal_code' => '31000', 'is_active' => false],
];

$FIXTURE_SLUGS = ['zz-test-bejaia-ville', 'zz-test-m-sila-ville', 'zz-test-oran-ville'];

// Mixed case on purpose, so the importer's slugging of the provider name is
// exercised — and unmistakable, so cleanup can delete every row it owns.
$FIXTURE_PROVIDER_NAME = 'ZzTestCourier';
$FIXTURE_PROVIDER = 'zztestcourier';

echo PHP_EOL, "=== the endpoints are public ===", PHP_EOL;

wp_set_current_user(0);

// The only routes in this plugin that answer an anonymous caller. Public
// administrative divisions with no shop data in them — see LocationController.
ac_check('GET /locations/wilayas signed out', ac_req('GET', '/locations/wilayas'), 200);
ac_check('GET /locations/wilayas/16 signed out', ac_req('GET', '/locations/wilayas/16'), 200);
ac_check('GET communes signed out', ac_req('GET', '/locations/wilayas/16/communes'), 200);
ac_check('GET /locations/coverage signed out', ac_req('GET', '/locations/coverage'), 200);

echo PHP_EOL, "=== the shipped wilayas ===", PHP_EOL;

$all = ac_check('all 58 wilayas are loaded', ac_req('GET', '/locations/wilayas'), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) === 58 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

ac_assert('codes run 1 to 58 with no gaps', (function () use ($all) {
    $ids = array_column($all['data'], 'id');

    return $ids === range(1, 58) ?: 'ids are not a contiguous 1..58';
})());

ac_assert('the id is the official wilaya code', (function () use ($all) {
    foreach ($all['data'] as $row) {
        if ($row['id'] === 16) {
            return str_contains($row['name'], 'Alg') ?: '16 is ' . $row['name'];
        }
    }

    return 'wilaya 16 is missing';
})());

ac_assert('codes are zero-padded', (function () use ($all) {
    return ($all['data'][0]['code'] ?? '') === '01' ?: 'first code is ' . ($all['data'][0]['code'] ?? '?');
})());

ac_assert('slugs are accent-folded', (function () use ($all) {
    foreach ($all['data'] as $row) {
        if ($row['id'] === 6) {
            return $row['slug'] === 'bejaia' ?: 'Béjaïa slugged to ' . $row['slug'];
        }
    }

    return 'wilaya 06 is missing';
})());

ac_check('read one wilaya', ac_req('GET', '/locations/wilayas/31'), 200, function ($d) {
    return ($d['data']['id'] ?? 0) === 31 ?: 'got ' . ($d['data']['id'] ?? '?');
});

ac_check('a wilaya that does not exist', ac_req('GET', '/locations/wilayas/59'), 404);
ac_check('a wilaya code of zero', ac_req('GET', '/locations/wilayas/0'), 400);

ac_check('search by name', ac_req('GET', '/locations/wilayas', ['search' => 'Oran']), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) === 1 && $d['data'][0]['id'] === 31
        ?: 'got ' . wp_json_encode(array_column($d['data'], 'name'));
});

ac_check('search by code', ac_req('GET', '/locations/wilayas', ['search' => '16']), 200, function ($d) {
    return in_array(16, array_column($d['data'], 'id'), true) ?: 'code search missed wilaya 16';
});

ac_check('search for nothing', ac_req('GET', '/locations/wilayas', ['search' => 'zzz-nowhere']), 200, function ($d) {
    return $d['data'] === [] ?: 'expected no matches';
});

echo PHP_EOL, "=== the shipped communes ===", PHP_EOL;

// Algeria's real commune count. The source dataset splits 92 communes into
// circonscriptions administratives and files 11 of Touggourt's under Ouargla's
// old code; scripts/build-algeria-dataset.php resolves both, and this is what
// says it resolved them into 58 wilayas rather than 69.
$coverage = ac_check('all 1,541 communes are loaded', ac_req('GET', '/locations/coverage'), 200, function ($d) {
    return ($d['data']['communes'] ?? 0) === 1541 ?: 'got ' . ($d['data']['communes'] ?? '?');
});

ac_assert('every wilaya has at least one commune', (function () {
    $empty = [];

    for ($code = 1; $code <= 58; $code++) {
        if ((ac_req('GET', "/locations/wilayas/{$code}/communes")[1]['meta']['total'] ?? 0) === 0) {
            $empty[] = $code;
        }
    }

    return $empty === [] ?: 'no communes for wilaya(s) ' . implode(', ', $empty);
})());

ac_check('Alger has its 57 communes', ac_req('GET', '/locations/wilayas/16/communes'), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) === 57 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

// The 2019 split was half-applied in the source: 11 of these carried Ouargla's
// code while being named Touggourt.
ac_check('Touggourt has its 13, not 2', ac_req('GET', '/locations/wilayas/55/communes'), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) === 13 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

// 34 filed under M'Sila plus 13 filed under the Boussaâda circonscription.
ac_check('M\'Sila absorbed the Boussaâda district', ac_req('GET', '/locations/wilayas/28/communes'), 200, function ($d) {
    if (($d['meta']['total'] ?? 0) !== 47) {
        return 'got ' . ($d['meta']['total'] ?? '?');
    }

    // Matched on the slug: the district is spelled "Boussaâda" and the commune
    // inside it "Bou Saada", which is exactly the spelling variance the
    // accent-folded key exists to absorb.
    return in_array('bou-saada', array_column($d['data'], 'slug'), true) ?: 'Bou Saada itself is missing';
});

ac_check('communes carry Arabic names and coordinates', ac_req('GET', '/locations/wilayas/16/communes'), 200, function ($d) {
    foreach ($d['data'] as $row) {
        if ($row['name'] === 'Bab El Oued') {
            if ($row['name_ar'] === '') {
                return 'no Arabic name';
            }

            if ($row['daira'] === '') {
                return 'no daira';
            }

            // Strings, like every other decimal this API emits: a JSON float
            // is the one thing guaranteed to change a coordinate in transit.
            return is_string($row['latitude']) && is_string($row['longitude'])
                ?: 'coordinates are not strings';
        }
    }

    return 'Bab El Oued is missing from Alger';
});

ac_check('wilayas carry Arabic names', ac_req('GET', '/locations/wilayas/6'), 200, function ($d) {
    return ($d['data']['name_ar'] ?? '') !== '' ?: 'no Arabic name on Béjaïa';
});

// The national commune code is NOT a postal code — three or four digits
// against Algeria's five — so it has a column of its own and postal_code
// stays empty rather than carrying a wrong number onto every address.
ac_check('the national code is not passed off as a postal code', ac_req('GET', '/locations/wilayas/16/communes'), 200, function ($d) {
    foreach ($d['data'] as $row) {
        if ($row['national_code'] === '') {
            return 'a commune has no national code';
        }

        if ($row['postal_code'] !== '') {
            return 'postal_code was filled with ' . $row['postal_code'];
        }
    }

    return true;
});

echo PHP_EOL, "=== importing communes ===", PHP_EOL;

ac_drop_fixtures($FIXTURE_SLUGS, $FIXTURE_PROVIDER);

// Counts move relative to the shipped dataset now, not from zero.
$baseline16 = ac_req('GET', '/locations/wilayas/16/communes')[1]['meta']['total'];
$baseline31 = ac_req('GET', '/locations/wilayas/31/communes')[1]['meta']['total'];

$dir = ac_fixture_dir('good', [
    GeoImporter::WILAYAS => $WILAYAS,
    GeoImporter::COMMUNES => ['communes' => $FIXTURE_COMMUNES],
]);

$dry = ac_importer($dir)->import(true);

ac_assert('a dry run validates without writing', $dry['errors'] === [] ?: implode('; ', $dry['errors']));
ac_assert('and reports what it would add', $dry['communes']['inserted'] === 3 ?: 'reported ' . $dry['communes']['inserted']);

ac_check('nothing was actually written', ac_req('GET', '/locations/wilayas/16/communes'), 200, function ($d) use ($baseline16) {
    return ($d['meta']['total'] ?? 0) === $baseline16 ?: 'the dry run wrote ' . ($d['meta']['total'] - $baseline16) . ' communes';
});

$first = ac_importer($dir)->import();

ac_assert('the real import succeeds', $first['errors'] === [] ?: implode('; ', $first['errors']));
ac_assert('three communes inserted', $first['communes']['inserted'] === 3 ?: 'got ' . $first['communes']['inserted']);

$second = ac_importer($dir)->import();

// The point of the natural key: re-importing a 1,500-row dataset must not
// duplicate it or renumber ids that orders and shipments point at.
ac_assert('re-importing inserts nothing', $second['communes']['inserted'] === 0 ?: 'got ' . $second['communes']['inserted']);
ac_assert('and updates in place', $second['communes']['updated'] === 3 ?: 'got ' . $second['communes']['updated']);

echo PHP_EOL, "=== reading communes ===", PHP_EOL;

$communes = ac_check('a wilaya\'s communes', ac_req('GET', '/locations/wilayas/16/communes'), 200, function ($d) use ($baseline16) {
    return ($d['meta']['total'] ?? 0) === $baseline16 + 2 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

$communeId = (int) array_values(array_filter(
    array_column($communes['data'], 'id', 'slug'),
    static fn (string $slug): bool => $slug === 'zz-test-bejaia-ville',
    ARRAY_FILTER_USE_KEY
))[0];

ac_assert('the accented name is kept for display', (function () use ($communes) {
    foreach ($communes['data'] as $row) {
        if ($row['slug'] === 'zz-test-bejaia-ville') {
            return $row['name'] === 'Zz Test Béjaïa Ville' ?: 'name came back as ' . $row['name'];
        }
    }

    return 'the accented fixture is missing';
})());

ac_assert('the apostrophe folded into the slug', (function () use ($communes) {
    return in_array('zz-test-m-sila-ville', array_column($communes['data'], 'slug'), true)
        ?: 'got ' . implode(', ', array_column($communes['data'], 'slug'));
})());

ac_assert('the postal code kept its leading zeros as a string', (function () use ($communes) {
    foreach ($communes['data'] as $row) {
        if ($row['slug'] === 'zz-test-bejaia-ville') {
            return $row['postal_code'] === '16000' ?: 'got ' . var_export($row['postal_code'], true);
        }
    }

    return 'the fixture is missing';
})());

ac_check('read one commune', ac_req('GET', "/locations/communes/{$communeId}"), 200, function ($d) use ($communeId) {
    return ($d['data']['id'] ?? 0) === $communeId ?: 'wrong commune';
});

ac_check('a commune that does not exist', ac_req('GET', '/locations/communes/99999999'), 404);

// "No communes loaded" and "no such wilaya" are different answers, and a
// client filling an address form has to tell them apart.
ac_check('communes of a wilaya that does not exist', ac_req('GET', '/locations/wilayas/59/communes'), 404);
ac_check('search within a wilaya', ac_req('GET', '/locations/wilayas/16/communes', ['search' => 'Zz Test M']), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) === 1 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

// People name the daira as often as the commune when asked where they live.
ac_check('search matches a daira too', ac_req('GET', '/locations/wilayas/16/communes', ['search' => 'Cheraga']), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) > 1 ?: 'a daira search returned ' . ($d['meta']['total'] ?? '?');
});

ac_check('search matches an Arabic name', ac_req('GET', '/locations/wilayas/16/communes', ['search' => 'باب الوادي']), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) >= 1 ?: 'an Arabic search returned nothing';
});

ac_check('filter by postal code', ac_req('GET', '/locations/wilayas/16/communes', ['postal_code' => '16000']), 200, function ($d) {
    return ($d['meta']['total'] ?? 0) === 1 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

ac_check('a malformed postal code is refused', ac_req('GET', '/locations/wilayas/16/communes', ['postal_code' => '16A']), 400);

ac_check('active_only hides a switched-off commune', ac_req('GET', '/locations/wilayas/31/communes', ['active_only' => true]), 200, function ($d) use ($baseline31) {
    return ($d['meta']['total'] ?? 0) === $baseline31 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

ac_check('and it is still there without the filter', ac_req('GET', '/locations/wilayas/31/communes'), 200, function ($d) use ($baseline31) {
    return ($d['meta']['total'] ?? 0) === $baseline31 + 1 ?: 'got ' . ($d['meta']['total'] ?? '?');
});

echo PHP_EOL, "=== a bad dataset imports nothing ===", PHP_EOL;

$before = ac_req('GET', '/locations/wilayas/16/communes')[1]['meta']['total'];

$badDir = ac_fixture_dir('bad-row', [
    GeoImporter::WILAYAS => $WILAYAS,
    GeoImporter::COMMUNES => ['communes' => [
        ['wilaya_code' => '16', 'name' => 'Zz Test Good One'],
        ['wilaya_code' => '99', 'name' => 'Zz Test Bad Wilaya'],
    ]],
]);

$bad = ac_importer($badDir)->import();

ac_assert('the bad row is reported', $bad['errors'] !== [] ?: 'no errors reported');

// All-or-nothing: a half-loaded geography would validate addresses in some
// wilayas and reject them in others with no sign which.
ac_assert('and the good row beside it was not written', (function () use ($before) {
    $after = ac_req('GET', '/locations/wilayas/16/communes')[1]['meta']['total'];

    return $after === $before ?: "commune count went from {$before} to {$after}";
})());

// Deliberately empty: no wilayas.json at all.
$missingDir = ac_fixture_dir('empty', []);

$missing = ac_importer($missingDir)->import();

ac_assert('a missing wilaya file is an error', $missing['errors'] !== [] ?: 'a missing required file was tolerated');

echo PHP_EOL, "=== provider destinations stay separate ===", PHP_EOL;

$providerDir = ac_fixture_dir('providers', [
    GeoImporter::WILAYAS => $WILAYAS,
    GeoImporter::COMMUNES => ['communes' => $FIXTURE_COMMUNES],
    GeoImporter::DESTINATIONS => ['destinations' => [
        ['provider' => $FIXTURE_PROVIDER_NAME, 'wilaya_code' => '16', 'commune' => 'Zz Test Béjaïa Ville', 'destination_id' => '16-08'],
        ['provider' => $FIXTURE_PROVIDER, 'wilaya_code' => '31', 'destination_id' => 'ORAN-DESK'],
    ]],
]);

$withProviders = ac_importer($providerDir)->import();

ac_assert('provider mappings import', $withProviders['errors'] === [] ?: implode('; ', $withProviders['errors']));
ac_assert('two destinations written', $withProviders['destinations']['inserted'] === 2 ?: 'got ' . $withProviders['destinations']['inserted']);

// Roadmap §51: provider ids are stored separately from the canonical data.
ac_check('no provider id leaks into a commune', ac_req('GET', "/locations/communes/{$communeId}"), 200, function ($d) {
    foreach (['destination_id', 'provider', 'yalidine', 'zedair', 'destinations'] as $leak) {
        if (array_key_exists($leak, $d['data'])) {
            return "{$leak} is on the commune payload";
        }
    }

    return true;
});

ac_check('nor into a wilaya', ac_req('GET', '/locations/wilayas/16'), 200, function ($d) {
    return !array_key_exists('destination_id', $d['data']) ?: 'a provider id is on the wilaya payload';
});

echo PHP_EOL, "=== coverage ===", PHP_EOL;

ac_check('coverage reports what is loaded', ac_req('GET', '/locations/coverage'), 200, function ($d) {
    if (($d['data']['wilayas'] ?? 0) !== 58) {
        return 'wilayas: ' . ($d['data']['wilayas'] ?? '?');
    }

    if (($d['data']['communes'] ?? 0) !== 1544) {
        return 'communes: ' . ($d['data']['communes'] ?? '?');
    }

    return ($d['data']['provider_destinations'] ?? 0) >= 2 ?: 'destinations: ' . ($d['data']['provider_destinations'] ?? '?');
});

echo PHP_EOL, "=== cleanup ===", PHP_EOL;

$removed = ac_drop_fixtures($FIXTURE_SLUGS, $FIXTURE_PROVIDER);

ac_assert('fixture communes removed', $removed === 3 ?: "removed {$removed}");

ac_check('the canonical table is back to its shipped state', ac_req('GET', '/locations/coverage'), 200, function ($d) {
    if (($d['data']['communes'] ?? -1) !== 1541) {
        return 'left ' . (($d['data']['communes'] ?? 0) - 1541) . ' fixture communes behind';
    }

    return ($d['data']['provider_destinations'] ?? -1) === 0
        ?: 'left ' . $d['data']['provider_destinations'] . ' fixture destinations behind';
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
