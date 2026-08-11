<?php
/**
 * Order endpoints against a real WordPress + WooCommerce install — roadmap
 * §50, §65 (API and Security test categories).
 *
 * Covers what unit tests structurally cannot: authorization (401/403), the
 * status transition guard against real WooCommerce statuses, catalogue pricing
 * of line items, and the thing this phase exists to get right — that a status
 * change moves stock and lands a balanced row in the §49 ledger with the order
 * id on it.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here — those live
 * in scripts/test-api.sh, over real HTTP.
 *
 *   scripts/test.sh                               # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/orders.php
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

/** A simple product at a known price and stock level, reused across runs. */
function ac_product(string $sku, string $price, int $stock): WC_Product
{
    $id = (int) wc_get_product_id_by_sku($sku);
    $product = $id > 0 ? wc_get_product($id) : new WC_Product_Simple();

    $product->set_name('Order test ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return wc_get_product($product->get_id());
}

/** Movement rows this suite wrote for one order, oldest first. */
function ac_movements(int $orderId): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT product_id, delta, quantity_before, quantity_after, reason, order_id
             FROM {$wpdb->prefix}ac_inventory_movements
             WHERE order_id = %d ORDER BY id ASC",
            $orderId
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
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

$manager = ac_user('ac_ord_manager', 'ac_order_manager');   // has ac_manage_orders
$support = ac_user('ac_ord_support', 'ac_support_agent');   // has not
$customer = ac_user('ac_ord_customer', 'customer');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /orders signed out', ac_req('GET', '/orders'), 401);
ac_check('GET /orders/1 signed out', ac_req('GET', '/orders/1'), 401);
ac_check('POST /orders signed out', ac_req('POST', '/orders', ['line_items' => []]), 401);
ac_check('POST cancel signed out', ac_req('POST', '/orders/1/cancel'), 401);

wp_set_current_user($support);
ac_check('GET /orders as support agent', ac_req('GET', '/orders'), 403);
ac_check('POST /orders as support agent', ac_req('POST', '/orders', ['line_items' => []]), 403);
ac_check('PATCH /orders/1 as support agent', ac_req('PATCH', '/orders/1', ['status' => 'processing']), 403);
ac_check('POST cancel as support agent', ac_req('POST', '/orders/1/cancel'), 403);

wp_set_current_user($manager);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$kettle = ac_product('AC-ORD-KETTLE', '1500', 50);
$mug = ac_product('AC-ORD-MUG', '300', 20);

ac_assert('kettle starts at 50 units', $kettle->get_stock_quantity() === 50 ?: 'got ' . var_export($kettle->get_stock_quantity(), true));
ac_assert('mug starts at 20 units', $mug->get_stock_quantity() === 20 ?: 'got ' . var_export($mug->get_stock_quantity(), true));

$kettleId = $kettle->get_id();
$mugId = $mug->get_id();

echo PHP_EOL, "=== create: validation ===", PHP_EOL;

ac_check('create with no body', ac_req('POST', '/orders', []), 400, function ($d) {
    return isset($d['error']['details']['fields']['line_items']) ?: 'expected a line_items error';
});

ac_check('create with an empty line list', ac_req('POST', '/orders', ['line_items' => []]), 400);

ac_check('create with an unknown field', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'wilaya' => 16,
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['wilaya']) ?: 'expected wilaya to be unknown';
});

ac_check('create refusing a caller-supplied line price', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '0.01']],
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['line_items.0.price']) ?: 'expected the price to be refused';
});

ac_check('create with a product that does not exist', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => 99999999, 'quantity' => 1]],
]), 400);

ac_check('create with an unknown customer', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'customer_id' => 99999999,
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['customer_id']) ?: 'expected a customer_id error';
});

ac_check('create with a malformed billing email', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'billing' => ['email' => 'nope'],
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['billing.email']) ?: 'expected a billing.email error';
});

ac_check('create with a country name instead of a code', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'billing' => ['country' => 'Algeria'],
]), 400);

