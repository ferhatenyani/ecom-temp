<?php
/**
 * Analytics endpoints against a real WordPress + WooCommerce install —
 * roadmap §63, §65 (API and Security test categories).
 *
 * Covers what unit tests structurally cannot: authorization on seven routes,
 * the capability split that decides whether money appears in a payload at all,
 * the aggregate SQL running against real HPOS tables, and the figures moving
 * the way they should when orders are actually placed, cancelled and refunded.
 *
 * Counted against the shop's own numbers rather than absolute ones, as
 * tests/Api/cod.php is: this suite runs repeatedly against an install that
 * already holds hundreds of orders, so what has to hold is the *change* each
 * event causes.
 *
 * The response cache is off for this suite (`AC_ANALYTICS_CACHE_TTL=0` in
 * scripts/test.sh) so every assertion sees the shop as it is rather than as it
 * was a minute ago. What the cache itself guarantees — above all that a payload
 * with money in it never shares a key with one without — is
 * tests/Unit/AnalyticsCacheTest, where the key function is pure.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here.
 *
 *   scripts/test.sh
 *   docker compose run --rm -T -e AC_ANALYTICS_CACHE_TTL=0 wpcli wp eval-file - < tests/Api/analytics.php
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

    $product->set_name('Analytics test ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return wc_get_product($product->get_id());
}

function ac_order(int $productId, int $quantity, string $status = 'pending'): int
{
    [, $body] = ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $productId, 'quantity' => $quantity]],
        'payment_method' => 'cod',
        'status' => $status,
        'billing' => ['first_name' => 'Yacine', 'phone' => '0551020304', 'country' => 'DZ'],
    ]);

    return (int) ($body['data']['id'] ?? 0);
}

/** One endpoint over one window, unwrapped. */
function ac_report(string $endpoint, string $range = 'today', array $query = []): array
{
    [, $body] = ac_req('GET', '/analytics/' . $endpoint, null, ['range' => $range] + $query);

    return $body['data'] ?? [];
}

/** Money, as an integer number of centimes, so deltas can be compared exactly. */
function ac_minor($amount): int
{
    return (int) round(((float) $amount) * 100);
}

$ROUTES = ['overview', 'revenue', 'orders', 'products', 'customers', 'shipping', 'cod'];

$manager = ac_user('ac_an_manager', 'ac_admin');       // ac_manage_orders + ac_view_analytics
$support = ac_user('ac_an_support', 'ac_support_agent'); // ac_view_analytics, no order access
$nobody = ac_user('ac_an_nobody', 'customer');           // neither

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);

foreach ($ROUTES as $route) {
    ac_check("GET /analytics/{$route} signed out", ac_req('GET', "/analytics/{$route}"), 401);
}

wp_set_current_user($nobody);

foreach ($ROUTES as $route) {
    ac_check("GET /analytics/{$route} without the capability", ac_req('GET', "/analytics/{$route}"), 403);
}

echo PHP_EOL, "=== the capability split that protects money ===", PHP_EOL;

wp_set_current_user($support);

/*
 * Support Agent holds ac_view_analytics and nothing else that matters here.
 * Every operational report is open to them; the shop's turnover is not, because
 * they cannot read an order's total through GET /orders either. Analytics must
 * not disclose in aggregate what the caller cannot already read in detail.
 */
foreach (['overview', 'orders', 'products', 'customers', 'shipping', 'cod'] as $route) {
    ac_check("a support agent may read /analytics/{$route}", ac_req('GET', "/analytics/{$route}"), 200);
}

ac_check('but the whole revenue report is refused', ac_req('GET', '/analytics/revenue'), 403, function ($d) {
    return ($d['error']['code'] ?? '') === 'forbidden' ?: 'got ' . ($d['error']['code'] ?? 'no code');
});

