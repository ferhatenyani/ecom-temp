<?php
/**
 * Inventory, product and audit endpoints against a real WordPress +
 * WooCommerce install — roadmap §65 (API and Security test categories).
 *
 * Covers what unit tests structurally cannot: authorization (401/403), IDOR,
 * cross-field validation, conflict states, bulk partial failure, pagination
 * bounds, and the ledger invariant that every movement's quantity_before
 * matches the previous row's quantity_after.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here — those live
 * in scripts/test-api.sh, over real HTTP. Keep the split in mind before
 * concluding a security control works because this file is green.
 *
 *   scripts/test.sh                                 # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/inventory.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, ?array $body = null, array $query = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));
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
    echo str_pad($label, 56), ' ', str_pad((string) $status, 4);

    if (!$ok) {
        echo "(expected {$expect}){$detail} ", substr((string) wp_json_encode($data), 0, 260);
    }

    echo PHP_EOL;

    return $data;
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

$manager = ac_user('ac_inv_manager', 'ac_product_manager');   // has ac_manage_inventory
$support = ac_user('ac_inv_support', 'ac_support_agent');     // has not

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /inventory signed out', ac_req('GET', '/inventory'), 401);
ac_check('POST adjust signed out', ac_req('POST', '/inventory/1/adjust', ['mode' => 'set', 'quantity' => 1, 'reason' => 'correction']), 401);

wp_set_current_user($support);
ac_check('GET /inventory as support agent', ac_req('GET', '/inventory'), 403);
ac_check('GET /inventory/movements as support agent', ac_req('GET', '/inventory/movements'), 403);
ac_check('POST /inventory/bulk as support agent', ac_req('POST', '/inventory/bulk', ['items' => [['id' => 1]]]), 403);

wp_set_current_user($manager);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

/*
 * A **fixed** SKU, and a purge before and after — roadmap §65,
 * docs/TESTING.md → "Conventions for the next suite".
 *
 * This was `'INV-' . wp_generate_password(6, false)` with no teardown, which
 * made it the only suite in the repository that did not start where the last
 * run finished: a fresh random SKU each time meant a fresh product each time,
 * and its unnamed companion below meant two. Nine runs had left eighteen
 * "Inventory Probe" and "Unmanaged Probe" products in the catalogue, which is
 * §63's analytics figures and §82's facet counts quietly drifting on every
 * `scripts/test.sh`.
 *
 * A random SKU is how a suite avoids colliding with its own leftovers. Not
 * leaving any is the better answer to that, and it is what every other suite
 * here does. `ac_drop_fixtures()` also removes what the random-SKU era left, so
 * one run of this file heals a database it never sees again.
 */
$sku = 'INV-PROBE';

/**
 * Delete this suite's products, including anything an older revision of it
 * left behind under a generated SKU.
 */
function ac_drop_fixtures(string $sku): void
{
    $ids = [];

    foreach (['publish', 'draft', 'pending', 'private', 'trash'] as $status) {
        foreach (wc_get_products(['sku' => $sku, 'status' => $status, 'limit' => 50, 'return' => 'ids']) as $id) {
            $ids[] = (int) $id;
        }
    }

    /*
     * The unmanaged probe carries no SKU, so it can only be found by name —
     * and the generated-SKU products share that name with it. Matching on the
     * two fixture names catches both, and nothing else in the catalogue is
     * called either: §67's seed is Algerian handicrafts.
     */
    foreach (wc_get_products([
        'limit' => 200,
        'return' => 'ids',
        'status' => ['publish', 'draft', 'pending', 'private', 'trash'],
    ]) as $id) {
        $product = wc_get_product((int) $id);

        if ($product && in_array($product->get_name(), ['Inventory Probe', 'Unmanaged Probe'], true)) {
            $ids[] = (int) $id;
        }
    }

    foreach (array_unique($ids) as $id) {
        wp_delete_post($id, true);
    }
}

ac_drop_fixtures($sku);

