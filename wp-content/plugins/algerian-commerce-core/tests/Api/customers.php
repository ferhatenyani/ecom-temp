<?php
/**
 * Customer endpoints against a real WordPress + WooCommerce install — roadmap
 * §50, §65.
 *
 * The statistics are the reason this suite exists: they are arithmetic over a
 * customer's whole order history, and the only way to know they are right is to
 * build a history with known answers and check them.
 *
 * The other reason is the capability boundary. `ac_manage_customers` is held by
 * Support Agent, the thinnest role in the system, so this file checks that the
 * endpoint cannot be used to read staff accounts or to change a role, an email
 * someone else owns, or a password.
 *
 *   scripts/test.sh                                  # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/customers.php
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

function ac_assert(string $label, $verdict): void
{
    $ok = $verdict === true;
    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;

    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 60);
    echo $ok ? '' : '     ' . (is_string($verdict) ? $verdict : 'failed');
    echo PHP_EOL;
}

function ac_user(string $login, string $role, string $email = ''): int
{
    $user = get_user_by('login', $login);

    if ($user) {
        $user->set_role($role);

        return (int) $user->ID;
    }

    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(24),
        'user_email' => $email !== '' ? $email : $login . '@example.test',
        'role' => $role,
    ]);

    return is_wp_error($id) ? 0 : (int) $id;
}

function ac_product(string $sku, string $price, int $stock): WC_Product
{
    $id = (int) wc_get_product_id_by_sku($sku);
    $product = $id > 0 ? wc_get_product($id) : new WC_Product_Simple();

    $product->set_name('Customer test ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);
    $product->set_status('publish');
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->save();

    return wc_get_product($product->get_id());
}

/**
 * Wipe this customer's order history.
 *
 * The statistics are exact figures over a whole history, so the history has to
 * start empty or a second run measures the first one's leftovers. Scoped to
 * this suite's own fixture customer and nothing else.
 */
function ac_clear_orders(int $customerId): int
{
    $orders = wc_get_orders(['customer_id' => $customerId, 'limit' => -1, 'type' => 'shop_order']);
    $removed = 0;

    foreach (is_array($orders) ? $orders : [] as $order) {
        $order->delete(true);
        $removed++;
    }

    return $removed;
}

/** Place an order for the fixture customer and bring it to a status. */
function ac_place(int $customerId, int $productId, int $quantity, string $status): int
{
    $order = new WC_Order();
    $order->set_customer_id($customerId);
    $order->save();
    $order->add_product(wc_get_product($productId), $quantity);
    $order->calculate_totals();

    if ($status !== 'pending') {
        $order->set_status($status);
        $order->save();
    }

    return $order->get_id();
}

$manager = ac_user('ac_cus_manager', 'ac_order_manager');     // has ac_manage_customers
$denied = ac_user('ac_cus_denied', 'ac_product_manager');     // has not
$customer = ac_user('ac_cus_shopper', 'customer');
$other = ac_user('ac_cus_other', 'customer', 'ac_cus_other@example.test');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /customers signed out', ac_req('GET', '/customers'), 401);
ac_check('GET /customers/{id} signed out', ac_req('GET', "/customers/{$customer}"), 401);
ac_check('PATCH /customers/{id} signed out', ac_req('PATCH', "/customers/{$customer}", ['first_name' => 'x']), 401);
ac_check('GET orders signed out', ac_req('GET', "/customers/{$customer}/orders"), 401);

wp_set_current_user($denied);
ac_check('GET /customers as a product manager', ac_req('GET', '/customers'), 403);
ac_check('PATCH /customers as a product manager', ac_req('PATCH', "/customers/{$customer}", ['first_name' => 'x']), 403);

wp_set_current_user($manager);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$kettle = ac_product('AC-CUS-KETTLE', '1500', 500);
$mug = ac_product('AC-CUS-MUG', '300', 500);

ac_clear_orders($customer);
ac_assert('the fixture history starts empty', ac_clear_orders($customer) === 0 ?: 'orders survived the wipe');

// Two completed (1500 + 600), one cancelled, one refunded, one pending.
ac_place($customer, $kettle->get_id(), 1, 'completed');
ac_place($customer, $mug->get_id(), 2, 'completed');
ac_place($customer, $kettle->get_id(), 1, 'cancelled');
ac_place($customer, $kettle->get_id(), 1, 'refunded');
$pendingId = ac_place($customer, $mug->get_id(), 1, 'pending');

ac_assert('five orders were placed', count(wc_get_orders(['customer_id' => $customer, 'limit' => -1])) === 5
    ?: 'the fixture history is the wrong size');

echo PHP_EOL, "=== list ===", PHP_EOL;

ac_check('list customers', ac_req('GET', '/customers'), 200, function ($d) {
    return isset($d['meta']['total'], $d['meta']['page'], $d['meta']['per_page']) ?: 'no pagination meta';
});

