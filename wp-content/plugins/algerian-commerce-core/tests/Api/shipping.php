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

// An in-house courier publishes no rate API, so it contributes no quote of its
// own. Asserted on the provider's contribution rather than on an empty list,
// because the shop's own tariff (§14) answers here too and this is a statement
// about ManualProvider, not about whether a tariff happens to be configured.
ac_check('in-house delivery quotes nothing', ac_req('GET', '/shipping/rates', null, [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
]), 200, function ($d) {
    foreach ($d['data'] as $quote) {
        if (($quote['source'] ?? '') === 'provider') {
            return 'in-house delivery quoted ' . wp_json_encode($quote);
        }
    }

    return true;
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

echo PHP_EOL, "=== confirmation creates the parcel ===", PHP_EOL;

/*
 * Backend step 2, item 5. Everything below runs through the real hook —
 * `Shipping\ShipmentSubscriber`, registered by `Plugin::boot()` — reached by
 * PATCHing an order to `processing`, which is what an operator's confirm button
 * does.
 *
 * **The destination is written as meta by hand, and that is the finding rather
 * than a shortcut.** `Cart\CheckoutService::createOrder()` writes `_ac_wilaya_id`,
 * `_ac_commune_id` and `_ac_delivery_type` onto every order the checkout places,
 * and `POST /orders` has no field that writes any of them — `OrderInput` carries
 * no wilaya and no commune. So a back-office order cannot be addressed, and the
 * only way to build the storefront order this feature is *for* is to write what
 * the checkout would have written. The order that has none is tested too, a few
 * cases down, because on this API it is the common one.
 */

function ac_ship_meta(int $orderId, int $wilayaId, int $communeId, string $deliveryType = 'home'): void
{
    $order = wc_get_order($orderId);
    $order->update_meta_data('_ac_wilaya_id', $wilayaId);
    $order->update_meta_data('_ac_commune_id', $communeId);
    $order->update_meta_data('_ac_delivery_type', $deliveryType);
    $order->save();
}

/**
 * Point an order's shipping line at a courier directly.
 *
 * Not a way round `POST /orders` — that route takes `shipping_provider` and is
 * used below. This produces the one state the route cannot: an order naming a
 * courier the shop does *not* have, which is exactly what a shop that switched
 * `ENABLE_YALIDINE` off is left holding, and what
 * `OrderService::guardShippingProviderKnown()`'s escape hatch exists for.
 */
function ac_name_courier(int $orderId, string $provider): void
{
    $order = wc_get_order($orderId);

    foreach ($order->get_items('shipping') as $item) {
        $item->set_method_id($provider);
    }

    $order->save();
}

function ac_shipments_for(int $orderId): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}ac_shipments WHERE order_id = %d ORDER BY id ASC", $orderId),
        ARRAY_A
    );

    return is_array($rows) ? $rows : [];
}

/** The order as the API publishes it — the shape a panel actually reads. */
function ac_order_body(int $orderId): array
{
    [, $body] = ac_req('GET', "/orders/{$orderId}");

    return is_array($body['data'] ?? null) ? $body['data'] : [];
}

function ac_new_order(int $productId, string $provider = ''): int
{
    $payload = [
        'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'cod',
        'status' => 'pending',
        'shipping' => [
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'address_1' => '5 Rue des Frères Bouadou',
            'city' => 'Bir Mourad Raïs',
            'country' => 'DZ',
            'phone' => '0661778899',
        ],
    ];

    if ($provider !== '') {
        $payload['shipping_provider'] = $provider;
    }

    [, $body] = ac_req('POST', '/orders', $payload);

    return (int) ($body['data']['id'] ?? 0);
}

// --- the ordinary case: a storefront order with a courier on it ---

$confirmId = ac_new_order($boxId, 'manual');
ac_ship_meta($confirmId, $wilayaId, $communeId);

ac_assert('a confirmable order exists', $confirmId > 0 ?: 'it was not created');
ac_assert('and no parcel yet', ac_shipments_for($confirmId) === [] ?: 'a parcel exists before confirmation');

ac_check(
    'confirming the order commits the status',
    ac_req('PATCH', "/orders/{$confirmId}", ['status' => 'processing']),
    200,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === 'processing'
        ?: 'the order is ' . ($d['data']['status'] ?? '?')
);