$product = ac_check(
    'POST /products (managed stock, qty 40)',
    ac_req('POST', '/products', [
        'name' => 'Inventory Probe',
        'sku' => $sku,
        'regular_price' => '2500',
        'manage_stock' => true,
        'stock_quantity' => 40,
        'status' => 'publish',
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['stock_quantity'] ?? null) === 40 ?: 'stock not 40'
);

$id = (int) $product['data']['id'];

$unmanaged = ac_check(
    'POST /products (no stock management)',
    ac_req('POST', '/products', ['name' => 'Unmanaged Probe', 'regular_price' => '100', 'status' => 'publish']),
    201
);
$unmanagedId = (int) $unmanaged['data']['id'];

echo PHP_EOL, "=== the ledger opens at creation ===", PHP_EOL;

ac_check(
    'GET /inventory/movements?product_id (opening row)',
    ac_req('GET', '/inventory/movements', null, ['product_id' => $id]),
    200,
    static function (array $d): bool|string {
        $rows = $d['data'];
        if (count($rows) !== 1) {
            return 'expected exactly one movement, got ' . count($rows);
        }
        $row = $rows[0];

        return $row['reason'] === 'product_edit'
            && $row['delta'] === 40
            && $row['quantity_before'] === 0
            && $row['quantity_after'] === 40
            ?: 'unexpected opening row: ' . wp_json_encode($row);
    }
);

echo PHP_EOL, "=== read ===", PHP_EOL;

ac_check('GET /inventory (paginated)', ac_req('GET', '/inventory'), 200, static function (array $d): bool|string {
    return isset($d['meta']['total'], $d['meta']['page'], $d['meta']['per_page'], $d['meta']['total_pages'])
        ?: 'pagination meta missing';
});

ac_check('GET /inventory/{id}', ac_req('GET', '/inventory/' . $id), 200, static function (array $d) use ($id): bool|string {
    $item = $d['data'];

    return $item['id'] === $id
        && $item['stock_quantity'] === 40
        && $item['managing_stock'] === true
        && $item['low_stock'] === false
        && array_key_exists('low_stock_amount', $item)
        ?: 'unexpected item: ' . wp_json_encode($item);
});

ac_check('GET /inventory/{id} not found', ac_req('GET', '/inventory/99999999'), 404);

ac_check(
    'GET /inventory/lookup?sku=',
    ac_req('GET', '/inventory/lookup', null, ['sku' => $sku]),
    200,
    static fn (array $d): bool|string => ($d['data']['id'] ?? 0) === $id ?: 'wrong product'
);

ac_check('GET /inventory/lookup unknown sku', ac_req('GET', '/inventory/lookup', null, ['sku' => 'nope-nope']), 404);
ac_check('GET /inventory/lookup without sku', ac_req('GET', '/inventory/lookup'), 400);

echo PHP_EOL, "=== adjust ===", PHP_EOL;

ac_check(
    'POST adjust decrease 5',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'decrease', 'quantity' => 5, 'reason' => 'damage', 'note' => 'water damage']),
    200,
    static function (array $d): bool|string {
        $m = $d['data']['movement'];

        return $m['delta'] === -5 && $m['quantity_before'] === 40 && $m['quantity_after'] === 35
            && $d['data']['item']['stock_quantity'] === 35
            ?: 'unexpected movement: ' . wp_json_encode($m);
    }
);

ac_check(
    'POST adjust increase 10',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'increase', 'quantity' => 10, 'reason' => 'restock']),
    200,
    static fn (array $d): bool|string => $d['data']['movement']['quantity_after'] === 45 ?: 'expected 45'
);

ac_check(
    'POST adjust set 12',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'set', 'quantity' => 12, 'reason' => 'correction']),
    200,
    static function (array $d): bool|string {
        $m = $d['data']['movement'];

        return $m['delta'] === -33 && $m['quantity_before'] === 45 && $m['quantity_after'] === 12
            ?: 'unexpected set movement: ' . wp_json_encode($m);
    }
);

echo PHP_EOL, "=== adjust: rejections ===", PHP_EOL;

