<?php
/**
 * Product endpoints — roadmap §47, §65.
 *
 * **This suite exists because the §65 audit found it missing.** Products were
 * the first commerce module built, before `tests/Api/` was a convention, and
 * every module from §49 onward got a suite while this one kept the incidental
 * coverage it picked up from `tests/Api/inventory.php` (four calls) and
 * `tests/Api/seo.php` (three). Nothing drove product CRUD hard, and the price of
 * that showed up immediately: the first walkthrough that trashed a product and
 * re-created its SKU got a **500** out of WooCommerce, which is the regression
 * at the bottom of this file.
 *
 * It covers §65's API list against one resource, in the order §65 writes it:
 * success, bad input, unauthenticated, unauthorized, not found, duplicate,
 * pagination, boundary values.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/products.php
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array|string|null $body = null, array $query = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(is_string($body) ? $body : wp_json_encode($body));
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

/** Purge anything a previous run left behind, trash included. */
function ac_purge_sku(string $sku): void
{
    foreach (['publish', 'draft', 'pending', 'private', 'trash'] as $status) {
        foreach (wc_get_products(['sku' => $sku, 'status' => $status, 'limit' => 20, 'return' => 'ids']) as $id) {
            wp_delete_post((int) $id, true);
        }
    }
}

$SKU = 'ac-test-sku-1';
$SKU_OTHER = 'ac-test-sku-2';

foreach ([$SKU, $SKU_OTHER] as $sku) {
    ac_purge_sku($sku);
}

$admin = ac_user('ac_prod_admin', 'ac_super_admin');
$support = ac_user('ac_prod_support', 'ac_support_agent');

wp_set_current_user($admin);

echo PHP_EOL, "── success ──", PHP_EOL;

$created = ac_check(
    'create a simple product',
    ac_req('POST', '/products', [
        'name' => 'Tapis berbère',
        'type' => 'simple',
        'regular_price' => '4500.00',
        'sku' => $SKU,
        'description' => 'Handwoven.',
        'manage_stock' => true,
        'stock_quantity' => 7,
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['name'] ?? '') === 'Tapis berbère'
        ? true
        : 'name came back as ' . var_export($d['data']['name'] ?? null, true)
);

$productId = (int) ($created['data']['id'] ?? 0);
ac_assert('the created product has an id', $productId > 0 ?: 'no id in the response');

ac_check(
    'read it back',
    ac_req('GET', "/products/{$productId}"),
    200,
    static function (array $d) use ($SKU): bool|string {
        $p = $d['data'] ?? [];

        return ($p['sku'] ?? '') === $SKU && ($p['regular_price'] ?? '') === '4500.00'
            ? true
            : 'sku/price came back as ' . var_export([$p['sku'] ?? null, $p['regular_price'] ?? null], true);
    }
);

ac_check(
    'patch one field without sending the rest',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '3900.00']),
    200,
    static fn (array $d): bool|string => ($d['data']['regular_price'] ?? '') === '3900.00'
        ? true
        : 'price is ' . var_export($d['data']['regular_price'] ?? null, true)
);