$created = ac_shipments_for($confirmId);
ac_assert('and it created exactly one parcel', count($created) === 1 ?: 'got ' . count($created));

/*
 * In-house delivery is not skipped on confirmation, and this is the answer to
 * "what does `manual` do" on an install where it is the only courier there is.
 * `ManualProvider::createShipment()` is pure — no HTTP, no credentials — so it
 * accepts every parcel and issues our own `MAN-` number, and the order is
 * tracked by `PATCH /shipments/{id}` from there. A skip would have left every
 * order on this install with no record of the delivery at all.
 */
ac_assert(
    'in-house delivery issues its own tracking number',
    ($created[0]['tracking_number'] ?? '') === "MAN-{$confirmId}-1"
        ?: 'got ' . ($created[0]['tracking_number'] ?? '?')
);
ac_assert('the parcel is live', ($created[0]['status'] ?? '') === 'created' ?: 'got ' . ($created[0]['status'] ?? '?'));
ac_assert(
    'and it names the courier the order named',
    ($created[0]['provider'] ?? '') === 'manual' ?: 'got ' . ($created[0]['provider'] ?? '?')
);

ac_check('a successful confirmation reports no error', ac_req('GET', "/orders/{$confirmId}"), 200, static function (array $d): bool|string {
    if (!array_key_exists('shipping_provider_error', $d['data'])) {
        return 'the field is missing from the order shape entirely';
    }

    return $d['data']['shipping_provider_error'] === null
        ?: 'got ' . wp_json_encode($d['data']['shipping_provider_error']);
});

// --- idempotence: a second transition into processing ---

ac_check('the order leaves processing', ac_req('PATCH', "/orders/{$confirmId}", ['status' => 'on-hold']), 200);
ac_check('and is confirmed a second time', ac_req('PATCH', "/orders/{$confirmId}", ['status' => 'processing']), 200);

/*
 * The guard is `ShipmentRepository::liveForOrder()` — the existing
 * one-live-shipment-per-order rule, not a second one written for this path. The
 * first parcel is still `created`, which is live, so the second confirmation
 * finds it and stops. EL's equivalent is `trackingNumber != null`.
 */
ac_assert(
    'a second confirmation creates no second parcel',
    count(ac_shipments_for($confirmId)) === 1 ?: 'got ' . count(ac_shipments_for($confirmId))
);

// --- an order nobody named a courier for ---

$noCourierId = ac_new_order($boxId);
ac_ship_meta($noCourierId, $wilayaId, $communeId);
ac_check('an order with no courier confirms', ac_req('PATCH', "/orders/{$noCourierId}", ['status' => 'processing']), 200);

/*
 * `null` is a real and expected state — an order taken by phone before anybody
 * decided who delivers it — so it is neither a parcel nor a failure. Recording
 * one would put a red flag on every order of a shop that assigns couriers at
 * dispatch time. This is item 6's case: the parcel is created from the panel.
 */
ac_assert('and gets no parcel', ac_shipments_for($noCourierId) === [] ?: 'a parcel was created anyway');
ac_assert(
    'and no error either, because nothing failed',
    (ac_order_body($noCourierId)['shipping_provider_error'] ?? null) === null ?: 'an error was recorded'
);

// --- an order with a courier and no destination: every POST /orders order ---

$noDestinationId = ac_new_order($boxId, 'manual');
ac_check(
    'an order with no destination still confirms',
    ac_req('PATCH', "/orders/{$noDestinationId}", ['status' => 'processing']),
    200,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === 'processing' ?: 'the status did not commit'
);

ac_assert('it gets no parcel', ac_shipments_for($noDestinationId) === [] ?: 'a parcel was addressed from nothing');

/*
 * The failure is on the order, in the same response that committed the status —
 * `OrderRepository::applyStatus()` re-reads the order after `save()`, and the
 * hook has already run by then. That is the field the admin panel reads.
 */
ac_check('and says so, in the confirming response', ac_req('GET', "/orders/{$noDestinationId}"), 200, static function (array $d): bool|string {
    $error = $d['data']['shipping_provider_error'] ?? null;

    if (!is_array($error)) {
        return 'no error was recorded';
    }

    if (($error['code'] ?? '') !== 'order_destination_missing') {
        return 'got code ' . ($error['code'] ?? '?');
    }

    if (($error['provider'] ?? '') !== 'manual') {
        return 'the error does not name the courier';
    }

    return is_string($error['at'] ?? null)
        ?: 'no time, so an operator cannot tell this from last week';
});

