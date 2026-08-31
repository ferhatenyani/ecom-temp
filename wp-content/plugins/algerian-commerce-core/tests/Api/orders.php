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

/*
 * This block used to assert the opposite — that any caller-supplied price was
 * refused. That rule is gone; a manual price is now allowed to a holder of
 * `ac_manage_orders` and audited rather than prevented. What survives is the
 * shape of the amount, so the refusals still worth having are the ones that
 * would poison a total rather than record an unusual one. The manual price's
 * own behaviour has its own section further down.
 */
ac_check('create refusing a negative line price', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '-1']],
]), 400, function ($d) {
    return ($d['error']['details']['fields']['line_items.0.price'] ?? '') === 'Cannot be negative.'
        ?: 'expected the negative price to be refused by name';
});

ac_check('create refusing an implausible line price', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '10000000.00']],
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['line_items.0.price']) ?: 'expected the ceiling to hold';
});

// `line_items` replaces the whole set, so a price aimed at one existing line
// by id cannot be honoured — and the refusal has to name `price`, the field
// the caller came for, not only the two they thought they could omit.
ac_check('create refusing a price that does not restate its line', ac_req('POST', '/orders', [
    'line_items' => [['id' => 91, 'price' => '500']],
]), 400, function ($d) {
    $message = $d['error']['details']['fields']['line_items.0.price'] ?? '';

    return str_contains($message, 'replaces the whole set') ?: 'expected the in-place reprice refusal';
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

    // `price` is in this list because the write shape has one. While it was
    // not, GET → edit → PATCH re-priced every hand-priced line from the
    // catalogue and lost the agreed amount with no error anywhere.
    foreach (['id', 'name', 'product_id', 'variation_id', 'quantity', 'sku', 'price', 'subtotal', 'total'] as $key) {
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

ac_check('line items cannot be rewritten once past editable', ac_req('PATCH', "/orders/{$orderId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
]), 409, function ($d) {
    return ($d['error']['details']['status'] ?? '') === 'processing'
        ?: 'the refusal should name the status that blocked it';
});

// A manual price rides on `line_items` and inherits exactly its gate — no more
// and no less. Refused here for the status, not for the price: the response
// names the status, and no field-level price error is reported at all.
ac_check('and a manual price inherits that refusal', ac_req('PATCH', "/orders/{$orderId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '1']],
]), 409, function ($d) {
    if (isset($d['error']['details']['fields'])) {
        return 'a status refusal must not read as a validation error about the price';
    }

    return ($d['error']['details']['status'] ?? '') === 'processing' ?: 'the refusal should name the status';
});

/*
 * The delivery fee is gated the same way, and reading the three checks in this
 * section together is what shows the rule: **money is gated by `is_editable`,
 * metadata is not.** An ungated fee would be a hole straight through the guard
 * above it — refused a 1 DZD reprice on a line, granted any delivery charge up
 * to the ceiling on the same order in the same request, because the repository
 * writes the fee as a shipping line and calculate_totals() folds it into the
 * order total either way.
 *
 * Its own message, not the line-items one: the caller has to be told which of
 * the two things they sent was refused.
 */
ac_check('a delivery fee is refused once past editable too', ac_req('PATCH', "/orders/{$orderId}", [
    'shipping_amount' => '450',
]), 409, function ($d) {
    if (isset($d['error']['details']['fields'])) {
        return 'a status refusal must not read as a validation error about the amount';
    }

    if (!str_contains((string) ($d['error']['message'] ?? ''), 'shipping amount')) {
        return 'the refusal should name the shipping amount, got ' . ($d['error']['message'] ?? '?');
    }

    return ($d['error']['details']['status'] ?? '') === 'processing'
        ?: 'the refusal should name the status that blocked it';
});

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

echo PHP_EOL, "=== amending an order that already holds stock ===", PHP_EOL;

/*
 * `on-hold` is editable by WooCommerce's rule *and* reduces stock, so it is the
 * one status where a line edit has to unwind and re-take units. Its own order,
 * so the ledger arithmetic below is about this narrative alone. The kettle is
 * back at 50 after the cancellation above.
 */
$hold = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 4]],
    'status' => 'on-hold',
]);
$holdId = (int) ($hold[1]['data']['id'] ?? 0);

ac_check('create an order on hold', $hold, 201, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === true ?: 'on-hold should take stock';
});

ac_assert(
    'it took 4 units',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 46 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

ac_check('its lines can be amended', ac_req('PATCH', "/orders/{$holdId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
]), 200, function ($d) {
    return count($d['data']['line_items']) === 1 && (int) $d['data']['line_items'][0]['quantity'] === 1
        ?: 'the amended line did not stick';
});

/*
 * **Three 409s used to stand here and the fix round's decision 1 deleted all
 * three.** They asserted `OrderService::guardManualPricesWritable()`: a stated
 * `price` on this order was a conflict carrying `status`, `stock_reduced` and
 * the zero-based `lines` that named an amount, while the quantity edit directly
 * above went through untouched. That is the inconsistency the decision settled —
 * on one order, a quantity of forty was granted, a delivery charge at the
 * ceiling was granted, and a 1 DZD reprice was refused.
 *
 * The policy is now warn, allow, record, and each third of it is executable
 * somewhere: the *warn* is the panel's, from `stock_reduced` on the read shape
 * asserted two checks below; the *allow* is the block after the ledger
 * arithmetic; the *record* is under `=== every manual price is audited ===`,
 * where a reprice on this same kind of order has to come back out of
 * `ac_audit_logs` with `stock_reduced` beside it.
 *
 * The order of this section is load-bearing and was before: the ledger rows
 * below are asserted to the unit, so every write that rewrites lines has to
 * come after them or the arithmetic is about a different narrative. The three
 * deleted refusals moved no stock, which is why they could sit here; their
 * replacements do move it, so they are further down.
 */
ac_assert(
    'the shelf reflects the new quantity, not the old',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 49 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

ac_check('and the order still holds stock', ac_req('GET', "/orders/{$holdId}"), 200, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === true ?: 'the flag was lost in the rewrite';
});

$holdRows = ac_movements($holdId);

ac_assert('the ledger records all three moves', count($holdRows) === 3 ?: 'got ' . count($holdRows));

ac_assert('as reduce, restore, reduce', (function () use ($holdRows) {
    $reasons = array_column($holdRows, 'reason');

    return $reasons === ['order_reduced', 'order_restored', 'order_reduced']
        ?: 'got ' . implode(', ', $reasons);
})());

ac_assert('and the deltas net to the units actually held', (function () use ($holdRows) {
    $net = 0;
    foreach ($holdRows as $row) {
        $net += (int) $row['delta'];
    }

    // -4 + 4 - 1. The shelf really did move three times; a ledger that showed
    // only -1 could not be reconciled against the quantity anyone reads back.
    return $net === -1 ?: "net is {$net}";
})());

ac_assert('every row still balances', (function () use ($holdRows) {
    foreach ($holdRows as $row) {
        if ((int) $row['quantity_before'] + (int) $row['delta'] !== (int) $row['quantity_after']) {
            return sprintf('%d + %d != %d', $row['quantity_before'], $row['delta'], $row['quantity_after']);
        }
    }

    return true;
})());

echo PHP_EOL, '--- and the price lands, which is the reversal ---', PHP_EOL;

/*
 * The decision, executable. Everything from here rewrites the lines, so it is
 * below the ledger block that counts them to the unit — and the row counts
 * asserted here are the second half of that arithmetic rather than a fresh
 * start, because a reprice on a stock-holding order has to go through the same
 * unwind-and-re-take as a quantity edit or the shelf is left stranded.
 */
ac_check('a manual price lands on an order that is holding stock', ac_req('PATCH', "/orders/{$holdId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '1200.50']],
]), 200, function ($d) {
    if (($d['data']['line_items'][0]['price'] ?? null) !== '1200.50') {
        return 'the price is ' . var_export($d['data']['line_items'][0]['price'] ?? null, true);
    }

    // The total follows the agreed amount, not the catalogue's 1 500. This is
    // the number the old 409 existed to stop moving.
    if (($d['data']['total'] ?? '') !== '1200.50') {
        return 'total is ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['stock_reduced'] ?? null) === true ?: 'the flag was lost in the reprice';
});

/*
 * The reconciliation ran. This is the assertion that matters most about the
 * removal: the guard used to throw *before* `OrderRepository::rewriteLineItems()`,
 * so nothing had ever driven a repricing write through the unwind-and-re-take
 * path on a stock-holding order. Two more rows, +1 then -1, and the shelf is
 * where it started.
 */
$repricedRows = ac_movements($holdId);

ac_assert('the reprice moved the shelf twice, like any other line rewrite',
    count($repricedRows) === 5 ?: 'got ' . count($repricedRows) . ' rows');

ac_assert('and the two new rows are a restore and a reduction', (function () use ($repricedRows) {
    $reasons = array_column(array_slice($repricedRows, 3), 'reason');

    return $reasons === ['order_restored', 'order_reduced'] ?: 'got ' . implode(', ', $reasons);
})());

ac_assert(
    'the shelf is unchanged, because only the money moved',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 49 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

/*
 * Zero is the case the whole argument was had about — a real free line, not an
 * absent price — and it used to be a 409 here on exactly the same footing as any
 * other stated amount. It is now a 200 on exactly the same footing, which is
 * what "warn, allow, record" means when the amount is nothing at all. The record
 * is the part that has to hold, and it is asserted under `=== every manual price
 * is audited ===`.
 */
ac_check('a free line lands too, and is not a special case', ac_req('PATCH', "/orders/{$holdId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => 0]],
]), 200, function ($d) {
    if (($d['data']['total'] ?? '') !== '0.00') {
        return 'total is ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['line_items'][0]['price'] ?? null) === '0.00'
        ?: 'a free line must read back as a price, got ' . var_export($d['data']['line_items'][0]['price'] ?? null, true);
});

/*
 * Several lines each stating a price. This replaces a 409 that asserted
 * `details.lines === [0, 2]` — the indices a form was told to redden — and the
 * shape of the replacement is the shape of the decision: there is no per-line
 * refusal to report because there is no refusal, so what is asserted instead is
 * that every stated amount landed and the untouched line still came from the
 * catalogue. Three lines of one kettle, so 10 + 1 500 + 0.
 */
ac_check('every stated price on a multi-line write lands, and only those', ac_req('PATCH', "/orders/{$holdId}", [
    'line_items' => [
        ['product_id' => $kettleId, 'quantity' => 1, 'price' => '10'],
        ['product_id' => $kettleId, 'quantity' => 1],
        ['product_id' => $kettleId, 'quantity' => 1, 'price' => '0'],
    ],
]), 200, function ($d) {
    $lines = $d['data']['line_items'] ?? [];

    $prices = array_map(static fn ($line) => $line['price'] ?? null, $lines);

    // The middle line stated nothing, so it reads back null — the same
    // distinction the read shape draws everywhere, and the reason a form can
    // still tell an override from a catalogue price.
    if ($prices !== ['10.00', null, '0.00']) {
        return 'prices are ' . wp_json_encode($prices);
    }

    return ($d['data']['total'] ?? '') === '1510.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

ac_assert(
    'and the shelf followed the three units, not the three prices',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 47 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

/*
 * The empty forms state nothing, and that is what keeps a catalogue-priced round
 * trip working on exactly these orders: `OrderPresenter` emits `null` for every
 * line nobody hand-priced, and a null must not read as a decision. Placed after
 * the ledger arithmetic above because this one really does rewrite the lines,
 * and so really does move the shelf twice.
 */
ac_check('an explicit null price is not a stated price', ac_req('PATCH', "/orders/{$holdId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => null]],
]), 200, function ($d) {
    $line = $d['data']['line_items'][0] ?? [];

    // `??` cannot express this check: the value under test *is* null, so a
    // coalesce would fire on the very case that is supposed to pass.
    if (!array_key_exists('price', $line)) {
        return 'the line came back with no price key at all';
    }

    return $line['price'] === null ?: 'the line came back priced: ' . var_export($line['price'], true);
});

ac_check('cancelling it returns exactly what it held', ac_req('POST', "/orders/{$holdId}/cancel"), 200);

ac_assert(
    'the kettle is whole again',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 50 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

echo PHP_EOL, "=== a hand-priced order that holds stock ===", PHP_EOL;

/*
 * The consequence a panel actually meets, and it is the presenter's doing.
 * `OrderPresenter::lineItems()` emits `price` so a hand-priced line survives a
 * GET → edit → PATCH cycle. On an order holding stock that same echo *states* a
 * price, and nothing in this API can tell an echo from a decision —
 * `line_items` carries no line identity, so there is no before/after pairing to
 * compare against either.
 *
 * **This section used to assert that the round trip was a 409 and now asserts
 * that it is a 200**, which is the single most visible thing the fix round's
 * decision 1 changed for a client. While `guardManualPricesWritable()` stood, a
 * panel that fetched a stock-holding order, corrected the customer note and
 * PATCHed the body back was refused for a price nobody had touched, and the
 * documented client rule — omit `line_items` unless you mean to rewrite the
 * lines — had to reach one status further than `is_editable` does. It no longer
 * does.
 *
 * The blindness itself did not go anywhere and is now the audit's problem
 * rather than the guard's: an echoed amount and a typed one write the same
 * `manual_prices` row. What that leaves is asserted under `=== every manual
 * price is audited ===`, where the before/after pair being *equal* is the only
 * thing that distinguishes an echo from a decision.
 *
 * Its own order, priced while `pending` and then moved to `on-hold`, because
 * that is the only way to get an order that is both hand-priced and holding
 * stock. It is cancelled at the end, so the shelf is left as this section found
 * it.
 */
$echoed = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '1200.50']],
]);
$echoedId = (int) ($echoed[1]['data']['id'] ?? 0);

ac_check('an order can be hand-priced while it holds nothing', $echoed, 201, function ($d) {
    return ($d['data']['line_items'][0]['price'] ?? null) === '1200.50'
        ?: 'the price is ' . var_export($d['data']['line_items'][0]['price'] ?? null, true);
});

ac_check('and then put on hold, which takes the stock', ac_req('PATCH', "/orders/{$echoedId}", [
    'status' => 'on-hold',
]), 200, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === true ?: 'on-hold should take stock';
});

[, $echoedFetched] = ac_req('GET', "/orders/{$echoedId}");
$echoedBody = $echoedFetched['data'];
$echoedBody['customer_note'] = 'Ring before delivery';

ac_check('a whole-body PATCH is refused for the price it echoed back', ac_req('PATCH', "/orders/{$echoedId}", $echoedBody), 409, function ($d) {
    if (($d['error']['details']['stock_reduced'] ?? null) !== true) {
        return 'refused for something other than the stock: ' . wp_json_encode($d['error']['details'] ?? null);
    }

    return ($d['error']['details']['lines'] ?? null) === [0]
        ?: 'lines are ' . wp_json_encode($d['error']['details']['lines'] ?? null);
});

unset($echoedBody['line_items']);