ac_check(
    'adjust below zero without backorders',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'decrease', 'quantity' => 999, 'reason' => 'loss']),
    409,
    static fn (array $d): bool|string => ($d['error']['code'] ?? '') === 'conflict' ?: 'wrong code'
);

ac_check(
    'adjust a product that does not manage stock',
    ac_req('POST', "/inventory/{$unmanagedId}/adjust", ['mode' => 'set', 'quantity' => 5, 'reason' => 'correction']),
    409
);

ac_check(
    'adjust with a system reason (forgery attempt)',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'set', 'quantity' => 5, 'reason' => 'order_reduced']),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['reason']) ?: 'no field error'
);

ac_check(
    'adjust with an unknown field',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'set', 'quantity' => 5, 'reason' => 'correction', 'quantiy' => 1]),
    400
);

ac_check(
    'adjust with a negative quantity',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'set', 'quantity' => -5, 'reason' => 'correction']),
    400
);

ac_check('adjust a product that does not exist', ac_req('POST', '/inventory/99999999/adjust', ['mode' => 'set', 'quantity' => 1, 'reason' => 'correction']), 404);

echo PHP_EOL, "=== settings ===", PHP_EOL;

ac_check(
    'PATCH /inventory/{id} threshold + backorders',
    ac_req('PATCH', '/inventory/' . $id, ['low_stock_amount' => 20, 'backorders' => 'yes']),
    200,
    static fn (array $d): bool|string => $d['data']['low_stock_amount'] === 20
        && $d['data']['backorders'] === 'yes'
        && $d['data']['low_stock'] === true
        ?: 'unexpected settings: ' . wp_json_encode($d['data'])
);

ac_check(
    'PATCH rejects stock_quantity',
    ac_req('PATCH', '/inventory/' . $id, ['stock_quantity' => 500]),
    400,
    static fn (array $d): bool|string => str_contains(
        $d['error']['details']['fields']['stock_quantity'] ?? '',
        '/inventory/{id}/adjust'
    ) ?: 'message does not point at the adjust endpoint'
);

ac_check('PATCH with no supported fields', ac_req('PATCH', '/inventory/' . $id, []), 400);

ac_check(
    'adjust below zero now that backorders are on',
    ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'decrease', 'quantity' => 20, 'reason' => 'loss']),
    200,
    static fn (array $d): bool|string => $d['data']['movement']['quantity_after'] === -8 ?: 'expected -8'
);

// Back to a sane level for the reports below.
ac_req('POST', "/inventory/{$id}/adjust", ['mode' => 'set', 'quantity' => 3, 'reason' => 'correction']);

echo PHP_EOL, "=== reports ===", PHP_EOL;

ac_check(
    'GET /inventory/low-stock includes the probe',
    ac_req('GET', '/inventory/low-stock', null, ['per_page' => 100]),
    200,
    static function (array $d) use ($id): bool|string {
        foreach ($d['data'] as $item) {
            if ($item['id'] === $id) {
                return true;
            }
        }

        return 'probe (qty 3, threshold 20) missing from the low-stock report';
    }
);

ac_check(
    'GET /inventory?stock_status=outofstock is the out-of-stock report',
    ac_req('GET', '/inventory', null, ['stock_status' => 'outofstock']),
    200,
    static function (array $d): bool|string {
        foreach ($d['data'] as $item) {
            if ($item['stock_status'] !== 'outofstock') {
                return 'filter leaked ' . $item['stock_status'];
            }
        }

        return true;
    }
);

ac_check('GET /inventory?manage_stock=false', ac_req('GET', '/inventory', null, ['manage_stock' => false]), 200, static function (array $d): bool|string {
    foreach ($d['data'] as $item) {
        if ($item['manage_stock'] !== false) {
            return 'filter leaked a managed product';
        }
    }

    return true;
});

ac_check('GET /inventory bad orderby', ac_req('GET', '/inventory', null, ['orderby' => 'stock']), 400);
ac_check('GET /inventory per_page over the cap', ac_req('GET', '/inventory', null, ['per_page' => 500]), 400);