ac_check(
    'the name survived a patch that did not mention it',
    ac_req('GET', "/products/{$productId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['name'] ?? '') === 'Tapis berbère'
        ? true
        : 'name is now ' . var_export($d['data']['name'] ?? null, true)
);

/*
 * The round trip a client actually performs: GET the object, change one field,
 * PATCH the whole thing back. `ProductInput::READ_ONLY` drops `id`, `price`,
 * `permalink` and the rest silently for exactly this, so a client is not
 * required to know which of the fields it was handed are writable.
 */
$whole = ac_req('GET', "/products/{$productId}")[1]['data'] ?? [];
$whole['name'] = 'Tapis berbère (grand)';
ac_check('the whole GET body can be PATCHed back', ac_req('PATCH', "/products/{$productId}", $whole), 200);

ac_check('list products', ac_req('GET', '/products', null, ['per_page' => 5]), 200, static function (array $d): bool|string {
    $meta = $d['meta'] ?? [];

    return isset($meta['total'], $meta['page'], $meta['per_page'], $meta['total_pages'])
        && is_array($d['data'] ?? null)
        && count($d['data']) <= 5
        ? true
        : 'pagination meta was ' . wp_json_encode($meta);
});

ac_check(
    'search finds it by name',
    ac_req('GET', '/products', null, ['search' => 'berbère', 'per_page' => 20]),
    200,
    static function (array $d) use ($productId): bool|string {
        foreach ($d['data'] ?? [] as $row) {
            if ((int) ($row['id'] ?? 0) === $productId) {
                return true;
            }
        }

        return 'the product was not in its own search results';
    }
);

$copy = ac_check(
    'duplicate a product',
    ac_req('POST', "/products/{$productId}/duplicate"),
    201,
    static fn (array $d): bool|string => (int) ($d['data']['id'] ?? 0) > 0 ? true : 'no copy id'
);
$copyId = (int) ($copy['data']['id'] ?? 0);

// A copy must not carry the original's SKU: SKUs are unique and WooCommerce
// would refuse the next save.
ac_assert(
    'the copy did not inherit the SKU',
    $copyId > 0 && ($copy['data']['sku'] ?? '') !== $SKU
        ?: 'the copy came back with sku ' . var_export($copy['data']['sku'] ?? null, true)
);

echo PHP_EOL, "── bad input ──", PHP_EOL;

ac_check('a product needs a name', ac_req('POST', '/products', ['regular_price' => '10']), 400);
ac_check('an empty name is refused', ac_req('POST', '/products', ['name' => '   ']), 400);
ac_check(
    'a negative price is refused',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '-1']),
    400
);
ac_check(
    'a non-numeric price is refused',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => 'free']),
    400
);
ac_check(
    'a sale price above the regular price is refused',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '100', 'sale_price' => '200']),
    400
);
ac_check(
    'a sale price above the *stored* regular price is refused',
    ac_req('PATCH', "/products/{$productId}", ['sale_price' => '999999']),
    400
);
ac_check('an unknown type is refused', ac_req('POST', '/products', ['name' => 'x', 'type' => 'bundle']), 400);
ac_check('an unknown status is refused', ac_req('POST', '/products', ['name' => 'x', 'status' => 'live']), 400);
ac_check(
    'an unknown field is refused',
    ac_req('PATCH', "/products/{$productId}", ['nonsense_field' => 1]),
    400
);
ac_check('a malformed body is refused', ac_req('POST', '/products', '{"name": '), 400);
ac_check(
    'an unknown orderby is refused',
    ac_req('GET', '/products', null, ['orderby' => 'post_password']),
    400
);

echo PHP_EOL, "── unauthenticated and unauthorized ──", PHP_EOL;

wp_set_current_user(0);
ac_check('listing needs a credential', ac_req('GET', '/products'), 401);
ac_check('reading one needs a credential', ac_req('GET', "/products/{$productId}"), 401);
ac_check('creating needs a credential', ac_req('POST', '/products', ['name' => 'x']), 401);
ac_check('deleting needs a credential', ac_req('DELETE', "/products/{$productId}"), 401);

// Support Agent holds ac_manage_customers and ac_view_analytics — never
// ac_manage_products. The capability boundary, not the login.
wp_set_current_user($support);
ac_check('a Support Agent cannot list products', ac_req('GET', '/products'), 403);
ac_check('a Support Agent cannot read one', ac_req('GET', "/products/{$productId}"), 403);
ac_check('a Support Agent cannot create', ac_req('POST', '/products', ['name' => 'x']), 403);
ac_check('a Support Agent cannot delete', ac_req('DELETE', "/products/{$productId}"), 403);

wp_set_current_user($admin);

echo PHP_EOL, "── not found ──", PHP_EOL;

ac_check('read a missing product', ac_req('GET', '/products/99999999'), 404);
ac_check('patch a missing product', ac_req('PATCH', '/products/99999999', ['name' => 'x']), 404);
ac_check('delete a missing product', ac_req('DELETE', '/products/99999999'), 404);
ac_check('duplicate a missing product', ac_req('POST', '/products/99999999/duplicate'), 404);
ac_check('variations of a missing product', ac_req('GET', '/products/99999999/variations'), 404);

echo PHP_EOL, "── duplicate ──", PHP_EOL;