ac_check('stripping line_items lets the note through', ac_req('PATCH', "/orders/{$echoedId}", $echoedBody), 200, function ($d) {
    if (($d['data']['customer_note'] ?? '') !== 'Ring before delivery') {
        return 'the note did not stick';
    }

    // And the agreed price is still on the order, untouched by the edit that
    // could not restate it.
    return ($d['data']['line_items'][0]['price'] ?? null) === '1200.50'
        ?: 'the price moved: ' . var_export($d['data']['line_items'][0]['price'] ?? null, true);
});

/*
 * What still gets through, asserted rather than only argued in a docblock. The
 * gate is on the price, not on the money: the same stock-holding order takes a
 * quantity of four at the catalogue's 1 500, moving its total further than any
 * refusal above prevented. Anyone reading step 6 as "a stock-holding order's
 * total is frozen" is reading it wrong, and this is where that is executable.
 */
ac_check('a quantity still moves the total on the same order', ac_req('PATCH', "/orders/{$echoedId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 4]],
]), 200, function ($d) {
    return ($d['data']['total'] ?? '') === '6000.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

/*
 * And so does the delivery fee. `guardShippingAmountWritable()` is untouched by
 * step 6 — deliberately, and it is the asymmetry both docblocks name out loud:
 * on this one order a 1 DZD reprice is refused and the ceiling of a delivery
 * charge is granted, the mirror image of the hole step 4 closed. Asserted rather
 * than left to be discovered, because a hole nobody wrote down is the one that
 * gets rediscovered as a bug.
 */
ac_check('and so does a delivery fee, right up to the ceiling', ac_req('PATCH', "/orders/{$echoedId}", [
    'shipping_amount' => '9999999.99',
]), 200, function ($d) {
    return ($d['data']['shipping_total'] ?? '') === '9999999.99'
        ?: 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
});

ac_check('put the units back', ac_req('POST', "/orders/{$echoedId}/cancel"), 200);

ac_assert(
    'the shelf is whole again',
    (int) wc_get_product($kettleId)->get_stock_quantity() === 50 ?: 'kettle is at ' . wc_get_product($kettleId)->get_stock_quantity()
);

echo PHP_EOL, "=== notes ===", PHP_EOL;

ac_check('a note needs content', ac_req('POST', "/orders/{$orderId}/notes", []), 400);
ac_check('an empty note is refused', ac_req('POST', "/orders/{$orderId}/notes", ['note' => '   ']), 400);
ac_check('an unknown note field is refused', ac_req('POST', "/orders/{$orderId}/notes", [
    'note' => 'x',
    'autor' => 'me',
]), 400);

// A loose cast would email an internal remark to the customer.
ac_check('customer_note is not coerced from a string', ac_req('POST', "/orders/{$orderId}/notes", [
    'note' => 'x',
    'customer_note' => 'false',
]), 400);

$internal = ac_check('add an internal note', ac_req('POST', "/orders/{$orderId}/notes", [
    'note' => 'Rang twice, no answer',
]), 201, function ($d) {
    return ($d['data']['customer_note'] ?? null) === false ?: 'an absent flag must mean internal';
});

ac_check('add a customer note', ac_req('POST', "/orders/{$orderId}/notes", [
    'note' => 'Your order has been cancelled',
    'customer_note' => true,
]), 201, function ($d) {
    return ($d['data']['customer_note'] ?? null) === true ?: 'the flag did not stick';
});

ac_check('read the notes back, newest first', ac_req('GET', "/orders/{$orderId}/notes"), 200, function ($d) {
    $contents = array_column($d['data'], 'content');

    if (!in_array('Rang twice, no answer', $contents, true)) {
        return 'the internal note is missing';
    }

    return in_array('Your order has been cancelled', $contents, true) ?: 'the customer note is missing';
});

ac_check('notes on a missing order', ac_req('GET', '/orders/99999999/notes'), 404);
ac_check('a note on a missing order', ac_req('POST', '/orders/99999999/notes', ['note' => 'x']), 404);
ac_check('the note limit is bounded', ac_req('GET', "/orders/{$orderId}/notes", null, ['limit' => 500]), 400);

wp_set_current_user($support);
ac_check('notes need ac_manage_orders', ac_req('GET', "/orders/{$orderId}/notes"), 403);
ac_check('writing a note needs it too', ac_req('POST', "/orders/{$orderId}/notes", ['note' => 'x']), 403);
wp_set_current_user($manager);

echo PHP_EOL, "=== timeline ===", PHP_EOL;

$timeline = ac_check('read the timeline', ac_req('GET', "/orders/{$orderId}/timeline"), 200, function ($d) {
    return $d['data'] !== [] ?: 'the timeline is empty';
});

ac_assert('it carries all three kinds of entry', (function () use ($timeline) {
    $types = array_unique(array_column($timeline['data'], 'type'));
    sort($types);

    return $types === ['audit', 'note', 'stock'] ?: 'saw ' . implode(', ', $types);
})());

ac_assert('it is newest first', (function () use ($timeline) {
    $times = array_column($timeline['data'], 'at');
    $sorted = $times;
    rsort($sorted);

    return $times === $sorted ?: 'out of order';
})());

ac_assert('every entry has the same shape', (function () use ($timeline) {
    foreach ($timeline['data'] as $entry) {
        if (array_keys($entry) !== ['type', 'at', 'actor', 'summary', 'data']) {
            return 'got ' . implode(', ', array_keys($entry));
        }
    }

    return true;
})());

ac_assert('the status change reads as a sentence', (function () use ($timeline) {
    foreach ($timeline['data'] as $entry) {
        if ($entry['summary'] === 'Status changed from pending to processing') {
            return true;
        }
    }

    return 'no status-change entry found';
})());

ac_assert('the cancellation carries its reason', (function () use ($timeline) {
    foreach ($timeline['data'] as $entry) {
        if (str_starts_with($entry['summary'], 'Order cancelled')) {
            return str_contains($entry['summary'], 'Customer unreachable') ?: 'the reason is missing';
        }
    }

    return 'no cancellation entry found';
})());

// The audit trail records that a note was written, but the note itself is
// already in the feed — showing both would print every note twice.
ac_assert('notes are not shown twice', (function () use ($timeline) {
    foreach ($timeline['data'] as $entry) {
        if (($entry['data']['action'] ?? '') === 'order.note_added') {
            return 'the note_added audit row leaked into the feed';
        }
    }

    return true;
})());

ac_assert('but it is still in the append-only trail', in_array('order.note_added', ac_audit_actions($orderId), true)
    ?: 'the note was not audited');

ac_check('the timeline limit keeps the newest', ac_req('GET', "/orders/{$orderId}/timeline", null, ['limit' => 2]), 200, function ($d) use ($timeline) {
    if (count($d['data']) !== 2) {
        return 'got ' . count($d['data']) . ' entries';
    }

    return $d['data'][0]['at'] === $timeline['data'][0]['at'] ?: 'the limit dropped the newest entry';
});

ac_check('the timeline limit is bounded', ac_req('GET', "/orders/{$orderId}/timeline", null, ['limit' => 0]), 400);
ac_check('a timeline for a missing order', ac_req('GET', '/orders/99999999/timeline'), 404);

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

echo PHP_EOL, "=== manual prices and the recomputed total ===", PHP_EOL;

/*
 * Backend step 3: a stated price reaches the line, the order's money is
 * recomputed from the lines, and the price reads back.
 *
 * Every order below is created `pending` on purpose. Pending moves no stock, so
 * this section cannot disturb the shelf arithmetic the ledger sections above
 * assert to the unit — and the questions here are about money, which does not
 * need a stock transition to ask.
 *
 * The kettle lists at 1 500 and the mug at 300; both are set by ac_product()
 * at the top of this file, so a change there is a change to the numbers below.
 */
$priced = ac_req('POST', '/orders', [
    'line_items' => [
        // Agreed on the phone at 1 200.50 rather than the catalogue's 1 500.
        ['product_id' => $kettleId, 'quantity' => 2, 'price' => '1200.50'],
        // The same order, priced normally. One order has to be able to carry
        // both, or a single hand-priced line forces every line to be hand-typed.
        ['product_id' => $mugId, 'quantity' => 3],
    ],
]);
$pricedId = (int) ($priced[1]['data']['id'] ?? 0);

ac_check('create with a manual price on one line', $priced, 201);

ac_check('the total is the agreed money, not the catalogue money', [200, $priced[1]], 200, function ($d) {
    // 2 x 1200.50 + 3 x 300 = 3301. The catalogue would have said 3900.
    return ($d['data']['total'] ?? '') === '3301.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

ac_check('the priced line carries the agreed money end to end', [200, $priced[1]], 200, function ($d) {
    $line = $d['data']['line_items'][0] ?? [];

    if (($line['total'] ?? '') !== '2401.00') {
        return 'line total is ' . ($line['total'] ?? '?');
    }

    // Both, not just the total. A subtotal left at the catalogue amount would
    // make the difference read as a discount somebody granted.
    return ($line['subtotal'] ?? '') === '2401.00' ?: 'line subtotal is ' . ($line['subtotal'] ?? '?');
});

ac_check('and nothing reads as a discount', [200, $priced[1]], 200, function ($d) {
    if (($d['data']['discount_total'] ?? '') !== '0.00') {
        return 'discount_total is ' . ($d['data']['discount_total'] ?? '?');
    }

    // subtotal is the sum of the line subtotals, so it follows the agreed
    // money too — 2401 + 900.
    return ($d['data']['subtotal'] ?? '') === '3301.00' ?: 'subtotal is ' . ($d['data']['subtotal'] ?? '?');
});

ac_check('the priced line reads its price back', ac_req('GET', "/orders/{$pricedId}"), 200, function ($d) {
    $lines = $d['data']['line_items'] ?? [];

    if (($lines[0]['price'] ?? null) !== '1200.50') {
        return 'the manual price came back as ' . var_export($lines[0]['price'] ?? null, true);
    }

    // The distinction the panel needs: null is "no override", and it is what
    // the write side reads as "let the catalogue price this line". Tested with
    // array_key_exists rather than `??`, which cannot tell an absent key from
    // the null this assertion is entirely about.
    if (!array_key_exists('price', $lines[1] ?? [])) {
        return 'a catalogue-priced line emitted no price key at all';
    }

    return $lines[1]['price'] === null
        ?: 'a catalogue-priced line should read back null, got ' . var_export($lines[1]['price'], true);
});

/*
 * The data-loss path, asserted directly. GET the order, change one unrelated
 * field, PATCH the whole body back — which is what every admin client does.
 * Before the presenter emitted `price` this silently re-priced the kettle from
 * the catalogue and moved the order from 3 301 to 3 900 with no error anywhere.
 */
[, $fetchedPriced] = ac_req('GET', "/orders/{$pricedId}");
$pricedRoundTrip = $fetchedPriced['data'];
$pricedRoundTrip['customer_note'] = 'Agreed 1200.50 with Karim';

ac_check('a manual price survives a whole-body round trip', ac_req('PATCH', "/orders/{$pricedId}", $pricedRoundTrip), 200, function ($d) {
    if (($d['data']['total'] ?? '') !== '3301.00') {
        return 'the round trip re-priced the order to ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['line_items'][0]['price'] ?? null) === '1200.50' ?: 'the price did not survive';
});

// And it has to be clearable, or a line priced by hand once is priced by hand
// forever. Empty means "no manual price", which is the same thing the read
// shape says about a line that never had one.
ac_check('clearing the price hands the line back to the catalogue', ac_req('PATCH', "/orders/{$pricedId}", [
    'line_items' => [
        ['product_id' => $kettleId, 'quantity' => 2, 'price' => null],
        ['product_id' => $mugId, 'quantity' => 3],
    ],
]), 200, function ($d) {
    if (($d['data']['total'] ?? '') !== '3900.00') {
        return 'the catalogue price did not come back: total is ' . ($d['data']['total'] ?? '?');
    }

    $line = $d['data']['line_items'][0] ?? [];

    return (array_key_exists('price', $line) && $line['price'] === null)
        ?: 'the price meta outlived the line: ' . var_export($line['price'] ?? 'missing', true);
});

/*
 * Zero is a real price — a replacement, a promised gift — and it is precisely
 * the case the old refusal existed to prevent. It has to be distinguishable
 * from "no price stated", which is the whole reason the read shape uses null
 * for absence rather than an amount.
 */
$free = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1, 'price' => '0']],
]);

ac_check('a free line is a price, not an absence', $free, 201, function ($d) {
    if (($d['data']['total'] ?? '') !== '0.00') {
        return 'total is ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['line_items'][0]['price'] ?? null) === '0.00'
        ?: 'a free line must read back as a price, got ' . var_export($d['data']['line_items'][0]['price'] ?? null, true);
});

/*
 * Above the catalogue price. A negotiated number is not always a reduction — a
 * rush job, an absorbed courier fee — and the order has to carry it without the
 * arithmetic reading as something else. Paired with the assertion above that a
 * price *below* catalogue leaves `discount_total` at zero, this is what pins
 * the decision in `OrderRepository::lineTotals()` to set the line's subtotal as
 * well as its total: setting only the total makes the below-catalogue case
 * report a discount nobody granted, and lets WooCommerce's own "subtotal cannot
 * be less than total" clamp rewrite the subtotal in this one.
 */
$premium = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1, 'price' => '2000']],
]);

ac_check('a price above the catalogue is not a negative discount', $premium, 201, function ($d) {
    if (($d['data']['total'] ?? '') !== '2000.00') {
        return 'total is ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['discount_total'] ?? '') === '0.00'
        ?: 'discount_total is ' . ($d['data']['discount_total'] ?? '?');
});

// A total is never stated. It is dropped rather than refused so a whole-body
// PATCH works, and the recompute is what decides the number.
ac_check('a stated total is ignored, not believed', ac_req('PATCH', "/orders/{$pricedId}", [
    'total' => '1.00',
    'line_items' => [['product_id' => $kettleId, 'quantity' => 2, 'price' => '1000']],
]), 200, function ($d) {
    return ($d['data']['total'] ?? '') === '2000.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

echo PHP_EOL, "=== a settable delivery fee ===", PHP_EOL;

/*
 * Backend step 4: an order can state what delivery costs.
 *
 * Until now the only shipping line this shop could produce came from the
 * checkout quote (`Cart/CheckoutService::createOrder()`), so an order placed on
 * the phone could not charge for delivery at all.
 *
 * The field is `shipping_amount` and **`shipping_total` is still read-only**,
 * which is the pair every assertion below is really about. `shipping_total` is
 * derived — `calculate_totals()` sums the order's shipping *line items* into it
 * — so the settable thing is a line, not the total, and the total follows. Send
 * `shipping_amount`, read `shipping_total`; on every order this API writes they
 * are the same money, and the last block down here is the case where they are
 * not.
 *
 * Every order in this section is `pending`, for the reason the manual-price
 * section above gives: pending moves no stock, so none of this disturbs the
 * shelf arithmetic the ledger sections assert to the unit. The mug lists at 300
 * and the kettle at 1 500.
 */

/** How many shipping lines an order really carries, read from WooCommerce. */
function ac_shipping_lines(int $orderId): int
{
    $order = wc_get_order($orderId);

    return $order instanceof WC_Order ? count($order->get_items('shipping')) : -1;
}

$delivered = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 2]],
    'shipping_amount' => '450',
]);
$deliveredId = (int) ($delivered[1]['data']['id'] ?? 0);