ac_assert(
    'the failure is in the audit trail too',
    in_array('shipment.create_failed', ac_audit_actions($noDestinationId), true)
        ?: 'saw ' . implode(', ', ac_audit_actions($noDestinationId))
);

// A read-only field: echoing it back is dropped, never stored.
ac_check(
    'the error cannot be stated by a client',
    ac_req('PATCH', "/orders/{$noDestinationId}", [
        'customer_note' => 'echoed the whole body back',
        'shipping_provider_error' => ['code' => 'invented', 'message' => 'a courier that was never asked'],
    ]),
    200,
    static fn (array $d): bool|string => ($d['data']['shipping_provider_error']['code'] ?? '') === 'order_destination_missing'
        ?: 'a client rewrote it'
);

// --- and it retries: the same order, once the destination exists ---

ac_ship_meta($noDestinationId, $wilayaId, $communeId);
ac_check('it leaves processing', ac_req('PATCH', "/orders/{$noDestinationId}", ['status' => 'on-hold']), 200);
ac_check('and is confirmed again', ac_req('PATCH', "/orders/{$noDestinationId}", ['status' => 'processing']), 200);

ac_assert(
    'the retry creates the parcel',
    count(ac_shipments_for($noDestinationId)) === 1 ?: 'got ' . count(ac_shipments_for($noDestinationId))
);
ac_assert(
    'and clears the failure, so no stale reason sits beside a real parcel',
    (ac_order_body($noDestinationId)['shipping_provider_error'] ?? null) === null ?: 'the old error is still there'
);

echo PHP_EOL, '--- and the back office can address an order itself ---', PHP_EOL;

/*
 * **The end-to-end this sub-task exists for**, and the one case above that it
 * turns from the normal answer into an exception.
 *
 * Every assertion up to here that needed a destination got it from
 * `ac_ship_meta()` — a hand-written meta write, standing in for a checkout that
 * cannot be driven from an admin test. That helper's own comment called it "the
 * finding rather than a shortcut": `POST /orders` had no field for a wilaya or a
 * commune, so a back-office order could name a courier, confirm, and never be
 * addressed. `order_destination_missing` was not an edge case on this route, it
 * was the *only* outcome.
 *
 * Below there is no `ac_ship_meta()`. The destination is three keys in the order
 * body, the order is confirmed by the same PATCH an operator's confirm button
 * sends, and a parcel appears — the phone order finally doing what the
 * storefront order has done since item 5.
 */

/** A back-office order, addressed on the wire. No meta is written by hand. */
function ac_addressed_order(int $productId, int $wilayaId, int $communeId, string $provider = 'manual'): int
{
    [, $body] = ac_req('POST', '/orders', [
        'line_items' => [['product_id' => $productId, 'quantity' => 1]],
        'payment_method' => 'cod',
        'status' => 'pending',
        'shipping' => [
            'first_name' => 'Yacine',
            'last_name' => 'Meziane',
            'address_1' => '18 Rue Larbi Ben M’hidi',
            'country' => 'DZ',
            'phone' => '0770112233',
        ],
        'shipping_provider' => $provider,
        'wilaya_id' => $wilayaId,
        'commune_id' => $communeId,
        'delivery_type' => 'desk',
    ]);

    return (int) ($body['data']['id'] ?? 0);
}

$phoneId = ac_addressed_order($boxId, $wilayaId, $communeId);

ac_assert('a phone order was created with a destination on it', $phoneId > 0 ?: 'it was not created');
ac_assert('and has no parcel yet', ac_shipments_for($phoneId) === [] ?: 'a parcel exists before confirmation');

/*
 * The write shape reaching the read shape, which is the rule `OrderPresenter`'s
 * docblock states: anything added to the write side arrives on the read side in
 * the same change. A panel that posts a destination has to be able to read it
 * back, or its edit form opens with empty pickers on an order that is addressed.
 */
