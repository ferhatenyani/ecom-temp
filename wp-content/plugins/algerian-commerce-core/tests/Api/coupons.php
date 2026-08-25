<?php
/**
 * Coupon endpoints — docs/PLAN.md §21, roadmap step 33, §65.
 *
 * Covers §65's eight API lines, and the two things this step exists to settle:
 * a coupon created here actually discounts a §59b cart, and the discount cap
 * PLAN §21 asks for "where supported" is refused by name rather than silently
 * accepted as something else.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/coupons.php
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, ?array $body = null, array $query = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);
    foreach ($query as $key => $value) { $request->set_param($key, $value); }
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
        if ($verdict !== true) { $ok = false; $detail = ' — ' . (is_string($verdict) ? $verdict : 'body check failed'); }
    }
    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;
    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 60), ' ', str_pad((string) $status, 4);
    if (!$ok) { echo "(expected {$expect}){$detail} ", substr((string) wp_json_encode($data), 0, 260); }
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
    if ($user) { $user->set_role($role); return (int) $user->ID; }
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(24),
                          'user_email' => $login . '@example.test', 'role' => $role]);
    return is_wp_error($id) ? 0 : (int) $id;
}

$CODE = 'ac-test-coupon';
$OTHER = 'ac-test-coupon-2';

foreach ([$CODE, $OTHER] as $code) {
    $id = wc_get_coupon_id_by_code($code);
    if ($id) { wp_delete_post($id, true); }
}

$admin = ac_user('ac_coupon_admin', 'ac_super_admin');
$support = ac_user('ac_coupon_support', 'ac_support_agent');
wp_set_current_user($admin);

echo PHP_EOL, "── success ──", PHP_EOL;

$created = ac_check(
    'create a percentage coupon',
    ac_req('POST', '/coupons', [
        'code' => 'AC-TEST-COUPON', 'discount_type' => 'percent', 'amount' => '15',
        'minimum_amount' => '2000', 'usage_limit' => 100, 'date_expires' => '2027-01-31',
        'individual_use' => true,
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['code'] ?? '') === 'ac-test-coupon'
        ? true : 'code came back as ' . ($d['data']['code'] ?? '?')
);

$id = (int) ($created['data']['id'] ?? 0);
ac_assert('it has an id', $id > 0 ?: 'no id');
ac_assert('the amount is a decimal string', ($created['data']['amount'] ?? '') === '15.00'
    ?: 'amount was ' . var_export($created['data']['amount'] ?? null, true));
ac_assert(
    'an unset limit reads null, not zero',
    array_key_exists('usage_limit_per_user', $created['data'] ?? [])
        && $created['data']['usage_limit_per_user'] === null
        ?: 'usage_limit_per_user was ' . var_export($created['data']['usage_limit_per_user'] ?? 'absent', true)
);
ac_assert('usage_count starts at zero', ($created['data']['usage_count'] ?? -1) === 0 ?: 'usage_count was wrong');

ac_check('read it back', ac_req('GET', "/coupons/{$id}"), 200, static fn (array $d): bool|string
    => ($d['data']['minimum_amount'] ?? '') === '2000.00' ? true : 'minimum_amount did not survive');

ac_check('patch one field', ac_req('PATCH', "/coupons/{$id}", ['amount' => '20']), 200,
    static fn (array $d): bool|string => ($d['data']['amount'] ?? '') === '20.00' ? true : 'amount did not change');

ac_check('the rest survived the patch', ac_req('GET', "/coupons/{$id}"), 200, static fn (array $d): bool|string
    => ($d['data']['usage_limit'] ?? 0) === 100 ? true : 'usage_limit was lost');

/*
 * THE REGRESSION TEST. This failed on the first run, twice over: the presenter
 * emits `date_expires` as ISO 8601 while the input demanded `Y-m-d`, and an
 * empty `maximum_amount` was compared as 0 against a real minimum. A client
 * PATCHing back a body this API had just produced got a 400 about two fields it
 * had never touched.
 */
$whole = ac_req('GET', "/coupons/{$id}")[1]['data'] ?? [];
$whole['description'] = 'Edited whole';
ac_check('the whole GET body can be PATCHed back', ac_req('PATCH', "/coupons/{$id}", $whole), 200,
    static fn (array $d): bool|string => ($d['data']['description'] ?? '') === 'Edited whole'
        ? true : 'the description did not change');
