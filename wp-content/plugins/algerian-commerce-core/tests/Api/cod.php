<?php
/**
 * Cash-on-delivery endpoints against a real WordPress + WooCommerce install —
 * roadmap §52, §65 (API and Security test categories).
 *
 * Covers what unit tests structurally cannot: authorization (401/403), the
 * confirmation state machine against orders that really exist, the derived
 * `enabled` answer for an order nobody has written COD meta to, the cancel hook
 * firing from a different endpoint entirely, and the funnel counting the same
 * orders the state endpoint calls COD.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here — those live
 * in scripts/test-api.sh, over real HTTP.
 *
 *   scripts/test.sh                            # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/cod.php
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
    echo str_pad($label, 60), ' ', str_pad((string) $status, 4);

    if (!$ok) {
        echo "(expected {$expect}){$detail} ", substr((string) wp_json_encode($data), 0, 300);
    }

    echo PHP_EOL;

    return $data;
}

/** A plain assertion, for facts read straight out of the database. */
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

    $product->set_name('COD test ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return wc_get_product($product->get_id());
}

function ac_audit_actions(int $orderId): array
{
    global $wpdb;

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT action FROM {$wpdb->prefix}ac_audit_logs
             WHERE resource_type = 'order' AND resource_id = %s ORDER BY id ASC",
            (string) $orderId
        )
    );

    return is_array($rows) ? $rows : [];
}

/** An order for $customer, paid by $method, at the given status. */
function ac_order(int $productId, int $customer, string $method, string $status = 'pending'): int
{
    [, $body] = ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        'customer_id' => $customer,
        'payment_method' => $method,
        'status' => $status,
        'billing' => ['first_name' => 'Yacine', 'phone' => '0551020304', 'country' => 'DZ'],
    ]);

    return (int) ($body['data']['id'] ?? 0);
}

/** The COD funnel for one customer, so runs cannot interfere with each other. */
function ac_stats(int $customer): array
{
    [, $body] = ac_req('GET', '/cod/statistics', null, ['customer_id' => $customer]);

    return $body['data'] ?? [];
}

$manager = ac_user('ac_cod_manager', 'ac_order_manager');   // ac_manage_orders + ac_view_analytics
$support = ac_user('ac_cod_support', 'ac_support_agent');   // ac_view_analytics, no order access
$customer = ac_user('ac_cod_customer', 'customer');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET cod state signed out', ac_req('GET', '/orders/1/cod'), 401);
ac_check('PATCH cod state signed out', ac_req('PATCH', '/orders/1/cod', ['enabled' => true]), 401);
ac_check('POST an attempt signed out', ac_req('POST', '/orders/1/cod/attempts', ['outcome' => 'confirmed']), 401);
ac_check('GET the funnel signed out', ac_req('GET', '/cod/statistics'), 401);

wp_set_current_user($support);
ac_check('GET cod state as support agent', ac_req('GET', '/orders/1/cod'), 403);
ac_check('POST an attempt as support agent', ac_req('POST', '/orders/1/cod/attempts', ['outcome' => 'confirmed']), 403);

// Least privilege working in both directions: reading how the shop performs is
// a different job from phoning its customers, and Support Agent holds only the
// first of the two capabilities.
ac_check('the funnel as support agent', ac_req('GET', '/cod/statistics'), 200);

wp_set_current_user($manager);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$lamp = ac_product('AC-COD-LAMP', '2000', 200);
$lampId = $lamp->get_id();

$codOrder = ac_order($lampId, $customer, 'cod');
$cardOrder = ac_order($lampId, $customer, 'bacs');

ac_assert('a COD order was created', $codOrder > 0 ?: 'no order id');
ac_assert('a non-COD order was created', $cardOrder > 0 ?: 'no order id');

ac_check('a missing order has no COD state', ac_req('GET', '/orders/99999999/cod'), 404);

echo PHP_EOL, "=== the derived state of an untouched order ===", PHP_EOL;

ac_check('a COD order is COD before anything is written', ac_req('GET', "/orders/{$codOrder}/cod"), 200, function ($d) {
    $cod = $d['data'];

    if ($cod['enabled'] !== true) {
        return 'enabled is ' . var_export($cod['enabled'], true);
    }

    if ($cod['status'] !== 'pending' || $cod['attempts'] !== 0) {
        return 'expected a pending order with no attempts';
    }

    return ($cod['confirmed_at'] === null && $cod['cancelled_at'] === null && $cod['last_attempt_at'] === null)
        ?: 'a fresh order carries a timestamp';
});