ac_check('and the overview carries no revenue block', ac_req('GET', '/analytics/overview'), 200, function ($d) {
    if (array_key_exists('revenue', $d['data'])) {
        return 'a support agent was shown revenue';
    }

    // Told which capability would show it, so a client can disable a card
    // rather than render an empty one.
    return ($d['meta']['money_visible'] === false && $d['meta']['money_requires'] === 'ac_manage_orders')
        ?: 'the response does not say why the block is missing';
});

ac_check('nor any money figure anywhere in it', ac_req('GET', '/analytics/overview'), 200, function ($d) {
    $flat = (string) wp_json_encode($d['data']);

    foreach (['gross', 'net', 'average_order_value', 'collected'] as $field) {
        if (str_contains($flat, '"' . $field . '"')) {
            return "{$field} reached a caller without ac_manage_orders";
        }
    }

    return true;
});

ac_check('the shipping report shows wilayas without revenue', ac_req('GET', '/analytics/shipping'), 200, function ($d) {
    if (array_key_exists('shipping_revenue', $d['data'])) {
        return 'shipping revenue reached a support agent';
    }

    foreach ($d['data']['by_wilaya'] as $wilaya) {
        if (array_key_exists('revenue', $wilaya)) {
            return 'a wilaya carried revenue for a support agent';
        }
    }

    return array_key_exists('orders', $d['data']['unattributed']) ?: 'the unattributed bucket lost its count';
});

ac_check('best sellers rank by units without revenue', ac_req('GET', '/analytics/products'), 200, function ($d) {
    foreach ($d['data']['best_sellers'] as $seller) {
        if (array_key_exists('revenue', $seller)) {
            return 'a best seller carried revenue for a support agent';
        }

        if (!array_key_exists('units', $seller)) {
            return 'a best seller has no unit count';
        }
    }

    return true;
});

wp_set_current_user($manager);

ac_check('a manager sees the revenue block', ac_req('GET', '/analytics/overview'), 200, function ($d) {
    return (isset($d['data']['revenue']['gross']) && $d['meta']['money_visible'] === true)
        ?: 'the revenue block is missing for a caller who holds ac_manage_orders';
});

echo PHP_EOL, "=== bad input ===", PHP_EOL;

ac_check('an invented preset', ac_req('GET', '/analytics/overview', null, ['range' => 'all-time']), 400);
ac_check('an empty preset', ac_req('GET', '/analytics/overview', null, ['range' => '']), 400);
ac_check('a malformed date', ac_req('GET', '/analytics/orders', null, [
    'range' => 'custom',
    'date_from' => '11-08-2026',
    'date_to' => '2026-08-16',
]), 400);

ac_check('a custom range with no dates', ac_req('GET', '/analytics/orders', null, ['range' => 'custom']), 400, function ($d) {
    $fields = $d['error']['details']['fields'] ?? [];

    return (isset($fields['date_from']) && isset($fields['date_to']))
        ?: 'both ends should be named as missing';
});

ac_check('a custom range with only one end', ac_req('GET', '/analytics/orders', null, [
    'range' => 'custom',
    'date_from' => '2026-08-01',
]), 400);

ac_check('a backwards range', ac_req('GET', '/analytics/orders', null, [
    'range' => 'custom',
    'date_from' => '2026-08-16',
    'date_to' => '2026-08-01',
]), 400);

// createFromFormat would roll this into the 3rd of March.
ac_check('the 31st of February', ac_req('GET', '/analytics/orders', null, [
    'range' => 'custom',
    'date_from' => '2026-02-31',
    'date_to' => '2026-03-01',
]), 400);

/*
 * The bound that stops "since the beginning", which is the query with no upper
 * cost — roadmap §63's whole instruction in one argument.
 */
ac_check('a window wider than the cap', ac_req('GET', '/analytics/revenue', null, [
    'range' => 'custom',
    'date_from' => '2019-01-01',
    'date_to' => '2026-08-16',
]), 400, function ($d) {
    return str_contains((string) ($d['error']['details']['fields']['date_from'] ?? ''), '366')
        ?: 'the refusal does not say what the limit is';
});

