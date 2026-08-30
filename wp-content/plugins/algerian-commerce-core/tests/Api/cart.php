<?php
/**
 * Cart and checkout — roadmap §59b, §65.
 *
 * Covers §65's eight API lines against the cart, plus the three properties the
 * section exists for: **prices come from the catalogue**, **a cart survives
 * only with its own token**, and **a forged token opens an empty cart rather
 * than somebody else's**.
 *
 * The suite creates its own product and its own §14 shipping rule rather than
 * leaning on whatever the database happens to hold — a cart assertion against a
 * catalogue that changes underneath it is a test that fails on Tuesdays.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/cart.php
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

        // rest_do_request() parses a JSON body for declared args, but the
        // suite also asserts on undeclared ones (a client-sent `price`), so
        // both paths are populated.
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
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

// ------------------------------------------------------------------ fixtures --
global $wpdb;

$SKU = 'ac-cart-fixture';

foreach (['publish', 'draft', 'trash'] as $status) {
    foreach (wc_get_products(['sku' => $SKU, 'status' => $status, 'limit' => 20, 'return' => 'ids']) as $id) {
        wp_delete_post((int) $id, true);
    }
}

$product = new WC_Product_Simple();
$product->set_name('AC cart fixture');
$product->set_sku($SKU);
$product->set_regular_price('1000.00');
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->set_status('publish');
$productId = $product->save();

ac_assert('a fixture product exists', $productId > 0 ?: 'could not create one');
ac_assert('it needs shipping', wc_get_product($productId)->needs_shipping() ?: 'the fixture is virtual');

// §14's tariff. Deleted first so a re-run does not stack rules.
$rates = $wpdb->prefix . 'ac_shipping_rates';
$wpdb->query("DELETE FROM {$rates} WHERE amount = '450.00' AND wilaya_id = 0 AND provider = ''");
$wpdb->insert($rates, [
    'provider' => '', 'wilaya_id' => 0, 'commune_id' => 0, 'delivery_type' => 'home',
    'amount' => '450.00', 'free_over' => null, 'estimated_days' => 3, 'is_active' => 1,
    'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true),
]);
ac_assert('a national shipping rule exists', $wpdb->insert_id > 0 ?: 'could not insert one');

echo PHP_EOL, "── success ──", PHP_EOL;

$empty = ac_check('an anonymous cart is empty', ac_req('GET', '/cart'), 200, static function (array $d): bool|string {
    return ($d['data']['items_count'] ?? -1) === 0 ? true : 'items_count was not 0';
});
ac_assert(
    'an empty cart is issued no token',
    !isset($empty['meta']['cart_token']) ?: 'a token was minted for an empty cart'
);

$added = ac_check(
    'add a line',
    ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 2]),
    201,
    static function (array $d): bool|string {
        return ($d['data']['items_count'] ?? 0) === 2 && ($d['data']['totals']['subtotal'] ?? '') === '2000.00'
            ? true
            : 'got ' . wp_json_encode($d['data']['totals'] ?? null);
    }
);

$token = (string) ($added['meta']['cart_token'] ?? '');
$key = (string) ($added['data']['items'][0]['key'] ?? '');

ac_assert('a token comes back with a non-empty cart', $token !== '' ?: 'no token');
ac_assert('the line has a 32-character key', preg_match('/^[a-f0-9]{32}$/', $key) === 1 ?: "key was '{$key}'");
ac_assert(
    'the line reports the catalogue price',
    ($added['data']['items'][0]['price'] ?? '') === '1000.00'
        ?: 'price was ' . var_export($added['data']['items'][0]['price'] ?? null, true)
);
ac_assert(
    'needs_shipping is true for a physical product',
    ($added['data']['needs_shipping'] ?? null) === true
        ?: 'reported ' . var_export($added['data']['needs_shipping'] ?? null, true)
        . ' — WC_Cart::needs_shipping() counts shipping zones, and §14 has none'
);

// **The property the whole token mechanism exists for.**
ac_check(
    'the cart survives into another request with its token',
    ac_req('GET', '/cart', null, ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? 0) === 2
        ? true
        : 'items_count was ' . var_export($d['data']['items_count'] ?? null, true)
);

ac_check(
    'quantity can be changed',
    ac_req('PATCH', "/cart/items/{$key}", ['quantity' => 5], ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? 0) === 5
        && ($d['data']['totals']['subtotal'] ?? '') === '5000.00'
        ? true
        : 'got ' . wp_json_encode($d['data']['totals'] ?? null)
);

ac_check(
    'quantity zero removes the line',
    ac_req('PATCH', "/cart/items/{$key}", ['quantity' => 0], ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? -1) === 0 ? true : 'the line survived'
);

$again = ac_check(
    're-add for the rest of the suite',
    ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 2], ['cart_token' => $token]),
    201
);
$key = (string) ($again['data']['items'][0]['key'] ?? '');
$token = (string) ($again['meta']['cart_token'] ?? $token);

echo PHP_EOL, "── the prices are the shop's ──", PHP_EOL;

/*
 * §59b's rule, at the boundary. `LineInput` proves the refusal in a unit test;
 * this proves it survives the route, the arg schema and the JSON body — and,
 * more importantly, that the cart total did not move.
 */
