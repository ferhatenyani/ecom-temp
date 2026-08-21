<?php
/**
 * Import and export endpoints against a real WordPress + WooCommerce install —
 * roadmap §64, §65 (API and Security test categories).
 *
 * Covers what unit tests structurally cannot: authorization on six routes, the
 * dry run genuinely writing nothing, a real import moving stock *and* leaving a
 * ledger movement behind it, WooCommerce's own CSV engine loading outside
 * admin, and the assertion that `CsvWriter` still escapes formulas exactly the
 * way `WC_CSV_Exporter` does — which takes a loaded WooCommerce and so cannot
 * live in the unit suite.
 *
 * A CSV arrives as the request body rather than as a multipart upload, which is
 * a deliberate design choice (see `ImportExportController`) and is also what
 * makes this suite possible in-process: `rest_do_request()` cannot perform a
 * real multipart upload.
 *
 *   scripts/test.sh
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/import-export.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, ?string $csv = null, array $query = [], string $type = 'text/csv'): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($csv !== null) {
        $request->set_header('content-type', $type);
        $request->set_body($csv);
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
    echo str_pad($label, 62), ' ', str_pad((string) $status, 4);

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
    echo str_pad($label, 62);
    echo $ok ? '' : '     ' . (is_string($verdict) ? $verdict : 'failed');
    echo PHP_EOL;
}

function ac_user(string $login, string $role): int
{
    $user = get_user_by('login', $login);

    if ($user) {
        $user->set_role($role);

        return (int) $user->ID;
    }

    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(24),
        'user_email' => $login . '@example.test',
        'role' => $role,
    ]);

    return is_wp_error($id) ? 0 : (int) $id;
}

function ac_product(string $sku, string $price, int $stock): WC_Product
{
    $id = (int) wc_get_product_id_by_sku($sku);
    $product = $id > 0 ? wc_get_product($id) : new WC_Product_Simple();

    $product->set_name('IE test ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return wc_get_product($product->get_id());
}

function ac_stock(string $sku): ?int
{
    $id = (int) wc_get_product_id_by_sku($sku);
    $product = $id > 0 ? wc_get_product($id) : null;

    return $product ? (int) $product->get_stock_quantity() : null;
}

function ac_movements(int $productId): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ac_inventory_movements WHERE product_id = %d",
        $productId
    ));
}

$admin = ac_user('ac_ie_admin', 'ac_admin');
$support = ac_user('ac_ie_support', 'ac_support_agent');

$EXPORTS = ['products', 'inventory', 'orders', 'customers'];
$IMPORTS = ['products', 'inventory'];

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);

foreach ($EXPORTS as $subject) {
    ac_check("GET /export/{$subject} signed out", ac_req('GET', "/export/{$subject}"), 401);
}

foreach ($IMPORTS as $subject) {
    ac_check("POST /import/{$subject} signed out", ac_req('POST', "/import/{$subject}", "sku\nA-1\n"), 401);
}

/*
 * An export is not a summary, it is the records themselves in a file that
 * leaves the building — so each one carries the capability of the thing it
 * exports. Support Agent holds ac_manage_customers and nothing else here.
 */
wp_set_current_user($support);

ac_check('a support agent may export customers', ac_req('GET', '/export/customers'), 200);
ac_check('but not orders', ac_req('GET', '/export/orders'), 403);
ac_check('nor products', ac_req('GET', '/export/products'), 403);
ac_check('nor stock', ac_req('GET', '/export/inventory'), 403);
ac_check('and may import nothing', ac_req('POST', '/import/inventory', "sku,stock_quantity\nA-1,1\n"), 403);

wp_set_current_user($admin);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$lamp = ac_product('AC-IE-LAMP', '2000', 40);
$lampId = $lamp->get_id();
ac_assert('a product exists to count', $lampId > 0 ?: 'no product');

echo PHP_EOL, "=== exports ===", PHP_EOL;

foreach ($EXPORTS as $subject) {
    ac_check("GET /export/{$subject}", ac_req('GET', "/export/{$subject}", null, ['limit' => 5]), 200, function ($d) {
        return is_string($d) && $d !== '' ?: 'the body is not a CSV string';
    });
}