ac_check('a route that does not exist', ac_req('GET', '/analytics/margin'), 404);

echo PHP_EOL, "=== the window ===", PHP_EOL;

foreach (['today', 'yesterday', '7d', '30d', '90d'] as $preset) {
    ac_check("range={$preset} is served", ac_req('GET', '/analytics/orders', null, ['range' => $preset]), 200,
        function ($d) use ($preset) {
            return $d['data']['range']['preset'] === $preset ?: 'echoed ' . $d['data']['range']['preset'];
        });
}

ac_check('the response echoes the window it used', ac_req('GET', '/analytics/orders', null, ['range' => '7d']), 200, function ($d) {
    $range = $d['data']['range'];

    return ($range['days'] === 7 && $range['from'] < $range['to'] && $range['timezone'] !== '')
        ?: 'the echoed window is ' . wp_json_encode($range);
});

// Adjacent windows must not both claim an order sitting on the boundary.
$todayRange = ac_report('orders', 'today')['range'];
$yesterdayRange = ac_report('orders', 'yesterday')['range'];

ac_assert(
    'yesterday ends where today begins',
    ($yesterdayRange['to'] < $todayRange['from'] && $yesterdayRange['days'] === 1)
        ?: "{$yesterdayRange['to']} against {$todayRange['from']}"
);

ac_check('a past window is empty rather than an error', ac_req('GET', '/analytics/revenue', null, [
    'range' => 'custom',
    'date_from' => '2019-01-01',
    'date_to' => '2019-01-31',
]), 200, function ($d) {
    return ($d['data']['orders_placed'] === 0 && $d['data']['gross'] === '0.00' && $d['data']['net'] === '0.00')
        ?: 'a shop that did not exist in 2019 reported trade';
});

ac_check('an empty window still divides safely', ac_req('GET', '/analytics/revenue', null, [
    'range' => 'custom',
    'date_from' => '2019-01-01',
    'date_to' => '2019-01-31',
]), 200, function ($d) {
    return $d['data']['average_order_value'] === '0.00' ?: 'AOV is ' . $d['data']['average_order_value'];
});

echo PHP_EOL, "=== what PLAN §28 can and cannot report ===", PHP_EOL;

ac_check('the revenue report carries every line the data supports', ac_req('GET', '/analytics/revenue'), 200, function ($d) {
    foreach (['currency', 'order_total', 'gross', 'discounts', 'shipping_revenue',
        'tax', 'refunds', 'net', 'collected', 'average_order_value'] as $field) {
        if (!array_key_exists($field, $d['data'])) {
            return "missing {$field}";
        }
    }

    return true;
});

/*
 * The three PLAN §28 asks for that this shop has no data for. Named with a
 * reason rather than emitted as zero — a dashboard rendering "Margin: 0.00 DZD"
 * has told the shop something false.
 */
ac_check('and names the three it cannot', ac_req('GET', '/analytics/revenue'), 200, function ($d) {
    $unavailable = $d['data']['unavailable'] ?? [];

    foreach (['shipping_cost', 'payment_fees', 'margin'] as $field) {
        if (($unavailable[$field] ?? '') === '') {
            return "{$field} is not explained";
        }

        if (array_key_exists($field, $d['data'])) {
            return "{$field} is reported as a number as well";
        }
    }

    return true;
});

ac_check('money is a fixed-point string, never a float', ac_req('GET', '/analytics/revenue'), 200, function ($d) {
    foreach (['gross', 'net', 'refunds', 'average_order_value'] as $field) {
        if (!is_string($d['data'][$field])) {
            return "{$field} is not a string";
        }
    }

    return true;
});

/*
 * WooCommerce records the currency per order, so a shop that traded on the
 * default before anyone set DZD has two currencies in its order book forever.
 * Whatever this install holds, the sums must never be a mixture.
 */