ac_check('...and the expiry survived it', ac_req('GET', "/coupons/{$id}"), 200,
    static fn (array $d): bool|string => str_starts_with((string) ($d['data']['date_expires'] ?? ''), '2027-01-31')
        ? true : 'date_expires became ' . var_export($d['data']['date_expires'] ?? null, true));

ac_check('list coupons', ac_req('GET', '/coupons', null, ['per_page' => 5]), 200,
    static fn (array $d): bool|string => isset($d['meta']['total']) ? true : 'no pagination meta');

echo PHP_EOL, "── bad input ──", PHP_EOL;

ac_check('a coupon needs a code', ac_req('POST', '/coupons', ['amount' => '5']), 400);
ac_check('a coupon needs an amount', ac_req('POST', '/coupons', ['code' => 'X']), 400);
ac_check('an unknown discount type is refused', ac_req('POST', '/coupons', ['code' => 'X', 'amount' => '5', 'discount_type' => 'bogo']), 400);
ac_check('a negative amount is refused', ac_req('POST', '/coupons', ['code' => 'X', 'amount' => '-5']), 400);
ac_check('an unknown field is refused', ac_req('PATCH', "/coupons/{$id}", ['nonsense' => 1]), 400);
ac_check('a bad expiry is refused', ac_req('PATCH', "/coupons/{$id}", ['date_expires' => '2027-02-31']), 400);
ac_check('minimum above maximum is refused', ac_req('PATCH', "/coupons/{$id}", ['minimum_amount' => '900', 'maximum_amount' => '100']), 400);
// Paired with the positive control below — on its own this assertion is passed
// just as happily by a router that validates the parameter and then ignores it.
ac_check('an unknown orderby is refused', ac_req('GET', '/coupons', null, ['orderby' => 'post_password']), 400);

/*
 * PLAN §21's "maximum discount where supported": WooCommerce does not support
 * it, and `maximum_amount` is a different thing. Refused by name so a shop
 * owner cannot set one believing they set the other.
 */
ac_check('a discount cap is refused with the reason', ac_req('POST', '/coupons', [
    'code' => 'X', 'amount' => '5', 'maximum_discount' => '100',
]), 400, static fn (array $d): bool|string => str_contains(
    (string) ($d['error']['details']['fields']['maximum_discount'] ?? ''), 'maximum_amount'
) ? true : 'the reason did not name maximum_amount');

echo PHP_EOL, "── orderby, and the positive control it never had ──", PHP_EOL;

/*
 * **This section exists because its absence was read as a measurement.**
 *
 * The refusal above was the only thing here that touched `orderby`, and a
 * negative alone settles nothing: a repository that validates the parameter and
 * then drops it passes it exactly as a working one does. The panel looked for a
 * positive control, found none, recorded `orderby` as accepted-and-ignored,
 * reproduced that in its mock — with a test pinning the identical sequence — and
 * shipped its coupon list with no sorting. All four values in fact work.
 *
 * **The seeded shop cannot settle it either way.** Its four coupons share one
 * `post_date` and all carry `usage_count` 0, so `date` and `usage` tie on every
 * row and answer identically whatever the repository does. That degeneracy is
 * what made the sort look inert, and it is why this builds fixtures of its own
 * rather than leaning on the seed — the same reason `tests/Api/products.php`
 * builds its own catalogue.
 *
 * The three are given code, id, date and usage orders that are **mutually
 * distinct**, which is the floor: a repository ignoring the parameter returns
 * one sequence for all four values and fails three of them, and one sorting
 * `usage` as text rather than as a number puts 99 before 7 and fails that one.
 */

$SORT_PREFIX = 'ac-sort-';

/* code suffix => [usage_count, post_date]. Deliberately not in id order: ids
   ascend charlie < alpha < bravo, so a `date` sort that silently fell back to
   `id` would still be caught. */
$sortFixtures = [
    'charlie' => [7, '2026-01-03 09:00:00'],
    'alpha' => [99, '2026-01-01 09:00:00'],
    'bravo' => [1, '2026-01-05 09:00:00'],
];

foreach (array_keys($sortFixtures) as $suffix) {
    $stale = wc_get_coupon_id_by_code($SORT_PREFIX . $suffix);
    if ($stale) { wp_delete_post($stale, true); }
}