ac_check('create with a delivery fee', $delivered, 201, function ($d) {
    // 2 x 300 goods + 450 delivery. The fee is in the total because
    // calculate_totals() found a shipping line, not because anything here added
    // it up.
    if (($d['data']['total'] ?? '') !== '1050.00') {
        return 'total is ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['shipping_total'] ?? '') === '450.00'
        ?: 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
});

ac_assert(
    'the fee is a real shipping line, and there is one of it',
    ac_shipping_lines($deliveredId) === 1 ?: 'shipping lines: ' . ac_shipping_lines($deliveredId)
);

ac_check('the read shape carries both halves of the pair', ac_req('GET', "/orders/{$deliveredId}"), 200, function ($d) {
    if (!array_key_exists('shipping_amount', $d['data'] ?? [])) {
        return 'the write shape has shipping_amount and the read shape must too';
    }

    return ($d['data']['shipping_amount'] ?? null) === '450.00'
        ?: 'shipping_amount is ' . var_export($d['data']['shipping_amount'] ?? null, true);
});

/*
 * The path that was broken before this step and is easy to break again.
 * `OrderRepository::update()` recomputed only when the payload carried
 * `line_items`; a PATCH stating just a fee took a plain save(), wrote a
 * shipping line, and left `total` and `shipping_total` at their old values with
 * no error anywhere — an order reading back with a delivery charge it was not
 * charging for.
 */
ac_check('a fee-only PATCH recomputes the order total', ac_req('PATCH', "/orders/{$deliveredId}", [
    'shipping_amount' => '500',
]), 200, function ($d) {
    if (($d['data']['total'] ?? '') !== '1100.00') {
        return 'the total did not follow the fee: ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['shipping_total'] ?? '') === '500.00'
        ?: 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
});

/*
 * Replacement, not accumulation — the assertion this step exists to make.
 * `replaceLineItems()` clears only `line_item` items, so a shipping line
 * survives every line edit; a second statement left beside the first would
 * double both `shipping_total` and the order total, with every number
 * internally consistent and wrong.
 */
ac_assert(
    'stating it twice left one shipping line, not two',
    ac_shipping_lines($deliveredId) === 1 ?: 'shipping lines: ' . ac_shipping_lines($deliveredId)
);

ac_check('lines and the fee in one PATCH are one recompute', ac_req('PATCH', "/orders/{$deliveredId}", [
    'line_items' => [['product_id' => $kettleId, 'quantity' => 1]],
    'shipping_amount' => '500',
]), 200, function ($d) {
    // 1500 goods + 500 delivery. Both terms of the sum have moved in one
    // request, and the order is never published at the intermediate value.
    return ($d['data']['total'] ?? '') === '2000.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

ac_assert(
    'and replacing the lines did not strand a second fee',
    ac_shipping_lines($deliveredId) === 1 ?: 'shipping lines: ' . ac_shipping_lines($deliveredId)
);

// The whole-body round trip, which is what every admin client does. A fee that
// did not survive it would be lost by a client that only changed an address.
[, $fetchedDelivered] = ac_req('GET', "/orders/{$deliveredId}");
$deliveredRoundTrip = $fetchedDelivered['data'];
$deliveredRoundTrip['customer_note'] = 'Delivery agreed at 500';

ac_check('a stated fee survives a whole-body round trip', ac_req('PATCH', "/orders/{$deliveredId}", $deliveredRoundTrip), 200, function ($d) {
    if (($d['data']['total'] ?? '') !== '2000.00') {
        return 'the round trip moved the total to ' . ($d['data']['total'] ?? '?');
    }

    return ($d['data']['shipping_amount'] ?? null) === '500.00' ?: 'the fee did not survive';
});

ac_assert(
    'and the round trip did not duplicate the line either',
    ac_shipping_lines($deliveredId) === 1 ?: 'shipping lines: ' . ac_shipping_lines($deliveredId)
);

/*
 * Zero is how a fee is cancelled, and it has to be a statement rather than an
 * absence — the same distinction the manual-price section draws about a free
 * line. If `0` meant "say nothing", an order charged for delivery once would be
 * charged for it forever.
 */
ac_check('a zero fee is a statement, not an absence', ac_req('PATCH', "/orders/{$deliveredId}", [
    'shipping_amount' => 0,
]), 200, function ($d) {
    if (($d['data']['total'] ?? '') !== '1500.00') {
        return 'the fee did not come off the total: ' . ($d['data']['total'] ?? '?');
    }

    if (($d['data']['shipping_total'] ?? '') !== '0.00') {
        return 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
    }

    return ($d['data']['shipping_amount'] ?? null) === '0.00'
        ?: 'a cancelled fee must read back as a stated zero, got '
            . var_export($d['data']['shipping_amount'] ?? null, true);
});

// Empty means "this request says nothing about delivery", exactly as an empty
// line price does — so a PATCH of nothing but an empty fee states nothing at
// all, and gets the same 400 a body of only read-only fields gets.
ac_check('an empty fee states nothing', ac_req('PATCH', "/orders/{$deliveredId}", [
    'shipping_amount' => null,
]), 400, function ($d) {
    return !isset($d['error']['details']['fields'])
        ?: 'an empty fee is not a validation error about a field';
});

/*
 * A total is never stated, and `shipping_total` is a total. Dropped rather than
 * refused, so the whole-body PATCH above works.
 *
 * The note rides along to pin one more thing about the fee-only branch: it ends
 * at `calculate_totals()`, which saves, instead of the `save()` the other
 * branches call. A property set by `applyProps()` has to survive that swap, and
 * "the totals are right" would not have noticed if it did not.
 */
ac_check('a stated shipping_total is ignored, not believed', ac_req('PATCH', "/orders/{$deliveredId}", [
    'shipping_total' => '999.00',
    'shipping_amount' => '250',
    'customer_note' => 'Delivery renegotiated to 250',
]), 200, function ($d) {
    if (($d['data']['customer_note'] ?? '') !== 'Delivery renegotiated to 250') {
        return 'a field set alongside the fee did not survive the recompute save';
    }

    return ($d['data']['shipping_total'] ?? '') === '250.00'
        ?: 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
});

echo PHP_EOL, '--- a quoted fee is not a stated one ---', PHP_EOL;

/*
 * The one case where `shipping_amount` and `shipping_total` disagree, and the
 * reason `shipping_amount` is null rather than an echo of the total.
 *
 * The shipping line here is written the way `Cart/CheckoutService::createOrder()`
 * writes the §14 quote — same class, same `method_title` — and the distinguishing
 * mark is the `_ac_manual_price` meta, which is the whole point: the amount says
 * nothing (450 DZD looks the same whichever way it was arrived at) and neither
 * does the label, which is deliberately identical so a customer's packing slip
 * reads the same on both.
 *
 * `method_id` is left empty here, and since backend step 2 that is no longer
 * "the same as a checkout line": a storefront order now names the registered
 * courier it was quoted for, because `Shipping\ShopperRates` resolves per
 * provider and there is no nameless one left. Empty is the back office's value
 * — a fee stated before any courier was chosen — which is exactly what this
 * fixture is standing in for.
 */
$quoted = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
]);
$quotedId = (int) ($quoted[1]['data']['id'] ?? 0);

$quotedOrder = wc_get_order($quotedId);
$quotedLine = new WC_Order_Item_Shipping();
$quotedLine->set_method_title('Delivery');
$quotedLine->set_method_id('');
$quotedLine->set_total(450.0);
$quotedOrder->add_item($quotedLine);
$quotedOrder->calculate_totals();

ac_check('a quoted fee is charged but not reported as stated', ac_req('GET', "/orders/{$quotedId}"), 200, function ($d) {
    if (($d['data']['shipping_total'] ?? '') !== '450.00') {
        return 'the quote is not on the order: shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
    }

    if (($d['data']['total'] ?? '') !== '750.00') {
        return 'total is ' . ($d['data']['total'] ?? '?');
    }

    // Tested with array_key_exists rather than `??`, which cannot tell an
    // absent key from the null this assertion is entirely about.
    if (!array_key_exists('shipping_amount', $d['data'] ?? [])) {
        return 'shipping_amount must be emitted even when nobody stated one';
    }

    return $d['data']['shipping_amount'] === null
        ?: 'a quoted fee must read back as null, got ' . var_export($d['data']['shipping_amount'], true);
});

// And the consequence that makes null the right value: PATCHing the fetched
// body back sends `shipping_amount: null`, which must leave the shopper's
// delivery charge alone rather than deleting it.
[, $fetchedQuoted] = ac_req('GET', "/orders/{$quotedId}");
$quotedRoundTrip = $fetchedQuoted['data'];
$quotedRoundTrip['customer_note'] = 'Left with the concierge';

ac_check('a whole-body PATCH does not delete a quoted fee', ac_req('PATCH', "/orders/{$quotedId}", $quotedRoundTrip), 200, function ($d) {
    if (($d['data']['shipping_total'] ?? '') !== '450.00') {
        return 'the quoted fee was destroyed by a round trip: ' . ($d['data']['shipping_total'] ?? '?');
    }

    return ($d['data']['total'] ?? '') === '750.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

ac_assert(
    'and the round trip did not add a line beside the quoted one',
    ac_shipping_lines($quotedId) === 1 ?: 'shipping lines: ' . ac_shipping_lines($quotedId)
);

echo PHP_EOL, '--- the refusals ---', PHP_EOL;

/*
 * The same three sentences `line_items.{n}.price` refuses with, under the
 * order's own key. The identity is the contract: a form that reddens one box
 * with one wording and its neighbour with another is a form read twice.
 */
foreach ([
    ['-1', 'Cannot be negative.', 'a negative fee'],
    ['10000000.00', 'Is implausibly large.', 'a fee above the tariff ceiling'],
    ['free', 'Must be an amount.', 'a fee that is not a number'],
] as [$value, $message, $label]) {
    ac_check("create refusing {$label}", ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
        'shipping_amount' => $value,
    ]), 400, function ($d) use ($message) {
        return ($d['error']['details']['fields']['shipping_amount'] ?? '') === $message
            ?: 'got ' . var_export($d['error']['details']['fields']['shipping_amount'] ?? null, true);
    });

    ac_check("update refusing {$label}", ac_req('PATCH', "/orders/{$deliveredId}", [
        'shipping_amount' => $value,
    ]), 400, function ($d) use ($message) {
        return ($d['error']['details']['fields']['shipping_amount'] ?? '') === $message
            ?: 'got ' . var_export($d['error']['details']['fields']['shipping_amount'] ?? null, true);
    });
}

// A refused fee must not half-apply. The order is still worth what it was, and
// still carries the one line it had.
ac_check('a refused fee left the order alone', ac_req('GET', "/orders/{$deliveredId}"), 200, function ($d) {
    return ($d['data']['shipping_total'] ?? '') === '250.00'
        ?: 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
});

echo PHP_EOL, '--- the whole-body round trip on a committed order ---', PHP_EOL;

/*
 * `shipping_amount` is the second field a whole-body PATCH has to strip once an
 * order is no longer editable, and the two halves behave differently — which is
 * the part a panel will not guess.
 *
 * The README already tells clients to omit `line_items` from a whole-body PATCH
 * on a committed order, because the presenter echoes it back into the guard. A
 * client that strips `line_items` and stops there meets a 409 naming the
 * shipping amount on exactly the orders it created itself, and never on the
 * ones the storefront placed — because a quoted fee reads back as `null`, which
 * is dropped before it reaches any guard.
 *
 * Both orders leave `pending` here, which moves stock; every stock assertion in
 * this suite is above the manual-price section, and both orders are a single
 * mug.
 */
$committed = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'shipping_amount' => '200',
]);
$committedId = (int) ($committed[1]['data']['id'] ?? 0);

ac_check('commit an order that carries a stated fee', ac_req('PATCH', "/orders/{$committedId}", [
    'status' => 'processing',
]), 200);

[, $fetchedCommitted] = ac_req('GET', "/orders/{$committedId}");
$committedBody = $fetchedCommitted['data'];
$committedBody['customer_note'] = 'Ring twice';
unset($committedBody['line_items']);

ac_check('stripping line_items is not enough when a fee was stated', ac_req('PATCH', "/orders/{$committedId}", $committedBody), 409, function ($d) {
    return str_contains((string) ($d['error']['message'] ?? ''), 'shipping amount')
        ?: 'expected the shipping amount to be what refused it, got ' . ($d['error']['message'] ?? '?');
});

unset($committedBody['shipping_amount']);

ac_check('stripping both lets the note through', ac_req('PATCH', "/orders/{$committedId}", $committedBody), 200, function ($d) {
    if (($d['data']['customer_note'] ?? '') !== 'Ring twice') {
        return 'the note did not stick';
    }

    // And the fee it could not restate is still on the order, untouched.
    return ($d['data']['shipping_total'] ?? '') === '200.00'
        ?: 'shipping_total is ' . ($d['data']['shipping_total'] ?? '?');
});

// The other half: a storefront order carries a quoted fee, the presenter emits
// null for it, and null states nothing — so the same body that 409s above goes
// through here with only `line_items` removed.
ac_check('commit the quoted order too', ac_req('PATCH', "/orders/{$quotedId}", ['status' => 'processing']), 200);

[, $fetchedQuotedCommitted] = ac_req('GET', "/orders/{$quotedId}");
$quotedCommittedBody = $fetchedQuotedCommitted['data'];
$quotedCommittedBody['customer_note'] = 'Left with the concierge';
unset($quotedCommittedBody['line_items']);

ac_check('a quoted fee never blocks the round trip', ac_req('PATCH', "/orders/{$quotedId}", $quotedCommittedBody), 200, function ($d) {
    if (($d['data']['customer_note'] ?? '') !== 'Left with the concierge') {
        return 'the note did not stick';
    }

    return ($d['data']['shipping_total'] ?? '') === '450.00'
        ?: 'the quoted fee moved: ' . ($d['data']['shipping_total'] ?? '?');
});

echo PHP_EOL, "=== every manual price is audited ===", PHP_EOL;

/*
 * Backend step 5, and the reason the two steps above are allowed to exist.
 *
 * `LineItemInput` used to refuse a caller-supplied price outright, on the
 * grounds that it lets anyone holding `ac_manage_orders` write an order at a
 * price of nothing. That refusal is gone and **this record is what replaced
 * it** — a price of nothing is no longer prevented, it is witnessed. So these
 * assertions are not about a log being tidy: they are the entire remaining
 * answer to the threat the old gate named, and a discount that reaches the
 * order book without a row here is the failure the reversal was betting
 * against.
 *
 * Read out of `ac_audit_logs` directly rather than through `GET /audit-logs`,
 * because the question is what was *written*. The route has its own suite
 * (tests/Api/audit.php) and its own §65 problem — a filter that does not filter
 * — which is a different thing to be wrong about.
 *
 * Its own product, at 800, so that the block below can move a catalogue price
 * mid-suite without touching a number any other section asserts.
 */
$reprice = ac_product('AC-ORD-AUDIT', '800', 60);
$repriceId = $reprice->get_id();