ac_check('an export is a file, not the envelope', ac_req('GET', '/export/inventory', null, ['limit' => 2]), 200, function ($d) {
    if (!is_string($d)) {
        return 'the body was wrapped in JSON';
    }

    // Excel reads the system codepage without this, and Arabic arrives broken.
    return str_starts_with($d, "\xEF\xBB\xBF") ?: 'no UTF-8 byte-order mark';
});

/*
 * The one thing this stage *can* see about the serving, and the reason it is
 * here rather than only in scripts/test-api.sh.
 *
 * `rest_pre_serve_request` never fires under `rest_do_request()`, so everything
 * above proves the CSV and nothing about whether the client receives it. What
 * is visible in-process is the response's **type**, and that is exactly where
 * the defect was: `FileDownload` used to mark its responses with
 * `set_matched_route()`, which `WP_REST_Server::respond_to_request()` overwrites
 * after the callback returns — so the filter declined every download and
 * WordPress JSON-encoded the CSV as a bare string. A `FileDownloadResponse`
 * survives `rest_ensure_response()` unchanged, and this asserts the controller
 * still returns one.
 */
$exportResponse = rest_do_request(
    (function () {
        $r = new WP_REST_Request('GET', '/algerian-commerce/v1/export/inventory');
        $r->set_param('limit', 2);

        return $r;
    })()
);

ac_assert('an export is typed as a download, which is how it escapes the encoder',
    $exportResponse instanceof AlgerianCommerce\API\FileDownloadResponse
        ?: 'got ' . get_class($exportResponse));

ac_assert('and an export error is not, so it keeps the envelope',
    !(rest_do_request(
        (function () {
            $r = new WP_REST_Request('GET', '/algerian-commerce/v1/export/orders');
            $r->set_param('limit', 999999);

            return $r;
        })()
    ) instanceof AlgerianCommerce\API\FileDownloadResponse));

ac_check('the stock export names the columns the importer reads', ac_req('GET', '/export/inventory', null, [
    'limit' => 2,
]), 200, function ($d) {
    $header = explode("\r\n", str_replace("\xEF\xBB\xBF", '', $d))[0];

    foreach (['sku', 'stock_quantity'] as $column) {
        if (!str_contains($header, $column)) {
            return "the export is missing {$column}, so it cannot be imported back";
        }
    }

    return true;
});

ac_check('the product export is WooCommerce\'s own format', ac_req('GET', '/export/products', null, [
    'limit' => 2,
]), 200, function ($d) {
    // Loading WooCommerce's exporter outside admin is the whole §64 finding.
    return str_contains($d, 'Uncategorized') || substr_count(explode("\r\n", $d)[0], ',') > 10
        ?: 'this does not look like the 40-column product CSV';
});

/*
 * **And it names its columns**, which it did not until `fix/product-export-header`.
 *
 * `WC_CSV_Exporter` splits the file in two — `export()` sends
 * `export_column_headers() . get_csv_data()` — and `ProductCsvExporter::toCsv()`
 * called only the second half. So the product export began `10,simple,AC-TAP-001,…`
 * while `/export/orders`, `/export/inventory` and `/export/customers` all began
 * with their column names. A 48-column file with no column names is unreadable
 * by a person, and `POST /import/products` read the first product's own values
 * as the header and answered *"Missing: sku."*
 *
 * The assertion above could not see it: it counts commas on the first line, and
 * a data row has more of them than the header does. This one names the column,
 * which is the thing that was missing.
 */
ac_check('and it names its columns, which a data row cannot', ac_req('GET', '/export/products', null, [
    'limit' => 2,
]), 200, function ($d) {
    $header = strtolower(explode("\n", str_replace("\xEF\xBB\xBF", '', $d))[0]);

    return str_starts_with($header, 'id,') && str_contains($header, 'sku')
        ?: 'the first line is not a header: ' . substr($header, 0, 80);
});