$sortBuilt = 0;
foreach ($sortFixtures as $suffix => [$usage, $date]) {
    [$status, $body] = ac_req('POST', '/coupons', ['code' => $SORT_PREFIX . $suffix, 'amount' => '5']);
    $newId = (int) ($body['data']['id'] ?? 0);

    if ($status !== 201 || $newId === 0) { continue; }

    // `usage_count` is read-only over the wire — deliberately, it is the shop's
    // count and not the panel's — so it is set through the CRUD class here.
    $coupon = new WC_Coupon($newId);
    $coupon->set_usage_count($usage);
    $coupon->save();

    wp_update_post(['ID' => $newId, 'post_date' => $date, 'post_date_gmt' => get_gmt_from_date($date)]);
    clean_post_cache($newId);
    $sortBuilt++;
}

ac_assert('three fixtures to sort', $sortBuilt === 3 ? true : "built {$sortBuilt}");

$sortOrder = static function (string $orderby, string $order) use ($SORT_PREFIX): array {
    [$status, $body] = ac_req('GET', '/coupons', null, [
        'search' => rtrim($SORT_PREFIX, '-'),
        'per_page' => 20,
        'orderby' => $orderby,
        'order' => $order,
    ]);

    return $status === 200
        ? array_map(static fn (array $row): string => (string) $row['code'], $body['data'] ?? [])
        : [];
};

$expected = [
    'code' => ['alpha', 'bravo', 'charlie'],
    'id' => ['charlie', 'alpha', 'bravo'],
    'date' => ['alpha', 'charlie', 'bravo'],
    'usage' => ['bravo', 'charlie', 'alpha'],
];

ac_assert(
    'the four orders are mutually distinct',
    count(array_unique(array_map(static fn (array $o): string => implode(',', $o), $expected))) === 4
        ? true : 'the fixtures do not separate the four values, so nothing below proves anything',
);

foreach ($expected as $orderby => $ascending) {
    $want = array_map(static fn (string $s): string => $SORT_PREFIX . $s, $ascending);

    $got = $sortOrder($orderby, 'asc');
    ac_assert(
        "orderby={$orderby} really sorts, ascending",
        $got === $want ? true : 'got ' . (implode(',', $got) ?: 'nothing'),
    );

    $gotDesc = $sortOrder($orderby, 'desc');
    ac_assert(
        "...and order=desc reverses it",
        $gotDesc === array_reverse($want) ? true : 'got ' . (implode(',', $gotDesc) ?: 'nothing'),
    );
}

foreach (array_keys($sortFixtures) as $suffix) {
    $leftover = wc_get_coupon_id_by_code($SORT_PREFIX . $suffix);
    if ($leftover) { wp_delete_post($leftover, true); }
}

echo PHP_EOL, "── a percentage cannot exceed 100 ──", PHP_EOL;

ac_check('...on create', ac_req('POST', '/coupons', ['code' => 'X', 'discount_type' => 'percent', 'amount' => '150']), 400);
// The one the pure class cannot see: an amount sent alone against a coupon that
// is *already* a percentage.
ac_check('...and on a patch that sends only the amount', ac_req('PATCH', "/coupons/{$id}", ['amount' => '150']), 400);
ac_check('a fixed_cart coupon may exceed 100', ac_req('POST', '/coupons', [
    'code' => 'AC-TEST-COUPON-2', 'discount_type' => 'fixed_cart', 'amount' => '500',
]), 201);
$otherId = (int) (ac_req('GET', '/coupons', null, ['search' => 'ac-test-coupon-2'])[1]['data'][0]['id'] ?? 0);

echo PHP_EOL, "── unauthenticated and unauthorized ──", PHP_EOL;

wp_set_current_user(0);
ac_check('listing needs a credential', ac_req('GET', '/coupons'), 401);
ac_check('creating needs a credential', ac_req('POST', '/coupons', ['code' => 'X', 'amount' => '1']), 401);

// Support Agent holds ac_manage_customers and ac_view_analytics, never coupons.
wp_set_current_user($support);
ac_check('a Support Agent cannot list coupons', ac_req('GET', '/coupons'), 403);
ac_check('a Support Agent cannot create one', ac_req('POST', '/coupons', ['code' => 'X', 'amount' => '1']), 403);
ac_check('a Support Agent cannot delete one', ac_req('DELETE', "/coupons/{$id}"), 403);
wp_set_current_user($admin);

echo PHP_EOL, "── not found and duplicate ──", PHP_EOL;

ac_check('read a missing coupon', ac_req('GET', '/coupons/99999999'), 404);
ac_check('patch a missing coupon', ac_req('PATCH', '/coupons/99999999', ['amount' => '5']), 404);
ac_check('delete a missing coupon', ac_req('DELETE', '/coupons/99999999'), 404);