/** The metadata of an order's newest audit row for one action. */
function ac_audit_meta(int $orderId, string $action): array
{
    global $wpdb;

    $json = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT metadata FROM {$wpdb->prefix}ac_audit_logs
             WHERE resource_type = 'order' AND resource_id = %s AND action = %s
             ORDER BY id DESC LIMIT 1",
            (string) $orderId,
            $action
        )
    );

    $decoded = json_decode((string) $json, true);

    return is_array($decoded) ? $decoded : [];
}

/** One row of a snapshot's `manual_prices`, by type and position. */
function ac_priced(array $snapshot, int $index): array
{
    $rows = $snapshot['manual_prices'] ?? null;

    return is_array($rows) && isset($rows[$index]) && is_array($rows[$index]) ? $rows[$index] : [];
}

$auditedCreate = ac_req('POST', '/orders', [
    'line_items' => [
        // Typed with three decimals on purpose. The store keeps two, so the
        // presenter publishes 1200.51 — and the audit must not, because the
        // record is of what a person wrote.
        ['product_id' => $kettleId, 'quantity' => 2, 'price' => '1200.505'],
        ['product_id' => $mugId, 'quantity' => 3],
    ],
    'shipping_amount' => '450',
]);
$auditedId = (int) ($auditedCreate[1]['data']['id'] ?? 0);

ac_check('create an order with one hand price and a stated fee', $auditedCreate, 201);

$created = ac_audit_meta($auditedId, 'order.created');

// The four keys order.created has always carried. They now come from the same
// snapshot() an update records, and a reader of the old shape must not break.
ac_assert(
    'order.created still carries the four fields it always did',
    // 2 x 1200.505 + 3 x 300 + 450 delivery.
    (($created['status'] ?? null) === 'pending'
        && array_key_exists('customer_id', $created)
        && (float) ($created['total'] ?? -1) === 3751.01
        && ($created['items'] ?? null) === 2)
        ?: 'got ' . wp_json_encode($created)
);

ac_assert(
    'and the delivery charge, which it did not',
    (float) ($created['shipping_total'] ?? -1) === 450.0
        ?: 'shipping_total is ' . var_export($created['shipping_total'] ?? null, true)
);

/*
 * And whether the order was holding stock, which it also did not until the fix
 * round's decision 1. `false` here rather than an absent key, for
 * `manual_prices`' reason: this order is `pending`, so the honest record is that
 * nothing was reserved, and a missing key would be indistinguishable from a row
 * written before anybody was looking.
 *
 * It reaches `order.created` because `create()` records the same `snapshot()`
 * an update does, and it had to: `OrderStatus::CREATABLE` includes `on-hold`
 * and `processing`, so an order can be *born* holding units at a price somebody
 * chose. The `on-hold` half of that is asserted a few blocks down.
 */
ac_assert(
    'and whether it was holding stock, which it also did not',
    (array_key_exists('stock_reduced', $created) && $created['stock_reduced'] === false)
        ?: 'stock_reduced is ' . var_export($created['stock_reduced'] ?? 'no key at all', true)
);

/*
 * Two amounts were chosen by a person on this order and three lines exist. The
 * catalogue-priced mug contributes nothing, which is the assertion that keeps
 * the record useful: an audit where every line carries a price and a comparison
 * is an audit where the one line that *is* a decision does not stand out.
 */
ac_assert(
    'the record names the two amounts a person chose, and only those',
    count($created['manual_prices'] ?? []) === 2
        ?: 'got ' . wp_json_encode($created['manual_prices'] ?? null)
);

ac_assert(
    'the hand-priced line records what was typed and what it replaced',
    (function () use ($created, $kettleId) {
        $line = ac_priced($created, 1);

        if (($line['type'] ?? null) !== 'line') {
            return 'row 1 is ' . wp_json_encode($line);
        }

        // Unrounded. GET /orders publishes this line's price as 1200.51.
        if (($line['charged'] ?? null) !== '1200.505') {
            return 'charged is ' . var_export($line['charged'] ?? null, true);
        }

        if ((float) ($line['catalogue'] ?? -1) !== 1500.0) {
            return 'catalogue is ' . var_export($line['catalogue'] ?? null, true);
        }

        // The ids and the quantity, so the discount is `(catalogue - charged) x
        // quantity` and somebody can look the product up.
        return (($line['product_id'] ?? 0) === $kettleId
            && ($line['variation_id'] ?? null) === 0
            && ($line['quantity'] ?? null) === 2)
            ?: 'the line is not identified: ' . wp_json_encode($line);
    })()
);

/*
 * The fee's row says `catalogue: null`, and that null is structural rather than
 * a gap: delivery has no catalogue price, because §14's tariff is quoted from a
 * cart and an order is not one. `type` is what tells this null apart from a
 * line whose catalogue price could not be captured — which is the whole reason
 * every row carries a type.
 */
ac_assert(
    'the stated fee is audited, and compares against nothing',
    (function () use ($created) {
        $fee = ac_priced($created, 0);

        if (($fee['type'] ?? null) !== 'shipping') {
            return 'row 0 is ' . wp_json_encode($fee);
        }

        if (($fee['charged'] ?? null) !== '450') {
            return 'charged is ' . var_export($fee['charged'] ?? null, true);
        }

        return (array_key_exists('catalogue', $fee) && $fee['catalogue'] === null)
            ?: 'a delivery fee must not be given an invented baseline: ' . wp_json_encode($fee);
    })()
);

ac_assert(
    'a complete list says so by carrying no omitted count',
    !array_key_exists('manual_prices_omitted', $created)
        ?: 'the record claimed a truncation that did not happen'
);

// An order nobody hand-priced records an empty list rather than no key. The
// trail is append-only and full of rows written before this key existed; "there
// were none" must not read the same as "nobody was looking".
$plainCreate = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
]);
$plainId = (int) ($plainCreate[1]['data']['id'] ?? 0);

ac_check('create a wholly catalogue-priced order', $plainCreate, 201);

ac_assert(
    'it records an empty list, not a missing one',
    (function () use ($plainId) {
        $meta = ac_audit_meta($plainId, 'order.created');

        return (array_key_exists('manual_prices', $meta) && $meta['manual_prices'] === [])
            ?: 'got ' . wp_json_encode($meta['manual_prices'] ?? 'no key at all');
    })()
);

echo PHP_EOL, '--- the before and after of a reprice ---', PHP_EOL;

ac_check('reprice the line and restate the fee', ac_req('PATCH', "/orders/{$auditedId}", [
    'line_items' => [
        ['product_id' => $kettleId, 'quantity' => 2, 'price' => '900'],
        ['product_id' => $mugId, 'quantity' => 3],
    ],
    'shipping_amount' => '600',
]), 200);

$updated = ac_audit_meta($auditedId, 'order.updated');

ac_assert(
    'the update records both halves',
    (isset($updated['before']['manual_prices'], $updated['after']['manual_prices']))
        ?: 'got ' . wp_json_encode($updated)
);

/*
 * The `before` half is the one that could not exist without capturing the
 * catalogue price at write time: these lines were destroyed by the very request
 * that wrote this row — `replaceLineItems()` removes every line and re-adds the
 * payload's — so nothing after the write could have read them.
 */
ac_assert(
    'the before half still names the price that was replaced',
    (function () use ($updated) {
        $line = ac_priced($updated['before'] ?? [], 1);

        return (($line['charged'] ?? null) === '1200.505' && (float) ($line['catalogue'] ?? -1) === 1500.0)
            ?: 'before is ' . wp_json_encode($line);
    })()
);

ac_assert(
    'and the after half names the price that replaced it',
    (function () use ($updated) {
        $line = ac_priced($updated['after'] ?? [], 1);

        return (($line['charged'] ?? null) === '900' && (float) ($line['catalogue'] ?? -1) === 1500.0)
            ?: 'after is ' . wp_json_encode($line);
    })()
);

/*
 * The delivery fee's comparison, which is not a catalogue price and is not
 * invented: the pair itself. `shipping_total` on both halves says what delivery
 * was charging before this request and what it charges after — a number that is
 * true for a quoted fee as well as a stated one, which no meta on the shipping
 * line could be.
 */
ac_assert(
    'the fee is compared against what delivery cost before it',
    ((float) ($updated['before']['shipping_total'] ?? -1) === 450.0
        && (float) ($updated['after']['shipping_total'] ?? -1) === 600.0
        && (ac_priced($updated['before'] ?? [], 0)['charged'] ?? null) === '450'
        && (ac_priced($updated['after'] ?? [], 0)['charged'] ?? null) === '600')
        ?: 'got before ' . wp_json_encode($updated['before'] ?? null)
            . ' after ' . wp_json_encode($updated['after'] ?? null)
);

ac_assert(
    'and both halves say the order was holding nothing',
    (($updated['before']['stock_reduced'] ?? null) === false
        && ($updated['after']['stock_reduced'] ?? null) === false)
        ?: 'got before ' . var_export($updated['before']['stock_reduced'] ?? 'no key', true)
            . ' after ' . var_export($updated['after']['stock_reduced'] ?? 'no key', true)
);

echo PHP_EOL, '--- and the same reprice on an order that is holding stock ---', PHP_EOL;

/*
 * **The assertion the fix round's decision 1 stands on.** Everything above this
 * line was already true before the decision, because a `pending` order was never
 * what `guardManualPricesWritable()` refused. This block is the case that used
 * to be a 409 and is now a 200, and the whole bet is that the record left behind
 * is enough — so it has to be able to answer, from one row, *what was repriced,
 * on an order that was holding stock, and away from what catalogue price*.
 *
 * Its own order and its own product (`$repriceId`, listed at 800), created
 * `on-hold` so it takes stock at birth. Cancelled at the end so the shelf is
 * left as this block found it; the sections above assert kettle and mug
 * quantities to the unit and must not be disturbed by a fixture down here.
 */
$heldCreate = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $repriceId, 'quantity' => 2]],
    'status' => 'on-hold',
]);
$heldId = (int) ($heldCreate[1]['data']['id'] ?? 0);

ac_check('create an order that is born holding stock', $heldCreate, 201, function ($d) {
    return ($d['data']['stock_reduced'] ?? null) === true ?: 'on-hold should take stock';
});

// The other half of "order.created carries it": born `on-hold`, so the flat
// snapshot records `true` rather than the `false` asserted at the top of this
// section. Without this the key would only ever have been observed as false.
ac_assert(
    'and order.created records that it was born holding stock',
    (ac_audit_meta($heldId, 'order.created')['stock_reduced'] ?? null) === true
        ?: 'got ' . wp_json_encode(ac_audit_meta($heldId, 'order.created'))
);

ac_check('reprice a line on it, which used to be a 409', ac_req('PATCH', "/orders/{$heldId}", [
    'line_items' => [['product_id' => $repriceId, 'quantity' => 2, 'price' => '650']],
]), 200, function ($d) {
    // 2 x 650. The catalogue would have said 1 600.
    return ($d['data']['total'] ?? '') === '1300.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

$held = ac_audit_meta($heldId, 'order.updated');

/*
 * The three facts, in one row, asserted together because it is their conjunction
 * that replaces the refusal. Any two of them were already available: the money
 * moved (`total`), and a status name is in the row. What was not available is
 * the third — a status name does not say whether units are off the shelf, and
 * `OrderRepository::stockReduced()` exists because there is no set of names that
 * does. An order can sit in `on-hold` holding nothing at all, having arrived
 * there from `cancelled`.
 */
ac_assert(
    'the record answers what was repriced, while holding stock, away from what',
    (function () use ($held, $repriceId) {
        if (($held['before']['stock_reduced'] ?? null) !== true) {
            return 'the before half does not say the order was holding stock: '
                . wp_json_encode($held['before'] ?? null);
        }

        if (($held['after']['stock_reduced'] ?? null) !== true) {
            return 'the after half does not: ' . wp_json_encode($held['after'] ?? null);
        }

        $line = ac_priced($held['after'] ?? [], 0);

        if (($line['charged'] ?? null) !== '650') {
            return 'charged is ' . var_export($line['charged'] ?? null, true);
        }

        if ((float) ($line['catalogue'] ?? -1) !== 800.0) {
            return 'catalogue is ' . var_export($line['catalogue'] ?? null, true);
        }

        // The ids and the quantity, so the giveaway is arithmetic somebody can
        // do — (800 - 650) x 2 — rather than a number they have to trust.
        return (($line['product_id'] ?? 0) === $repriceId && ($line['quantity'] ?? null) === 2)
            ?: 'the line is not identified: ' . wp_json_encode($line);
    })()
);

/*
 * The before half of *this* pair is empty, and that is the sharpest thing the
 * record says: the order was holding stock and nobody had chosen an amount on
 * it, so this request is the whole of the decision. A reader comparing the two
 * halves sees a discount appear against a reserved line rather than an amount
 * that was already there.
 */
ac_assert(
    'and its before half records that nothing had been hand-priced yet',
    (array_key_exists('manual_prices', $held['before'] ?? []) && $held['before']['manual_prices'] === [])
        ?: 'before manual_prices is ' . wp_json_encode($held['before']['manual_prices'] ?? 'no key at all')
);

/*
 * The echo, and the limit of what this record can do — promised by the section
 * `=== a hand-priced order that holds stock ===` and paid here.
 *
 * `OrderPresenter` emits `price`, so a whole-body PATCH restates it, and nothing
 * downstream can tell that from somebody typing the same number again. While
 * `guardManualPricesWritable()` stood, this request was a 409 and the question
 * never arose; now it is a 200 that writes a `manual_prices` row identical to
 * the one already there. **The pair being equal is the only tell**, and it is a
 * tell a reader has to know to look for — which is why it is asserted rather
 * than left as a sentence in a docblock.
 */
[, $heldFetched] = ac_req('GET', "/orders/{$heldId}");
$heldBody = $heldFetched['data'];
$heldBody['customer_note'] = 'Ring before delivery';

ac_check('a whole-body PATCH now lands where it used to be refused', ac_req('PATCH', "/orders/{$heldId}", $heldBody), 200, function ($d) {
    if (($d['data']['customer_note'] ?? '') !== 'Ring before delivery') {
        return 'the note did not stick';
    }

    return ($d['data']['line_items'][0]['price'] ?? null) === '650.00'
        ?: 'the price moved: ' . var_export($d['data']['line_items'][0]['price'] ?? null, true);
});

ac_assert(
    'and the audit shows an echo as a pair that does not move',
    (function () use ($heldId) {
        $echo = ac_audit_meta($heldId, 'order.updated');

        $before = ac_priced($echo['before'] ?? [], 0);
        $after = ac_priced($echo['after'] ?? [], 0);

        // 650.00 rather than 650: the echo came back through `money()`, so the
        // *stored string* changed even though the amount did not. The record is
        // of what was written, so it says so.
        if (($before['charged'] ?? null) !== '650' || ($after['charged'] ?? null) !== '650.00') {
            return 'the pair is ' . wp_json_encode([$before, $after]);
        }

        return ((float) ($before['charged'] ?? -1) === (float) ($after['charged'] ?? -2))
            ?: 'an echo moved the amount';
    })()
);

ac_check('put its units back', ac_req('POST', "/orders/{$heldId}/cancel"), 200);

echo PHP_EOL, '--- the catalogue price is frozen, not looked up ---', PHP_EOL;

/*
 * The assertion the whole step turns on.
 *
 * A product's price moves — a sale starts, a supplier puts the kettle up — so a
 * catalogue price read when the audit row is *read* answers "what does this cost
 * today", and one read on the order's next write answers "what did it cost the
 * next time somebody edited this order". Neither is the question the record
 * exists to answer. `OrderRepository::CATALOGUE_PRICE_META` freezes the number
 * onto the line at the instant it is written, and this block is what proves the
 * difference is real rather than argued.
 */
$frozen = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $repriceId, 'quantity' => 1, 'price' => '500']],
]);
$frozenId = (int) ($frozen[1]['data']['id'] ?? 0);