foreach (['price', 'line_total', 'line_subtotal', 'subtotal', 'total', 'discount', 'currency'] as $field) {
    ac_check(
        "a client-sent {$field} is refused" . str_pad('', 22 - strlen($field)),
        ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 1, $field => '0.01'], ['cart_token' => $token]),
        400
    );
}

ac_check(
    'and the total is still the catalogue price',
    ac_req('GET', '/cart', null, ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['totals']['subtotal'] ?? '') === '2000.00'
        ? true
        : 'subtotal is now ' . var_export($d['data']['totals']['subtotal'] ?? null, true)
);

echo PHP_EOL, "── the token is the owner ──", PHP_EOL;

ac_check(
    'a forged token opens an empty cart, not another one',
    ac_req('GET', '/cart', null, ['cart_token' => 'eyJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjoidF9mYWtlIn0.forged']),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? -1) === 0
        ? true
        : 'a forged token reached a cart holding ' . $d['data']['items_count'] . ' items'
);

ac_check(
    'a nonsense token is not an error, just a new cart',
    ac_req('GET', '/cart', null, ['cart_token' => 'not-a-token']),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? -1) === 0 ? true : 'items came back'
);

// The control: without it the two assertions above pass against a route that
// simply always returns an empty cart.
ac_check(
    'the real token still reaches the real cart',
    ac_req('GET', '/cart', null, ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? 0) === 2 ? true : 'the real cart was lost'
);

echo PHP_EOL, "── bad input ──", PHP_EOL;

ac_check('a missing product id is refused', ac_req('POST', '/cart/items', ['quantity' => 1]), 400);
ac_check('quantity zero on add is refused', ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 0]), 400);
ac_check('a negative quantity is refused', ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => -3]), 400);
ac_check('quantity over the cap is refused', ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 1000]), 400);
ac_check('an unknown field is refused', ac_req('POST', '/cart/items', ['product_id' => $productId, 'nonsense' => 1]), 400);
ac_check('a malformed line key is a 404 from the router', ac_req('PATCH', '/cart/items/nope', ['quantity' => 1]), 404);

echo PHP_EOL, "── not found ──", PHP_EOL;

ac_check(
    'a product that does not exist',
    ac_req('POST', '/cart/items', ['product_id' => 99999999, 'quantity' => 1], ['cart_token' => $token]),
    404
);
ac_check(
    'a line key that is not in this cart',
    ac_req('DELETE', '/cart/items/' . str_repeat('a', 32), null, ['cart_token' => $token]),
    404
);
ac_check(
    'removing a coupon that is not applied',
    ac_req('DELETE', '/cart/coupons/nosuchcode', null, ['cart_token' => $token]),
    404
);