ac_check('the order reads its destination back', ac_req('GET', "/orders/{$phoneId}"), 200, static function (array $d) use ($wilayaId, $communeId): bool|string {
    foreach (['wilaya_id', 'commune_id', 'delivery_type'] as $key) {
        if (!array_key_exists($key, $d['data'] ?? [])) {
            return "the read shape is missing {$key}";
        }
    }

    return (($d['data']['wilaya_id'] ?? null) === $wilayaId
        && ($d['data']['commune_id'] ?? null) === $communeId
        && ($d['data']['delivery_type'] ?? null) === 'desk')
        ?: 'got ' . wp_json_encode([
            'wilaya_id' => $d['data']['wilaya_id'] ?? null,
            'commune_id' => $d['data']['commune_id'] ?? null,
            'delivery_type' => $d['data']['delivery_type'] ?? null,
        ]);
});

/*
 * **The same three keys, in the same shape, as the checkout writes.**
 *
 * Read straight out of the meta rather than off the API, because the API is the
 * one thing that cannot detect this failure: two writers could publish an
 * identical `wilaya_id` on the wire and store `"16"` and `16` underneath, and
 * every assertion above would still pass while
 * `ShipmentSubscriber::destinationOf()` — which reads the meta — quietly saw
 * two different orders. `OrderRepository::WILAYA_META` argues that this
 * identity is the whole reason the keys are constants; this is the assertion
 * that holds them to it.
 */
ac_assert(
    'the destination is stored exactly as the checkout stores it',
    (static function () use ($phoneId, $wilayaId, $communeId): bool|string {
        $order = wc_get_order($phoneId);

        foreach ([
            AlgerianCommerce\Orders\OrderRepository::WILAYA_META => $wilayaId,
            AlgerianCommerce\Orders\OrderRepository::COMMUNE_META => $communeId,
        ] as $key => $expected) {
            $stored = $order->get_meta($key, true);

            // Loose value, strict type: the checkout casts to (int) and so does
            // applyProps(), and a string that happens to render the same digits
            // is the drift this is here to catch.
            if ((int) $stored !== $expected) {
                return "{$key} is " . var_export($stored, true);
            }
        }

        $type = $order->get_meta(AlgerianCommerce\Orders\OrderRepository::DELIVERY_TYPE_META, true);

        return ($type === 'desk') ?: 'the delivery type is ' . var_export($type, true);
    })()
);

ac_check(
    'confirming the phone order commits the status',
    ac_req('PATCH', "/orders/{$phoneId}", ['status' => 'processing']),
    200,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === 'processing'
        ?: 'the order is ' . ($d['data']['status'] ?? '?')
);

$phoneParcels = ac_shipments_for($phoneId);

ac_assert(
    'and the confirmation created its parcel — no manual step',
    count($phoneParcels) === 1 ?: 'got ' . count($phoneParcels) . ' parcels'
);
ac_assert(
    'the parcel names the courier the order body named',
    ($phoneParcels[0]['provider'] ?? '') === 'manual' ?: 'got ' . ($phoneParcels[0]['provider'] ?? '?')
);

/*
 * The code this whole sub-task was aimed at, asserted by its absence.
 * `ShipmentFailure::noDestination()` is what a back-office order used to get
 * every time, and the field it is published under is the one the panel renders.
 */
ac_check('and records no destination failure', ac_req('GET', "/orders/{$phoneId}"), 200, static function (array $d): bool|string {
    if (!array_key_exists('shipping_provider_error', $d['data'] ?? [])) {
        return 'the field is missing from the order shape entirely';
    }

    return $d['data']['shipping_provider_error'] === null
        ?: 'got ' . wp_json_encode($d['data']['shipping_provider_error']);
});

/*
 * The journey survives the round trip into the parcel.
 *
 * `desk` was stated on the order body and never mentioned again;
 * `destinationOf()` read it off the meta and handed it to
 * `ManualProvider::createShipment()`, which writes it into the shipment's
 * metadata. That chain is the reason `delivery_type` is on the order at all
 * rather than being defaulted at confirmation — an order for a stop-desk
 * collection that quietly became a home delivery would be a promise nobody made.
 */
ac_assert(
    'the stop-desk collection reached the courier',
    (static function () use ($phoneParcels): bool|string {
        $metadata = json_decode((string) ($phoneParcels[0]['metadata'] ?? ''), true);

        return (is_array($metadata) && ($metadata['delivery_type'] ?? '') === 'desk')
            ?: 'got ' . var_export($metadata['delivery_type'] ?? null, true);
    })()
);

