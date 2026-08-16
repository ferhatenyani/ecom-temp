<?php
/**
 * Marketing endpoints and the event queue — roadmap §62b, §65.
 *
 * Covers what unit tests structurally cannot: authorization, the claim under a
 * real `$wpdb`, and the one behaviour the whole section exists for — **the same
 * order produces one queued conversion no matter how many times it is
 * reported**. A double-clicked pay button, a refreshed confirmation page and a
 * second browser tab are one sale, and a read-then-write would race exactly
 * there.
 *
 * **No network.** No Meta credentials exist in this project, so the provider is
 * usually absent and the queue simply stays empty — which is itself the case to
 * assert, because a shop without an ad account is the normal case. Where a
 * provider *is* configured, the extra assertions run. Meta's request and
 * response shapes are covered against recorded bodies in
 * tests/Unit/MetaProviderTest.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/marketing.php
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

function ac_queued(int $orderId): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ac_marketing_events WHERE order_id = %d ORDER BY id ASC",
            $orderId
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
}

$marketing = ac_user('ac_mkt_marketing', 'ac_marketing_manager');  // has ac_manage_marketing
$orders = ac_user('ac_mkt_orders', 'ac_order_manager');            // runs orders, not marketing
$admin = ac_user('ac_mkt_admin', 'ac_admin');                      // builds the fixture order
$customer = ac_user('ac_mkt_customer', 'customer');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /marketing/config signed out', ac_req('GET', '/marketing/config'), 401);
ac_check('POST a purchase event signed out', ac_req('POST', '/marketing/events/purchase', ['order_id' => 1]), 401);

// Least privilege in the direction that is easy to get wrong: the person who
// runs the order book does not report conversions to an ad account.
wp_set_current_user($orders);
ac_check('GET /marketing/config as order manager', ac_req('GET', '/marketing/config'), 403);
ac_check(
    'POST a purchase event as order manager',
    ac_req('POST', '/marketing/events/purchase', ['order_id' => 1]),
    403
);

wp_set_current_user($marketing);

echo PHP_EOL, "=== config ===", PHP_EOL;

$config = ac_check('GET /marketing/config', ac_req('GET', '/marketing/config'), 200, static function ($data): bool|string {
    $body = $data['data'] ?? [];

    if (!array_key_exists('enabled', $body) || !is_array($body['providers'] ?? null)) {
        return 'the config has no enabled flag or provider list';
    }

    return in_array('Purchase', $body['server_events'] ?? [], true)
        ? true
        : 'Purchase is not listed as a server event';
});

$hasMeta = in_array('meta', array_column($config['data']['providers'] ?? [], 'name'), true);
echo '  meta registered: ', $hasMeta ? 'yes' : 'no (ENABLE_MARKETING_PIXELS / credentials unset)', PHP_EOL;

// A shop without an ad account is the normal case, not a misconfiguration:
// the answer is "off", never an error.
ac_assert(
    'a shop with no pixel is reported as off, not broken',
    $hasMeta || ($config['data']['enabled'] ?? true) === false
        ?: 'enabled should be false when nothing is configured'
);

// Whatever is configured, the credential must never appear in a response.
$token = (string) getenv('META_CAPI_ACCESS_TOKEN');
ac_assert(
    'the access token is never in the config',
    $token === '' || !str_contains((string) wp_json_encode($config), $token)
        ?: 'the access token reached a response body'
);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

/*
 * Built as an administrator, not as the marketing manager. `ac_manage_marketing`
 * deliberately does not carry `ac_manage_orders` — the person who reports
 * conversions does not write the order book — which the authorization block
 * above asserts from the other direction.
 */
wp_set_current_user($admin);

$product = wc_get_product((int) wc_get_product_id_by_sku('AC-MKT-TAPIS'));

if (!$product) {
    $product = new WC_Product_Simple();
    $product->set_sku('AC-MKT-TAPIS');
}

$product->set_name('Tapis marketing');
$product->set_regular_price('2500');
$product->set_status('publish');
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product->save();

