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