/*
 * **The header is the importer's field names**, which it was not until
 * `fix/product-export-field-names`.
 *
 * `WC_CSV_Exporter` keeps its columns as `id => label` and wrote the labels, so
 * the export began `ID,Type,SKU,"GTIN, UPC, EAN, or ISBN",Name,…`. Nothing
 * outside `wp-admin` reads those: `WC_Product_CSV_Importer::map_headers()` is
 * `isset($mapping[$key]) ? $mapping[$key] : $key` and the label-to-field table
 * is applied by the admin *controller*, which `WooCsv` does not load.
 *
 * `stock_quantity` is named on purpose. It is one of exactly two columns whose
 * export id is not the importer's field name — the id is `stock` — so it is the
 * column a header built from raw ids would silently drop, and a re-import that
 * dropped every quantity is precisely this bug wearing a smaller hat.
 */
ac_check('the header names import fields, not display labels', ac_req('GET', '/export/products', null, [
    'limit' => 2,
]), 200, function ($d) {
    $header = explode("\n", str_replace("\xEF\xBB\xBF", '', $d))[0];
    $columns = str_getcsv(rtrim($header, "\r"));

    foreach (['sku', 'name', 'regular_price', 'stock_quantity'] as $field) {
        if (!in_array($field, $columns, true)) {
            return "the header has no {$field} column: " . substr($header, 0, 120);
        }
    }

    // The labels, which are what it used to say. Named rather than implied, so
    // a revert cannot pass by adding `sku` alongside them.
    foreach (['SKU', 'Regular price', 'Stock', 'Is featured?'] as $label) {
        if (in_array($label, $columns, true)) {
            return "the header still carries the display label {$label}";
        }
    }

    return true;
});

/*
 * **And the file round-trips**, which is the whole reason both routes exist.
 *
 * Measured 2026-08-21 against the previous version: the exported file previewed
 * `rows 33, created 33, updated 0, skipped 0, failed 0` with `sku` and `name`
 * empty on every row — a dry run reporting 33 creations out of a file from which
 * WooCommerce had read nothing at all. `mode: update` is asserted because that
 * is the operator's actual errand: export, edit in a spreadsheet, send it back.
 */
$exported = ac_req('GET', '/export/products', null, ['limit' => 2])[1];

ac_check('a products export round-trips, SKUs and all', ac_req('POST', '/import/products', $exported, [
    'mode' => 'update',
]), 200, function ($d) {
    $preview = $d['data']['preview'] ?? [];
    $skus = array_column($preview, 'sku');
    $actions = array_unique(array_column($preview, 'action'));

    if ($skus === [] || in_array('', $skus, true)) {
        return 'the SKUs did not resolve: ' . wp_json_encode($skus);
    }

    return $actions === ['updated']
        ?: 'products that exist were not reported as updates: ' . wp_json_encode($actions);
});

/*
 * The negative control, and it is one character wide.
 *
 * `sku` is uppercased in the header and nothing else is touched — which is what
 * a wp-admin product export calls that column. `CsvReader` lower-cases the
 * header on purpose, so `requireColumns(['sku'])` is satisfied; WooCommerce
 * matches exactly, so it maps nothing. Those two readings disagreeing, with only
 * the lenient one asked, is the entire defect: the previous version answered
 * **200** here with `created: 2` and an empty `sku` on both rows.
 *
 * A refusal, not a rescue. Handing WooCommerce a case-folding `mapping` would
 * resolve `SKU` and `Name` and still drop `Regular price` and forty others, so
 * the file would import as products with no prices and report success.
 */
$uppercasedSku = (function (string $csv): string {
    $lines = explode("\n", str_replace("\xEF\xBB\xBF", '', $csv));
    $lines[0] = preg_replace('/(^|,)sku(,|$)/', '$1SKU$2', $lines[0], 1);

    return implode("\n", $lines);
})($exported);

ac_check('a header the importer cannot map is refused, not previewed', ac_req(
    'POST',
    '/import/products',
    $uppercasedSku,
    ['mode' => 'update']
), 400, function ($d) {
    return str_contains((string) wp_json_encode($d['error']['details'] ?? []), 'sku')
        ?: 'the refusal does not name the column: ' . wp_json_encode($d);
});

ac_check('an export beyond the cap is refused with the limit named', ac_req('GET', '/export/orders', null, [
    'limit' => 999999,
]), 400);

echo PHP_EOL, "=== the two escapings agree ===", PHP_EOL;

