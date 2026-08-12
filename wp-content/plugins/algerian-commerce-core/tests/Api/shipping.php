<?php
/**
 * Shipping endpoints against a real WordPress + WooCommerce install — roadmap
 * §53, §65 (API and Security test categories).
 *
 * Covers what unit tests structurally cannot: authorization (401/403), the
 * destination validated against the real §51 geography tables, the
 * one-live-shipment-per-order rule against rows that actually exist, and the
 * abstraction itself — that a shipment created through the service comes back
 * carrying the provider that made it.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here — those live
 * in scripts/test-api.sh, over real HTTP.
 *
 *   scripts/test.sh                             # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/shipping.php
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

    $product->set_name('Shipping test ' . $sku);
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

function ac_shipment_row(int $id): array
{
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ac_shipments WHERE id = %d", $id),
        ARRAY_A
    );

    return is_array($row) ? $row : [];
}

function ac_order(int $productId, string $method = 'cod', string $status = 'processing'): int
{
    [, $body] = ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => $method,
        'status' => $status,
        'shipping' => [
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'address_1' => '5 Rue des Frères Bouadou',
            'city' => 'Bir Mourad Raïs',
            'country' => 'DZ',
            'phone' => '0661778899',
        ],
    ]);

    return (int) ($body['data']['id'] ?? 0);
}

$manager = ac_user('ac_ship_manager', 'ac_order_manager');   // ac_manage_orders + ac_manage_shipping
$support = ac_user('ac_ship_support', 'ac_support_agent');   // neither

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET providers signed out', ac_req('GET', '/shipping/providers'), 401);
ac_check('GET rates signed out', ac_req('GET', '/shipping/rates', null, ['wilaya_id' => 1, 'commune_id' => 1]), 401);
ac_check('GET shipments signed out', ac_req('GET', '/shipments'), 401);
ac_check('POST a shipment signed out', ac_req('POST', '/orders/1/shipments', ['wilaya_id' => 1, 'commune_id' => 1]), 401);
ac_check('POST cancel signed out', ac_req('POST', '/shipments/1/cancel'), 401);

wp_set_current_user($support);
// A tracking number and a destination are delivery data, so the
// least-privileged staff role does not get them, not even to read.
ac_check('GET shipments as support agent', ac_req('GET', '/shipments'), 403);
ac_check('GET providers as support agent', ac_req('GET', '/shipping/providers'), 403);
ac_check('POST a shipment as support agent', ac_req('POST', '/orders/1/shipments', ['wilaya_id' => 1, 'commune_id' => 1]), 403);

wp_set_current_user($manager);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$box = ac_product('AC-SHIP-BOX', '4200', 100);
$boxId = $box->get_id();

// Real ids from the §51 dataset — the whole point of validating a destination
// is that it is checked against the geography this install actually loaded.
[, $wilayas] = ac_req('GET', '/locations/wilayas');
$wilayaId = (int) ($wilayas['data'][0]['id'] ?? 0);

[, $communes] = ac_req('GET', "/locations/wilayas/{$wilayaId}/communes");
$communeId = (int) ($communes['data'][0]['id'] ?? 0);

ac_assert('a wilaya was loaded to ship to', $wilayaId > 0 ?: 'no wilayas in the dataset');
ac_assert('it has a commune', $communeId > 0 ?: 'no communes in the dataset');

echo PHP_EOL, "=== providers ===", PHP_EOL;

ac_check('the shop knows what it can ship with', ac_req('GET', '/shipping/providers'), 200, function ($d) {
    $names = array_column($d['data'], 'name');

    if (!in_array('manual', $names, true)) {
        return 'in-house delivery is missing: ' . implode(', ', $names);
    }

    return ($d['data'][0]['is_default'] ?? false) === true ?: 'no provider is marked default';
});

echo PHP_EOL, "=== rates ===", PHP_EOL;

ac_check('rates need a destination', ac_req('GET', '/shipping/rates'), 400);
ac_check('an unknown provider is refused', ac_req('GET', '/shipping/rates', null, [
    'provider' => 'yalidine',
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 400, function ($d) {
    return ($d['error']['details']['available'] ?? []) === ['manual'] ?: 'the refusal does not name what is available';
});

ac_check('an invented delivery type is refused', ac_req('GET', '/shipping/rates', null, [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
    'delivery_type' => 'locker',
]), 400);

// Empty, and that is the honest answer: an in-house courier publishes no rate
// API, and what a shop charges for its own delivery is §14's pricing.
ac_check('in-house delivery quotes nothing', ac_req('GET', '/shipping/rates', null, [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 200, function ($d) {
    return $d['data'] === [] ?: 'expected no quotes';
});

echo PHP_EOL, "=== creating a shipment: validation ===", PHP_EOL;

$orderId = ac_order($boxId);
ac_assert('an order to ship was created', $orderId > 0 ?: 'no order id');

ac_check('a shipment for a missing order', ac_req('POST', '/orders/99999999/shipments', [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 404);

ac_check('a shipment with no destination', ac_req('POST', "/orders/{$orderId}/shipments", []), 400, function ($d) {
    return isset($d['error']['details']['fields']['commune_id']) ?: 'expected a commune_id error';
});

ac_check('a shipment with an unknown field', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
    'tracking_number' => 'MINE-1',
]), 400);

ac_check('a caller cannot invent the status', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
    'status' => 'delivered',
]), 400);

ac_check('a commune that does not exist', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => 99999999,
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['commune_id']) ?: 'expected a commune_id error';
});

// The mistake an address form actually makes: the commune from the new
// dropdown, the wilaya left over from the previous selection.
ac_check('a commune from a different wilaya', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId + 1000,
    'commune_id' => $communeId,
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['wilaya_id']) ?: 'expected a wilaya_id error';
});

echo PHP_EOL, "=== creating a shipment: the happy path ===", PHP_EOL;

$created = ac_check('the parcel is handed over', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
    'delivery_type' => 'desk',
    'note' => 'Fragile',
]), 201, function ($d) use ($orderId) {
    $s = $d['data'];

    if (($s['provider'] ?? '') !== 'manual') {
        return 'provider is ' . ($s['provider'] ?? '?');
    }

    if (($s['status'] ?? '') !== 'created' || ($s['is_live'] ?? null) !== true) {
        return 'expected a live, created shipment';
    }

    // The first attempt at this order, not merely "this order".
    return ($s['tracking_number'] ?? '') === "MAN-{$orderId}-1" ?: 'tracking is ' . ($s['tracking_number'] ?? '?');
});

$shipmentId = (int) ($created['data']['id'] ?? 0);

ac_assert('it was written to ac_shipments', ac_shipment_row($shipmentId) !== [] ?: 'no row');

ac_assert(
    'the row carries the order and the provider',
    (function () use ($shipmentId, $orderId) {
        $row = ac_shipment_row($shipmentId);

        return ((int) $row['order_id'] === $orderId && $row['provider'] === 'manual')
            ?: 'row is ' . wp_json_encode($row);
    })()
);

ac_check('the COD amount the driver must collect was recorded', [200, $created], 200, function ($d) {
    // The order was placed cash on delivery, so the parcel carries what the
    // driver has to come back with.
    return ($d['data']['metadata']['cod_amount'] ?? '') === '4200.00'
        ?: 'cod_amount is ' . ($d['data']['metadata']['cod_amount'] ?? '?');
});

// Two parcels for one order is two vans, one of them delivering to a customer
// who has already been served.
ac_check('a second parcel for the same order', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 409, function ($d) use ($shipmentId) {
    return ($d['error']['details']['shipment_id'] ?? 0) === $shipmentId ?: 'the refusal does not name the live shipment';
});

echo PHP_EOL, "=== reading ===", PHP_EOL;

ac_check('read the shipment back', ac_req('GET', "/shipments/{$shipmentId}"), 200, function ($d) use ($shipmentId) {
    return ($d['data']['id'] ?? 0) === $shipmentId ?: 'wrong shipment';
});

ac_check('a missing shipment', ac_req('GET', '/shipments/99999999'), 404);

ac_check('the order lists its parcels', ac_req('GET', "/orders/{$orderId}/shipments"), 200, function ($d) use ($shipmentId) {
    return in_array($shipmentId, array_column($d['data'], 'id'), true) ?: 'the shipment is missing';
});

ac_check('shipments for an order that has none', ac_req('GET', '/orders/' . ac_order($boxId) . '/shipments'), 200, function ($d) {
    return $d['data'] === [] ?: 'expected no shipments';
});

ac_check('the shipments list paginates', ac_req('GET', '/shipments'), 200, function ($d) {
    return isset($d['meta']['total'], $d['meta']['page'], $d['meta']['per_page']) ?: 'no pagination meta';
});

ac_check('per_page above the maximum is refused', ac_req('GET', '/shipments', null, ['per_page' => 500]), 400);
ac_check('an unknown status filter is refused', ac_req('GET', '/shipments', null, ['status' => 'en_route']), 400);
ac_check('a malformed date is refused', ac_req('GET', '/shipments', null, ['date_from' => '12-08-2026']), 400);

ac_check('filter by order', ac_req('GET', '/shipments', null, ['order_id' => $orderId]), 200, function ($d) use ($orderId) {
    foreach ($d['data'] as $shipment) {
        if ((int) $shipment['order_id'] !== $orderId) {
            return 'got a shipment for order ' . $shipment['order_id'];
        }
    }

    return $d['data'] !== [] ?: 'the order\'s own shipment is missing';
});

ac_check('filter by tracking number', ac_req('GET', '/shipments', null, [
    'tracking_number' => "MAN-{$orderId}-1",
]), 200, function ($d) use ($shipmentId) {
    return array_column($d['data'], 'id') === [$shipmentId] ?: 'expected exactly the one parcel';
});

echo PHP_EOL, "=== syncing ===", PHP_EOL;

// Answering "created" would look like a successful sync and would later walk a
// shipment a person had advanced back to the beginning.
ac_check('in-house delivery has no status to sync', ac_req('POST', "/shipments/{$shipmentId}/sync"), 409, function ($d) {
    return ($d['error']['code'] ?? '') === 'sync_unsupported' ?: 'got ' . ($d['error']['code'] ?? '?');
});

echo PHP_EOL, "=== moving the parcel by hand ===", PHP_EOL;

ac_check('an unknown status is refused', ac_req('PATCH', "/shipments/{$shipmentId}", ['status' => 'en_route']), 400);
ac_check('an unknown field is refused', ac_req('PATCH', "/shipments/{$shipmentId}", ['tracking_number' => 'X']), 400);

ac_check('the driver picks it up', ac_req('PATCH', "/shipments/{$shipmentId}", ['status' => 'picked_up']), 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'picked_up' ?: 'status is ' . ($d['data']['status'] ?? '?');
});

ac_check('re-sending the same status changes nothing', ac_req('PATCH', "/shipments/{$shipmentId}", [
    'status' => 'picked_up',
]), 409);

ac_check('it is delivered', ac_req('PATCH', "/shipments/{$shipmentId}", ['status' => 'delivered']), 200, function ($d) {
    return (($d['data']['status'] ?? '') === 'delivered' && ($d['data']['is_live'] ?? null) === false)
        ?: 'expected a finished shipment';
});

// A replayed webhook or a poll that crossed a delivery in flight must not
// reopen a parcel that has arrived.
ac_check('a delivered parcel is not reopened', ac_req('PATCH', "/shipments/{$shipmentId}", [
    'status' => 'in_transit',
]), 409, function ($d) {
    return ($d['error']['details']['is_live'] ?? null) === false ?: 'the refusal does not say it has finished';
});

ac_check('a delivered parcel cannot be cancelled', ac_req('POST', "/shipments/{$shipmentId}/cancel"), 409);

// Nothing left to learn, and nobody is called: a finished shipment syncs to
// itself rather than erroring, so a polling loop can stay simple.
ac_check('syncing a finished parcel is a no-op', ac_req('POST', "/shipments/{$shipmentId}/sync"), 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'delivered' ?: 'status is ' . ($d['data']['status'] ?? '?');
});

echo PHP_EOL, "=== a second attempt after the first finished ===", PHP_EOL;

// A finished shipment does not block a new one — that is what makes a re-send
// after a failed delivery possible without deleting history.
$resend = ac_check('the order can be shipped again', ac_req('POST', "/orders/{$orderId}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 201);

$resendId = (int) ($resend['data']['id'] ?? 0);

// A driver reading a number aloud must land on a parcel, not on an order that
// has had two of them.
ac_check('the second parcel has its own tracking number', [200, $resend], 200, function ($d) use ($orderId) {
    return ($d['data']['tracking_number'] ?? '') === "MAN-{$orderId}-2"
        ?: 'tracking is ' . ($d['data']['tracking_number'] ?? '?');
});

ac_check('the order now has two parcels on record', ac_req('GET', "/orders/{$orderId}/shipments"), 200, function ($d) {
    return count($d['data']) === 2 ?: 'got ' . count($d['data']) . ' shipments';
});

ac_check('the second parcel is cancelled', ac_req('POST', "/shipments/{$resendId}/cancel"), 200, function ($d) {
    return (($d['data']['status'] ?? '') === 'cancelled' && ($d['data']['is_live'] ?? null) === false)
        ?: 'status is ' . ($d['data']['status'] ?? '?');
});

ac_check('cancelling it twice', ac_req('POST', "/shipments/{$resendId}/cancel"), 409);

echo PHP_EOL, "=== orders nobody ships ===", PHP_EOL;

$cancelledOrder = ac_order($boxId);
ac_check('the order is cancelled', ac_req('POST', "/orders/{$cancelledOrder}/cancel", ['reason' => 'customer refused']), 200);

ac_check('a cancelled order cannot be shipped', ac_req('POST', "/orders/{$cancelledOrder}/shipments", [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 409, function ($d) {
    return ($d['error']['details']['order_status'] ?? '') === 'cancelled' ?: 'the refusal does not name the status';
});

echo PHP_EOL, "=== the trail ===", PHP_EOL;

$actions = ac_audit_actions($orderId);

ac_assert('the shipment was audited', in_array('shipment.created', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the status changes were audited', in_array('shipment.status_changed', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the cancellation was audited', in_array('shipment.cancelled', $actions, true) ?: 'saw ' . implode(', ', $actions));

// Shipping events are recorded against the *order*, so they land in the feed a
// shop already reads to see what happened to it.
ac_check('both parcels appear on the order timeline', ac_req('GET', "/orders/{$orderId}/timeline"), 200, function ($d) use ($orderId) {
    $summaries = [];

    foreach ($d['data'] as $entry) {
        if (($entry['data']['action'] ?? '') === 'shipment.created') {
            $summaries[] = $entry['summary'];
        }
    }

    if (count($summaries) !== 2) {
        return 'expected two shipments on the timeline, got ' . count($summaries);
    }

    // Each sentence carries its own tracking number — that is what an operator
    // is looking for when a customer calls about one of two parcels.
    foreach (["MAN-{$orderId}-1", "MAN-{$orderId}-2"] as $tracking) {
        $found = false;

        foreach ($summaries as $summary) {
            $found = $found || str_contains($summary, $tracking);
        }

        if (!$found) {
            return "{$tracking} is missing from: " . implode(' | ', $summaries);
        }
    }

    return true;
});

// A shipment is a fact about a parcel. Whether the order is then completed is
// the shop's decision, and on a COD order it is one taken with money in mind.
ac_check('shipping did not move the order', ac_req('GET', "/orders/{$orderId}"), 200, function ($d) {
    return ($d['data']['status'] ?? '') === 'processing' ?: 'the order is ' . ($d['data']['status'] ?? '?');
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
