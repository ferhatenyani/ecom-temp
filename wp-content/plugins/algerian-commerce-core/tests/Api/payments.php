<?php
/**
 * Payment endpoints and the Chargily webhook against a real WordPress +
 * WooCommerce install — roadmap §59, §60, §65 (API and Security categories).
 *
 * Covers what unit tests structurally cannot: authorization (401/403), the
 * transaction table under a real `$wpdb`, the order actually moving to paid,
 * and — the two §60 requires — **webhook forgery** and **webhook replay**
 * through the route rather than through the adapter.
 *
 * **No network.** The money path is exercised with cash on delivery, which is a
 * real provider with no HTTP behind it; the Chargily route is exercised on the
 * paths that end before the re-fetch. Chargily's own request and response
 * shapes are covered against recorded bodies in tests/Unit/ChargilyProviderTest,
 * and against the live test API by hand — see the §59 notes in the README.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here — those live
 * in scripts/test-api.sh, over real HTTP.
 *
 *   scripts/test.sh                                 # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/payments.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array|string|null $body = null, array $query = [], array $headers = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    foreach ($headers as $name => $value) {
        $request->set_header($name, $value);
    }

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        // The raw bytes matter here: a webhook signature is over exactly what
        // was sent, so the body is set as a string and never re-encoded.
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

function ac_product(string $sku, string $price, int $stock): WC_Product
{
    $id = (int) wc_get_product_id_by_sku($sku);
    $product = $id > 0 ? wc_get_product($id) : new WC_Product_Simple();

    $product->set_name('Payment test ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return wc_get_product($product->get_id());
}

function ac_order(int $productId, int $customer, string $status = 'pending'): int
{
    [, $body] = ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        'customer_id' => $customer,
        'payment_method' => 'cod',
        'status' => $status,
        'billing' => ['first_name' => 'Yacine', 'phone' => '0551020304', 'country' => 'DZ'],
    ]);

    return (int) ($body['data']['id'] ?? 0);
}

function ac_transactions(int $orderId): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ac_payment_transactions WHERE order_id = %d ORDER BY id ASC",
            $orderId
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
}

function ac_events(): int
{
    global $wpdb;

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ac_webhook_events");
}

/** A Chargily event, signed the way Chargily signs one. */
function ac_chargily_event(array $overrides = []): array
{
    $secret = (string) getenv('CHARGILY_SECRET_KEY');

    $event = array_replace([
        'id' => 'evt_' . wp_generate_password(20, false),
        'entity' => 'event',
        'livemode' => false,
        'type' => 'checkout.paid',
        'data' => [
            'id' => 'chk_' . wp_generate_password(20, false),
            'entity' => 'checkout',
            'amount' => 2000,
            'status' => 'paid',
        ],
        'created_at' => time(),
        'updated_at' => time(),
    ], $overrides);

    $raw = (string) wp_json_encode($event);

    return [$raw, ['signature' => hash_hmac('sha256', $raw, $secret)]];
}

/*
 * Admin, not Manager. `ac_manage_payments` is deliberately not in the Manager
 * role: running the shop day to day — products, stock, orders, customers,
 * parcels — does not include reaching into what a gateway did with somebody's
 * money. Manager is exercised below as a negative case for exactly that reason.
 */
$admin = ac_user('ac_pay_admin', 'ac_admin');                // has ac_manage_payments
$manager = ac_user('ac_pay_manager', 'ac_manager');          // runs the shop, but not payments
$support = ac_user('ac_pay_support', 'ac_support_agent');    // neither
$customer = ac_user('ac_pay_customer', 'customer');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET payment methods signed out', ac_req('GET', '/payments/methods'), 401);
ac_check('GET payments signed out', ac_req('GET', '/payments'), 401);
ac_check('POST a payment signed out', ac_req('POST', '/orders/1/payments', ['provider' => 'cod']), 401);

wp_set_current_user($support);
ac_check('GET payment methods as support agent', ac_req('GET', '/payments/methods'), 403);
ac_check('GET payments as support agent', ac_req('GET', '/payments'), 403);
ac_check('POST a payment as support agent', ac_req('POST', '/orders/1/payments', ['provider' => 'cod']), 403);

// Least privilege in the direction that is easy to get wrong: Manager can do
// almost everything operational and still may not read the payment ledger.
wp_set_current_user($manager);
ac_check('GET payments as shop manager', ac_req('GET', '/payments'), 403);
ac_check('POST a payment as shop manager', ac_req('POST', '/orders/1/payments', ['provider' => 'cod']), 403);