ac_check('create directly as cancelled', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'status' => 'cancelled',
]), 409);

ac_check('create directly as refunded', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'status' => 'refunded',
]), 409);

ac_check('create with an unknown status', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'status' => 'shipped',
]), 400);

echo PHP_EOL, "=== create: the happy path ===", PHP_EOL;

$created = ac_check('create a pending order', ac_req('POST', '/orders', [
    'line_items' => [
        ['product_id' => $kettleId, 'quantity' => 2],
        ['product_id' => $mugId, 'quantity' => 3],
    ],
    'customer_id' => $customer,
    'payment_method' => 'cod',
    'payment_method_title' => 'Cash on delivery',
    'customer_note' => 'Call before delivery',
    'billing' => [
        'first_name' => 'Amina',
        'last_name' => 'Benali',
        'address_1' => '12 Rue Didouche Mourad',
        'city' => 'Alger',
        'state' => 'Alger',
        'country' => 'dz',
        'phone' => '0550123456',
        'email' => 'amina@example.test',
    ],
], []), 201);

$orderId = (int) ($created['data']['id'] ?? 0);

ac_assert('the order got an id', $orderId > 0 ?: 'no id in the response');

ac_check('it is pending', [200, $created], 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'pending' ?: 'status is ' . ($d['data']['status'] ?? '?');
});