ac_check('sell one at 500 while the catalogue says 800', $frozen, 201);

ac_assert(
    'the creation recorded the catalogue price of the day',
    (float) (ac_priced(ac_audit_meta($frozenId, 'order.created'), 0)['catalogue'] ?? -1) === 800.0
        ?: 'got ' . wp_json_encode(ac_audit_meta($frozenId, 'order.created')['manual_prices'] ?? null)
);

// The catalogue moves. Nothing about the order changes.
$reprice->set_regular_price('950');
$reprice->save();

ac_assert(
    'the catalogue really did move',
    (float) wc_get_product($repriceId)->get_price() === 950.0
        ?: 'the product is at ' . var_export(wc_get_product($repriceId)->get_price(), true)
);

ac_check('touch the order without touching its lines', ac_req('PATCH', "/orders/{$frozenId}", [
    'customer_note' => 'Agreed 500 with Nadia on the 3rd',
]), 200);

$frozenUpdate = ac_audit_meta($frozenId, 'order.updated');

/*
 * Both halves report 800, the price that was actually replaced, and neither
 * reports 950. A record that said 950 here would describe a 450 discount nobody
 * granted, on a sale that happened when the gap was 300.
 */
ac_assert(
    'the record still names the price that was really replaced',
    ((float) (ac_priced($frozenUpdate['before'] ?? [], 0)['catalogue'] ?? -1) === 800.0
        && (float) (ac_priced($frozenUpdate['after'] ?? [], 0)['catalogue'] ?? -1) === 800.0)
        ?: 'got ' . wp_json_encode($frozenUpdate)
);

/*
 * And the other direction, which is the same fact seen from the front: a line
 * written *now* records the catalogue as it is now. The capture is at write
 * time, so restating the identical manual price against a moved catalogue is a
 * different record — and has to be, because it is a different decision.
 */
ac_check('restate the same price against the new catalogue', ac_req('PATCH', "/orders/{$frozenId}", [
    'line_items' => [['product_id' => $repriceId, 'quantity' => 1, 'price' => '500']],
]), 200);

ac_assert(
    'the rewritten line records the catalogue it was actually written against',
    (function () use ($frozenId) {
        $meta = ac_audit_meta($frozenId, 'order.updated');

        if ((float) (ac_priced($meta['before'] ?? [], 0)['catalogue'] ?? -1) !== 800.0) {
            return 'the before half moved: ' . wp_json_encode($meta['before'] ?? null);
        }

        return (float) (ac_priced($meta['after'] ?? [], 0)['catalogue'] ?? -1) === 950.0
            ?: 'the after half is ' . wp_json_encode($meta['after'] ?? null);
    })()
);

// Put the fixture back, so nothing downstream inherits a moved price.
$reprice->set_regular_price('800');
$reprice->save();

echo PHP_EOL, '--- a quoted fee is not somebody\'s decision ---', PHP_EOL;

/*
 * The distinction the record has to keep, and the only thing that keeps it: a
 * shipping line written by the checkout carries no `_ac_manual_price` meta, so
 * it contributes no row — while `shipping_total` still reports the money the
 * order is really charging. Emit the fee under both names and every §14 quote
 * in the order book starts reading as a decision an operator made.
 */
$quotedAudit = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
]);
$quotedAuditId = (int) ($quotedAudit[1]['data']['id'] ?? 0);

$quotedAuditOrder = wc_get_order($quotedAuditId);
$quotedAuditLine = new WC_Order_Item_Shipping();
$quotedAuditLine->set_method_title('Delivery');
$quotedAuditLine->set_method_id('');
$quotedAuditLine->set_total(450.0);
$quotedAuditOrder->add_item($quotedAuditLine);
$quotedAuditOrder->calculate_totals();

ac_check('touch an order the checkout charged for delivery', ac_req('PATCH', "/orders/{$quotedAuditId}", [
    'customer_note' => 'Quoted at the till',
]), 200);

ac_assert(
    'a quoted fee is reported as money, never as a decision',
    (function () use ($quotedAuditId) {
        $meta = ac_audit_meta($quotedAuditId, 'order.updated');

        if (($meta['before']['manual_prices'] ?? null) !== []) {
            return 'a quoted fee turned up as somebody\'s choice: '
                . wp_json_encode($meta['before']['manual_prices'] ?? null);
        }

        return (float) ($meta['before']['shipping_total'] ?? -1) === 450.0
            ?: 'shipping_total is ' . var_export($meta['before']['shipping_total'] ?? null, true);
    })()
);

echo PHP_EOL, '--- the size bound, and how it announces itself ---', PHP_EOL;

/*
 * `line_items` has no cap on how many lines it carries, every one of them may
 * state a price, and an update writes the list twice. `OrderService::MAX_AUDITED_PRICES`
 * is 20; past it the record has to say what it left out, because a truncation
 * that does not announce itself is a record that lies to the person reading it
 * to establish what an operator did.
 *
 * Twenty-five lines and a fee is twenty-six chosen amounts. The fee is the row
 * that must never be the one dropped — there is at most one of it and it is a
 * decision of its own — which is why `manualPrices()` asks for the shipping
 * item before the lines.
 */
$manyLines = [];

for ($i = 0; $i < 25; $i++) {
    $manyLines[] = ['product_id' => $repriceId, 'quantity' => 1, 'price' => (string) (100 + $i)];
}

$bulk = ac_req('POST', '/orders', ['line_items' => $manyLines, 'shipping_amount' => '700']);
$bulkId = (int) ($bulk[1]['data']['id'] ?? 0);

ac_check('create an order with twenty-five hand-priced lines', $bulk, 201);

$bulkMeta = ac_audit_meta($bulkId, 'order.created');

ac_assert(
    'the row lists twenty and no more',
    count($bulkMeta['manual_prices'] ?? []) === 20
        ?: 'listed ' . count($bulkMeta['manual_prices'] ?? [])
);

ac_assert(
    'and says how many it left out',
    ($bulkMeta['manual_prices_omitted'] ?? null) === 6
        ?: 'omitted count is ' . var_export($bulkMeta['manual_prices_omitted'] ?? null, true)
);

ac_assert(
    'the one fee survived the bound; the lines are what it bit',
    (function () use ($bulkMeta) {
        $fee = ac_priced($bulkMeta, 0);

        if (($fee['type'] ?? null) !== 'shipping' || ($fee['charged'] ?? null) !== '700') {
            return 'the fee was dropped or displaced: ' . wp_json_encode($fee);
        }

        // 19 of the 25 lines, in payload order, so the count of omitted rows is
        // the tail rather than an arbitrary sample.
        return ((ac_priced($bulkMeta, 1)['charged'] ?? null) === '100'
            && (ac_priced($bulkMeta, 19)['charged'] ?? null) === '118')
            ?: 'the kept lines are not the first nineteen';
    })()
);

echo PHP_EOL, '--- which courier carries a back-office order ---', PHP_EOL;

/*
 * Backend step 2's fourth item: `shipping_provider` on `POST /orders`.
 *
 * An order taken on the phone has no cart and no §51 destination — `OrderInput`
 * has no `wilaya_id` and never will, because turning a free-text `city` into a
 * commune is the fuzzy match `Shipping\ShipmentInput` refuses by name. So
 * "which couriers serve this destination" is a question that cannot be asked
 * here, and the check is **registration only**: the courier must be one
 * `Plugin::shippingProviders()` has, because backend step 2's fifth item hands
 * this exact string to `ProviderRegistry::get()` when it creates the parcel.
 *
 * That is deliberately weaker than `POST /checkout`, which validates against
 * the couriers that actually quoted. The two routes are answering different
 * questions: the checkout refuses what it cannot *charge*, this refuses what it
 * cannot *ship with*. Being stricter here would also make the field unusable on
 * this install — with `sync-destinations` unrun no courier serves anywhere, and
 * an operator would be unable to record a courier at all.
 */
$courierOrder = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'shipping_amount' => '450',
    'shipping_provider' => 'manual',
]);
$courierId = (int) ($courierOrder[1]['data']['id'] ?? 0);

ac_check('an order can state its courier and its fee together', $courierOrder, 201, function ($d) {
    // '450.00' rather than the '450' that was typed: `shippingAmount()` puts a
    // stated fee through `money()` exactly as it does a derived one, so the two
    // keys are comparable at a glance. The unrounded string a person actually
    // wrote is what the audit keeps — see `OrderService::snapshot()`.
    return (($d['data']['shipping_provider'] ?? null) === 'manual'
        && ($d['data']['shipping_amount'] ?? null) === '450.00'
        && ($d['data']['shipping_total'] ?? null) === '450.00')
        ?: 'got ' . wp_json_encode([
            'shipping_provider' => $d['data']['shipping_provider'] ?? null,
            'shipping_amount' => $d['data']['shipping_amount'] ?? null,
            'shipping_total' => $d['data']['shipping_total'] ?? null,
        ]);
});

/*
 * The pair a reader has to be able to tell apart, on one order.
 *
 * `shipping_source` is null — nobody quoted this, a person typed it — while
 * `shipping_provider` names a courier. That combination is the whole argument
 * in `OrderInput`'s docblock made concrete: one field is about the price, the
 * other is about the parcel, and neither implies the other.
 */
ac_assert(
    'a stated fee has no source even when it has a courier',
    // `array_key_exists` rather than `??`, which cannot tell an absent key from
    // the null this assertion is entirely about — the same care the
    // `shipping_source` probes below take, and for the same reason.
    (array_key_exists('shipping_source', $courierOrder[1]['data'] ?? [])
        && $courierOrder[1]['data']['shipping_source'] === null)
        ?: 'shipping_source was ' . var_export($courierOrder[1]['data']['shipping_source'] ?? 'absent', true)
);

ac_check('an unregistered courier is refused', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'shipping_provider' => 'yalidine',
]), 400, function ($d) {
    $field = $d['error']['details']['fields']['shipping_provider'] ?? null;

    // The registered set *is* named here, unlike on the public checkout: this
    // route asserts `ac_manage_orders`, and an operator who may enter orders
    // may know which couriers the shop has.
    return (is_string($field) && str_contains($field, 'manual'))
        ?: 'got ' . wp_json_encode($d['error'] ?? null);
});

/*
 * A courier with no fee beside it, which is the case the field exists for: the
 * operator knows who is collecting and has not been told the price.
 *
 * `OrderRepository::assignShippingProvider()` creates the shipping line for it,
 * and the line carries no `_ac_manual_price` — so `shipping_amount` reads null,
 * `shipping_total` reads 0.00, and the order says a courier was named and no
 * fee was. Nothing invents a quote to fill the gap, and nothing may: there is
 * no destination to quote against, `getShippingRates()` returns `[]` for every
 * destination on this install anyway, and putting a live courier call inside an
 * order write would make the back office depend on a courier's API being up
 * while an operator is on the phone.
 */
$courierOnly = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'shipping_provider' => 'manual',
]);
$courierOnlyId = (int) ($courierOnly[1]['data']['id'] ?? 0);

ac_check('a courier can be named with no fee at all', $courierOnly, 201, function ($d) {
    return (($d['data']['shipping_provider'] ?? null) === 'manual'
        && ($d['data']['shipping_amount'] ?? null) === null
        && ($d['data']['shipping_total'] ?? null) === '0.00')
        ?: 'got ' . wp_json_encode([
            'shipping_provider' => $d['data']['shipping_provider'] ?? null,
            'shipping_amount' => $d['data']['shipping_amount'] ?? null,
            'shipping_total' => $d['data']['shipping_total'] ?? null,
        ]);
});

/*
 * **The regression this change had to not cause**, and the reason
 * `replaceShippingLine()` grew a `$provider` argument.
 *
 * Correcting a delivery charge is the most ordinary PATCH this route takes —
 * *the courier came back at 600, not 450*. The shipping line is destroyed and
 * rebuilt to do it, and a rebuild that wrote an empty `method_id` would
 * silently un-assign the courier: the operator would fix a price and break the
 * dispatch that backend step 2's fifth item performs off this exact field, with
 * nothing in the response to say so.
 *
 * The rule is the one this API applies everywhere else — a payload changes what
 * it mentions.
 */
ac_check('restating the fee leaves the courier alone', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_amount' => '600',
]), 200, function ($d) {
    return (($d['data']['shipping_provider'] ?? null) === 'manual'
        && ($d['data']['shipping_total'] ?? null) === '600.00')
        ?: 'got ' . wp_json_encode([
            'shipping_provider' => $d['data']['shipping_provider'] ?? null,
            'shipping_total' => $d['data']['shipping_total'] ?? null,
        ]);
});

/*
 * And the other direction: naming a courier does not touch the money.
 *
 * `method_id` is not a term in the shipping sum — `calculate_totals()` adds up
 * line *totals* — so this write takes the plain `save()` path and the fee it
 * finds is the fee it leaves.
 */
ac_check('naming a courier leaves the fee alone', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => 'manual',
]), 200, function ($d) {
    return (($d['data']['shipping_total'] ?? null) === '600.00'
        && ($d['data']['shipping_amount'] ?? null) === '600.00')
        ?: 'got ' . wp_json_encode([
            'shipping_total' => $d['data']['shipping_total'] ?? null,
            'shipping_amount' => $d['data']['shipping_amount'] ?? null,
        ]);
});

/*
 * Empty says nothing, exactly as `shipping_amount` does.
 *
 * `null` and `""` are dropped rather than stored, which is what lets a client
 * PATCH back a whole order it just read: `OrderPresenter` emits `null` for an
 * order whose courier nobody has named, and that null must not arrive as an
 * instruction to forget one. The cost — a courier cannot be un-chosen, only
 * replaced — is argued in `OrderInput`'s docblock.
 */
ac_check('an empty courier is dropped, not stored', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => null,
    'customer_note' => 'sent with an empty courier',
]), 200, function ($d) {
    return (($d['data']['shipping_provider'] ?? null) === 'manual')
        ?: 'the courier was cleared by an empty statement: '
            . var_export($d['data']['shipping_provider'] ?? null, true);
});

ac_check('and so is a blank one', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => '   ',
]), 400, function ($d) {
    // Dropped, so the body reads as empty — the same refusal `{"total": "1.00"}`
    // gets, and deliberately not a per-field error: there is nothing wrong with
    // the value, it simply says nothing.
    return str_contains((string) ($d['error']['message'] ?? ''), 'No supported fields')
        ?: 'got ' . ($d['error']['message'] ?? '?');
});