wp_set_current_user($admin);

echo PHP_EOL, "=== methods ===", PHP_EOL;

$methods = ac_check('GET payment methods', ac_req('GET', '/payments/methods'), 200, static function ($data): bool|string {
    $names = array_column($data['data'] ?? [], 'name');

    return in_array('cod', $names, true) ? true : 'cod missing from ' . implode(',', $names);
});

$hasChargily = in_array('chargily', array_column($methods['data'] ?? [], 'name'), true);
echo '  chargily registered: ', $hasChargily ? 'yes' : 'no (ENABLE_CHARGILY / key unset)', PHP_EOL;

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$lamp = ac_product('AC-PAY-LAMP', '2000', 200);
$orderId = ac_order($lamp->get_id(), $customer);
ac_assert('an order to be paid for', $orderId > 0 ?: 'no order created');

echo PHP_EOL, "=== creating a payment ===", PHP_EOL;

ac_check('POST a payment for a missing order', ac_req('POST', '/orders/99999999/payments', ['provider' => 'cod']), 404);
ac_check(
    'POST a payment with an unknown provider',
    ac_req('POST', "/orders/{$orderId}/payments", ['provider' => 'paypal']),
    400
);
ac_check(
    'POST a payment with an unknown field',
    ac_req('POST', "/orders/{$orderId}/payments", ['provider' => 'cod', 'amount' => '1.00']),
    400
);
// An open redirect with a payment provider's credibility behind it.
ac_check(
    'POST a payment with a plaintext return URL',
    ac_req('POST', "/orders/{$orderId}/payments", ['provider' => 'cod', 'return_url' => 'http://evil.test']),
    400
);

$created = ac_check(
    'POST a payment',
    ac_req('POST', "/orders/{$orderId}/payments", ['provider' => 'cod', 'reference' => 'AC-1']),
    201,
    static fn ($data): bool|string => ($data['data']['status'] ?? '') === 'pending'
        ? true
        : 'a created payment must not be a paid one'
);

$rows = ac_transactions($orderId);
ac_assert('the attempt is recorded', count($rows) === 1 ?: 'expected 1 row, got ' . count($rows));
ac_assert('with the order amount', ($rows[0]['amount'] ?? '') === '2000.00' ?: 'amount was ' . ($rows[0]['amount'] ?? ''));
ac_assert('and the provider reference', ($rows[0]['provider_transaction_id'] ?? '') !== '' ?: 'no provider id stored');

$transactionId = (int) ($rows[0]['id'] ?? 0);

echo PHP_EOL, "=== reading payments ===", PHP_EOL;

ac_check('GET the payment', ac_req('GET', "/payments/{$transactionId}"), 200);
ac_check('GET a payment that does not exist', ac_req('GET', '/payments/99999999'), 404);
ac_check(
    'GET payments for the order',
    ac_req('GET', "/orders/{$orderId}/payments"),
    200,
    static fn ($data): bool => count($data['data'] ?? []) === 1
);
ac_check(
    'GET payments filtered by status',
    ac_req('GET', '/payments', null, ['status' => 'pending', 'order_id' => $orderId]),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? 0) >= 1
);
ac_check(
    'GET payments with an unknown status',
    ac_req('GET', '/payments', null, ['status' => 'authorized']),
    400
);
ac_check(
    'GET payments with an oversized page size',
    ac_req('GET', '/payments', null, ['per_page' => 100000]),
    400
);

echo PHP_EOL, "=== verification ===", PHP_EOL;

// Cash on delivery has nobody to ask, so it answers pending forever — which is
// the honest answer and leaves the order where it is.
ac_check(
    'POST verify',
    ac_req('POST', "/payments/{$transactionId}/verify"),
    200,
    static fn ($data): bool|string => ($data['data']['transaction']['status'] ?? '') === 'pending'
        ? true
        : 'COD must not settle itself'
);

$order = wc_get_order($orderId);
ac_assert('the order is still unpaid', $order->get_date_paid() === null ?: 'COD marked an order paid');

echo PHP_EOL, "=== webhooks ===", PHP_EOL;

ac_check('a webhook route for a provider nobody registered', ac_req('POST', '/webhooks/stripe'), 404);
ac_check(
    'a webhook to a provider that receives none',
    ac_req('POST', '/webhooks/cod', ['anything' => true]),
    400,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'webhook_unsupported'
);