ac_check('the total was priced from the catalogue', [200, $created], 200, function ($d) {
    // 2 x 1500 + 3 x 300 = 3900. Never from the request.
    return ($d['data']['total'] ?? '') === '3900.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

ac_check('money is a decimal string, not a float', [200, $created], 200, function ($d) {
    foreach (['total', 'subtotal', 'total_tax', 'shipping_total', 'discount_total'] as $field) {
        if (!is_string($d['data'][$field] ?? null)) {
            return "{$field} is not a string";
        }
    }

    return true;
});

ac_check('the country code was upper-cased', [200, $created], 200, function ($d) {
    return ($d['data']['billing']['country'] ?? '') === 'DZ' ?: 'country is ' . ($d['data']['billing']['country'] ?? '?');
});

ac_check('the customer was attached', [200, $created], 200, function ($d) use ($customer) {
    return ($d['data']['customer_id'] ?? 0) === $customer ?: 'customer_id is ' . ($d['data']['customer_id'] ?? '?');
});

ac_check('a pending order holds no stock', [200, $created], 200, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === false ?: 'stock_reduced is not false';
});

ac_assert(
    'a pending order moved no stock',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 50 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

ac_assert('creating the order wrote no ledger rows', ac_movements($orderId) === [] ?: 'found movements already');

echo PHP_EOL, "=== read ===", PHP_EOL;

ac_check('read it back', ac_req('GET', "/orders/{$orderId}"), 200, function ($d) use ($orderId) {
    return ($d['data']['id'] ?? 0) === $orderId ?: 'wrong order';
});

ac_check('read a missing order', ac_req('GET', '/orders/99999999'), 404);

ac_check('the line items came back in the write shape', ac_req('GET', "/orders/{$orderId}"), 200, function ($d) use ($kettleId) {
    $line = $d['data']['line_items'][0] ?? [];

    foreach (['id', 'name', 'product_id', 'variation_id', 'quantity', 'sku', 'subtotal', 'total'] as $key) {
        if (!array_key_exists($key, $line)) {
            return "line item is missing {$key}";
        }
    }

    return (int) $line['product_id'] === $kettleId ?: 'wrong product on the first line';
});

echo PHP_EOL, "=== the round trip ===", PHP_EOL;

// The pattern every admin client uses: GET, change one field, PATCH the whole
// object back. Every computed field the presenter emits has to survive that.
[, $fetched] = ac_req('GET', "/orders/{$orderId}");
$roundTrip = $fetched['data'];
$roundTrip['customer_note'] = 'Ring the bell twice';

ac_check('PATCH the whole GET body back unchanged', ac_req('PATCH', "/orders/{$orderId}", $roundTrip), 200, function ($d) {
    return ($d['data']['customer_note'] ?? '') === 'Ring the bell twice' ?: 'the note did not stick';
});

ac_check('PATCH with nothing usable', ac_req('PATCH', "/orders/{$orderId}", ['id' => $orderId]), 400);

echo PHP_EOL, "=== list, filters and bounds ===", PHP_EOL;

ac_check('list orders', ac_req('GET', '/orders'), 200, function ($d) {
    return isset($d['meta']['total'], $d['meta']['page'], $d['meta']['per_page']) ?: 'no pagination meta';
});

ac_check('per_page above the maximum is refused', ac_req('GET', '/orders', null, ['per_page' => 500]), 400);
ac_check('page zero is refused', ac_req('GET', '/orders', null, ['page' => 0]), 400);
ac_check('an unknown status filter is refused', ac_req('GET', '/orders', null, ['status' => 'shipped']), 400);
ac_check('an unknown orderby is refused', ac_req('GET', '/orders', null, ['orderby' => 'total_tax']), 400);
ac_check('a malformed date is refused', ac_req('GET', '/orders', null, ['date_from' => '11-08-2026']), 400);

ac_check('on-hold survives the status enum', ac_req('GET', '/orders', null, ['status' => 'on-hold']), 200);

ac_check('filter by status', ac_req('GET', '/orders', null, ['status' => 'pending']), 200, function ($d) {
    foreach ($d['data'] as $order) {
        if ($order['status'] !== 'pending') {
            return 'got a ' . $order['status'] . ' order';
        }
    }

    return true;
});

ac_check('filter by customer', ac_req('GET', '/orders', null, ['customer_id' => $customer]), 200, function ($d) use ($customer, $orderId) {
    $ids = array_column($d['data'], 'id');

    if (!in_array($orderId, $ids, true)) {
        return 'the order is missing from its own customer filter';
    }

    foreach ($d['data'] as $order) {
        if ((int) $order['customer_id'] !== $customer) {
            return 'got an order for customer ' . $order['customer_id'];
        }
    }

    return true;
});

ac_check('filter by a customer with no orders', ac_req('GET', '/orders', null, ['customer_id' => $support]), 200, function ($d) {
    return $d['data'] === [] ?: 'expected no orders';
});

$today = gmdate('Y-m-d');

ac_check('a same-day date range covers the whole day', ac_req('GET', '/orders', null, [
    'date_from' => $today,
    'date_to' => $today,
]), 200, function ($d) use ($orderId) {
    return in_array($orderId, array_column($d['data'], 'id'), true) ?: 'today\'s order is not in today\'s range';
});

ac_check('a past date range excludes it', ac_req('GET', '/orders', null, [
    'date_from' => '2020-01-01',
    'date_to' => '2020-01-02',
]), 200, function ($d) use ($orderId) {
    return !in_array($orderId, array_column($d['data'], 'id'), true) ?: 'the order leaked into 2020';
});

ac_check('search by order id', ac_req('GET', '/orders', null, ['search' => (string) $orderId]), 200, function ($d) use ($orderId) {
    return in_array($orderId, array_column($d['data'], 'id'), true) ?: 'search did not find the order by id';
});

echo PHP_EOL, "=== status transitions ===", PHP_EOL;

ac_check('pending cannot jump to refunded', ac_req('PATCH', "/orders/{$orderId}", ['status' => 'refunded']), 409, function ($d) {
    $allowed = $d['error']['details']['allowed'] ?? [];

    return (is_array($allowed) && !in_array('refunded', $allowed, true))
        ?: 'the refusal should list what is reachable, without refunded';
});

ac_check('an unknown status is a validation error, not a conflict', ac_req('PATCH', "/orders/{$orderId}", ['status' => 'delivered']), 400);

ac_check('re-setting the current status is a no-op', ac_req('PATCH', "/orders/{$orderId}", ['status' => 'pending']), 200);

echo PHP_EOL, "=== stock and the ledger ===", PHP_EOL;

ac_check('pending to processing', ac_req('PATCH', "/orders/{$orderId}", ['status' => 'processing']), 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'processing' ?: 'status is ' . ($d['data']['status'] ?? '?');
});

ac_assert(
    'the kettle lost 2 units',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 48 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

ac_assert(
    'the mug lost 3 units',
    (int) wc_get_product($mugId)->get_stock_quantity() === 17 ?: 'mug is at ' . wc_get_product($mugId)->get_stock_quantity()
);

$reduced = ac_movements($orderId);

ac_assert('two ledger rows were written', count($reduced) === 2 ?: 'got ' . count($reduced) . ' rows');

ac_assert('both rows say order_reduced', (function () use ($reduced) {
    foreach ($reduced as $row) {
        if ($row['reason'] !== 'order_reduced') {
            return 'found reason ' . $row['reason'];
        }
    }

    return true;
})());

ac_assert('every row balances', (function () use ($reduced) {
    foreach ($reduced as $row) {
        if ((int) $row['quantity_before'] + (int) $row['delta'] !== (int) $row['quantity_after']) {
            return sprintf('%d + %d != %d', $row['quantity_before'], $row['delta'], $row['quantity_after']);
        }
    }

    return true;
})());

ac_assert('the deltas are negative and match the quantities ordered', (function () use ($reduced, $kettleId, $mugId) {
    $byProduct = [];
    foreach ($reduced as $row) {
        $byProduct[(int) $row['product_id']] = (int) $row['delta'];
    }

    if (($byProduct[$kettleId] ?? null) !== -2) {
        return 'kettle delta is ' . var_export($byProduct[$kettleId] ?? null, true);
    }

    return ($byProduct[$mugId] ?? null) === -3 ?: 'mug delta is ' . var_export($byProduct[$mugId] ?? null, true);
})());

ac_check('the order now reports its stock as taken', ac_req('GET', "/orders/{$orderId}"), 200, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === true ?: 'stock_reduced is not true';
});

// WooCommerce marks each item once, so a second pass through a reducing
// status must not deduct again.
ac_req('PATCH', "/orders/{$orderId}", ['status' => 'on-hold']);
ac_req('PATCH', "/orders/{$orderId}", ['status' => 'processing']);

ac_assert(
    'a second reducing transition does not double-deduct',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 48 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

ac_assert('and wrote no extra ledger rows', count(ac_movements($orderId)) === 2 ?: 'now ' . count(ac_movements($orderId)) . ' rows');

echo PHP_EOL, "=== editing a committed order ===", PHP_EOL;

ac_check('line items cannot be rewritten once stock is held', ac_req('PATCH', "/orders/{$orderId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
]), 409);

/*
 * The case the *second* guard exists for. `processing` is refused by
 * WooCommerce's own is_editable() rule, so the check above never reaches the
 * stock test. `on-hold` is editable and still holds stock — rewriting its
 * lines would drop the _reduced_stock markers and strand those units.
 */
ac_req('PATCH', "/orders/{$orderId}", ['status' => 'on-hold']);

ac_check('an editable order still holding stock is refused too', ac_req('PATCH', "/orders/{$orderId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
]), 409, function ($d) {
    return ($d['error']['details']['stock_reduced'] ?? null) === true
        ?: 'refused by the editability rule, not the stock rule';
});