[, $created] = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $product->get_id(), 'quantity' => 2]],
    'customer_id' => $customer,
    'payment_method' => 'cod',
    'billing' => [
        'first_name' => 'Mohamed',
        'last_name' => 'Bensalem',
        'email' => 'mohamed@example.test',
        'phone' => '0551020304',
        'city' => 'Alger',
        'country' => 'DZ',
    ],
]);

$orderId = (int) ($created['data']['id'] ?? 0);
ac_assert('an order to report', $orderId > 0 ?: 'no order created — ' . wp_json_encode($created));

// Back to the role that is actually allowed to report a conversion.
wp_set_current_user($marketing);

echo PHP_EOL, "=== recording a purchase ===", PHP_EOL;

ac_check(
    'POST a purchase for an order that does not exist',
    ac_req('POST', '/marketing/events/purchase', ['order_id' => 99999999]),
    404
);

ac_check('POST a purchase with no order id', ac_req('POST', '/marketing/events/purchase', []), 400);
ac_check(
    'POST a purchase with a malformed fbp',
    ac_req('POST', '/marketing/events/purchase', ['order_id' => $orderId, 'fbp' => 'not-a-cookie']),
    400
);
ac_check(
    'POST a purchase with a bogus client ip',
    ac_req('POST', '/marketing/events/purchase', ['order_id' => $orderId, 'client_ip_address' => 'nope']),
    400
);

$first = ac_check(
    'POST a purchase',
    ac_req('POST', '/marketing/events/purchase', [
        'order_id' => $orderId,
        'fbp' => 'fb.1.1755300000.123456789',
        'client_ip_address' => '41.100.1.2',
        'client_user_agent' => 'Mozilla/5.0',
        'event_source_url' => 'https://boutique.dz/merci',
    ]),
    200,
    static fn ($data): bool|string => ($data['data']['event_name'] ?? '') === 'Purchase'
        && ($data['data']['event_id'] ?? '') !== ''
            ? true
            : 'no event id was minted'
);

$eventId = (string) ($first['data']['event_id'] ?? '');

// The dedup contract: the backend mints the id, the storefront is told, and the
// same order yields the same string forever — otherwise the browser copy and
// the server copy would be two conversions.
ac_check(
    'the same order mints the same event id',
    ac_req('POST', '/marketing/events/purchase', ['order_id' => $orderId]),
    200,
    static fn ($data): bool|string => ($data['data']['event_id'] ?? '') === $eventId
        ? true
        : 'a second call minted a different id'
);

ac_assert(
    'a different order mints a different id',
    AlgerianCommerce\Marketing\MarketingEvent::idFor('Purchase', $orderId)
        !== AlgerianCommerce\Marketing\MarketingEvent::idFor('Purchase', $orderId + 1)
        ?: 'two orders share an event id'
);

// The order id must not be readable out of the event id: this string is handed
// to an advertising network on every sale.
ac_assert(
    'the event id does not publish the order number',
    !str_contains($eventId, (string) $orderId) ?: 'the order id is visible in the event id'
);

echo PHP_EOL, "=== the queue ===", PHP_EOL;

$rows = ac_queued($orderId);

if (!$hasMeta) {
    // Nothing configured means nothing queued, and no error either.
    ac_assert('nothing is queued when no provider is registered', $rows === [] ?: 'rows appeared with no provider');
    ac_assert('and the call still answered with an id', $eventId !== '' ?: 'no id was minted');
} else {
    ac_assert('exactly one row was claimed', count($rows) === 1 ?: 'expected 1 row, got ' . count($rows));
    ac_assert('it is pending', ($rows[0]['status'] ?? '') === 'pending' ?: 'status was ' . ($rows[0]['status'] ?? ''));
    ac_assert('with the minted event id', ($rows[0]['event_id'] ?? '') === $eventId ?: 'the stored id differs');

    $payload = json_decode((string) ($rows[0]['payload'] ?? ''), true);

    ac_assert('the payload froze the conversion value', ($payload['custom_data']['value'] ?? 0) == 5000.0
        ?: 'value was ' . wp_json_encode($payload['custom_data']['value'] ?? null));

    // The privacy property, at the storage layer: the queue outlives the
    // request, so a table holding raw customer emails is exactly what §62b
    // forbids.
    $stored = (string) ($rows[0]['payload'] ?? '');
    ac_assert(
        'no raw customer data is stored in the queue',
        !str_contains($stored, 'mohamed@example.test') && !str_contains($stored, '0551020304')
            ?: 'raw PII was written to the queue'
    );
    ac_assert(
        'the identifiers are hashed',
        ($payload['user_data']['em'] ?? '') === hash('sha256', 'mohamed@example.test')
            ?: 'the email hash is wrong or missing'
    );
    ac_assert(
        'and the browser context is not hashed',
        ($payload['user_data']['client_ip_address'] ?? '') === '41.100.1.2'
            ?: 'the client ip was altered'
    );
}