ac_check(
    'a second product cannot take the same SKU',
    ac_req('POST', '/products', ['name' => 'Copy', 'regular_price' => '10', 'sku' => $SKU]),
    409
);
ac_check(
    'a product cannot be patched onto another SKU that is taken',
    ac_req('PATCH', "/products/{$copyId}", ['sku' => $SKU]),
    409
);
ac_check(
    'a product may keep its own SKU on patch',
    ac_req('PATCH', "/products/{$productId}", ['sku' => $SKU]),
    200
);

echo PHP_EOL, "── pagination and boundaries ──", PHP_EOL;

ac_check(
    'per_page is honoured',
    ac_req('GET', '/products', null, ['per_page' => 2]),
    200,
    static fn (array $d): bool|string => count($d['data'] ?? []) <= 2
        ? true
        : 'got ' . count($d['data'] ?? []) . ' rows for per_page=2'
);
ac_check('per_page above the cap is refused', ac_req('GET', '/products', null, ['per_page' => 101]), 400);
ac_check('per_page of zero is refused', ac_req('GET', '/products', null, ['per_page' => 0]), 400);
ac_check('a negative page is refused', ac_req('GET', '/products', null, ['page' => -1]), 400);
ac_check('per_page at the cap is allowed', ac_req('GET', '/products', null, ['per_page' => 100]), 200);
ac_check(
    'a page past the end is empty, not an error',
    ac_req('GET', '/products', null, ['page' => 99999, 'per_page' => 5]),
    200,
    static fn (array $d): bool|string => ($d['data'] ?? []) === [] ? true : 'rows came back past the last page'
);

// Price precision: WooCommerce stores prices as strings and this shop runs two
// decimals. A price that arrives with more must not be silently truncated into
// a different number without the response saying so.
ac_check(
    'a price keeps two decimals',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '1234.50']),
    200,
    static fn (array $d): bool|string => ($d['data']['regular_price'] ?? '') === '1234.50'
        ? true
        : 'price came back as ' . var_export($d['data']['regular_price'] ?? null, true)
);

echo PHP_EOL, "── delete, and the SKU the trash keeps ──", PHP_EOL;

/*
 * THE REGRESSION TEST.
 *
 * `DELETE` trashes; `force=true` is permanent. A trashed product still holds
 * its SKU in `wc_product_meta_lookup`, but `wc_get_product_id_by_sku()`
 * excludes trashed rows — so the conflict check said the SKU was free and
 * WooCommerce's own insert then threw "already present in the lookup table",
 * which reached the client as **500 internal_error**. An admin who trashed a
 * product and re-created it hit this every time.
 *
 * `ProductRepository::skuExists()` now looks in the trash as well, and the
 * message names the trashed product rather than saying "already in use" about
 * something that is not in the catalogue.
 */
ac_check('delete trashes by default', ac_req('DELETE', "/products/{$copyId}"), 200);
ac_assert(
    'the trashed product is really in the trash',
    get_post_status($copyId) === 'trash' ?: 'status is ' . var_export(get_post_status($copyId), true)
);

ac_check('patch the surviving product onto a free SKU', ac_req('PATCH', "/products/{$productId}", ['sku' => $SKU_OTHER]), 200);

// $SKU is now held only by the trashed original... after we trash it too.
ac_check('trash the original as well', ac_req('DELETE', "/products/{$productId}"), 200);

$reborn = ac_check(
    'a SKU held by a trashed product is a conflict, not a 500',
    ac_req('POST', '/products', ['name' => 'Reborn', 'regular_price' => '10', 'sku' => $SKU_OTHER]),
    409,
    static fn (array $d): bool|string => isset($d['error']['details']['trashed_product_id'])
        ? true
        : 'the conflict did not name the trashed product: ' . substr((string) wp_json_encode($d), 0, 200)
);

ac_check('force=true removes it permanently', ac_req('DELETE', "/products/{$productId}", null, ['force' => true]), 200);
ac_assert(
    'the forced delete really removed the row',
    get_post_status($productId) === false ?: 'status is still ' . var_export(get_post_status($productId), true)
);

ac_check(
    'and now the SKU can be used again',
    ac_req('POST', '/products', ['name' => 'Reborn', 'regular_price' => '10', 'sku' => $SKU_OTHER]),
    201
);

// Tidy up, so a re-run starts where it started.
foreach ([$SKU, $SKU_OTHER] as $sku) {
    ac_purge_sku($sku);
}
wp_delete_post($copyId, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