/*
 * The product export uses WooCommerce's exporter and everything else uses ours,
 * so a shop opening both must not find one escaped and the other not. This is
 * the check that stops the duplication drifting after a WooCommerce upgrade.
 */
$reflector = null;

try {
    \AlgerianCommerce\ImportExport\WooCsv::load();
    $class = new ReflectionClass('WC_CSV_Exporter');
    $reflector = $class->getMethod('escape_data');
    $reflector->setAccessible(true);
} catch (Throwable $e) {
    ac_assert('WooCommerce\'s CSV engine loads outside admin', $e->getMessage());
}

if ($reflector !== null) {
    ac_assert('WooCommerce\'s CSV engine loads outside admin', true);

    $exporter = new \AlgerianCommerce\ImportExport\ProductCsvExporter();
    $agree = true;
    $disagreed = '';

    foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx", 'Lamp', "'Ain Defla", ''] as $probe) {
        $theirs = (string) $reflector->invoke($exporter, $probe);
        $ours = \AlgerianCommerce\ImportExport\CsvWriter::escape($probe);

        if ($theirs !== $ours) {
            $agree = false;
            $disagreed = sprintf('%s: WooCommerce %s, ours %s', var_export($probe, true), $theirs, $ours);
            break;
        }
    }

    ac_assert('CsvWriter escapes exactly as WC_CSV_Exporter does', $agree ?: $disagreed);
}

echo PHP_EOL, "=== import: bad input ===", PHP_EOL;

ac_check('an empty body', ac_req('POST', '/import/inventory', ''), 400);
ac_check('a file with no data rows', ac_req('POST', '/import/inventory', "sku,stock_quantity\n"), 200, function ($d) {
    return $d['data']['rows'] === 0 ?: 'a header-only file is not an error, it is zero rows';
});

ac_check('a JSON body is refused by name', ac_req('POST', '/import/inventory', '{"file":"x"}', [], 'application/json'), 400, function ($d) {
    return str_contains((string) ($d['error']['details']['fields']['body'] ?? ''), 'text/csv')
        ?: 'the refusal does not say what to send instead';
});

ac_check('a missing required column names every one', ac_req('POST', '/import/inventory', "name\nLamp\n"), 400, function ($d) {
    $message = (string) ($d['error']['details']['fields']['file'] ?? '');

    return str_contains($message, 'sku') && str_contains($message, 'stock_quantity')
        ?: "got: {$message}";
});

echo PHP_EOL, "=== import: the dry run writes nothing ===", PHP_EOL;

$before = ac_stock('AC-IE-LAMP');
$movementsBefore = ac_movements($lampId);

ac_check('a dry run reports what it would do', ac_req('POST', '/import/inventory', "sku,stock_quantity\nAC-IE-LAMP,9\n", [
    'dry_run' => true,
]), 200, function ($d) {
    $r = $d['data'];

    if ($r['dry_run'] !== true) {
        return 'the report does not say it was a dry run';
    }

    return ($r['updated'] === 1 && $r['failed'] === 0) ?: 'expected one update, got ' . wp_json_encode($r);
});

ac_assert('the dry run changed no stock', ac_stock('AC-IE-LAMP') === $before ?: 'stock moved during a dry run');
ac_assert('and wrote no ledger movement', ac_movements($lampId) === $movementsBefore ?: 'a dry run wrote to the ledger');

// dry_run defaults to true: a client that forgets the flag gets a preview,
// never a write. The other way round, one bad integration overwrites a shop.
ac_check('omitting dry_run defaults to a preview', ac_req('POST', '/import/inventory', "sku,stock_quantity\nAC-IE-LAMP,3\n"), 200, function ($d) {
    return $d['data']['dry_run'] === true ?: 'dry_run defaulted to false — a client that forgets writes';
});

ac_assert('so stock is still untouched', ac_stock('AC-IE-LAMP') === $before ?: 'the default wrote to the shop');

echo PHP_EOL, "=== import: the real run ===", PHP_EOL;

ac_check('a real run applies the file', ac_req('POST', '/import/inventory', "sku,stock_quantity\nAC-IE-LAMP,9\n", [
    'dry_run' => false,
]), 200, function ($d) {
    return ($d['data']['dry_run'] === false && $d['data']['updated'] === 1)
        ?: 'expected one applied update';
});