/*
 * The two shape refusals, which are `OrderInput`'s and not the registry's.
 *
 * There is deliberately no charset rule — the registry is the authority on
 * which names exist, and a pattern here could only ever refuse names it also
 * refuses while standing ready to reject a future adapter's spelling.
 */
ac_check('a courier that is not a string is refused', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => ['manual'],
]), 400, function ($d) {
    return (($d['error']['details']['fields']['shipping_provider'] ?? '') === 'Must be a string.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('an implausibly long courier name is refused', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => str_repeat('a', 41),
]), 400, function ($d) {
    return (($d['error']['details']['fields']['shipping_provider'] ?? '') === 'Is implausibly long.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * A name is normalized the way `ProviderRegistry` normalizes one — trimmed and
 * lower-cased — so that the string stored in `method_id` is the string the
 * registry answers to. Anything else passes validation here and then misses in
 * the registry later, at confirmation, which is the worst moment to find out.
 */
ac_check('a courier name is trimmed and lower-cased', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => '  MANUAL ',
]), 200, function ($d) {
    return (($d['data']['shipping_provider'] ?? null) === 'manual')
        ?: 'stored ' . var_export($d['data']['shipping_provider'] ?? null, true);
});

/*
 * **No `is_editable` gate, and this is the assertion that says so on purpose.**
 *
 * `guardShippingAmountWritable()` states the rule: everything that moves the
 * order total is gated, everything else is free at any status. Naming a courier
 * moves nothing, so it sits with the address and the customer note — and it has
 * to, because backend step 2 confirms an order into `processing`, which is not
 * editable, and *"Yalidine refused this commune, send it with ZR Express"* is
 * the retry the next sub-task is built around. A gate here would make the
 * courier unchangeable from the exact moment it starts to matter.
 *
 * The fee on the same order is refused in the same status, which is what makes
 * this a decision rather than an oversight.
 */
ac_check('the courier order reaches processing', ac_req('PATCH', "/orders/{$courierId}", [
    'status' => 'processing',
]), 200);

ac_check('the courier can still be changed on a committed order', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_provider' => 'manual',
]), 200, function ($d) {
    return (($d['data']['shipping_provider'] ?? null) === 'manual')
        ?: 'got ' . var_export($d['data']['shipping_provider'] ?? null, true);
});

ac_check('while the fee on that same order is not', ac_req('PATCH', "/orders/{$courierId}", [
    'shipping_amount' => '700',
]), 409, function ($d) {
    return (($d['error']['details']['editable_in'] ?? []) === ['pending', 'on-hold'])
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * The whole-body round trip, with the new key in it.
 *
 * `shipping_provider` is writable — unlike `shipping_source`, which is
 * read-only precisely so nobody can claim a courier answered — so it cannot be
 * parked in `READ_ONLY` to make the round trip safe. It is made safe the other
 * way: restating what the order already says is a no-op, and a no-op must never
 * be refused for a change.
 */
ac_check('an order round-trips through PATCH with its courier on it', (function () use ($courierOnlyId) {
    [, $read] = ac_req('GET', "/orders/{$courierOnlyId}");
    $body = $read['data'] ?? [];
    // The one key a committed order may not echo. This order is `pending`, so
    // it would survive — it is dropped anyway because the finding that
    // `buildPayload()` must omit it does not stop being true on a pending order.
    unset($body['line_items']);

    return ac_req('PATCH', "/orders/{$courierOnlyId}", $body);
})(), 200, function ($d) {
    return (($d['data']['shipping_provider'] ?? null) === 'manual')
        ?: 'the round trip lost the courier: ' . wp_json_encode($d['data']['shipping_provider'] ?? null);
});

foreach ([$courierId, $courierOnlyId] as $id) {
    if ($id > 0) {
        wc_get_order($id)?->delete(true);
    }
}

echo PHP_EOL, '--- and where it is going ---', PHP_EOL;

/*
 * Backend step 2: a structured destination on `POST /orders`.
 *
 * The gap the courier field could not close alone. `shipping_provider` said who
 * carries the parcel and nothing said **where to**, so
 * `Shipping\ShipmentSubscriber` recorded `order_destination_missing` against
 * every back-office order that was ever confirmed — not an edge case on this
 * route but the only outcome. The parcel that a confirmed order is supposed to
 * create automatically was a storefront-only promise.
 *
 * The three keys are `wilaya_id`, `commune_id` and `delivery_type` — the same
 * spelling `POST /orders/{id}/shipments`, `GET /shipping/rates`, `POST /checkout`
 * and a shipping rule all use, and `Shipping\Destination::toArray()`'s own
 * order. **They are §51 row ids and the address's `city`/`state` are free
 * text**; `OrderInput`'s docblock draws the line and gives the tell — `_id`
 * means a row, and a row is what a courier routes on.
 *
 * That the parcel now actually appears is proved end to end in
 * `tests/Api/shipping.php`, which owns the hook. What is measured here is the
 * write contract: what is accepted, what is refused, with which key and which
 * sentence, and that the read shape carries it back.
 */

[, $wilayaList] = ac_req('GET', '/locations/wilayas');
$destWilaya = (int) ($wilayaList['data'][0]['id'] ?? 0);
$otherWilaya = (int) ($wilayaList['data'][1]['id'] ?? 0);

[, $communeList] = ac_req('GET', "/locations/wilayas/{$destWilaya}/communes");
$destCommune = (int) ($communeList['data'][0]['id'] ?? 0);

// A commune that genuinely belongs somewhere else, for the mismatch below. The
// interesting refusal cannot be written with an invented id: "no such commune"
// and "that commune is in another wilaya" are different mistakes with different
// fixes, and only a real row from a second wilaya asks the second question.
[, $otherCommunes] = ac_req('GET', "/locations/wilayas/{$otherWilaya}/communes");
$foreignCommune = (int) ($otherCommunes['data'][0]['id'] ?? 0);

ac_assert('the geography dataset has two wilayas to work with', ($destWilaya > 0 && $otherWilaya > 0)
    ?: 'got ' . $destWilaya . ' and ' . $otherWilaya);
ac_assert('and a commune in each', ($destCommune > 0 && $foreignCommune > 0)
    ?: 'got ' . $destCommune . ' and ' . $foreignCommune);

$destOrder = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'shipping_provider' => 'manual',
    'wilaya_id' => $destWilaya,
    'commune_id' => $destCommune,
    'delivery_type' => 'desk',
]);
$destId = (int) ($destOrder[1]['data']['id'] ?? 0);

/*
 * The read shape gains whatever the write shape gains, in the same change —
 * `OrderPresenter`'s rule, and the one a line's `price` most recently broke by
 * being settable before it was readable. A panel that can post a destination
 * has to be able to reopen the order and see it, or its edit form shows empty
 * pickers on an order that is addressed.
 */
ac_check('an order can be created with a destination on it', $destOrder, 201, function ($d) use ($destWilaya, $destCommune) {
    foreach (['wilaya_id', 'commune_id', 'delivery_type'] as $key) {
        if (!array_key_exists($key, $d['data'] ?? [])) {
            return "the write shape has {$key} and the read shape must too";
        }
    }

    // Ints on the wire, not the strings money is emitted as: these are row
    // ids, and a client uses them to re-select an option rather than to
    // display an amount.
    return (($d['data']['wilaya_id'] ?? null) === $destWilaya
        && ($d['data']['commune_id'] ?? null) === $destCommune
        && ($d['data']['delivery_type'] ?? null) === 'desk')
        ?: 'got ' . wp_json_encode([
            'wilaya_id' => $d['data']['wilaya_id'] ?? null,
            'commune_id' => $d['data']['commune_id'] ?? null,
            'delivery_type' => $d['data']['delivery_type'] ?? null,
        ]);
});

/*
 * **The interesting refusal**: a commune that is real and is somewhere else.
 *
 * This is the mistake an address form actually makes — a commune from the right
 * dropdown beside a wilaya left over from the previous selection — and
 * `ShippingService::validatedDestination()` names the consequence: it routes a
 * parcel to a commune of the same name in another wilaya, of which Algeria has
 * several. The sentence and the `commune_wilaya_id` beside it are copied from
 * that method verbatim, so a panel renders one wording whether it was the order
 * or the parcel that was refused.
 *
 * Keyed `wilaya_id` rather than `commune_id`, which is that method's call too:
 * the commune is the value the operator picked deliberately and the wilaya is
 * the one they left behind.
 */
ac_check('a commune from another wilaya is refused', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'wilaya_id' => $destWilaya,
    'commune_id' => $foreignCommune,
]), 400, function ($d) use ($otherWilaya) {
    if (($d['error']['details']['fields']['wilaya_id'] ?? '') !== 'That commune belongs to a different wilaya.') {
        return 'got ' . wp_json_encode($d['error']['details'] ?? null);
    }

    // Published beside the field so a panel can offer to move the selection
    // rather than merely clearing it.
    return (($d['error']['details']['commune_wilaya_id'] ?? null) === $otherWilaya)
        ?: 'commune_wilaya_id is ' . var_export($d['error']['details']['commune_wilaya_id'] ?? null, true);
});

ac_check('a commune that does not exist is refused differently', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'wilaya_id' => $destWilaya,
    'commune_id' => 99999999,
]), 400, function ($d) {
    return (($d['error']['details']['fields']['commune_id'] ?? '') === 'No commune with id 99999999.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * Half a destination is refused, because half of one silently does nothing.
 * `ShipmentSubscriber::destinationOf()` returns null unless both ids are at
 * least 1, so an order carrying a wilaya and no commune is addressed exactly as
 * well as an order carrying neither — it would confirm, create no parcel, and
 * record `order_destination_missing` naming a thing the operator thought they
 * had entered.
 */
ac_check('a wilaya with no commune is refused', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'wilaya_id' => $destWilaya,
]), 400, function ($d) {
    return (($d['error']['details']['fields']['commune_id'] ?? '') === 'Required when the order names a wilaya.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('and a commune with no wilaya is refused the other way round', ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
    'commune_id' => $destCommune,
]), 400, function ($d) {
    return (($d['error']['details']['fields']['wilaya_id'] ?? '') === 'Required when the order names a commune.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * **But on an *order* that already names a wilaya, a commune alone is the
 * point.** "Same wilaya, wrong commune" is the correction
 * `ShipmentSubscriber`'s retry is built around — a courier refuses a commune,
 * the operator fixes it, the next confirmation creates the parcel — and a guard
 * that judged the payload in isolation would refuse the single most useful
 * write this route makes. The pair is resolved against the order first, which
 * is this API's rule everywhere else: a payload changes what it mentions.
 */
[, $secondCommune] = ac_req('GET', "/locations/wilayas/{$destWilaya}/communes");
$neighbour = (int) ($secondCommune['data'][1]['id'] ?? 0);

ac_check('a commune alone corrects an order that already names a wilaya', ac_req('PATCH', "/orders/{$destId}", [
    'commune_id' => $neighbour,
]), 200, function ($d) use ($destWilaya, $neighbour) {
    return (($d['data']['commune_id'] ?? null) === $neighbour && ($d['data']['wilaya_id'] ?? null) === $destWilaya)
        ?: 'got ' . wp_json_encode([
            'wilaya_id' => $d['data']['wilaya_id'] ?? null,
            'commune_id' => $d['data']['commune_id'] ?? null,
        ]);
});

// And the same single-field write is re-validated against the stored half, so
// the correction cannot introduce the mismatch the create refused.
ac_check('a correction into another wilaya is still refused', ac_req('PATCH', "/orders/{$destId}", [
    'commune_id' => $foreignCommune,
]), 400, function ($d) {
    return (($d['error']['details']['fields']['wilaya_id'] ?? '') === 'That commune belongs to a different wilaya.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * The two shape refusals, which are `OrderInput`'s and not the geography's.
 *
 * One sentence for every way an id can be wrong, against the money fields'
 * three, and it is `Shipping\ShipmentInput::requiredId()`'s word for word: a
 * panel offering the same destination picker on the order drawer and the parcel
 * drawer must not render two wordings for one mistake. `0` is in the list on
 * purpose — it is the one value that separates an id from a fee, which has a
 * meaningful zero and keeps it.
 */
foreach ([['a zero id', 0], ['a negative id', -1], ['a word', 'sixteen']] as [$label, $value]) {
    ac_check("{$label} is refused as an id", ac_req('PATCH', "/orders/{$destId}", [
        'commune_id' => $value,
    ]), 400, function ($d) {
        return (($d['error']['details']['fields']['commune_id'] ?? '') === 'Must be a positive id.')
            ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
    });
}

ac_check('an unknown delivery type is refused with the shared enum', ac_req('PATCH', "/orders/{$destId}", [
    'delivery_type' => 'pickup',
]), 400, function ($d) {
    return (($d['error']['details']['fields']['delivery_type'] ?? '') === 'Must be one of: home, desk.')
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * The journey moves on its own, with no pair to satisfy. It is not a place, it
 * needs no lookup, and `destinationOf()` never reads it unless both ids are
 * present — so "the customer will collect it from the desk" is a legal thing to
 * say about an order whose address may still be coming.
 */
ac_check('the delivery type can be changed by itself', ac_req('PATCH', "/orders/{$destId}", [
    'delivery_type' => 'home',
]), 200, function ($d) {
    return (($d['data']['delivery_type'] ?? null) === 'home')
        ?: 'got ' . var_export($d['data']['delivery_type'] ?? null, true);
});

/*
 * An order nobody addressed reads three nulls rather than three zeroes, and the
 * distinction is the round trip's. `OrderInput` refuses `0` outright — there is
 * no commune 0 — so publishing it would emit a value this API's own write side
 * rejects, and every whole-body PATCH of an unaddressed order would 400 on keys
 * the client echoed without touching.
 */
$unaddressed = ac_req('POST', '/orders', [
    'line_items' => [['product_id' => $mugId, 'quantity' => 1]],
]);
$unaddressedId = (int) ($unaddressed[1]['data']['id'] ?? 0);

ac_check('an unaddressed order reads null, not zero', $unaddressed, 201, function ($d) {
    foreach (['wilaya_id', 'commune_id', 'delivery_type'] as $key) {
        if (!array_key_exists($key, $d['data'] ?? [])) {
            return "{$key} must be emitted even when nobody stated one";
        }

        if ($d['data'][$key] !== null) {
            return "{$key} is " . var_export($d['data'][$key], true);
        }
    }

    return true;
});

/*
 * The whole-body round trip with the destination on it, on both an addressed
 * order and an unaddressed one — the two shapes the presenter emits.
 *
 * These keys are writable, so they cannot be parked in `READ_ONLY` to make the
 * round trip safe the way `shipping_source` and `shipping_provider_error` are.
 * It is made safe the other way: `null` says nothing and is dropped, and
 * restating what the order already names is a no-op that can never be refused.
 */
foreach ([['an addressed', $destId], ['an unaddressed', $unaddressedId]] as [$label, $id]) {
    ac_check("{$label} order round-trips through PATCH with its destination", (function () use ($id) {
        [, $read] = ac_req('GET', "/orders/{$id}");
        $body = $read['data'] ?? [];
        // The one key a whole-body PATCH always strips; see the courier round
        // trip above for why that finding does not stop being true here.
        unset($body['line_items']);

        return ac_req('PATCH', "/orders/{$id}", $body);
    })(), 200);
}

foreach ([$destId, $unaddressedId] as $id) {
    if ($id > 0) {
        wc_get_order($id)?->delete(true);
    }
}

echo PHP_EOL, "=== the PATCH field contract, measured ===", PHP_EOL;

/*
 * Item 1, backend step 1: the refusals this route actually returns.
 *
 * Everything ADMIN_PANEL.md and the panel's mock said about `PATCH /orders/{id}`
 * covered `status` alone; `billing`, `shipping`, `line_items`, `payment_method`
 * and `customer_note` were transcribed from source and never observed. This
 * section observes them. The exact `details.fields` **key strings** are the
 * point rather than the prose: they are what an edit form binds an error to,
 * and a key that is one character off shows the operator nothing.
 *
 * **Measured in-process via rest_do_request()**, which is not the same thing as
 * a signed HTTP request to the deployed instance. Routing, the args schema, the
 * permission callback, OrderInput, AddressInput, LineItemInput, the service
 * guards, the repository and WooCommerce itself all run; Application Password
 * authentication, nonce handling, the REST cookie/CORS layer and any reverse
 * proxy do not. Nothing below says anything about those.
 *
 * Its own product and its own orders, because the questions here are about
 * fields rather than shelves. Every order is created `pending`, which moves no
 * stock, and the three that must leave `pending` to be asked their question
 * carry the probe product only — so the kettle and mug arithmetic the ledger
 * sections above assert to the unit is untouched either way.
 */
$probe = ac_product('AC-ORD-PROBE', '100', 100000);
$probeId = $probe->get_id();

/** A pending order with every address field filled, so a probe can ask what survives. */
function ac_probe_order(int $productId): int
{
    [, $data] = ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        'billing' => [
            'first_name' => 'Amina', 'last_name' => 'Belkacem', 'company' => 'Belkacem SARL',
            'address_1' => '12 Rue Didouche', 'address_2' => 'Apt 4', 'city' => 'Alger',
            'state' => 'Alger', 'postcode' => '16000', 'country' => 'DZ',
            'phone' => '0550000000', 'email' => 'amina@example.test',
        ],
        'shipping' => [
            'first_name' => 'Amina', 'last_name' => 'Belkacem', 'city' => 'Alger',
            'state' => 'Alger', 'postcode' => '16000', 'country' => 'DZ', 'phone' => '0550000000',
        ],
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'customer_note' => 'seeded',
    ]);

    return (int) ($data['data']['id'] ?? 0);
}

/** The one error key a refusal names, or a description of why that question has no answer. */
function ac_field_error(array $data, string $key): string
{
    return $data['error']['details']['fields'][$key] ?? '';
}

/*
 * The single most important answer for an edit form, so it goes first.
 *
 * A partial address **merges**. `OrderRepository::applyProps()` walks
 * `AddressInput::$fields`, which holds only the keys the payload stated, and
 * calls one setter per key — so a field the caller omitted is never written and
 * survives. The form may therefore send only what changed; it does not have to
 * echo the whole address back to avoid blanking it.
 */
$merge = ac_probe_order($probeId);

ac_check('a partial billing merges rather than replaces', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => ['first_name' => 'Karim'],
]), 200, function ($d) {
    $billing = $d['data']['billing'] ?? [];

    if (($billing['first_name'] ?? '') !== 'Karim') {
        return 'the stated field did not land';
    }

    // Every other field, named one at a time: "the object is unchanged" is the
    // claim a form is betting on, and a spot check of one neighbour would not
    // catch a setter that blanked the rest.
    foreach ([
        'last_name' => 'Belkacem', 'company' => 'Belkacem SARL', 'address_1' => '12 Rue Didouche',
        'address_2' => 'Apt 4', 'city' => 'Alger', 'state' => 'Alger', 'postcode' => '16000',
        'country' => 'DZ', 'phone' => '0550000000', 'email' => 'amina@example.test',
    ] as $field => $expected) {
        if (($billing[$field] ?? null) !== $expected) {
            return "billing.{$field} was blanked: " . var_export($billing[$field] ?? null, true);
        }
    }

    return true;
});

ac_check('the sibling address is untouched too', ac_req('GET', "/orders/{$merge}"), 200, function ($d) {
    return ($d['data']['shipping']['first_name'] ?? '') === 'Amina'
        ?: 'a billing write reached the shipping address';
});

// Clearing is therefore explicit. null (and '') writes an empty string, which
// is how a form deletes a company name it cannot omit its way out of.
ac_check('null clears one field and only that field', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => ['company' => null],
]), 200, function ($d) {
    if (($d['data']['billing']['company'] ?? null) !== '') {
        return 'company is ' . var_export($d['data']['billing']['company'] ?? null, true);
    }

    return ($d['data']['billing']['city'] ?? '') === 'Alger' ?: 'clearing one field cleared another';
});

// An empty object is a supported field, so it satisfies the "no supported
// fields" check below while changing nothing.
ac_check('an empty billing object is accepted and changes nothing', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => [],
]), 200, function ($d) {
    return ($d['data']['billing']['city'] ?? '') === 'Alger' ?: 'an empty object rewrote the address';
});