ac_check('orders in another currency are reported, not added in', ac_req('GET', '/analytics/revenue', null, [
    'range' => '90d',
]), 200, function ($d) {
    $excluded = $d['data']['excluded_currencies'] ?? [];

    if (array_key_exists($d['data']['currency'], $excluded)) {
        return 'the store currency was reported as excluded from its own total';
    }

    foreach ($excluded as $currency => $count) {
        if (!is_int($count) || $currency === '') {
            return 'the exclusion list is malformed';
        }
    }

    return true;
});

echo PHP_EOL, "=== the figures move with the shop ===", PHP_EOL;

$lamp = ac_product('AC-AN-LAMP', '2000', 500);
$lampId = $lamp->get_id();

$before = ac_report('orders');
$revenueBefore = ac_report('revenue');

$placed = ac_order($lampId, 1, 'pending');
ac_assert('an order was created', $placed > 0 ?: 'no order id');

$after = ac_report('orders');

ac_assert(
    'a new order appears in today',
    $after['placed'] === $before['placed'] + 1 ?: "placed {$before['placed']} → {$after['placed']}"
);

ac_assert(
    'and lands in the pending bucket',
    $after['by_status']['pending'] === $before['by_status']['pending'] + 1
        ?: 'pending did not move'
);

$revenueAfterPending = ac_report('revenue');

/*
 * A pending order is not revenue: nothing has been paid and nothing committed.
 * The top line moves because it counts every order; gross does not.
 */
ac_assert(
    'a pending order is in the top line but not in gross',
    (ac_minor($revenueAfterPending['order_total']) === ac_minor($revenueBefore['order_total']) + 200000
        && ac_minor($revenueAfterPending['gross']) === ac_minor($revenueBefore['gross']))
        ?: "order_total {$revenueBefore['order_total']} → {$revenueAfterPending['order_total']}, "
            . "gross {$revenueBefore['gross']} → {$revenueAfterPending['gross']}"
);

ac_check('the order is completed', ac_req('PATCH', "/orders/{$placed}", ['status' => 'completed']), 200);

$revenueCompleted = ac_report('revenue');

ac_assert(
    'a completed order is gross and collected alike',
    (ac_minor($revenueCompleted['gross']) === ac_minor($revenueBefore['gross']) + 200000
        && ac_minor($revenueCompleted['collected']) === ac_minor($revenueBefore['collected']) + 200000)
        ?: "gross {$revenueBefore['gross']} → {$revenueCompleted['gross']}"
);

ac_assert(
    'and net follows gross while nothing has been given back',
    ac_minor($revenueCompleted['net']) === ac_minor($revenueBefore['net']) + 200000
        ?: "net {$revenueBefore['net']} → {$revenueCompleted['net']}"
);

/*
 * The property `refunded` is a counted status for. A fully refunded order
 * belongs in gross with its refund subtracted, netting to zero. Excluding the
 * order while still counting the refund would net to *minus* the sale.
 */
$refund = wc_create_refund([
    'order_id' => $placed,
    'amount' => '2000',
    'reason' => 'analytics test',
]);

ac_assert('a refund was issued', !is_wp_error($refund) ?: $refund->get_error_message());

$revenueRefunded = ac_report('revenue');

ac_assert(
    'the refund is reported',
    ac_minor($revenueRefunded['refunds']) === ac_minor($revenueCompleted['refunds']) + 200000
        ?: "refunds {$revenueCompleted['refunds']} → {$revenueRefunded['refunds']}"
);

ac_assert(
    'a fully refunded order nets to zero, not to minus the sale',
    ac_minor($revenueRefunded['net']) === ac_minor($revenueBefore['net'])
        ?: "net was {$revenueBefore['net']}, is {$revenueRefunded['net']}"
);