ac_check('it offers the outcomes a first call can have', ac_req('GET', "/orders/{$codOrder}/cod"), 200, function ($d) {
    return $d['data']['allowed_outcomes'] === ['confirmed', 'rejected', 'unreachable']
        ?: 'got ' . implode(', ', $d['data']['allowed_outcomes']);
});

ac_check('an order paid another way is not COD', ac_req('GET', "/orders/{$cardOrder}/cod"), 200, function ($d) {
    return $d['data']['enabled'] === false ?: 'enabled is ' . var_export($d['data']['enabled'], true);
});

ac_check('and no confirmation can be recorded against it', ac_req('POST', "/orders/{$cardOrder}/cod/attempts", [
    'outcome' => 'confirmed',
]), 409);

echo PHP_EOL, "=== recording a call: validation ===", PHP_EOL;

ac_check('an attempt with no body', ac_req('POST', "/orders/{$codOrder}/cod/attempts", []), 400, function ($d) {
    return isset($d['error']['details']['fields']['outcome']) ?: 'expected an outcome error';
});

ac_check('an unknown field is refused', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'confirmed',
    'attempts' => 99,
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['attempts']) ?: 'expected attempts to be unknown';
});

// `pending` is where an order starts and `cancelled` happens to the order
// itself — neither is something a phone call concludes with.
ac_check('pending is not a call outcome', ac_req('POST', "/orders/{$codOrder}/cod/attempts", ['outcome' => 'pending']), 400);
ac_check('cancelled is not a call outcome', ac_req('POST', "/orders/{$codOrder}/cod/attempts", ['outcome' => 'cancelled']), 400);
ac_check('an invented outcome is refused', ac_req('POST', "/orders/{$codOrder}/cod/attempts", ['outcome' => 'delivered']), 400);

ac_check('an overlong reason is refused', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'rejected',
    'reason' => str_repeat('a', 501),
]), 400);

echo PHP_EOL, "=== recording a call: the state machine ===", PHP_EOL;

ac_check('the phone rang out', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'unreachable',
    'reason' => 'no answer',
]), 201, function ($d) {
    $cod = $d['data'];

    if ($cod['status'] !== 'unreachable' || $cod['attempts'] !== 1) {
        return "status {$cod['status']}, attempts {$cod['attempts']}";
    }

    if ($cod['last_attempt_at'] === null) {
        return 'the attempt was not timestamped';
    }

    return $cod['confirmed_at'] === null ?: 'an unreachable customer confirmed nothing';
});

ac_check('a customer who does not answer is called again', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'unreachable',
]), 201, function ($d) {
    return $d['data']['attempts'] === 2 ?: 'attempts is ' . $d['data']['attempts'];
});

ac_check('the third call confirms', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'confirmed',
    'reason' => 'confirmed by phone',
]), 201, function ($d) {
    $cod = $d['data'];

    if ($cod['status'] !== 'confirmed' || $cod['attempts'] !== 3) {
        return "status {$cod['status']}, attempts {$cod['attempts']}";
    }

    return $cod['confirmed_at'] !== null ?: 'confirmed_at was not stamped';
});

[, $afterConfirm] = ac_req('GET', "/orders/{$codOrder}/cod");
$confirmedAt = $afterConfirm['data']['confirmed_at'];

// A customer who says yes and later changes their mind has cancelled. Folding
// that into a rejection would make the funnel count one event two ways.
ac_check('a confirmed order cannot be rejected', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'rejected',
]), 409, function ($d) {
    $details = $d['error']['details'] ?? [];

    return (($details['from'] ?? '') === 'confirmed' && ($details['allowed'] ?? null) === ['confirmed'])
        ?: 'the refusal does not say what is reachable';
});

ac_check('re-confirming is a harmless retry', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'confirmed',
]), 201, function ($d) use ($confirmedAt) {
    if ($d['data']['attempts'] !== 4) {
        return 'attempts is ' . $d['data']['attempts'];
    }

    return $d['data']['confirmed_at'] === $confirmedAt ?: 'the confirmation moved in time';
});

echo PHP_EOL, "=== turning COD off for one order ===", PHP_EOL;

ac_check('a string that looks like a boolean is refused', ac_req('PATCH', "/orders/{$codOrder}/cod", [
    'enabled' => 'false',
]), 400);

ac_check('PATCH with nothing usable', ac_req('PATCH', "/orders/{$codOrder}/cod", []), 400);

ac_check('COD is turned off', ac_req('PATCH', "/orders/{$codOrder}/cod", ['enabled' => false]), 200, function ($d) {
    // The history survives: turning the queue off is not the same as deciding
    // the calls never happened.
    return ($d['data']['enabled'] === false && $d['data']['attempts'] === 4)
        ?: 'expected a disabled order with its four attempts';
});