// A draft product exists but cannot be bought — a different answer from 404,
// and the one that tells a storefront developer what is actually wrong.
$draft = new WC_Product_Simple();
$draft->set_name('AC cart draft');
$draft->set_regular_price('50.00');
$draft->set_status('draft');
$draftId = $draft->save();

ac_check(
    'an unpublished product is refused, not "missing"',
    ac_req('POST', '/cart/items', ['product_id' => $draftId, 'quantity' => 1], ['cart_token' => $token]),
    400
);

echo PHP_EOL, "── stock ──", PHP_EOL;

ac_check(
    'more than the shop holds is refused',
    ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 500], ['cart_token' => $token]),
    400,
    static fn (array $d): bool|string => str_contains(
        strtolower((string) wp_json_encode($d['error']['details']['fields'] ?? [])),
        'stock'
    ) ? true : 'the reason did not mention stock: ' . wp_json_encode($d['error']['details'] ?? null)
);

$outOfStock = new WC_Product_Simple();
$outOfStock->set_name('AC cart sold out');
$outOfStock->set_regular_price('50.00');
$outOfStock->set_stock_status('outofstock');
$outOfStock->set_status('publish');
$soldOutId = $outOfStock->save();

ac_check(
    'a sold-out product is refused',
    ac_req('POST', '/cart/items', ['product_id' => $soldOutId, 'quantity' => 1], ['cart_token' => $token]),
    400
);

echo PHP_EOL, "── checkout ──", PHP_EOL;

$quoted = ac_check(
    'shipping is quoted from §14, not from WooCommerce zones',
    ac_req('GET', '/checkout/shipping-rates', null, [
        'cart_token' => $token, 'wilaya_id' => 16, 'commune_id' => 0, 'delivery_type' => 'home',
    ]),
    200,
    static function (array $d): bool|string {
        $rates = $d['data']['rates'] ?? [];

        return $rates !== [] && ($rates[0]['amount'] ?? '') === '450.00'
            ? true
            : 'rates came back as ' . wp_json_encode($rates);
    }
);

ac_assert(
    'the quote used the cart subtotal, not a client-sent one',
    ($quoted['data']['subtotal'] ?? '') === '2000.00'
        ?: 'subtotal was ' . var_export($quoted['data']['subtotal'] ?? null, true)
);

ac_check(
    'a checkout with no address is refused',
    ac_req('POST', '/checkout', ['wilaya_id' => 16], ['cart_token' => $token]),
    400
);

ac_check(
    'an unknown payment method is refused',
    ac_req('POST', '/checkout', [
        'billing' => ['first_name' => 'A', 'last_name' => 'B', 'address_1' => '1 rue', 'city' => 'Alger',
                      'country' => 'DZ', 'phone' => '0551020304', 'email' => 'a@example.test'],
        'wilaya_id' => 16, 'payment_method' => 'bitcoin',
    ], ['cart_token' => $token]),
    400
);

$placed = ac_check(
    'a checkout creates an order',
    ac_req('POST', '/checkout', [
        'billing' => ['first_name' => 'Amina', 'last_name' => 'B', 'address_1' => '12 rue X', 'city' => 'Alger',
                      'country' => 'DZ', 'phone' => '0551020304', 'email' => 'amina@example.test'],
        'wilaya_id' => 16, 'commune_id' => 0, 'delivery_type' => 'home',
    ], ['cart_token' => $token]),
    201,
    static fn (array $d): bool|string => (int) ($d['data']['order']['id'] ?? 0) > 0 ? true : 'no order id'
);

$orderId = (int) ($placed['data']['order']['id'] ?? 0);