ac_check('a billing that is not an object is refused by prefix alone', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => 'nope',
]), 400, function ($d) {
    return ac_field_error($d, 'billing') === 'Must be an object of address fields.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * The country check is a **shape** check, and the probe that assumed otherwise
 * was asking the wrong question. `ZZ` is not a country and is accepted: the
 * rule is `^[A-Z]{2}$`, deliberately, because membership of the real list means
 * WC()->countries and AddressInput is pure. What it catches is the mistake that
 * happens — a country *name* where a code belongs.
 *
 * So a form cannot lean on this to validate a country. It refuses "Algeria" and
 * accepts "ZZ", "XX" and "QQ" alike.
 */
ac_check('a two-letter non-country is accepted — the check is shape only', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => ['country' => 'ZZ'],
]), 200, function ($d) {
    return ($d['data']['billing']['country'] ?? '') === 'ZZ' ?: 'ZZ did not land';
});

ac_check('a country name is what the check refuses', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => ['country' => 'Algeria'],
]), 400, function ($d) {
    return ac_field_error($d, 'billing.country') === 'Must be a two-letter ISO country code, such as DZ.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('a lowercase code is accepted and upper-cased', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => ['country' => 'dz'],
]), 200, function ($d) {
    return ($d['data']['billing']['country'] ?? '') === 'DZ' ?: 'got ' . ($d['data']['billing']['country'] ?? '?');
});

// Both prefixes report independently, so a form binding two country inputs gets
// one error each rather than a single message about "the address".
ac_check('both addresses refuse under their own prefix', ac_req('PATCH', "/orders/{$merge}", [
    'billing' => ['country' => 'Algeria'],
    'shipping' => ['country' => 'France'],
]), 400, function ($d) {
    return (ac_field_error($d, 'billing.country') !== '' && ac_field_error($d, 'shipping.country') !== '')
        ?: 'got ' . wp_json_encode(array_keys($d['error']['details']['fields'] ?? []));
});