ac_assert('the stock moved', ac_stock('AC-IE-LAMP') === 9 ?: 'stock is ' . var_export(ac_stock('AC-IE-LAMP'), true));

/*
 * The property that matters most. An import must not be a back door around the
 * ledger — every change to a quantity has a reason and an actor, and "a
 * spreadsheet said so" is a reason like any other.
 */
ac_assert(
    'and left a ledger movement behind it',
    ac_movements($lampId) === $movementsBefore + 1
        ?: "movements {$movementsBefore} → " . ac_movements($lampId)
);

ac_check('re-importing the same file changes nothing', ac_req('POST', '/import/inventory', "sku,stock_quantity\nAC-IE-LAMP,9\n", [
    'dry_run' => false,
]), 200, function ($d) {
    // `set`, not a delta — so an import is safe to run twice.
    return $d['data']['skipped'] === 1 ?: 'expected the unchanged row to be skipped';
});

ac_assert('so the stock is still 9', ac_stock('AC-IE-LAMP') === 9 ?: 'a second import moved the count');

echo PHP_EOL, "=== import: rows fail individually ===", PHP_EOL;

ac_check('one bad row does not abandon the good ones', ac_req('POST', '/import/inventory',
    "sku,stock_quantity\nAC-IE-LAMP,11\nNO-SUCH-SKU,5\nAC-IE-LAMP,12\n", ['dry_run' => false]), 200, function ($d) {
        $r = $d['data'];

        if ($r['updated'] !== 1) {
            return 'the good row was not applied: ' . wp_json_encode($r);
        }

        // A missing SKU and a duplicate SKU are both failures, and both name
        // the line so somebody can fix that row rather than the whole file.
        if ($r['failed'] !== 2) {
            return 'expected two failures, got ' . $r['failed'];
        }

        foreach ($r['errors'] as $error) {
            if (!isset($error['line'])) {
                return 'an error does not say which line it came from';
            }
        }

        return true;
    });

ac_assert('the good row applied', ac_stock('AC-IE-LAMP') === 11 ?: 'stock is ' . var_export(ac_stock('AC-IE-LAMP'), true));

ac_check('an import never creates a product', ac_req('POST', '/import/inventory', "sku,stock_quantity\nAC-IE-GHOST,4\n", [
    'dry_run' => false,
]), 200, function ($d) {
    return $d['data']['failed'] === 1 ?: 'a missing SKU should fail, not create';
});

ac_assert('and no product appeared', wc_get_product_id_by_sku('AC-IE-GHOST') === 0 ?: 'an import created a product');

echo PHP_EOL, "=== import: products, through WooCommerce's engine ===", PHP_EOL;

/*
 * This section asserts absolutes — "created 1", "skipped 1" — because the two
 * modes are only distinguishable that way, so the SKUs it uses have to start
 * from nothing on every run. Everywhere else this suite counts deltas, as
 * tests/Api/cod.php does, because the install it runs against is not empty.
 */
foreach (['AC-IE-CSV', 'AC-IE-NOWHERE', 'AC-IE-GHOST'] as $sku) {
    $stale = (int) wc_get_product_id_by_sku($sku);

    if ($stale > 0) {
        wp_delete_post($stale, true);
    }
}

ac_assert('the product fixtures start from nothing',
    wc_get_product_id_by_sku('AC-IE-CSV') === 0 ?: 'a previous run left AC-IE-CSV behind');

ac_check('a product dry run says what it cannot promise', ac_req('POST', '/import/products',
    "sku,name,regular_price\nAC-IE-CSV,Imported Lamp,1500\n", ['dry_run' => true]), 200, function ($d) {
        if (!isset($d['data']['preview_only'])) {
            return 'the response does not admit the dry run is a parse, not a rehearsal';
        }

        return $d['data']['created'] === 1 ?: 'expected one row to be seen as a creation';
    });

ac_assert('the dry run created nothing', wc_get_product_id_by_sku('AC-IE-CSV') === 0 ?: 'a dry run created a product');