ac_req('PATCH', "/orders/{$orderId}", ['status' => 'processing']);

ac_check('but the billing phone can still be corrected', ac_req('PATCH', "/orders/{$orderId}", [
    'billing' => ['phone' => '0660999888'],
]), 200, function ($d) {
    return ($d['data']['billing']['phone'] ?? '') === '0660999888' ?: 'the phone did not stick';
});

echo PHP_EOL, "=== cancellation ===", PHP_EOL;

ac_check('cancel it', ac_req('POST', "/orders/{$orderId}/cancel", ['reason' => 'Customer unreachable']), 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'cancelled' ?: 'status is ' . ($d['data']['status'] ?? '?');
});

ac_assert(
    'the kettle got its 2 units back',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 50 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

ac_assert(
    'the mug got its 3 units back',
    (int) wc_get_product($mugId)->get_stock_quantity() === 20 ?: 'mug is at ' . wc_get_product($mugId)->get_stock_quantity()
);

$all = ac_movements($orderId);

ac_assert('the ledger has four rows now', count($all) === 4 ?: 'got ' . count($all));

ac_assert('the last two are order_restored', (function () use ($all) {
    foreach (array_slice($all, 2) as $row) {
        if ($row['reason'] !== 'order_restored') {
            return 'found reason ' . $row['reason'];
        }
    }

    return true;
})());

ac_assert('the ledger nets to zero for this order', (function () use ($all) {
    $net = 0;
    foreach ($all as $row) {
        $net += (int) $row['delta'];
    }

    return $net === 0 ?: "net is {$net}";
})());

ac_assert('every row carries the order id', (function () use ($all, $orderId) {
    foreach ($all as $row) {
        if ((int) $row['order_id'] !== $orderId) {
            return 'found order_id ' . $row['order_id'];
        }
    }

    return true;
})());

ac_check('cancelling again is a no-op, not an error', ac_req('POST', "/orders/{$orderId}/cancel"), 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'cancelled' ?: 'status changed';
});