echo PHP_EOL, "=== movements ===", PHP_EOL;

ac_check(
    'GET /inventory/movements?reason=damage',
    ac_req('GET', '/inventory/movements', null, ['reason' => 'damage', 'product_id' => $id]),
    200,
    static function (array $d): bool|string {
        foreach ($d['data'] as $row) {
            if ($row['reason'] !== 'damage') {
                return 'filter leaked ' . $row['reason'];
            }
        }

        return count($d['data']) === 1 ?: 'expected one damage row';
    }
);

ac_check('GET /inventory/movements bad reason', ac_req('GET', '/inventory/movements', null, ['reason' => 'shrinkage']), 400);
ac_check('GET /inventory/movements bad date', ac_req('GET', '/inventory/movements', null, ['date_from' => '11-08-2026']), 400);
ac_check('GET /inventory/movements today', ac_req('GET', '/inventory/movements', null, ['date_from' => gmdate('Y-m-d'), 'date_to' => gmdate('Y-m-d')]), 200, static fn (array $d): bool|string => count($d['data']) > 0 ?: 'today should not be empty');

$chain = ac_check(
    'ledger reconciles: before(n) == after(n-1)',
    ac_req('GET', '/inventory/movements', null, ['product_id' => $id, 'per_page' => 100]),
    200,
    static function (array $d): bool|string {
        $rows = array_reverse($d['data']);   // oldest first
        $previous = null;

        foreach ($rows as $row) {
            if ($row['quantity_before'] + $row['delta'] !== $row['quantity_after']) {
                return 'row does not balance: ' . wp_json_encode($row);
            }

            if ($previous !== null && $row['quantity_before'] !== $previous) {
                return "gap in the ledger: expected before={$previous}, got {$row['quantity_before']}";
            }

            $previous = $row['quantity_after'];
        }

        return true;
    }
);

ac_check(
    'GET /inventory/movements/summary',
    ac_req('GET', '/inventory/movements/summary', null, ['product_id' => $id]),
    200,
    static fn (array $d): bool|string => ($d['data']['damage']['net'] ?? null) === -5 ?: 'damage net should be -5: ' . wp_json_encode($d['data'])
);

echo PHP_EOL, "=== bulk ===", PHP_EOL;

ac_check(
    'POST /inventory/bulk mixed success and failure',
    ac_req('POST', '/inventory/bulk', [
        'reason' => 'correction',
        'note' => 'stocktake',
        'items' => [
            ['id' => $id, 'mode' => 'set', 'quantity' => 7],
            ['id' => $unmanagedId, 'mode' => 'set', 'quantity' => 7],
            ['id' => 99999999, 'mode' => 'set', 'quantity' => 7],
        ],
    ]),
    200,
    static function (array $d): bool|string {
        return $d['meta']['total'] === 3
            && $d['meta']['succeeded'] === 1
            && $d['meta']['failed'] === 2
            && $d['data'][0]['success'] === true
            && $d['data'][1]['success'] === false
            && $d['data'][2]['error']['code'] === 'not_found'
            ?: 'unexpected bulk result: ' . wp_json_encode($d);
    }
);

ac_check(
    'bulk rejects duplicate ids',
    ac_req('POST', '/inventory/bulk', ['items' => [['id' => $id, 'mode' => 'set', 'quantity' => 1], ['id' => $id, 'mode' => 'set', 'quantity' => 2]]]),
    400
);

ac_check('bulk rejects an empty batch', ac_req('POST', '/inventory/bulk', ['items' => []]), 400);

echo PHP_EOL, "=== product endpoint still feeds the ledger ===", PHP_EOL;

ac_check(
    'PATCH /products/{id} stock_quantity writes a movement',
    ac_req('PATCH', '/products/' . $id, ['stock_quantity' => 55]),
    200
);