$grossBeforeCancel = ac_report('revenue')['gross'];
$ordersBeforeCancel = ac_report('orders');

$doomed = ac_order($lampId, 1, 'pending');
ac_check('an order is cancelled', ac_req('POST', "/orders/{$doomed}/cancel", ['reason' => 'analytics test']), 200);

$ordersAfterCancel = ac_report('orders');

ac_assert(
    'a cancelled order is counted as activity',
    ($ordersAfterCancel['placed'] === $ordersBeforeCancel['placed'] + 1
        && $ordersAfterCancel['cancelled'] === $ordersBeforeCancel['cancelled'] + 1)
        ?: 'the cancellation did not reach the order counts'
);

ac_assert(
    'but never as revenue',
    ac_minor(ac_report('revenue')['gross']) === ac_minor($grossBeforeCancel)
        ?: "gross {$grossBeforeCancel} → " . ac_report('revenue')['gross']
);

$products = ac_report('products');
$sold = null;

foreach ($products['best_sellers'] as $seller) {
    if ($seller['product_id'] === $lampId) {
        $sold = $seller;
    }
}

ac_assert('the product sold today is in the best sellers', $sold !== null ?: 'the lamp is missing');
ac_assert('with the name it was sold under', ($sold['name'] ?? '') !== '' ?: 'the line has no name');
ac_assert('and a positive unit count', (($sold['units'] ?? 0) > 0) ?: 'units is ' . ($sold['units'] ?? 'missing'));

echo PHP_EOL, "=== the reports agree with each other ===", PHP_EOL;

$overview = ac_report('overview');
$orders = ac_report('orders');
$cod = ac_report('cod');
$shipping = ac_report('shipping');

ac_assert(
    'the overview and the order report count the same orders',
    $overview['orders']['placed'] === $orders['placed']
        ?: "{$overview['orders']['placed']} against {$orders['placed']}"
);

/*
 * The COD funnel is delegated to CodService rather than recomputed here, so
 * /analytics/cod and /cod/statistics cannot drift apart into two definitions of
 * "confirmation rate".
 */
[, $codDirect] = ac_req('GET', '/cod/statistics', null, [
    'date_from' => $cod['range']['from'],
    'date_to' => $cod['range']['to'],
]);

ac_assert(
    'the COD funnel is the same one /cod/statistics serves',
    $cod['rates']['confirmation'] === ($codDirect['data']['rates']['confirmation'] ?? null)
        ?: 'the two endpoints disagree about the confirmation rate'
);

ac_assert(
    'the overview headline matches the COD report',
    $overview['cod']['confirmation_rate'] === $cod['rates']['confirmation']
        ?: 'the overview and /analytics/cod disagree'
);

ac_assert(
    'the overview and the shipping report count the same parcels',
    $overview['shipping']['shipments'] === $shipping['shipments']['total']
        ?: 'the two disagree about how many parcels exist'
);

ac_assert(
    'every parcel is attributed to exactly one provider',
    array_sum(array_column($shipping['providers'], 'shipments')) === $shipping['shipments']['total']
        ?: 'the provider breakdown does not add up to the total'
);

ac_assert(
    'no order counts as revenue that is not in the order book',
    $orders['counted_as_revenue'] <= $orders['placed']
        ?: "{$orders['counted_as_revenue']} of {$orders['placed']}"
);

echo PHP_EOL, "=== the shape of a response ===", PHP_EOL;

ac_check('every report is stamped with when it was built', ac_req('GET', '/analytics/overview'), 200, function ($d) {
    $meta = $d['meta'] ?? [];

    if (($meta['generated_at'] ?? '') === '' || strtotime($meta['generated_at']) === false) {
        return 'generated_at is missing or unparseable';
    }

    return array_key_exists('cache_ttl', $meta) ?: 'the response does not say how long it may be reused';
});