/*
 * And the correction the retry needs, on a `processing` order.
 *
 * `guardDestinationResolves()` has no `is_editable` gate, and this is the
 * assertion that says so deliberately rather than by omission — the same shape
 * as the `shipping_provider` pair in `tests/Api/orders.php`. An order refused by
 * a courier for a bad commune is confirmed, and confirmed is not editable; a
 * gate would freeze the destination at the exact moment it starts to matter and
 * leave `ShipmentSubscriber`'s retry with nothing to retry against.
 *
 * The fee on the same order is refused in the same status, which is what makes
 * this a decision rather than an oversight.
 */
ac_check('the destination can still be corrected on a committed order', ac_req('PATCH', "/orders/{$phoneId}", [
    'commune_id' => $communeId,
]), 200, static fn (array $d): bool|string => ($d['data']['commune_id'] ?? null) === $communeId
    ?: 'got ' . var_export($d['data']['commune_id'] ?? null, true));

ac_check('while the fee on that same order is not', ac_req('PATCH', "/orders/{$phoneId}", [
    'shipping_amount' => '700',
]), 409);

echo PHP_EOL, "=== the manual route still works, which is item 6 ===", PHP_EOL;

/*
 * The order the automatic step refused. `ShippingService::create()`'s docblock
 * argues in full why this route stays; this is the case item 6 names first, and
 * the one the automatic path structurally cannot reach — the order carries no
 * destination, and this route takes one in its body.
 */
$fallbackId = ac_new_order($boxId, 'manual');
ac_check('an order the automatic step refuses', ac_req('PATCH', "/orders/{$fallbackId}", ['status' => 'processing']), 200);
ac_assert('has no parcel', ac_shipments_for($fallbackId) === [] ?: 'it was created automatically');

$manualParcel = ac_check(
    'is still shippable by hand',
    ac_req('POST', "/orders/{$fallbackId}/shipments", [
        'wilaya_id' => $wilayaId,
        'commune_id' => $communeId,
        // Neither of these has anywhere to live on an order, which is the last
        // of the five reasons this route stays.
        'recipient' => 'The neighbour, flat 3',
        'note' => 'Ring twice',
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['is_live'] ?? false) === true ?: 'the parcel is not live'
);

ac_assert(
    'and the fallback parcel is recorded like any other',
    count(ac_shipments_for($fallbackId)) === 1 ?: 'got ' . count(ac_shipments_for($fallbackId))
);
ac_assert(
    'the hand-made parcel now blocks an automatic one',
    ($manualParcel['data']['status'] ?? '') === 'created' ?: 'got ' . ($manualParcel['data']['status'] ?? '?')
);

echo PHP_EOL, "=== never throws: every way a courier can fail ===", PHP_EOL;

/*
 * A stand-in courier, because no real one can be switched on here — the whole
 * of `BLOCKED.md`. `manual` cannot fail, so the property the item asks for
 * ("a courier outage cannot block the order book") has no branch to exercise
 * without one.
 *
 * The double replaces the *subscriber*, not the provider: the real one is
 * unhooked, a second one built on a `ProviderRegistry` holding only the double
 * is hooked in its place, and the confirmations below go through the same
 * `PATCH /orders/{id}` an operator uses. So what is measured is the real path —
 * WooCommerce's transition, the hook, the service, the claim, the order write —
 * with one courier swapped for one that can be told how to fail.
 *
 * **Measured in-process via `rest_do_request()`**, never against a live courier
 * API: `createShipment()` against a real account creates a real parcel a
 * courier may try to collect.
 */
final class AcScriptedCourier implements \AlgerianCommerce\Shipping\ShippingProviderInterface
{
    public int $createCalls = 0;

    /** @var \Throwable|\AlgerianCommerce\Shipping\ShipmentResult|null */
    private $next = null;

    public function fails(\Throwable $failure): void
    {
        $this->next = $failure;
    }

    public function succeeds(): void
    {
        $this->next = null;
    }

    public function name(): string
    {
        return 'acship';
    }

    public function label(): string
    {
        return 'Scripted courier';
    }

    public function createShipment(\AlgerianCommerce\Shipping\ShipmentRequest $request): \AlgerianCommerce\Shipping\ShipmentResult
    {
        $this->createCalls++;

        if ($this->next instanceof \Throwable) {
            throw $this->next;
        }

        return new \AlgerianCommerce\Shipping\ShipmentResult(
            'SCRIPT-' . $request->reference,
            'TRK-' . $request->reference,
            \AlgerianCommerce\Shipping\ShipmentStatus::CREATED,
            ['label' => 'https://courier.test/labels/' . $request->reference]
        );
    }

    public function cancelShipment(string $providerShipmentId): bool
    {
        return true;
    }

    public function getShipmentStatus(string $providerShipmentId): \AlgerianCommerce\Shipping\StatusReport
    {
        return new \AlgerianCommerce\Shipping\StatusReport(
            \AlgerianCommerce\Shipping\ShipmentStatus::IN_TRANSIT,
            'RAW'
        );
    }

    public function getShippingRates(\AlgerianCommerce\Shipping\Destination $destination): array
    {
        return [];
    }

    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): \AlgerianCommerce\Shipping\ShipmentWebhookResult
    {
        throw new \AlgerianCommerce\API\ApiException('webhook_unsupported', 'No webhooks.', 400);
    }
}