ac_check('per_page above the maximum is refused', ac_req('GET', '/customers', null, ['per_page' => 500]), 400);
ac_check('page zero is refused', ac_req('GET', '/customers', null, ['page' => 0]), 400);
ac_check('an unknown orderby is refused', ac_req('GET', '/customers', null, ['orderby' => 'user_pass']), 400);

ac_check('the list holds only customers', ac_req('GET', '/customers', null, ['per_page' => 100]), 200, function ($d) {
    foreach ($d['data'] as $row) {
        if ($row['role'] !== 'customer') {
            return 'found a ' . $row['role'];
        }
    }

    return true;
});

ac_check('staff accounts are not listed', ac_req('GET', '/customers', null, ['per_page' => 100]), 200, function ($d) use ($manager) {
    return !in_array($manager, array_column($d['data'], 'id'), true) ?: 'the order manager appeared as a customer';
});

ac_check('search finds a customer', ac_req('GET', '/customers', null, ['search' => 'ac_cus_shopper']), 200, function ($d) use ($customer) {
    return in_array($customer, array_column($d['data'], 'id'), true) ?: 'search missed the fixture customer';
});

ac_check('search for nobody', ac_req('GET', '/customers', null, ['search' => 'zzz-nobody-zzz']), 200, function ($d) {
    return $d['data'] === [] ?: 'expected no matches';
});

ac_check('the list carries no statistics', ac_req('GET', '/customers', null, ['search' => 'ac_cus_shopper']), 200, function ($d) {
    // One query per row is not what a list is for.
    return !array_key_exists('statistics', $d['data'][0] ?? []) ?: 'statistics leaked into the list';
});

echo PHP_EOL, "=== profile ===", PHP_EOL;

ac_check('read a customer', ac_req('GET', "/customers/{$customer}"), 200, function ($d) use ($customer) {
    return ($d['data']['id'] ?? 0) === $customer ?: 'wrong customer';
});

ac_check('a missing customer', ac_req('GET', '/customers/99999999'), 404);

/*
 * The enumeration guard. WC_Customer wraps any WordPress user, so without a
 * role check this would hand a support account the administrator's email.
 */
ac_check('a staff account is not a customer record', ac_req('GET', "/customers/{$manager}"), 404);
ac_check('and neither are its orders', ac_req('GET', "/customers/{$manager}/orders"), 404);

ac_check('the profile carries no credentials', ac_req('GET', "/customers/{$customer}"), 200, function ($d) {
    foreach (['user_pass', 'password', 'capabilities', 'allcaps', 'user_activation_key'] as $leak) {
        if (array_key_exists($leak, $d['data'])) {
            return "{$leak} is in the response";
        }
    }

    return true;
});

echo PHP_EOL, "=== statistics ===", PHP_EOL;

$profile = ac_check('the profile carries statistics', ac_req('GET', "/customers/{$customer}"), 200, function ($d) {
    return isset($d['data']['statistics']) ?: 'no statistics';
});

$stats = $profile['data']['statistics'];

ac_assert('total orders', ($stats['total_orders'] ?? null) === 5 ?: 'got ' . var_export($stats['total_orders'] ?? null, true));
ac_assert('completed orders', ($stats['completed_orders'] ?? null) === 2 ?: 'got ' . var_export($stats['completed_orders'] ?? null, true));
ac_assert('cancelled orders', ($stats['cancelled_orders'] ?? null) === 1 ?: 'got ' . var_export($stats['cancelled_orders'] ?? null, true));
ac_assert('returned orders map to refunded', ($stats['returned_orders'] ?? null) === 1 ?: 'got ' . var_export($stats['returned_orders'] ?? null, true));

// 1 x 1500 + 2 x 300. The cancelled, refunded and pending orders are not money.
ac_assert('revenue counts completed orders only', ($stats['total_revenue'] ?? null) === '2100.00'
    ?: 'got ' . var_export($stats['total_revenue'] ?? null, true));

ac_assert('average is over the orders that earned it', ($stats['average_order_value'] ?? null) === '1050.00'
    ?: 'got ' . var_export($stats['average_order_value'] ?? null, true));

ac_assert('money is a string', is_string($stats['total_revenue'] ?? null) && is_string($stats['average_order_value'] ?? null)
    ?: 'a total came back as a number');

ac_assert('the last order is the newest', ($stats['last_order']['id'] ?? 0) === $pendingId
    ?: 'got ' . var_export($stats['last_order']['id'] ?? null, true));

ac_assert('first and last are different orders', ($stats['first_order']['id'] ?? 0) !== ($stats['last_order']['id'] ?? 0)
    ?: 'first and last collapsed');

ac_assert('by_status totals agree with total_orders', (function () use ($stats) {
    $sum = array_sum($stats['by_status'] ?? []);

    return $sum === ($stats['total_orders'] ?? -1) ?: "by_status sums to {$sum}";
})());

$fresh = ac_user('ac_cus_fresh', 'customer');
ac_clear_orders($fresh);