ac_check(
    'the movement landed with reason product_edit',
    ac_req('GET', '/inventory/movements', null, ['product_id' => $id, 'reason' => 'product_edit', 'per_page' => 100]),
    200,
    static function (array $d): bool|string {
        $latest = $d['data'][0] ?? null;

        return $latest !== null && $latest['quantity_after'] === 55
            ?: 'expected a product_edit row ending at 55, got ' . wp_json_encode($latest);
    }
);

echo PHP_EOL, "=== audit trail ===", PHP_EOL;

ac_check(
    'inventory.adjusted is audited',
    ac_req('GET', '/audit-logs', null, ['action' => 'inventory.adjusted', 'per_page' => 5]),
    403   // ac_product_manager does not hold ac_view_audit_logs
);

wp_set_current_user(ac_user('ac_inv_admin', 'ac_super_admin'));

ac_check(
    'inventory.adjusted is audited (as super admin)',
    ac_req('GET', '/audit-logs', null, ['action' => 'inventory.adjusted', 'per_page' => 5]),
    200,
    static fn (array $d): bool|string => count($d['data']) > 0
        && isset($d['data'][0]['metadata']['reason'], $d['data'][0]['metadata']['before'], $d['data'][0]['metadata']['after'])
        ?: 'no audited adjustment with before/after'
);

ac_check(
    'inventory.settings_updated is audited',
    ac_req('GET', '/audit-logs', null, ['action' => 'inventory.settings_updated', 'per_page' => 5]),
    200,
    static fn (array $d): bool|string => count($d['data']) > 0 ?: 'not audited'
);

echo PHP_EOL, "=== per_page cap is enforced on every list endpoint ===", PHP_EOL;

foreach (['/inventory', '/inventory/low-stock', '/inventory/movements', '/products', '/product-categories', '/audit-logs'] as $route) {
    ac_check("GET {$route}?per_page=500", ac_req('GET', $route, null, ['per_page' => 500]), 400);
    ac_check("GET {$route}?per_page=100", ac_req('GET', $route, null, ['per_page' => 100]), 200);
    ac_check("GET {$route}?page=0", ac_req('GET', $route, null, ['page' => 0]), 400);
}

echo PHP_EOL, "=== audit action filter survives the dot ===", PHP_EOL;

ac_check(
    'GET /audit-logs?action=product.created',
    ac_req('GET', '/audit-logs', null, ['action' => 'product.created']),
    200,
    static function (array $d): bool|string {
        foreach ($d['data'] as $row) {
            if ($row['action'] !== 'product.created') {
                return 'filter leaked ' . $row['action'];
            }
        }

        return count($d['data']) > 0 ?: 'dotted action filter still matches nothing';
    }
);

ac_check('GET /audit-logs?action= injection attempt', ac_req('GET', '/audit-logs', null, ['action' => "x' OR '1'='1"]), 400);

// Tidy up, so a second run starts where the first did. The ledger rows these
// products generated stay: `ac_inventory_movements` is a history, and deleting
// history because its subject was deleted is the opposite of what an audit
// trail is for.
ac_drop_fixtures($sku);

/*
 * Asserted rather than assumed. A teardown that stops working is invisible
 * — the suite stays green while the catalogue grows — which is exactly how
 * this file came to leave eighteen products behind without anyone noticing.
 */
$survivors = wc_get_products([
    'limit' => 5,
    'return' => 'ids',
    'status' => ['publish', 'draft', 'pending', 'private', 'trash'],
    'sku' => $sku,
]);

if ($survivors === []) {
    $GLOBALS['ac_pass']++;
    echo "\033[32mPASS\033[0m the suite left no fixture products behind", PHP_EOL;
} else {
    $GLOBALS['ac_fail']++;
    echo "\033[31mFAIL\033[0m fixture products survived teardown: ", wp_json_encode($survivors), PHP_EOL;
}

echo PHP_EOL, '=== ', $GLOBALS['ac_pass'], ' passed, ', $GLOBALS['ac_fail'], " failed ===", PHP_EOL;

// Non-zero so scripts/test.sh fails the run rather than printing red and
// exiting 0, which is how a broken suite quietly becomes decoration.
exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