$acPlugin = AlgerianCommerce\Core\Plugin::instance();
$acCourier = new AcScriptedCourier();

$acReal = $acPlugin->shipmentSubscriber();
$acRemoved = remove_action('woocommerce_order_status_processing', [$acReal, 'onOrderConfirmed'], 10);
ac_assert('the real subscriber was hooked, and is now unhooked', $acRemoved === true ?: 'it was never registered');

$acDouble = new AlgerianCommerce\Shipping\ShipmentSubscriber(
    new AlgerianCommerce\Shipping\ShippingService(
        $acPlugin->shipmentRepository(),
        new AlgerianCommerce\Shipping\ProviderRegistry([$acCourier]),
        $acPlugin->orderRepository(),
        $acPlugin->geoRepository(),
        $acPlugin->auditLogger(),
        $acPlugin->shippingRuleRepository(),
        $acPlugin->webhookEventRepository(),
        $acPlugin->logger()
    ),
    $acPlugin->orderRepository(),
    $acPlugin->shipmentRepository(),
    $acPlugin->auditLogger(),
    $acPlugin->logger()
);
$acDouble->register();

/**
 * One order, confirmed, against whatever the courier has been told to do.
 *
 * Returns the order body as the API publishes it, which is where every
 * assertion below looks — the point being that an operator finds the reason in
 * the same place every time, whatever the courier did.
 */
function ac_confirm_against(int $productId, int $wilayaId, int $communeId, string $courier = 'acship'): array
{
    $orderId = ac_new_order($productId, 'manual');
    ac_ship_meta($orderId, $wilayaId, $communeId);
    ac_name_courier($orderId, $courier);

    [$status] = ac_req('PATCH', "/orders/{$orderId}", ['status' => 'processing']);

    return [$orderId, $status, ac_order_body($orderId)];
}

// 1. The courier refuses the address — EL's "bad commune", and the case the
//    retry exists for.
$acCourier->fails(new AlgerianCommerce\API\ApiException(
    'acship_parcel_refused',
    'The courier would not create this parcel.',
    400,
    ['provider' => 'acship', 'provider_message' => 'commune introuvable: Ouled Fayet']
));

[$refusedId, $refusedStatus, $refusedBody] = ac_confirm_against($boxId, $wilayaId, $communeId);

ac_assert('a refusing courier does not fail the request', $refusedStatus === 200 ?: 'got ' . $refusedStatus);
ac_assert(
    'and the status change still commits',
    ($refusedBody['status'] ?? '') === 'processing' ?: 'the order is ' . ($refusedBody['status'] ?? '?')
);
ac_assert('and no parcel was written', ac_shipments_for($refusedId) === [] ?: 'a row exists for a parcel that does not');
ac_assert(
    'the refusal keeps the courier code',
    ($refusedBody['shipping_provider_error']['code'] ?? '') === 'acship_parcel_refused'
        ?: 'got ' . ($refusedBody['shipping_provider_error']['code'] ?? '?')
);
// The half that tells the operator which field to fix. Our own message names
// nothing; theirs names the commune.
ac_assert(
    "and the courier's own sentence",
    ($refusedBody['shipping_provider_error']['provider_message'] ?? '') === 'commune introuvable: Ouled Fayet'
        ?: 'got ' . wp_json_encode($refusedBody['shipping_provider_error'] ?? null)
);

// 2. …and it retries. The operator fixes the address; the courier stops
//    refusing; the next confirmation creates the parcel. This is the whole
//    point of guarding on "no live shipment" rather than on "never tried".
$acCourier->succeeds();
ac_req('PATCH', "/orders/{$refusedId}", ['status' => 'on-hold']);
ac_req('PATCH', "/orders/{$refusedId}", ['status' => 'processing']);