ac_check('a duplicate code is a conflict', ac_req('POST', '/coupons', ['code' => 'AC-TEST-COUPON', 'amount' => '5']), 409);
ac_check('a coupon may keep its own code', ac_req('PATCH', "/coupons/{$id}", ['code' => 'AC-TEST-COUPON']), 200);
ac_check('it cannot take another coupon\'s code', ac_req('PATCH', "/coupons/{$id}", ['code' => 'AC-TEST-COUPON-2']), 409);

echo PHP_EOL, "── restrictions: resolved on read, checked on write ──", PHP_EOL;

/*
 * A coupon's restrictions are ids, and until this step every id the API was handed
 * went straight to the database. `{"product_ids": [999999]}` answered 200 and so
 * did a *customer* id — the coupon then applied to nothing and looked, in every
 * response, exactly like a coupon that worked.
 */
$realProduct = (int) (wc_get_products(['status' => 'publish', 'limit' => 1, 'return' => 'ids'])[0] ?? 0);
$realCategory = 0;
$catTerms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1]);
if (is_array($catTerms) && isset($catTerms[0]) && $catTerms[0] instanceof WP_Term) {
    $realCategory = (int) $catTerms[0]->term_id;
}

ac_assert('a real product and a real category to restrict to', $realProduct > 0 && $realCategory > 0
    ?: "product={$realProduct} category={$realCategory}");

ac_check('a product id that exists is accepted', ac_req('PATCH', "/coupons/{$id}", [
    'product_ids' => [$realProduct], 'product_categories' => [$realCategory],
]), 200, static function (array $d) use ($realProduct, $realCategory): bool|string {
    // The positive control for every refusal below. Without it, "bad ids are
    // refused" is satisfied by an endpoint that refuses all of them.
    $r = $d['data']['restrictions'] ?? [];
    $product = $r['product_ids'][0] ?? [];
    $category = $r['product_categories'][0] ?? [];

    if (($product['id'] ?? 0) !== $realProduct || ($category['id'] ?? 0) !== $realCategory) {
        return 'the ids did not come back';
    }
    if (($product['missing'] ?? true) !== false || ($category['missing'] ?? true) !== false) {
        return 'a real id was reported missing';
    }
    if (!is_string($product['name'] ?? null) || ($product['name'] ?? '') === '') {
        return 'the product resolved to no name';
    }
    if (!is_string($category['name'] ?? null) || ($category['name'] ?? '') === '') {
        return 'the category resolved to no name';
    }
    // The whole reason the block exists: a Marketing Manager can read this
    // without ever being able to reach /products.
    return true;
});

ac_check('a product id that does not exist is refused', ac_req('PATCH', "/coupons/{$id}", [
    'product_ids' => [999999999],
]), 400, static function (array $d): bool|string {
    $message = $d['error']['details']['fields']['product_ids'] ?? '';

    return str_contains((string) $message, '999999999')
        ? true
        : 'the refusal should name the id: ' . var_export($message, true);
});

/*
 * An id that exists as a *post* but not as a product. This is the sharper half of
 * the check, and it replaces the obvious version: "a customer id is not a product"
 * looked like a good test and is not one, because **user ids and post ids are
 * separate sequences that collide**. Customer 24 in this shop is also product 24,
 * and customer 13 is a variation — so that test passes or fails on an accident of
 * seeding rather than on anything the endpoint does. A page id cannot be a
 * product by construction.
 */