ac_check('a real product import creates', ac_req('POST', '/import/products',
    "sku,name,regular_price\nAC-IE-CSV,Imported Lamp,1500\n", ['dry_run' => false]), 200, function ($d) {
        return $d['data']['created'] === 1 ?: 'nothing was created: ' . wp_json_encode($d['data']);
    });

$imported = wc_get_product_id_by_sku('AC-IE-CSV');
ac_assert('the product exists', $imported > 0 ?: 'no product was created');
ac_assert('with the name from the file', $imported > 0 && wc_get_product($imported)->get_name() === 'Imported Lamp'
    ?: 'the name did not come through');

/*
 * WooCommerce's `update_existing` is a mode switch whose name reads as a
 * modifier: neither setting both creates and updates. Measured 2026-08-16 and
 * exposed as `mode`, because passed through under its own name it is a trap in
 * both directions.
 */
ac_check('create mode skips a SKU that already exists', ac_req('POST', '/import/products',
    "sku,name,regular_price\nAC-IE-CSV,Renamed,9999\n", ['dry_run' => false, 'mode' => 'create']), 200, function ($d) {
        return ($d['data']['skipped'] === 1 && $d['data']['created'] === 0)
            ?: 'expected the duplicate to be skipped: ' . wp_json_encode($d['data']);
    });

ac_assert('so the existing product is untouched',
    wc_get_product($imported)->get_regular_price() === '1500' ?: 'create mode overwrote an existing product');

ac_check('update mode changes it', ac_req('POST', '/import/products',
    "sku,name,regular_price\nAC-IE-CSV,Imported Lamp,1750\n", ['dry_run' => false, 'mode' => 'update']), 200, function ($d) {
        return $d['data']['updated'] === 1 ?: 'expected one update: ' . wp_json_encode($d['data']);
    });

ac_assert('the price moved', wc_get_product($imported)->get_regular_price() === '1750'
    ?: 'price is ' . wc_get_product($imported)->get_regular_price());

ac_check('update mode skips a SKU that does not exist', ac_req('POST', '/import/products',
    "sku,name,regular_price\nAC-IE-NOWHERE,Ghost,10\n", ['dry_run' => false, 'mode' => 'update']), 200, function ($d) {
        // WooCommerce's own words: "No matching product exists to update."
        return $d['data']['skipped'] === 1 ?: 'expected a skip: ' . wp_json_encode($d['data']);
    });

ac_assert('and created nothing', wc_get_product_id_by_sku('AC-IE-NOWHERE') === 0 ?: 'update mode created a product');

ac_check('an invented mode is refused', ac_req('POST', '/import/products', "sku\nA-1\n", [
    'dry_run' => true,
    'mode' => 'upsert',
]), 400);

ac_check('a dry run reflects the mode it was given', ac_req('POST', '/import/products',
    "sku,name,regular_price\nAC-IE-CSV,Imported Lamp,1800\n", ['dry_run' => true, 'mode' => 'create']), 200, function ($d) {
        // An existing SKU in create mode is a skip, and a preview that said
        // "1 created" would promise work the real run will not do.
        return $d['data']['skipped'] === 1 ?: 'the preview ignored the mode: ' . wp_json_encode($d['data']);
    });

// Nothing may be left in the temp directory once the request is over.
$leftovers = glob(rtrim(get_temp_dir(), '/\\') . '/ac-import-*.csv');
ac_assert('no import file was left on disk', ($leftovers === [] || $leftovers === false)
    ?: 'left behind: ' . implode(', ', (array) $leftovers));

echo PHP_EOL, "=== a formula in the shop cannot reach the file ===", PHP_EOL;

$evil = ac_product('AC-IE-EVIL', '100', 5);
$evil->set_name('=cmd|\' /C calc\'!A0');
$evil->save();

ac_check('a hostile product name is neutralised on export', ac_req('GET', '/export/inventory', null, [
    'limit' => 2000,
]), 200, function ($d) {
    if (!str_contains($d, 'cmd|')) {
        return true; // outside the page; nothing to assert
    }

    return (str_contains($d, "'=cmd|") || str_contains($d, "\"'=cmd|"))
        ?: 'the formula reached the file unescaped';
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