ac_assert(
    'the next confirmation creates the parcel',
    count(ac_shipments_for($refusedId)) === 1 ?: 'got ' . count(ac_shipments_for($refusedId))
);
ac_assert(
    'and the failure is cleared',
    (ac_order_body($refusedId)['shipping_provider_error'] ?? null) === null ?: 'the old refusal survived'
);

// 3. The courier is unreachable — a timeout, in the only form this system ever
//    sees one: the ApiException an HTTP client raises when the socket gives up.
$acCourier->fails(new AlgerianCommerce\API\ApiException(
    'provider_unreachable',
    'The courier could not be reached.',
    502,
    ['provider' => 'acship']
));

[$downId, $downStatus, $downBody] = ac_confirm_against($boxId, $wilayaId, $communeId);

ac_assert('an unreachable courier does not fail the request', $downStatus === 200 ?: 'got ' . $downStatus);
ac_assert('and the order is still confirmed', ($downBody['status'] ?? '') === 'processing' ?: 'the status rolled back');
ac_assert(
    'the outage is named as an outage',
    ($downBody['shipping_provider_error']['code'] ?? '') === 'provider_unreachable'
        ?: 'got ' . ($downBody['shipping_provider_error']['code'] ?? '?')
);
// Present and null, not absent and not `""` — a client tests one thing to
// decide whether to render the second line.
ac_assert(
    'with no courier sentence to show, and null rather than an empty string',
    (array_key_exists('provider_message', $downBody['shipping_provider_error'] ?? [])
        && $downBody['shipping_provider_error']['provider_message'] === null)
        ?: 'got ' . wp_json_encode($downBody['shipping_provider_error'] ?? null)
);

/*
 * …and the rest of the transition survived it. `WC_Order::status_transition()`
 * wraps its `do_action` in one `try`, so a hook that throws takes every hook
 * after it down too — the status-transition note,
 * `woocommerce_order_status_changed`, and whatever a future subscriber
 * registers. This is the assertion that the catch is doing that job and not
 * merely making the request return 200.
 */
$GLOBALS['ac_transitions'] = 0;
add_action('woocommerce_order_status_changed', static function (): void {
    $GLOBALS['ac_transitions']++;
});

[$stillId] = ac_confirm_against($boxId, $wilayaId, $communeId);

ac_assert(
    'a failing courier does not silence the other subscribers',
    $GLOBALS['ac_transitions'] > 0 ?: 'woocommerce_order_status_changed never fired'
);
ac_assert(
    'and WooCommerce logged no transition error against the order',
    !str_contains(
        implode(' ', array_column(wc_get_order_notes(['order_id' => $stillId, 'limit' => 20]), 'content')),
        'Error during status transition'
    ) ?: 'the exception escaped into an order note'
);

// 4. The courier returns something malformed. In this system that surfaces as
//    ShipmentResult's constructor refusing an unmapped status — an adapter bug,
//    a TypeError, a PDOException: all the same shape from here.
$acCourier->fails(new RuntimeException('SQLSTATE[28000] password for wp_prod is bad'));

[$brokenId, $brokenStatus, $brokenBody] = ac_confirm_against($boxId, $wilayaId, $communeId);

ac_assert('an exception nobody catalogued does not fail the request', $brokenStatus === 200 ?: 'got ' . $brokenStatus);
ac_assert('and the order is still confirmed', ($brokenBody['status'] ?? '') === 'processing' ?: 'the status rolled back');
ac_assert(
    'it is reported as our own code, not the provider inventing one',
    ($brokenBody['shipping_provider_error']['code'] ?? '') === 'shipment_create_failed'
        ?: 'got ' . ($brokenBody['shipping_provider_error']['code'] ?? '?')
);
// docs/SECURITY.md: a raw exception carries paths, SQL and sometimes a
// credential, and this value is published to a client.
ac_assert(
    'and nothing it said is repeated to the client',
    !str_contains(wp_json_encode($brokenBody['shipping_provider_error']), 'password')
        ?: 'the exception message reached the response'
);