echo PHP_EOL, "=== firing once ===", PHP_EOL;

// The whole reason §62b exists: report the same sale five more times and the
// queue must not grow. A read-then-write would race precisely here.
for ($i = 0; $i < 5; $i++) {
    ac_req('POST', '/marketing/events/purchase', ['order_id' => $orderId]);
}

ac_assert(
    'six reports of one sale are one queued conversion',
    count(ac_queued($orderId)) === count($rows)
        ?: 'the queue grew to ' . count(ac_queued($orderId)) . ' rows'
);

echo PHP_EOL, "=== draining ===", PHP_EOL;

/*
 * **This never sends anything, on purpose.** Draining a row that belongs to a
 * registered provider would make a real HTTPS call to Meta from the test
 * suite, so what is exercised here is the orchestration around the send —
 * the provider filter, and the "provider is gone" path — against a
 * destination that is deliberately not registered. What Meta is actually sent,
 * and how each answer is classified, is tests/Unit/MetaProviderTest against
 * recorded responses.
 */
$service = AlgerianCommerce\Core\Plugin::instance()->marketingService();
$repository = AlgerianCommerce\Core\Plugin::instance()->marketingEventRepository();

$orphan = new AlgerianCommerce\Marketing\MarketingEvent(
    AlgerianCommerce\Marketing\MarketingEvent::PURCHASE,
    'orphan-' . $orderId,
    time(),
    AlgerianCommerce\Marketing\UserData::empty(),
    ['value' => 1.0, 'currency' => 'DZD'],
    '',
    AlgerianCommerce\Marketing\MarketingEvent::SOURCE_WEBSITE,
    $orderId
);

// A provider nobody registered — a pixel that was switched off after the sale.
$repository->claim('tiktok', $orphan);

$report = $service->drain(10, 'tiktok');

ac_assert(
    'the drain reports what it considered',
    is_array($report) && array_key_exists('sent', $report) && array_key_exists('skipped', $report)
        ?: 'the drain report is the wrong shape'
);
ac_assert(
    'it saw the queued event',
    ($report['considered'] ?? 0) >= 1 ?: 'the provider filter matched nothing'
);
// Left pending rather than failed: turning a pixel back on must resume the
// queue, not require a database edit.
ac_assert(
    'an event for an unregistered provider is skipped, not failed',
    ($report['skipped'] ?? 0) >= 1 && ($report['sent'] ?? 0) === 0
        ?: 'the drain did something other than skip: ' . wp_json_encode($report)
);

global $wpdb;
$orphanStatus = $wpdb->get_var($wpdb->prepare(
    "SELECT status FROM {$wpdb->prefix}ac_marketing_events WHERE provider = %s AND event_id = %s",
    'tiktok',
    'orphan-' . $orderId
));
ac_assert('and it is still pending', $orphanStatus === 'pending' ?: 'status was ' . var_export($orphanStatus, true));

// The filter is real: a drain scoped to one destination leaves the others be.
if ($hasMeta) {
    $metaRow = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}ac_marketing_events WHERE provider = %s AND event_id = %s",
        'meta',
        $eventId
    ));
    ac_assert('a scoped drain left the other provider alone', $metaRow === 'pending' ?: 'meta row was ' . var_export($metaRow, true));
}

// Tidy up, so a re-run starts where it started.
$wpdb->delete($wpdb->prefix . 'ac_marketing_events', ['order_id' => $orderId], ['%d']);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