ac_check('rates are fixed-point strings', ac_req('GET', '/analytics/shipping'), 200, function ($d) {
    foreach (['delivery', 'return'] as $rate) {
        if (!is_string($d['data']['rates'][$rate] ?? null)) {
            return "the {$rate} rate is not a fixed-point string";
        }
    }

    return true;
});

ac_check('a wilaya is named as well as numbered', ac_req('GET', '/analytics/shipping', null, ['range' => '90d']), 200, function ($d) {
    foreach ($d['data']['by_wilaya'] as $wilaya) {
        foreach (['wilaya_id', 'code', 'name', 'name_ar', 'orders'] as $field) {
            if (!array_key_exists($field, $wilaya)) {
                return "a wilaya row is missing {$field}";
            }
        }
    }

    /*
     * An order is attributed to a wilaya only once a parcel exists for it, so
     * everything unshipped lands in one bucket that says so. A map with a
     * stated gap beats a map that is quietly wrong.
     */
    return isset($d['data']['unattributed']['reason']) ?: 'the unattributed bucket gives no reason';
});

ac_check('every order status has a bucket even when empty', ac_req('GET', '/analytics/orders'), 200, function ($d) {
    foreach (['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'] as $status) {
        if (!array_key_exists($status, $d['data']['by_status'])) {
            return "missing the {$status} bucket";
        }
    }

    return true;
});

ac_check('every shipment status has a bucket even when empty', ac_req('GET', '/analytics/shipping'), 200, function ($d) {
    foreach (['pending', 'created', 'in_transit', 'delivered', 'returned', 'cancelled', 'failed'] as $status) {
        if (!array_key_exists($status, $d['data']['shipments']['by_status'])) {
            return "missing the {$status} bucket";
        }
    }

    return true;
});

echo PHP_EOL, "=== an install these queries cannot read ===", PHP_EOL;

/*
 * The 501. AnalyticsRepository reads WooCommerce's order tables directly, and
 * under legacy post storage the same query returns zero rows and no error — a
 * dashboard reporting a trading shop as having taken nothing, which CLAUDE.md
 * names as the worst possible failure shape. §63 answers it by refusing.
 *
 * HPOS is not switched off to prove it, and nothing is migrated:
 * custom_orders_table_usage_is_enabled() reads an option, and every WordPress
 * option can be short-circuited for the length of one request with
 * `pre_option_*`. The filter is removed immediately and service is re-asserted
 * below, so this section cannot leak into anything else.
 *
 * Last in the file for the same reason.
 */
$hposOff = static fn () => 'no';
add_filter('pre_option_woocommerce_custom_orders_table_enabled', $hposOff);

foreach (['overview', 'revenue', 'orders', 'shipping'] as $route) {
    ac_check("/analytics/{$route} refuses rather than answering zero", ac_req('GET', "/analytics/{$route}"), 501,
        function ($d) {
            return ($d['error']['code'] ?? '') === 'analytics_unsupported'
                ?: 'got ' . ($d['error']['code'] ?? 'no code');
        });
}

ac_check('and the refusal names what is missing', ac_req('GET', '/analytics/overview'), 501, function ($d) {
    return str_contains($d['error']['message'], 'High-Performance Order Storage')
        ?: 'the message does not say what is wrong: ' . $d['error']['message'];
});

// Authorization still runs first: an install that cannot report must not become
// one that reports to anybody.
wp_set_current_user(0);
ac_check('an unsupported install still refuses a stranger first', ac_req('GET', '/analytics/overview'), 401);
wp_set_current_user($manager);

remove_filter('pre_option_woocommerce_custom_orders_table_enabled', $hposOff);

ac_check('service returns when the storage does', ac_req('GET', '/analytics/overview'), 200);

ac_assert(
    'and nothing was migrated to prove any of it',
    (int) $GLOBALS['wpdb']->get_var(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}wc_orders WHERE type = 'shop_order'"
    ) > 0 ?: 'the order table is empty'
);

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