/*
 * 4b. A PHP `Error` rather than an `Exception` — the gap WooCommerce's own
 *     `catch ( Exception $e )` does not cover. An adapter handed a null it did
 *     not expect raises a `TypeError`, which is a `Throwable` and not an
 *     `Exception`, so without this class's `catch (Throwable)` it would sail out
 *     of `save()` and turn the confirm into a 500 on an order that is already
 *     confirmed. This is the single case that most justifies catching at all.
 */
$acCourier->fails(new TypeError('Argument #1 ($destination) must be of type Destination, null given'));

[$fatalId, $fatalStatus, $fatalBody] = ac_confirm_against($boxId, $wilayaId, $communeId);

ac_assert('a PHP Error does not fail the request either', $fatalStatus === 200 ?: 'got ' . $fatalStatus);
ac_assert('and the order is still confirmed', ($fatalBody['status'] ?? '') === 'processing' ?: 'the status rolled back');
ac_assert(
    'and it is reported like any other unexpected failure',
    ($fatalBody['shipping_provider_error']['code'] ?? '') === 'shipment_create_failed'
        ?: 'got ' . ($fatalBody['shipping_provider_error']['code'] ?? '?')
);
ac_assert('no parcel came of it', ac_shipments_for($fatalId) === [] ?: 'a parcel exists');

// 5. The courier the order names is not registered any more — the shop that
//    switched ENABLE_YALIDINE off, with Yalidine orders still in the book.
$acCourier->succeeds();
$acCallsBefore = $acCourier->createCalls;
[$goneId, $goneStatus, $goneBody] = ac_confirm_against($boxId, $wilayaId, $communeId, 'yalidine');

ac_assert('a de-registered courier does not fail the request', $goneStatus === 200 ?: 'got ' . $goneStatus);
ac_assert('and the order is still confirmed', ($goneBody['status'] ?? '') === 'processing' ?: 'the status rolled back');
ac_assert('no parcel is invented for it', ac_shipments_for($goneId) === [] ?: 'a parcel was created by nobody');
ac_assert(
    'and the order says which courier it could not find',
    ($goneBody['shipping_provider_error']['provider'] ?? '') === 'yalidine'
        ?: 'got ' . wp_json_encode($goneBody['shipping_provider_error'] ?? null)
);
// `ProviderRegistry::get()` refuses the name before anything is asked, so the
// one courier this shop *does* have is not handed a parcel meant for another.
ac_assert(
    'and the courier it does have was never asked',
    $acCourier->createCalls === $acCallsBefore ?: 'it was called ' . ($acCourier->createCalls - $acCallsBefore) . ' time(s)'
);

// 6. The ordinary success, through the double, so the whole path is measured
//    with a courier that has an identifier of its own — which `manual` does not.
[$okId, $okStatus, $okBody] = ac_confirm_against($boxId, $wilayaId, $communeId);
$okRows = ac_shipments_for($okId);

ac_assert('a working courier confirms cleanly', $okStatus === 200 ?: 'got ' . $okStatus);
ac_assert('and the parcel is recorded', count($okRows) === 1 ?: 'got ' . count($okRows));
ac_assert(
    "with the courier's own tracking number",
    ($okRows[0]['tracking_number'] ?? '') === "TRK-{$okId}-1" ?: 'got ' . ($okRows[0]['tracking_number'] ?? '?')
);
/*
 * The label is in the parcel's metadata and stays there. Item 5 says to store
 * it "on the order" and it deliberately is not: `ShipmentResult`'s docblock
 * forbids core code reading a key out of a provider's metadata, and
 * `GET /orders/{id}/shipments` already publishes the whole row. This is what
 * the admin panel reads to show the label.
 */
ac_check('the label reaches the panel through the parcel', ac_req('GET', "/orders/{$okId}/shipments"), 200, static function (array $d) use ($okId): bool|string {
    $metadata = $d['data'][0]['metadata'] ?? [];

    return ($metadata['label'] ?? '') === "https://courier.test/labels/{$okId}-1"
        ?: 'got ' . wp_json_encode($metadata);
});
ac_assert('and no error is left on the order', ($okBody['shipping_provider_error'] ?? null) === null ?: 'an error survived');

// Put the shop back the way it was, so anything running after this file — or
// after this section — sees the real wiring.
remove_action('woocommerce_order_status_processing', [$acDouble, 'onOrderConfirmed'], 10);
add_action('woocommerce_order_status_processing', [$acReal, 'onOrderConfirmed'], 10, 2);

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