$pageId = (int) (get_posts(['post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0);

if ($pageId > 0) {
    ac_check('an id that is a page, not a product, is refused', ac_req('PATCH', "/coupons/{$id}", [
        'product_ids' => [$pageId],
    ]), 400);
}

ac_check('a category id that does not exist is refused', ac_req('PATCH', "/coupons/{$id}", [
    'excluded_product_categories' => [999999999],
]), 400);

ac_check('emptying a restriction is still allowed', ac_req('PATCH', "/coupons/{$id}", [
    'product_ids' => [], 'product_categories' => [],
]), 200, static fn (array $d): bool|string => ($d['data']['product_ids'] ?? null) === []
    ? true : 'the list did not clear');

/*
 * Validation on write cannot make a read total. A product deleted after the
 * coupon was written leaves a real, stale id, and the read has to survive it —
 * dropping the row would make a form silently delete the restriction on save.
 */
$doomed = wp_insert_post(['post_type' => 'product', 'post_title' => 'AC coupon doomed product', 'post_status' => 'publish']);
if (is_int($doomed) && $doomed > 0) {
    ac_check('a restriction on a product that exists', ac_req('PATCH', "/coupons/{$id}", [
        'product_ids' => [$doomed],
    ]), 200);

    wp_delete_post($doomed, true);

    ac_check('...survives the product being deleted, as missing', ac_req('GET', "/coupons/{$id}"), 200,
        static function (array $d) use ($doomed): bool|string {
            $row = $d['data']['restrictions']['product_ids'][0] ?? [];

            if (($row['id'] ?? 0) !== $doomed) {
                return 'the stale id was dropped from the read';
            }

            // `?? ` is the wrong operator for a key whose expected value *is* null —
            // it fires on null and hands back the default, so the assertion could
            // never pass. array_key_exists is the one that distinguishes "absent"
            // from "present and null", which is the whole distinction here.
            return ($row['missing'] ?? false) === true
                && array_key_exists('name', $row) && $row['name'] === null
                ? true
                : 'a deleted product should read as missing with no name, got ' . wp_json_encode($row);
        });

    ac_check('...and the stale id can be cleared', ac_req('PATCH', "/coupons/{$id}", ['product_ids' => []]), 200);
}

ac_check('the list does not carry resolved names', ac_req('GET', '/coupons', null, ['per_page' => 1]), 200,
    static fn (array $d): bool|string => !array_key_exists('restrictions', $d['data'][0] ?? ['restrictions' => 1])
        ? true : 'a list row resolved its restrictions');

echo PHP_EOL, "── the picker sources ──", PHP_EOL;

/*
 * The routes this step exists for. `/products` and `/product-categories` are both
 * `ac_manage_products`, which **Marketing Manager does not hold** — so the one
 * role whose job is coupons could not see what a coupon applied to, and no client
 * work could fix it.
 */
$marketing = ac_user('ac_coupon_marketing', 'ac_marketing_manager');

ac_check('the product picker lists products', ac_req('GET', '/coupons/eligible-products'), 200,
    static function (array $d): bool|string {
        $row = $d['data'][0] ?? null;

        if (!is_array($row)) {
            return 'no products';
        }

        // Identity and a label. Nothing priced, nothing stocked — the capability
        // this route sits behind is about coupons, not about the catalogue.
        $leaked = array_diff(array_keys($row), ['id', 'name', 'sku', 'status']);

        return $leaked === [] ? true : 'the picker leaked: ' . implode(', ', $leaked);
    });

ac_check('the category picker lists categories', ac_req('GET', '/coupons/eligible-categories'), 200,
    static fn (array $d): bool|string => is_array($d['data'][0] ?? null) && isset($d['data'][0]['name'])
        ? true : 'no categories');

ac_check('the product picker searches by name', ac_req('GET', '/coupons/eligible-products', null,
    ['search' => 'zzz-nothing-matches-this']), 200,
    static fn (array $d): bool|string => ($d['data'] ?? []) === [] ? true : 'a nonsense search matched');

if ($realProduct > 0) {
    $sku = (string) get_post_meta($realProduct, '_sku', true);

    if ($sku !== '') {
        ac_check('...and by SKU, which a title search cannot', ac_req('GET', '/coupons/eligible-products', null,
            ['search' => $sku]), 200,
            static function (array $d) use ($realProduct): bool|string {
                foreach ($d['data'] ?? [] as $row) {
                    if (($row['id'] ?? 0) === $realProduct) {
                        return true;
                    }
                }

                return 'a shop that knows a product by its SKU got an empty picker';
            });
    }

    ac_check('include resolves a known set in one request', ac_req('GET', '/coupons/eligible-products', null,
        ['include' => (string) $realProduct]), 200,
        static fn (array $d): bool|string => count($d['data'] ?? []) === 1
            && ($d['data'][0]['id'] ?? 0) === $realProduct ? true : 'include did not narrow');
}

ac_check('per_page above the cap is refused here too', ac_req('GET', '/coupons/eligible-products', null,
    ['per_page' => 101]), 400);
ac_check('a malformed include is refused', ac_req('GET', '/coupons/eligible-products', null,
    ['include' => '12,abc']), 400);

wp_set_current_user($marketing);
ac_check('a Marketing Manager reaches the product picker', ac_req('GET', '/coupons/eligible-products'), 200);
ac_check('...and the category picker', ac_req('GET', '/coupons/eligible-categories'), 200);
ac_check('...and reads a coupon\'s resolved names', ac_req('GET', "/coupons/{$id}"), 200,
    static fn (array $d): bool|string => isset($d['data']['restrictions']) ? true : 'no restrictions block');
// The positive control that says the pickers are not simply open: the same role
// is still refused the catalogue they are a narrow substitute for.
ac_check('...while /products stays refused to them', ac_req('GET', '/products'), 403);
ac_check('...and /product-categories too', ac_req('GET', '/product-categories'), 403);

wp_set_current_user($support);
ac_check('a Support Agent reaches neither picker', ac_req('GET', '/coupons/eligible-products'), 403);
ac_check('...nor the category picker', ac_req('GET', '/coupons/eligible-categories'), 403);
wp_set_current_user(0);
ac_check('the pickers need a credential', ac_req('GET', '/coupons/eligible-products'), 401);
wp_set_current_user($admin);

echo PHP_EOL, "── pagination and boundaries ──", PHP_EOL;

ac_check('per_page is honoured', ac_req('GET', '/coupons', null, ['per_page' => 1]), 200,
    static fn (array $d): bool|string => count($d['data'] ?? []) <= 1 ? true : 'too many rows');
ac_check('per_page above the cap is refused', ac_req('GET', '/coupons', null, ['per_page' => 101]), 400);
ac_check('per_page of zero is refused', ac_req('GET', '/coupons', null, ['per_page' => 0]), 400);
ac_check('a page past the end is empty, not an error', ac_req('GET', '/coupons', null, ['page' => 9999]), 200,
    static fn (array $d): bool|string => ($d['data'] ?? []) === [] ? true : 'rows past the end');

echo PHP_EOL, "── it actually discounts a cart ──", PHP_EOL;

/*
 * The reason this step existed: §59b shipped `POST /cart/coupons` against a
 * coupon nothing could create. This is the join.
 */
$productId = 0;
foreach (wc_get_products(['status' => 'publish', 'limit' => 40, 'return' => 'ids']) as $candidate) {
    $product = wc_get_product($candidate);
    if ($product && $product->is_purchasable() && $product->is_in_stock()
        && $product->get_price() !== '' && (float) $product->get_price() > 500) {
        $productId = $candidate;
        break;
    }
}

if ($productId > 0) {
    $cart = ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 1])[1];
    $token = $cart['meta']['cart_token'] ?? '';
    $before = (float) ($cart['data']['totals']['total'] ?? 0);

    $applied = ac_check('a coupon created here applies to a cart',
        ac_req('POST', '/cart/coupons', ['code' => 'AC-TEST-COUPON-2'], ['cart_token' => $token]), 201);

    $after = (float) ($applied['data']['totals']['total'] ?? 0);
    ac_assert('and it took 500 off', abs(($before - $after) - 500.0) < 0.01
        ?: "total went {$before} -> {$after}");
    ac_assert('the discount is reported', ($applied['data']['totals']['discount'] ?? '') === '500.00'
        ?: 'discount was ' . var_export($applied['data']['totals']['discount'] ?? null, true));

    ac_check('and it can be removed', ac_req('DELETE', '/cart/coupons/ac-test-coupon-2', null, ['cart_token' => $token]), 200,
        static fn (array $d): bool|string => ($d['data']['coupons'] ?? []) === [] ? true : 'the coupon stayed');

    ac_req('DELETE', '/cart', null, ['cart_token' => $token]);
} else {
    ac_assert('a product over 500 to discount', 'none found — skipped the cart join');
}

echo PHP_EOL, "── delete ──", PHP_EOL;

ac_check('delete trashes by default', ac_req('DELETE', "/coupons/{$id}"), 200);
// The §65 lesson from product SKUs, applied: a trashed coupon keeps its code.
ac_check('a trashed code is still a conflict', ac_req('POST', '/coupons', ['code' => 'AC-TEST-COUPON', 'amount' => '5']), 409);
ac_check('force=true removes it', ac_req('DELETE', "/coupons/{$id}", null, ['force' => true]), 200);
ac_check('and then the code is free again', ac_req('POST', '/coupons', ['code' => 'AC-TEST-COUPON', 'amount' => '5']), 201);

foreach ([$CODE, $OTHER] as $code) {
    $leftover = wc_get_coupon_id_by_code($code);
    if ($leftover) { wp_delete_post($leftover, true); }
}
if ($otherId > 0) { wp_delete_post($otherId, true); }

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) { exit(1); }