if ($orderId > 0) {
    $order = wc_get_order($orderId);

    // 2 × 1000 goods + 450 delivery. The whole point of the section: the
    // shipping charge is on the order and comes from the §14 rule.
    ac_assert(
        'the order total includes the §14 shipping charge',
        (string) wc_format_decimal($order->get_total(), 2) === '2450.00'
            ?: 'total was ' . $order->get_total() . ' (expected 2450.00 = 2000 goods + 450 delivery)'
    );
    ac_assert(
        'the shipping charge is a real line item',
        count($order->get_items('shipping')) === 1 ?: 'shipping items: ' . count($order->get_items('shipping'))
    );

    /*
     * Backend step 4 gave the *back office* a settable delivery fee, written as
     * a shipping line the same way this one is. The storefront must be exactly
     * as it was, and "exactly" is worth pinning rather than assuming: a
     * shopper's payload has no `shipping_amount` and never will — what a
     * customer pays is §14's tariff, which is the whole rule
     * `Cart\LineInput` enforces for line prices — so the only thing that could
     * have regressed here is the line itself.
     *
     * The distinguishing mark is the `_ac_manual_price` meta and nothing else.
     * A quoted line carries none, which is what makes `OrderPresenter` report a
     * checkout order's `shipping_amount` as null while `shipping_total` shows
     * the money that was really charged. If a future change ever starts writing
     * that meta here, every quoted fee in the order book begins reading as
     * somebody's decision and `OrderService::manualPrices()` — which is what
     * puts a stated fee into an `order.created` or `order.updated` row — can no
     * longer tell the two apart.
     */
    $quotedLine = array_values($order->get_items('shipping'))[0] ?? null;

    ac_assert(
        'the quoted charge is not marked as stated by a person',
        ($quotedLine !== null && $quotedLine->get_meta('_ac_manual_price', true) === '')
            ?: 'a checkout quote must carry no manual-price meta'
    );

    $presented = \AlgerianCommerce\Orders\OrderPresenter::toArray($order);

    ac_assert(
        'so the order reports a shipping_total and no shipping_amount',
        ($presented['shipping_amount'] === null && $presented['shipping_total'] === '450.00')
            ?: 'presented as ' . wp_json_encode([
                'shipping_amount' => $presented['shipping_amount'] ?? null,
                'shipping_total' => $presented['shipping_total'] ?? null,
            ])
    );
    ac_assert(
        'the destination is recorded on the order',
        (int) $order->get_meta('_ac_wilaya_id') === 16 ?: 'wilaya meta was ' . var_export($order->get_meta('_ac_wilaya_id'), true)
    );
    ac_assert(
        'the shipping address defaulted to billing',
        $order->get_shipping_city() === 'Alger' ?: 'shipping city was ' . var_export($order->get_shipping_city(), true)
    );
    ac_assert(
        'the order is pending, not paid',
        $order->get_status() === 'pending' ?: 'status was ' . $order->get_status()
    );
    ac_assert(
        'checkout hands off to §58 rather than taking the money itself',
        ($placed['data']['next']['endpoint'] ?? '') === "/orders/{$orderId}/payments"
            ?: 'next was ' . wp_json_encode($placed['data']['next'] ?? null)
    );

    $order->delete(true);
}

ac_check(
    'the cart is empty after checkout',
    ac_req('GET', '/cart', null, ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? -1) === 0 ? true : 'the cart survived checkout'
);

ac_check(
    'checking out an empty cart is refused',
    ac_req('POST', '/checkout', [
        'billing' => ['first_name' => 'A', 'last_name' => 'B', 'address_1' => '1 rue', 'city' => 'Alger',
                      'country' => 'DZ', 'phone' => '0551020304', 'email' => 'a@example.test'],
        'wilaya_id' => 16,
    ], ['cart_token' => $token]),
    400
);

echo PHP_EOL, "── clear ──", PHP_EOL;

ac_req('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 1], ['cart_token' => $token]);
ac_check(
    'DELETE /cart empties it',
    ac_req('DELETE', '/cart', null, ['cart_token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['items_count'] ?? -1) === 0 ? true : 'items remained'
);

// ------------------------------------------------------------------ cleanup --
foreach ([$productId, $draftId, $soldOutId] as $id) {
    if ($id > 0) {
        wp_delete_post((int) $id, true);
    }
}
$wpdb->query("DELETE FROM {$rates} WHERE amount = '450.00' AND wilaya_id = 0 AND provider = ''");

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