ac_assert('and moved no further stock', count(ac_movements($orderId)) === 4 ?: 'now ' . count(ac_movements($orderId)) . ' rows');

ac_check('a cancelled order cannot be reopened', ac_req('PATCH', "/orders/{$orderId}", ['status' => 'processing']), 409);

ac_check('an over-long cancellation reason is refused', ac_req('POST', "/orders/{$orderId}/cancel", [
    'reason' => str_repeat('x', 501),
]), 400);

echo PHP_EOL, "=== terminal states ===", PHP_EOL;

$second = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'status' => 'processing',
]);
$secondId = (int) ($second[1]['data']['id'] ?? 0);

ac_check('create straight into processing', $second, 201, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === true ?: 'stock was not taken on create';
});

ac_assert(
    'and it took stock immediately',
    (int) wc_get_product($mugId)->get_stock_quantity() === 19 ?: 'mug is at ' . wc_get_product($mugId)->get_stock_quantity()
);

ac_assert('with a ledger row to match', count(ac_movements($secondId)) === 1 ?: 'got ' . count(ac_movements($secondId)) . ' rows');

ac_check('processing to completed', ac_req('PATCH', "/orders/{$secondId}", ['status' => 'completed']), 200);
ac_check('a completed order cannot be cancelled', ac_req('POST', "/orders/{$secondId}/cancel"), 409);
ac_check('but it can be refunded', ac_req('PATCH', "/orders/{$secondId}", ['status' => 'refunded']), 200);
ac_check('and a refunded order goes nowhere', ac_req('PATCH', "/orders/{$secondId}", ['status' => 'completed']), 409);

echo PHP_EOL, "=== audit trail ===", PHP_EOL;

$actions = ac_audit_actions($orderId);

ac_assert('the creation was audited', in_array('order.created', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the status change was audited', in_array('order.status_changed', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the cancellation was audited', in_array('order.cancelled', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the field edit was audited', in_array('order.updated', $actions, true) ?: 'saw ' . implode(', ', $actions));

// An Order Manager cannot read the audit log — the role has ac_manage_orders
// but not ac_view_audit_logs, which is the least-privilege split working.
ac_check('an order manager cannot read the audit log', ac_req('GET', '/audit-logs'), 403);

wp_set_current_user(ac_user('ac_ord_auditor', 'ac_admin'));

ac_check('the audit log can be filtered to order events', ac_req('GET', '/audit-logs', null, [
    'action' => 'order.status_changed',
]), 200, function ($d) {
    if ($d['data'] === []) {
        return 'the dotted action filter matched nothing';
    }

    foreach ($d['data'] as $row) {
        if ($row['action'] !== 'order.status_changed') {
            return 'got ' . $row['action'];
        }
    }

    return true;
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