if (!$hasChargily) {
    echo '  skipping the Chargily webhook cases — the provider is not registered', PHP_EOL;
} else {
    // Forgery: a well-formed event with a signature that is not ours.
    [$raw] = ac_chargily_event();
    ac_check(
        'an unsigned event',
        ac_req('POST', '/webhooks/chargily', $raw),
        401,
        static fn ($data): bool => ($data['error']['code'] ?? '') === 'webhook_unverified'
    );
    ac_check(
        'an event signed with the wrong key',
        ac_req('POST', '/webhooks/chargily', $raw, [], ['signature' => hash_hmac('sha256', $raw, 'not-the-key')]),
        401
    );

    // Tampering: a real signature against a body that has been edited.
    [$raw, $headers] = ac_chargily_event();
    ac_check(
        'a tampered body with a real signature',
        ac_req('POST', '/webhooks/chargily', str_replace('2000', '1', $raw), [], $headers),
        401
    );

    // Replay: a genuine event, correctly signed, sent again ten minutes later.
    [$stale, $staleHeaders] = ac_chargily_event(['created_at' => time() - 600]);
    $staleAnswer = ac_check('a stale event, correctly signed', ac_req('POST', '/webhooks/chargily', $stale, [], $staleHeaders), 401);
    ac_assert(
        'and it is told nothing about which check failed',
        ($staleAnswer['error']['code'] ?? '') === 'webhook_unverified'
            && !str_contains(strtolower((string) ($staleAnswer['error']['message'] ?? '')), 'timestamp')
            ?: 'the failure named its own cause'
    );

    // A verified event about a payment this shop has never heard of: 200 and
    // dropped, so the gateway stops retrying over a gap on our side.
    $before = ac_events();
    [$unknown, $unknownHeaders] = ac_chargily_event();
    ac_check(
        'a verified event for an unknown payment',
        ac_req('POST', '/webhooks/chargily', $unknown, [], $unknownHeaders),
        200,
        static fn ($data): bool => ($data['data']['status'] ?? '') === 'unknown_payment'
    );
    ac_assert('the event id was claimed', ac_events() === $before + 1 ?: 'no claim was written');

    // The same delivery again — the claim is refused and the event is dropped.
    ac_check(
        'the same event delivered twice',
        ac_req('POST', '/webhooks/chargily', $unknown, [], $unknownHeaders),
        200,
        static fn ($data): bool => ($data['data']['status'] ?? '') === 'duplicate'
    );
    ac_assert('and no second claim was written', ac_events() === $before + 1 ?: 'the claim raced');

    // A verified event of a type we do not handle.
    [$other, $otherHeaders] = ac_chargily_event(['type' => 'customer.created']);
    ac_check(
        'a verified event of an unhandled type',
        ac_req('POST', '/webhooks/chargily', $other, [], $otherHeaders),
        200,
        static fn ($data): bool => ($data['data']['status'] ?? '') === 'ignored'
    );
}

echo PHP_EOL, "=== paying an order twice ===", PHP_EOL;

// Settle the transaction directly, which is what a verified gateway answer
// would have done, then check the second checkout is refused.
global $wpdb;
$wpdb->update(
    $wpdb->prefix . 'ac_payment_transactions',
    ['status' => 'paid'],
    ['id' => $transactionId],
    ['%s'],
    ['%d']
);

ac_check(
    'POST a second payment for a paid order',
    ac_req('POST', "/orders/{$orderId}/payments", ['provider' => 'cod']),
    409,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'payment_already_settled'
);

// Put it back, so a re-run of this file starts where it started.
$wpdb->update(
    $wpdb->prefix . 'ac_payment_transactions',
    ['status' => 'pending'],
    ['id' => $transactionId],
    ['%s'],
    ['%d']
);

echo PHP_EOL, "=== cancelled orders ===", PHP_EOL;

// Created pending and then cancelled: `cancelled` is not a status an order may
// be created *as* (OrderStatus::CREATABLE), which is the order lifecycle
// refusing to invent history.
$deadOrder = ac_order($lamp->get_id(), $customer);
ac_check('cancel it', ac_req('PATCH', "/orders/{$deadOrder}", ['status' => 'cancelled']), 200);
ac_check(
    'POST a payment for a cancelled order',
    ac_req('POST', "/orders/{$deadOrder}/payments", ['provider' => 'cod']),
    409
);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