ac_check('a customer who has never ordered', ac_req('GET', "/customers/{$fresh}"), 200, function ($d) {
    $s = $d['data']['statistics'];

    if ($s['total_orders'] !== 0) {
        return 'total_orders is ' . $s['total_orders'];
    }

    if ($s['average_order_value'] !== '0.00') {
        return 'average is ' . $s['average_order_value'];
    }

    return $s['first_order'] === null && $s['last_order'] === null ?: 'first/last should be null';
});

echo PHP_EOL, "=== order history ===", PHP_EOL;

ac_check('a customer\'s orders', ac_req('GET', "/customers/{$customer}/orders"), 200, function ($d) use ($customer) {
    if ($d['data'] === []) {
        return 'no orders returned';
    }

    foreach ($d['data'] as $order) {
        if ((int) $order['customer_id'] !== $customer) {
            return 'got an order for customer ' . $order['customer_id'];
        }
    }

    return true;
});

ac_check('the history is paginated', ac_req('GET', "/customers/{$customer}/orders", null, ['per_page' => 2]), 200, function ($d) {
    return count($d['data']) === 2 && ($d['meta']['total'] ?? 0) === 5
        ?: 'got ' . count($d['data']) . ' of ' . ($d['meta']['total'] ?? '?');
});

ac_check('the history can be filtered by status', ac_req('GET', "/customers/{$customer}/orders", null, ['status' => 'completed']), 200, function ($d) {
    if (count($d['data']) !== 2) {
        return 'got ' . count($d['data']) . ' completed orders';
    }

    foreach ($d['data'] as $order) {
        if ($order['status'] !== 'completed') {
            return 'got a ' . $order['status'] . ' order';
        }
    }

    return true;
});

ac_check('an unknown status filter is refused', ac_req('GET', "/customers/{$customer}/orders", null, ['status' => 'shipped']), 400);
ac_check('orders for a missing customer', ac_req('GET', '/customers/99999999/orders'), 404);

ac_check('a customer with no orders', ac_req('GET', "/customers/{$fresh}/orders"), 200, function ($d) {
    return $d['data'] === [] ?: 'expected an empty history';
});

echo PHP_EOL, "=== update ===", PHP_EOL;

ac_check('update the profile', ac_req('PATCH', "/customers/{$customer}", [
    'first_name' => 'Amina',
    'last_name' => 'Benali',
    'billing' => ['city' => 'Alger', 'country' => 'dz', 'phone' => '0550123456'],
]), 200, function ($d) {
    if (($d['data']['first_name'] ?? '') !== 'Amina') {
        return 'the name did not stick';
    }

    return ($d['data']['billing']['country'] ?? '') === 'DZ' ?: 'the country was not normalized';
});

ac_check('an empty update', ac_req('PATCH', "/customers/{$customer}", []), 400);
ac_check('an unknown field', ac_req('PATCH', "/customers/{$customer}", ['wilaya' => 16]), 400);
ac_check('a malformed email', ac_req('PATCH', "/customers/{$customer}", ['email' => 'nope']), 400);
ac_check('an emptied email', ac_req('PATCH', "/customers/{$customer}", ['email' => '']), 400);

ac_check('an email another account owns', ac_req('PATCH', "/customers/{$customer}", [
    'email' => 'ac_cus_other@example.test',
]), 409);

ac_check('a missing customer', ac_req('PATCH', '/customers/99999999', ['first_name' => 'x']), 404);

echo PHP_EOL, "=== the privilege boundary ===", PHP_EOL;

foreach (['password', 'user_pass', 'roles', 'capabilities'] as $field) {
    ac_check("{$field} is refused by name", ac_req('PATCH', "/customers/{$customer}", [
        $field => 'administrator',
    ]), 400, function ($d) use ($field) {
        $message = $d['error']['details']['fields'][$field] ?? '';

        return ($message !== '' && $message !== 'Unknown field.')
            ?: 'the refusal should say where the boundary is';
    });
}

// `role` is emitted by the presenter, so it is dropped rather than refused —
// and dropping is what makes it safe.
ac_check('role is dropped, not applied', ac_req('PATCH', "/customers/{$customer}", [
    'role' => 'administrator',
    'first_name' => 'Amina',
]), 200);

ac_assert('the customer is still a customer', (function () use ($customer) {
    $user = get_userdata($customer);

    return in_array('customer', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true)
        ?: 'roles are now ' . implode(', ', (array) $user->roles);
})());

ac_assert('and still cannot manage anything', !user_can($customer, 'ac_manage_orders') ?: 'the customer gained a capability');

echo PHP_EOL, "=== audit trail ===", PHP_EOL;

wp_set_current_user(ac_user('ac_cus_auditor', 'ac_admin'));

ac_check('the update was audited', ac_req('GET', '/audit-logs', null, ['action' => 'customer.updated']), 200, function ($d) use ($customer) {
    foreach ($d['data'] as $row) {
        if ($row['resource_type'] === 'customer' && $row['resource_id'] === (string) $customer) {
            return true;
        }
    }

    return 'no customer.updated row for this customer';
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