ac_check('no more calls can be recorded against it', ac_req('POST', "/orders/{$codOrder}/cod/attempts", [
    'outcome' => 'confirmed',
]), 409);

// The pattern every admin client uses: GET, flip one field, PATCH the whole
// object back. The read-only fields the state emits have to survive that.
[, $fetched] = ac_req('GET', "/orders/{$codOrder}/cod");
$roundTrip = $fetched['data'];
$roundTrip['enabled'] = true;

ac_check('PATCH the whole GET body back', ac_req('PATCH', "/orders/{$codOrder}/cod", $roundTrip), 200, function ($d) {
    return $d['data']['enabled'] === true ?: 'the flag did not stick';
});

echo PHP_EOL, "=== the trail and the timeline ===", PHP_EOL;

$actions = ac_audit_actions($codOrder);

ac_assert('the calls were audited', in_array('cod.attempt_recorded', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the setting change was audited', in_array('cod.settings_changed', $actions, true) ?: 'saw ' . implode(', ', $actions));

ac_assert(
    'one audit row per recorded call',
    count(array_filter($actions, fn ($a) => $a === 'cod.attempt_recorded')) === 4
        ?: 'saw ' . count(array_filter($actions, fn ($a) => $a === 'cod.attempt_recorded'))
);

ac_check('the calls read as sentences on the order timeline', ac_req('GET', "/orders/{$codOrder}/timeline"), 200, function ($d) {
    foreach ($d['data'] as $entry) {
        if (($entry['data']['action'] ?? '') === 'cod.attempt_recorded') {
            return str_contains($entry['summary'], 'COD confirmation attempt')
                ?: 'the summary reads "' . $entry['summary'] . '"';
        }
    }

    return 'no COD entry reached the timeline';
});

echo PHP_EOL, "=== cancelling the order closes the queue ===", PHP_EOL;

$toCancel = ac_order($lampId, $customer, 'cod');

ac_check('one call, no answer', ac_req('POST', "/orders/{$toCancel}/cod/attempts", [
    'outcome' => 'unreachable',
]), 201);

// Cancelled through the order endpoint, not a COD one: the point is that a
// cancellation from anywhere closes the confirmation queue.
ac_check('the order is cancelled', ac_req('POST', "/orders/{$toCancel}/cancel", ['reason' => 'duplicate order']), 200);

ac_check('the COD state was closed with it', ac_req('GET', "/orders/{$toCancel}/cod"), 200, function ($d) {
    $cod = $d['data'];

    if ($cod['status'] !== 'cancelled') {
        return 'status is ' . $cod['status'];
    }

    if ($cod['cancelled_at'] === null) {
        return 'the cancellation was not timestamped';
    }

    // Nobody phoned anyone, so the counter must not have moved.
    return $cod['attempts'] === 1 ?: 'attempts is ' . $cod['attempts'];
});

ac_check('nothing more can be recorded against it', ac_req('POST', "/orders/{$toCancel}/cod/attempts", [
    'outcome' => 'confirmed',
]), 409);

$rejected = ac_order($lampId, $customer, 'cod');

ac_check('a customer refuses the order', ac_req('POST', "/orders/{$rejected}/cod/attempts", [
    'outcome' => 'rejected',
    'reason' => 'changed their mind',
]), 201);

ac_check('and the order is cancelled afterwards', ac_req('POST', "/orders/{$rejected}/cancel", ['reason' => 'refused on the phone']), 200);

ac_check('the refusal is not overwritten by the cancellation', ac_req('GET', "/orders/{$rejected}/cod"), 200, function ($d) {
    return ($d['data']['status'] === 'rejected' && $d['data']['reason'] === 'changed their mind')
        ?: 'the outcome the call produced was lost';
});

echo PHP_EOL, "=== the funnel ===", PHP_EOL;

ac_check('a malformed date is refused', ac_req('GET', '/cod/statistics', null, ['date_from' => '11-08-2026']), 400);
ac_check('customer zero is refused', ac_req('GET', '/cod/statistics', null, ['customer_id' => 0]), 400);

ac_check('the funnel has every bucket and every rate', ac_req('GET', '/cod/statistics'), 200, function ($d) {
    $stats = $d['data'];

    foreach (['total_orders', 'by_status', 'confirmed_orders', 'delivered_orders', 'returned_orders', 'rates'] as $key) {
        if (!array_key_exists($key, $stats)) {
            return "missing {$key}";
        }
    }

    foreach (['pending', 'confirmed', 'rejected', 'unreachable', 'cancelled'] as $status) {
        if (!array_key_exists($status, $stats['by_status'])) {
            return "missing the {$status} bucket";
        }
    }

    foreach (['confirmation', 'rejection', 'cancellation', 'delivery', 'return'] as $rate) {
        if (!is_string($stats['rates'][$rate] ?? null)) {
            return "the {$rate} rate is not a fixed-point string";
        }
    }

    return true;
});

/*
 * Counted against the shop's own numbers rather than absolute ones: this suite
 * runs repeatedly against the same install, so what has to hold is the *change*
 * each event causes.
 */
$before = ac_stats($customer);

$fresh = ac_order($lampId, $customer, 'cod');

$after = ac_stats($customer);

ac_assert(
    'an order nobody has touched already counts as pending',
    ($after['total_orders'] === $before['total_orders'] + 1
        && $after['by_status']['pending'] === $before['by_status']['pending'] + 1)
        ?: "total {$before['total_orders']} → {$after['total_orders']}"
);

ac_check('the fresh order is confirmed', ac_req('POST', "/orders/{$fresh}/cod/attempts", ['outcome' => 'confirmed']), 201);

$confirmed = ac_stats($customer);

ac_assert(
    'confirming moves it out of pending and into confirmed',
    ($confirmed['total_orders'] === $after['total_orders']
        && $confirmed['by_status']['confirmed'] === $after['by_status']['confirmed'] + 1
        && $confirmed['by_status']['pending'] === $after['by_status']['pending'] - 1)
        ?: 'the buckets did not move as expected'
);

ac_assert(
    'the confirmation rate is the confirmed count over the total',
    $confirmed['rates']['confirmation'] === number_format(
        $confirmed['confirmed_orders'] / max(1, $confirmed['total_orders']),
        4,
        '.',
        ''
    ) ?: 'rate ' . $confirmed['rates']['confirmation'] . ' over ' . $confirmed['total_orders'] . ' orders'
);

ac_check('the order is delivered and paid', ac_req('PATCH', "/orders/{$fresh}", ['status' => 'completed']), 200);

$delivered = ac_stats($customer);

ac_assert(
    'a completed COD order counts as delivered',
    $delivered['delivered_orders'] === $confirmed['delivered_orders'] + 1
        ?: "delivered {$confirmed['delivered_orders']} → {$delivered['delivered_orders']}"
);

// An order that was confirmed and later called off is in both rates. Both
// things happened, and a shop that only counted the cancellation would blame
// the wrong half of its process.
$both = ac_order($lampId, $customer, 'cod');
ac_check('confirmed, then cancelled', ac_req('POST', "/orders/{$both}/cod/attempts", ['outcome' => 'confirmed']), 201);
ac_check('called off after confirmation', ac_req('POST', "/orders/{$both}/cancel", ['reason' => 'out of stock']), 200);

$afterBoth = ac_stats($customer);

ac_assert(
    'it counts as a confirmation and as a cancellation',
    ($afterBoth['confirmed_orders'] === $delivered['confirmed_orders'] + 1
        && $afterBoth['by_status']['cancelled'] === $delivered['by_status']['cancelled'] + 1)
        ?: 'confirmed ' . $afterBoth['confirmed_orders'] . ', cancelled ' . $afterBoth['by_status']['cancelled']
);

ac_order($lampId, $customer, 'bacs');

$afterCard = ac_stats($customer);

ac_assert(
    'an order paid another way never enters the funnel',
    $afterCard['total_orders'] === $afterBoth['total_orders']
        ?: "total {$afterBoth['total_orders']} → {$afterCard['total_orders']}"
);

ac_check('the funnel can be scoped to a customer with no orders', ac_req('GET', '/cod/statistics', null, [
    'customer_id' => $support,
]), 200, function ($d) {
    return ($d['data']['total_orders'] === 0 && $d['data']['rates']['confirmation'] === '0.0000')
        ?: 'expected an empty funnel';
});

ac_check('a past window excludes today\'s orders', ac_req('GET', '/cod/statistics', null, [
    'customer_id' => $customer,
    'date_from' => '2020-01-01',
    'date_to' => '2020-12-31',
]), 200, function ($d) {
    return $d['data']['total_orders'] === 0 ?: 'got ' . $d['data']['total_orders'] . ' orders in 2020';
});

$today = gmdate('Y-m-d');

ac_check('a same-day window covers the whole day', ac_req('GET', '/cod/statistics', null, [
    'customer_id' => $customer,
    'date_from' => $today,
    'date_to' => $today,
]), 200, function ($d) {
    return $d['data']['total_orders'] > 0 ?: 'today\'s orders are missing from today';
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