// The create-side finding, confirmed on the update side: shipping carries no
// email, and it is named specifically rather than as an unknown field.
ac_check('shipping still carries no email on update', ac_req('PATCH', "/orders/{$merge}", [
    'shipping' => ['email' => 'a@b.co'],
]), 400, function ($d) {
    return ac_field_error($d, 'shipping.email') === 'Only a billing address carries an email.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

// Unknown fields are refused by name under the prefix they arrived on, at all
// three depths. A form can therefore point at the input that produced one.
ac_check('an unknown field is named at the top level', ac_req('PATCH', "/orders/{$merge}", [
    'wilaya' => 16,
]), 400, function ($d) {
    return ac_field_error($d, 'wilaya') === 'Unknown field.' ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('an unknown field is named under an address prefix', ac_req('PATCH', "/orders/{$merge}", [
    'shipping' => ['wilaya' => 16],
]), 400, function ($d) {
    return ac_field_error($d, 'shipping.wilaya') === 'Unknown field.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('an unknown field is named under a line index', ac_req('PATCH', "/orders/{$merge}", [
    'line_items' => [['product_id' => $probeId, 'quantity' => 1, 'colour' => 'red']],
]), 400, function ($d) {
    return ac_field_error($d, 'line_items.0.colour') === 'Unknown field.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * The one refusal on this route that carries **no `details.fields` at all**, and
 * the reason it is worth a test of its own.
 *
 * `AddressInput::validateEmail()` uses filter_var(), documented there as
 * disagreeing with WordPress's is_email() "only on addresses neither a customer
 * nor a courier will ever use". The disagreement is real and it is not
 * harmless: `a@b.c` passes filter_var and fails is_email, so it clears
 * validation and then `WC_Order::set_billing_email()` throws WC_Data_Exception,
 * which `OrderService::save()` translates with the exception's own message and
 * an empty details array. A form binding on `details.fields["billing.email"]`
 * renders nothing at all for this input.
 *
 * The whole PATCH is refused, not half-applied — asserted below, because a
 * failure between two setters is where a partial write would hide.
 */
$wcEmail = ac_probe_order($probeId);

$wcRefusal = ac_check('a filter_var-valid address WooCommerce refuses has no field key', ac_req('PATCH', "/orders/{$wcEmail}", [
    'customer_note' => 'changed alongside',
    'billing' => ['email' => 'a@b.c'],
]), 400, function ($d) {
    if (($d['error']['code'] ?? '') !== 'invalid_request') {
        return 'code is ' . ($d['error']['code'] ?? '?');
    }

    if (($d['error']['message'] ?? '') !== 'Invalid billing email address') {
        return 'message is ' . ($d['error']['message'] ?? '?');
    }

    return ($d['error']['details']['fields'] ?? null) === null
        ?: 'this refusal grew a field key: ' . wp_json_encode($d['error']['details']);
});

ac_check('and it applies nothing — the note in the same body did not move', ac_req('GET', "/orders/{$wcEmail}"), 200, function ($d) {
    if (($d['data']['customer_note'] ?? '') !== 'seeded') {
        return 'a refused PATCH still wrote the note: ' . ($d['data']['customer_note'] ?? '?');
    }

    return ($d['data']['billing']['email'] ?? '') === 'amina@example.test' ?: 'the email moved anyway';
});

// The malformed-by-both-rules case does get a field key, which is what makes
// the one above easy to miss.
ac_check('an address both rules reject is named normally', ac_req('PATCH', "/orders/{$wcEmail}", [
    'billing' => ['email' => 'nope'],
]), 400, function ($d) {
    return ac_field_error($d, 'billing.email') === 'Must be a valid email address.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * `line_items` replaces the whole set, and a line `id` identifies nothing.
 *
 * Sending fewer lines than the order holds deletes the rest; the ids in the
 * response are new rows every time, even when the payload restates the order
 * exactly as it stands. So an admin client cannot use an id to aim an edit at
 * one line, and must send the complete intended set or omit the key entirely.
 */
$lines = ac_probe_order($probeId);

[, $linesBefore] = ac_req('GET', "/orders/{$lines}");
$firstLineId = (int) ($linesBefore['data']['line_items'][0]['id'] ?? 0);

ac_check('an id alone cannot aim at an existing line', ac_req('PATCH', "/orders/{$lines}", [
    'line_items' => [['id' => $firstLineId, 'quantity' => 9]],
]), 400, function ($d) {
    return ac_field_error($d, 'line_items.0.product_id') === 'A product id is required.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('a line omitted from the payload is removed', ac_req('PATCH', "/orders/{$lines}", [
    'line_items' => [
        ['product_id' => $probeId, 'quantity' => 3],
        ['product_id' => $kettleId, 'quantity' => 1],
    ],
]), 200, function ($d) {
    return count($d['data']['line_items'] ?? []) === 2 ?: 'expected two lines';
});

ac_check('sending one line back drops the other', ac_req('PATCH', "/orders/{$lines}", [
    'line_items' => [['product_id' => $probeId, 'quantity' => 3]],
]), 200, function ($d) {
    return count($d['data']['line_items'] ?? []) === 1 ?: 'expected the kettle line to be gone';
});

// The identity question, asked directly: restate the order exactly as it stands
// and the row is still replaced. Nothing a client stores about a line survives
// a write that touches `line_items`.
[, $idsBefore] = ac_req('GET', "/orders/{$lines}");
ac_req('PATCH', "/orders/{$lines}", ['line_items' => [['product_id' => $probeId, 'quantity' => 3]]]);
[, $idsAfter] = ac_req('GET', "/orders/{$lines}");

ac_assert(
    'an identical replace still issues new line ids',
    ($idsBefore['data']['line_items'][0]['id'] ?? 0) !== ($idsAfter['data']['line_items'][0]['id'] ?? 0)
        ?: 'the id survived, so something does pair the lines'
);

// And a PATCH that does not mention the lines leaves the ids alone, which is
// what makes "omit the key" the safe default for a form editing anything else.
ac_req('PATCH', "/orders/{$lines}", ['customer_note' => 'lines untouched']);
[, $idsAfterNote] = ac_req('GET', "/orders/{$lines}");

ac_assert(
    'a PATCH that omits line_items preserves the ids',
    ($idsAfter['data']['line_items'][0]['id'] ?? 0) === ($idsAfterNote['data']['line_items'][0]['id'] ?? -1)
        ?: 'a note-only PATCH rewrote the lines'
);

ac_check('an empty list is refused, not read as "remove everything"', ac_req('PATCH', "/orders/{$lines}", [
    'line_items' => [],
]), 400, function ($d) {
    return ac_field_error($d, 'line_items') === 'An order needs at least one line item.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('a non-list is refused under the bare key', ac_req('PATCH', "/orders/{$lines}", [
    'line_items' => ['product_id' => $probeId],
]), 400, function ($d) {
    return ac_field_error($d, 'line_items') === 'Must be an array of line items.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

// A product the catalogue does not have is caught in the repository rather than
// in the pure input, and still arrives keyed by its index — so the two layers
// are indistinguishable to a form, which is the point.
ac_check('a missing product is named by its line index', ac_req('PATCH', "/orders/{$lines}", [
    'line_items' => [
        ['product_id' => $probeId, 'quantity' => 1],
        ['product_id' => 99999999, 'quantity' => 1],
    ],
]), 400, function ($d) {
    return ac_field_error($d, 'line_items.1.product_id') === 'No product with id 99999999.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * The `is_editable` refusal, and the shape of it: a 409 whose details are
 * `status` and `editable_in` at the top of `details`, with **no `fields` key**.
 * It is a state error rather than a field error, and a form has to render it as
 * one — there is nothing to attach to an input.
 */
$committed = ac_probe_order($probeId);
ac_check('the probe order reaches processing', ac_req('PATCH', "/orders/{$committed}", ['status' => 'processing']), 200);

ac_check('line_items on a committed order is a 409 with no field key', ac_req('PATCH', "/orders/{$committed}", [
    'line_items' => [['product_id' => $probeId, 'quantity' => 5]],
]), 409, function ($d) {
    if (($d['error']['code'] ?? '') !== 'conflict') {
        return 'code is ' . ($d['error']['code'] ?? '?');
    }

    if (($d['error']['message'] ?? '') !== 'The line items of an order in this status cannot be changed.') {
        return 'message is ' . ($d['error']['message'] ?? '?');
    }

    if (isset($d['error']['details']['fields'])) {
        return 'a state refusal grew a fields key';
    }

    return (($d['error']['details']['status'] ?? '') === 'processing'
        && ($d['error']['details']['editable_in'] ?? []) === ['pending', 'on-hold'])
        ?: 'details are ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('everything else stays writable on a committed order', ac_req('PATCH', "/orders/{$committed}", [
    'billing' => ['city' => 'Oran'],
    'customer_note' => 'Customer rang about the address',
]), 200);

/*
 * The consequence that matters most to a panel, asserted rather than reasoned
 * about. `OrderInput`'s docblock says the read shape is droppable so a client
 * can "GET an order, change one thing and PATCH the whole object back". That
 * holds on `pending` and `on-hold` and **fails on every other status**, because
 * the presenter emits `line_items` and echoing it back trips the guard above —
 * even when the only thing the operator changed was the note.
 *
 * So the edit form must omit `line_items` from a whole-body PATCH unless it
 * means to rewrite the lines. That is a payload rule, not a display rule.
 */
[, $fetched] = ac_req('GET', "/orders/{$committed}");
$wholeBody = $fetched['data'];
$wholeBody['customer_note'] = 'Changed one field and sent it all back';

ac_check('a whole-body round trip is refused on a committed order', ac_req('PATCH', "/orders/{$committed}", $wholeBody), 409, function ($d) {
    return ($d['error']['details']['status'] ?? '') === 'processing'
        ?: 'refused for something other than the line items';
});

unset($wholeBody['line_items']);

ac_check('the same body without line_items is accepted', ac_req('PATCH', "/orders/{$committed}", $wholeBody), 200, function ($d) {
    return ($d['data']['customer_note'] ?? '') === 'Changed one field and sent it all back'
        ?: 'the note is ' . ($d['data']['customer_note'] ?? '?');
});

// The status guard is checked before the editability guard, so a body that
// changes both reports the transition and never mentions the lines.
ac_check('a transition refusal wins over the line-item refusal', ac_req('PATCH', "/orders/{$committed}", [
    'status' => 'pending',
    'line_items' => [['product_id' => $probeId, 'quantity' => 4]],
]), 409, function ($d) {
    return str_contains((string) ($d['error']['message'] ?? ''), 'cannot move from')
        ?: 'got ' . ($d['error']['message'] ?? '?');
});

/*
 * `payment_method` and `payment_method_title` move independently — with one
 * asymmetry a form has to know about. Clearing the method clears the title as
 * a side effect (WC_Abstract_Order::set_payment_method() blanks both on an
 * empty string), unless the same body states a title, because the repository
 * runs the method setter first and the title setter after it.
 */
$payment = ac_probe_order($probeId);

ac_check('the method moves without its title', ac_req('PATCH', "/orders/{$payment}", [
    'payment_method' => 'bacs',
]), 200, function ($d) {
    return (($d['data']['payment_method'] ?? '') === 'bacs'
        && ($d['data']['payment_method_title'] ?? '') === 'Cash on delivery')
        ?: 'got ' . wp_json_encode([$d['data']['payment_method'] ?? null, $d['data']['payment_method_title'] ?? null]);
});

ac_check('the title moves without its method', ac_req('PATCH', "/orders/{$payment}", [
    'payment_method_title' => 'Virement bancaire',
]), 200, function ($d) {
    return (($d['data']['payment_method'] ?? '') === 'bacs'
        && ($d['data']['payment_method_title'] ?? '') === 'Virement bancaire')
        ?: 'got ' . wp_json_encode([$d['data']['payment_method'] ?? null, $d['data']['payment_method_title'] ?? null]);
});

ac_check('clearing the method also clears the title', ac_req('PATCH', "/orders/{$payment}", [
    'payment_method' => '',
]), 200, function ($d) {
    return (($d['data']['payment_method'] ?? '?') === '' && ($d['data']['payment_method_title'] ?? '?') === '')
        ?: 'the title survived: ' . wp_json_encode($d['data']['payment_method_title'] ?? null);
});

ac_check('unless the same body states one', ac_req('PATCH', "/orders/{$payment}", [
    'payment_method' => '',
    'payment_method_title' => 'A la livraison',
]), 200, function ($d) {
    return ($d['data']['payment_method_title'] ?? '') === 'A la livraison'
        ?: 'the title is ' . ($d['data']['payment_method_title'] ?? '?');
});

// No gateway registry check. A slug no gateway answers to is stored as typed,
// so a form offering a select is the only thing keeping the value meaningful.
ac_check('an unregistered gateway slug is stored as typed', ac_req('PATCH', "/orders/{$payment}", [
    'payment_method' => 'not_a_gateway',
]), 200, function ($d) {
    return ($d['data']['payment_method'] ?? '') === 'not_a_gateway' ?: 'the slug was rewritten';
});

ac_check('a non-string method is refused by name', ac_req('PATCH', "/orders/{$payment}", [
    'payment_method' => ['cod'],
]), 400, function ($d) {
    return ac_field_error($d, 'payment_method') === 'Must be a string.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * `customer_note`: 5 000 characters, counted with mb_strlen after trimming, and
 * refused by its own name. The three string fields share the cap.
 */
$note = ac_probe_order($probeId);

ac_check('a 5000-character note is accepted', ac_req('PATCH', "/orders/{$note}", [
    'customer_note' => str_repeat('a', 5000),
]), 200, function ($d) {
    return mb_strlen((string) ($d['data']['customer_note'] ?? '')) === 5000
        ?: 'stored ' . mb_strlen((string) ($d['data']['customer_note'] ?? '')) . ' characters';
});

ac_check('5001 is refused under customer_note', ac_req('PATCH', "/orders/{$note}", [
    'customer_note' => str_repeat('a', 5001),
]), 400, function ($d) {
    return ac_field_error($d, 'customer_note') === 'Must be at most 5000 characters.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

// Trimmed before it is counted, so surrounding whitespace never costs a caller
// the last characters of a note that fits.
ac_check('whitespace is trimmed before the cap is applied', ac_req('PATCH', "/orders/{$note}", [
    'customer_note' => '  ' . str_repeat('b', 5000) . '  ',
]), 200);

ac_check('null empties the note', ac_req('PATCH', "/orders/{$note}", ['customer_note' => null]), 200, function ($d) {
    return ($d['data']['customer_note'] ?? '?') === '' ?: 'got ' . var_export($d['data']['customer_note'] ?? null, true);
});

// Stored verbatim — no sanitizer runs on the way in or the way out. The panel
// escapes on render; nothing here does it for them.
ac_check('a note is stored and returned verbatim', ac_req('PATCH', "/orders/{$note}", [
    'customer_note' => '<script>alert(1)</script> & "quoted"',
]), 200, function ($d) {
    return ($d['data']['customer_note'] ?? '') === '<script>alert(1)</script> & "quoted"'
        ?: 'got ' . var_export($d['data']['customer_note'] ?? null, true);
});

ac_check('an address field is stored verbatim too', ac_req('PATCH', "/orders/{$note}", [
    'billing' => ['first_name' => '<b>Amina</b>'],
]), 200, function ($d) {
    return ($d['data']['billing']['first_name'] ?? '') === '<b>Amina</b>'
        ?: 'got ' . var_export($d['data']['billing']['first_name'] ?? null, true);
});

ac_check('an address field caps at 200 characters', ac_req('PATCH', "/orders/{$note}", [
    'billing' => ['city' => str_repeat('x', 201)],
]), 400, function ($d) {
    return ac_field_error($d, 'billing.city') === 'Must be at most 200 characters.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

/*
 * A read-only field is **dropped, not refused** — which means a body of nothing
 * but read-only fields is indistinguishable from an empty body, and gets the
 * empty-body refusal rather than anything naming `total`. That refusal carries
 * no `details.fields` either.
 *
 * The distinction matters to a form: there is no per-field error to render for
 * a read-only key, and a whole-body PATCH is exactly what this behaviour is for.
 */
$dropped = ac_probe_order($probeId);

ac_check('a body of only read-only fields reads as an empty body', ac_req('PATCH', "/orders/{$dropped}", [
    'id' => 1, 'number' => 'x', 'order_key' => 'x', 'created_via' => 'x', 'currency' => 'USD',
    'version' => '1', 'discount_total' => '5', 'shipping_total' => '5', 'total_tax' => '5',
    // Backend step 2's label. Dropped rather than rejected for the same reason
    // as the rest, and it must stay in this list: the presenter emits it, so a
    // client PATCHing back a body it just read sends it without meaning to.
    'shipping_source' => 'provider',
    'total' => '1.00', 'subtotal' => '5', 'prices_include_tax' => true, 'payment_url' => 'x',
    'is_editable' => false, 'needs_payment' => false, 'stock_reduced' => true, 'customer' => [],
    'date_created' => 'x', 'date_modified' => 'x', 'date_paid' => 'x', 'date_completed' => 'x',
]), 400, function ($d) {
    if (($d['error']['message'] ?? '') !== 'No supported fields were provided.') {
        return 'message is ' . ($d['error']['message'] ?? '?');
    }

    return !isset($d['error']['details']['fields']) ?: 'a dropped field produced a field error after all';
});

ac_check('and none of it landed', ac_req('GET', "/orders/{$dropped}"), 200, function ($d) {
    return ($d['data']['total'] ?? '') === '100.00' ?: 'total is ' . ($d['data']['total'] ?? '?');
});

ac_check('a read-only field alongside a real one is simply ignored', ac_req('PATCH', "/orders/{$dropped}", [
    'total' => '1.00',
    'customer_note' => 'sent with a total alongside',
]), 200, function ($d) {
    return ($d['data']['total'] ?? '') === '100.00' ?: 'the stated total was believed: ' . ($d['data']['total'] ?? '?');
});

ac_check('an empty body is the same refusal', ac_req('PATCH', "/orders/{$dropped}", []), 400, function ($d) {
    return ($d['error']['message'] ?? '') === 'No supported fields were provided.'
        ?: 'got ' . ($d['error']['message'] ?? '?');
});

/*
 * Nobody may state where a delivery charge came from — backend step 2.
 *
 * `shipping_amount` is settable because a person may decide a delivery fee.
 * `shipping_source` is not settable by anyone, because stating it would be
 * stating that a courier answered when none was asked, and the field's entire
 * worth is that an operator can trust it months later. It is emitted by the
 * presenter, so it also has to survive a whole-body PATCH rather than 400 on a
 * key the client never touched — the lossy round trip `OrderPresenter`'s
 * docblock says a line's `price` once broke.
 */
ac_check('a stated shipping_source is dropped, not believed', ac_req('PATCH', "/orders/{$dropped}", [
    'shipping_source' => 'provider',
    'customer_note' => 'sent with a source alongside',
]), 200, function ($d) {
    // array_key_exists rather than `??`, which cannot tell an absent key from
    // the null this assertion is entirely about — the same care
    // `shipping_amount`'s null assertion takes a few hundred lines above.
    if (!array_key_exists('shipping_source', $d['data'] ?? [])) {
        return 'the presenter must emit shipping_source even when nothing set it';
    }

    return $d['data']['shipping_source'] === null
        ?: 'a caller stated a source and it stuck: ' . var_export($d['data']['shipping_source'], true);
});

/*
 * `customer_id` is editable on an existing order, in both directions, and the
 * only thing checked is that the user exists. Re-attributing an order to a
 * shop employee is accepted — there is no role or "is a customer" rule — so a
 * form offering a customer picker is what keeps the value sensible.
 */
$owner = ac_probe_order($probeId);

// Its own two users rather than the ones at the top of the file: the list
// section above asserts that `$support` has no orders at all, and an
// attribution left behind here would fail that assertion on the *next* run
// rather than this one.
$probeBuyer = ac_user('ac_ord_probe_buyer', 'customer');
$probeStaff = ac_user('ac_ord_probe_staff', 'ac_order_manager');

ac_check('an order can be re-attributed to a user', ac_req('PATCH', "/orders/{$owner}", [
    'customer_id' => $probeBuyer,
]), 200, function ($d) use ($probeBuyer) {
    return ($d['data']['customer_id'] ?? null) === $probeBuyer ?: 'got ' . var_export($d['data']['customer_id'] ?? null, true);
});

ac_check('and back to a guest with 0', ac_req('PATCH', "/orders/{$owner}", ['customer_id' => 0]), 200, function ($d) {
    return ($d['data']['customer_id'] ?? null) === 0 ?: 'got ' . var_export($d['data']['customer_id'] ?? null, true);
});

ac_check('a user that does not exist is refused by id', ac_req('PATCH', "/orders/{$owner}", [
    'customer_id' => 99999999,
]), 400, function ($d) {
    return ac_field_error($d, 'customer_id') === 'No user with id 99999999.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('a negative id is refused by shape', ac_req('PATCH', "/orders/{$owner}", [
    'customer_id' => -1,
]), 400, function ($d) {
    return ac_field_error($d, 'customer_id') === 'Must be a user id, or 0 for a guest.'
        ?: 'got ' . wp_json_encode($d['error']['details'] ?? null);
});

// No role check: an order manager is a valid customer_id as far as this route
// is concerned. Put back to a guest immediately afterwards, so the section
// leaves no order attributed to anyone.
ac_check('a staff user is an acceptable customer', ac_req('PATCH', "/orders/{$owner}", [
    'customer_id' => $probeStaff,
]), 200);

ac_check('and the probe order is handed back to a guest', ac_req('PATCH', "/orders/{$owner}", [
    'customer_id' => 0,
]), 200);

/*
 * Every refusal in one body, because a form renders them all at once. The keys
 * below are the complete vocabulary an edit form has to be able to bind to, and
 * they arrive together rather than one per round trip.
 */
ac_check('every field-level refusal arrives in one response', ac_req('PATCH', "/orders/{$owner}", [
    'billing' => ['country' => 'Algeria', 'email' => 'nope', 'wilaya' => 16],
    'shipping' => ['email' => 'a@b.co', 'country' => 'France'],
    'customer_id' => 'abc',
    'status' => 'nonsense',
    'customer_note' => str_repeat('a', 5001),
    'unknown_top' => 1,
    'line_items' => [['product_id' => 0, 'quantity' => 0]],
]), 400, function ($d) {
    $expected = [
        'unknown_top', 'customer_note', 'status', 'customer_id', 'billing.wilaya', 'billing.email',
        'billing.country', 'shipping.email', 'shipping.country', 'line_items.0.product_id',
        'line_items.0.quantity',
    ];

    $actual = array_keys($d['error']['details']['fields'] ?? []);
    sort($expected);
    sort($actual);

    return $expected === $actual ? true : 'got ' . wp_json_encode($actual);
});

// A 404 carries no details at all, so an id that has gone away is a page-level
// error rather than anything a field can show.
ac_check('a missing order is a bare 404', ac_req('PATCH', '/orders/99999999', [
    'customer_note' => 'x',
]), 404, function ($d) {
    return (($d['error']['code'] ?? '') === 'not_found' && ($d['error']['details'] ?? null) === null)
        ?: 'got ' . wp_json_encode($d['error'] ?? null);
});

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
