<?php
/**
 * Order tracking — roadmap §84, §86's definition of done, §65's shapes.
 *
 * **§84 is a small section whose entire risk is authorization**, so this suite
 * is almost all refusals — and every one of them carries the positive control
 * §65 insists on, because a refusal and a broken route look identical from
 * outside.
 *
 * Four properties §84 names, in the order it names them:
 *
 *   1. customer A is refused customer B's tracking **and** served their own
 *   2. a guest order is reachable with its token and 404 without it
 *   3. the token is not derivable from the order id
 *   4. the refused-fields list, asserted **field by field**
 *
 * The fourth is the one written twice on purpose. Keys are asserted here and in
 * tests/Unit/TrackingPresenterTest; **values** are asserted here as well, over a
 * real order carrying a real address, a real phone and a Yalidine `label` URL in
 * its parcel's metadata. A presenter that renamed a field would pass the key
 * half and fail the value half, which is the failure this pair exists for.
 *
 * The rate limit is deliberately absent: `scripts/test-api.sh` owns it, because
 * only that stage sees a client IP, and this stage runs with
 * `AC_RATE_LIMIT_DISABLED=1` so one suite's counters cannot fail the next one's.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/tracking.php
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

use AlgerianCommerce\Core\Plugin;
use AlgerianCommerce\Settings\SettingsRepository;
use AlgerianCommerce\Shipping\Shipment;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Tracking\TrackingLink;
use AlgerianCommerce\Tracking\TrackingPresenter;
use AlgerianCommerce\Tracking\TrackingToken;

/*
 * WooCommerce's own mailer sends **synchronously** inside
 * `woocommerce_order_status_changed`, so a suite that creates half a dozen
 * orders attempts half a dozen sends. Here that is only `sendmail: can't
 * connect` noise; on a machine with an MTA it would mail the shop's real admin
 * address about fictional orders. §67 found exactly this and short-circuited it
 * the same way — and asserts at the end that the filter was removed again,
 * because one left behind silences every later suite in the same process.
 */
$silenceMail = static fn (): bool => true;
add_filter('pre_wp_mail', $silenceMail, 99);

$plugin = Plugin::instance();
$links = $plugin->trackingLink();
$shipments = $plugin->shipmentRepository();
$audit = $plugin->auditLogger();

// ------------------------------------------------------------------ fixtures --
$EMAIL_A = 'ac-track-a@example.test';
$EMAIL_B = 'ac-track-b@example.test';
$PASS = 'CorrectHorseBatteryTrack';

foreach ([$EMAIL_A, $EMAIL_B] as $email) {
    $existing = get_user_by('email', $email);

    if ($existing) {
        wp_delete_user($existing->ID);
    }
}

$settingsBefore = get_option(SettingsRepository::OPTION, []);

$registerA = ac_req('POST', '/account/register', [
    'email' => $EMAIL_A, 'password' => $PASS, 'first_name' => 'Amina', 'last_name' => 'B',
]);
$registerB = ac_req('POST', '/account/register', [
    'email' => $EMAIL_B, 'password' => $PASS, 'first_name' => 'Yacine', 'last_name' => 'K',
]);

$idA = (int) ($registerA[1]['data']['customer']['id'] ?? 0);
$idB = (int) ($registerB[1]['data']['customer']['id'] ?? 0);
$tokenA = (string) ($registerA[1]['data']['token'] ?? '');
$tokenB = (string) ($registerB[1]['data']['token'] ?? '');

ac_assert('two shoppers exist', $idA > 0 && $idB > 0 ?: 'registration failed');

/**
 * An order with an address worth protecting, a parcel, and a courier label URL
 * in the parcel's metadata. Every value here is one §84 forbids a tracking page
 * to publish, which is what makes the value assertions below discriminating.
 */
function ac_track_order(int $customerId, string $status = 'processing'): WC_Order
{
    $order = wc_create_order(['status' => $status, 'customer_id' => $customerId]);
    $order->set_address([
        'first_name' => 'Amina', 'last_name' => 'Belkacem',
        'address_1' => '12 rue des Freres Bouadou', 'city' => 'Ouled Fayet',
        'state' => 'Alger', 'country' => 'DZ',
        'phone' => '0551020304', 'email' => 'ac-track-secret@example.test',
    ], 'billing');
    $order->set_total('26350.00');
    // §59b's checkout writes this, and it is where the wilaya on a tracking page
    // comes from — never the free-text address above.
    $order->update_meta_data('_ac_wilaya_id', 16);
    $order->update_meta_data('_ac_commune_id', 1);
    $order->save();

    return $order;
}

function ac_track_parcel(
    AlgerianCommerce\Shipping\ShipmentRepository $shipments,
    AlgerianCommerce\Audit\AuditLogger $audit,
    int $orderId,
    string $status = ShipmentStatus::IN_TRANSIT,
    string $when = ''
): int {
    $when = $when !== '' ? $when : gmdate('Y-m-d H:i:s');

    $id = $shipments->insert(new Shipment(
        $orderId,
        'yalidine',
        'yal-16-TRACK' . $orderId,
        'yal-16-TRACK' . $orderId,
        $status,
        [
            // The §55 credential. It belongs in the row; it must never be served.
            'label' => 'https://api.yalidine.app/labels/X?token=secret-bearer-value',
            'to_commune_name' => 'Ouled Fayet',
            'cod_amount' => '26350.00',
        ],
        $when,
        $when
    ));

    $audit->record('shipment.created', 'order', $orderId, [
        'shipment_id' => $id, 'provider' => 'yalidine', 'status' => 'created',
        'tracking_number' => 'yal-16-TRACK' . $orderId,
    ]);
    $audit->record('shipment.status_changed', 'order', $orderId, [
        'shipment_id' => $id, 'provider' => 'yalidine', 'from' => 'created', 'to' => $status,
        'source' => 'provider',
    ]);

    return (int) $id;
}

$orderA = ac_track_order($idA);
$orderB = ac_track_order($idB);
$guest = ac_track_order(0);

$parcelA = ac_track_parcel($shipments, $audit, $orderA->get_id());
ac_track_parcel($shipments, $audit, $guest->get_id());

$linkA = $links->tokenFor($orderA);
$linkB = $links->tokenFor($orderB);
$linkGuest = $links->tokenFor($guest);

echo PHP_EOL, "── the token ──", PHP_EOL;

ac_assert('a token was minted', $linkA !== '' ?: 'no token');
ac_assert(
    'it names the order it is for',
    TrackingToken::orderIdFrom($linkA) === $orderA->get_id()
        ?: 'parsed ' . TrackingToken::orderIdFrom($linkA)
);

/*
 * §84's first requirement over a real install rather than in a unit test: the
 * MAC must not follow the order id. The unit suite proves it for twenty
 * consecutive ids; this proves the *wiring* — that the nonce is per order and
 * really is stored, so two real orders minted a second apart share nothing.
 */
$macA = explode(TrackingToken::SEPARATOR, $linkA)[1];
$macB = explode(TrackingToken::SEPARATOR, $linkB)[1];

ac_assert('two orders get unrelated MACs', $macA !== $macB ?: 'the same MAC twice');
ac_assert(
    'and the MAC is not the order id in disguise',
    !str_contains($macA, (string) $orderA->get_id()) || strlen($macA) === 32
        ?: 'the MAC contains the order id'
);
ac_assert(
    'the nonce is stored on the order, not derived',
    strlen((string) wc_get_order($orderA->get_id())->get_meta(TrackingLink::NONCE_META)) === TrackingToken::NONCE_BYTES * 2
        ?: 'no nonce meta'
);
ac_assert(
    'minting twice yields the same link',
    $links->tokenFor(wc_get_order($orderA->get_id())) === $linkA ?: 'the token changed between calls'
);

echo PHP_EOL, "── the public route ──", PHP_EOL;

$tracked = ac_check(
    'a valid token is served',
    ac_req('GET', '/orders/track', null, ['token' => $linkA]),
    200,
    static fn (array $d): bool|string => (string) ($d['data']['order_number'] ?? '') !== ''
        ? true : 'no order number came back'
);

ac_check('no token at all is 404', ac_req('GET', '/orders/track'), 404);
ac_check('an empty token is 404', ac_req('GET', '/orders/track', null, ['token' => '']), 404);
ac_check(
    'a token for an order that has none is 404',
    ac_req('GET', '/orders/track', null, ['token' => $orderB->get_id() . '.' . str_repeat('a', 32)]),
    404
);

/*
 * THE PAIR, in §59c's shape. Neither half means anything alone: a route that
 * refused everything would pass the first and fail the second.
 */
$tamperedMac = substr($macA, 0, 31) . ($macA[31] === 'a' ? 'b' : 'a');

ac_check(
    "A's token with one character changed is 404",
    ac_req('GET', '/orders/track', null, ['token' => $orderA->get_id() . '.' . $tamperedMac]),
    404
);
ac_check(
    "...and A's real token still works",
    ac_req('GET', '/orders/track', null, ['token' => $linkA]),
    200
);

/*
 * The cross-order attack the token exists to stop: B's MAC pointed at A's order.
 * A construction that HMAC'd only the nonce, or only the secret, would answer
 * 200 here.
 */
ac_check(
    "B's MAC against A's order id is 404",
    ac_req('GET', '/orders/track', null, ['token' => $orderA->get_id() . '.' . $macB]),
    404
);
ac_check(
    "...and B's own token is served",
    ac_req('GET', '/orders/track', null, ['token' => $linkB]),
    200
);

// Walking the order ids with the same MAC — the enumeration §84 rejects.
$walked = 0;

for ($id = $orderA->get_id() - 3; $id <= $orderA->get_id() + 3; $id++) {
    if ($id === $orderA->get_id()) {
        continue;
    }

    [$status] = ac_req('GET', '/orders/track', null, ['token' => $id . '.' . $macA]);

    if ($status === 200) {
        $walked++;
    }
}

ac_assert('the order ids around it cannot be walked', $walked === 0 ?: "{$walked} neighbours answered 200");

// §84's reason for existing: a guest order has no owner and no session, and
// this is the only door it has.
ac_check(
    'a GUEST order is reachable with its token',
    ac_req('GET', '/orders/track', null, ['token' => $linkGuest]),
    200,
    static fn (array $d): bool|string => ($d['data']['shipment']['tracking_number'] ?? '') !== ''
        ? true : 'no parcel came back'
);
ac_check(
    '...and is reachable by nobody without it',
    ac_req('GET', "/account/orders/{$guest->get_id()}", null, ['customer_token' => $tokenA]),
    403
);

echo PHP_EOL, "── the disclosure list, field by field ──", PHP_EOL;

$view = $tracked['data'] ?? [];

foreach (TrackingPresenter::PUBLIC_FIELDS as $field) {
    ac_assert(
        "allowed: {$field}" . str_pad('', 44 - strlen($field)),
        array_key_exists($field, $view) ?: "{$field} is missing"
    );
}

ac_assert(
    'and nothing else at the top level',
    array_keys($view) === TrackingPresenter::PUBLIC_FIELDS
        ?: 'keys are ' . implode(',', array_keys($view))
);

foreach (TrackingPresenter::REFUSED_FIELDS as $field) {
    ac_assert(
        "refused: {$field}" . str_pad('', 44 - strlen($field)),
        !array_key_exists($field, $view) && !array_key_exists($field, (array) ($view['shipment'] ?? []))
            ?: "{$field} is published"
    );
}

/*
 * The value half, which is the one that survives a rename. Every string below
 * is on the order or in the parcel's metadata, so each is genuinely reachable
 * from where the presenter is standing.
 */
$encoded = (string) wp_json_encode($view);

foreach ([
    'secret-bearer-value' => 'the courier label URL',
    'api.yalidine.app/labels' => 'the label host',
    '12 rue des Freres Bouadou' => 'the street address',
    'Ouled Fayet' => 'the commune',
    '0551020304' => 'the phone number',
    'ac-track-secret@example.test' => 'the email address',
    '26350.00' => 'the order total',
    'Belkacem' => 'the customer name',
] as $needle => $what) {
    ac_assert(
        "no trace of {$what}" . str_pad('', 41 - strlen($what)),
        !str_contains($encoded, $needle) ?: "{$what} reached the tracking page"
    );
}

echo PHP_EOL, "── what it does say ──", PHP_EOL;

ac_assert(
    'the destination is the wilaya and stops there',
    ($view['destination']['wilaya_id'] ?? 0) === 16
        && array_keys((array) $view['destination']) === ['wilaya_id', 'wilaya_code', 'wilaya', 'wilaya_ar']
        ?: 'destination is ' . wp_json_encode($view['destination'] ?? null)
);
ac_assert(
    'the courier and tracking number are there',
    ($view['shipment']['courier'] ?? '') === 'yalidine'
        && ($view['shipment']['tracking_number'] ?? '') !== ''
        ?: 'shipment block is ' . wp_json_encode($view['shipment'] ?? null)
);
ac_assert(
    'the history reads oldest first',
    array_column((array) ($view['history'] ?? []), 'status') === ['created', ShipmentStatus::IN_TRANSIT]
        ?: 'history is ' . wp_json_encode($view['history'] ?? null)
);

/*
 * **A parcel's status never moves the order** — true since §55, and a tracking
 * view does not get to be the exception. The two sit side by side and nothing
 * merges them.
 */
ac_assert(
    'the parcel is in transit while the order is still processing',
    ($view['order_status'] ?? '') === 'processing'
        && ($view['shipment']['status'] ?? '') === ShipmentStatus::IN_TRANSIT
        ?: 'order ' . ($view['order_status'] ?? '?') . ', parcel ' . ($view['shipment']['status'] ?? '?')
);
ac_assert(
    'and the order in the database did not move',
    wc_get_order($orderA->get_id())->get_status() === 'processing' ?: 'the order status changed'
);

echo PHP_EOL, "── an order with no parcel ──", PHP_EOL;

$unshipped = ac_track_order($idA, 'pending');
$linkUnshipped = $links->tokenFor($unshipped);

ac_check(
    'an unshipped order tracks and says so',
    ac_req('GET', '/orders/track', null, ['token' => $linkUnshipped]),
    200,
    static fn (array $d): bool|string => array_key_exists('shipment', $d['data'] ?? [])
        && $d['data']['shipment'] === null
        && ($d['data']['history'] ?? null) === []
        ? true : 'shipment was ' . wp_json_encode($d['data']['shipment'] ?? 'missing')
);

echo PHP_EOL, "── expiry and revocation ──", PHP_EOL;

/*
 * §84: "expiring some fixed period after the shipment reaches a terminal
 * status. A tracking link in an email lives forever otherwise."
 */
$old = ac_track_order($idA);
$oldParcel = ac_track_parcel(
    $shipments,
    $audit,
    $old->get_id(),
    ShipmentStatus::DELIVERED,
    gmdate('Y-m-d H:i:s', time() - (AlgerianCommerce\Tracking\TrackingService::DAYS_AFTER_TERMINAL + 5) * DAY_IN_SECONDS)
);
$linkOld = $links->tokenFor($old);

ac_check(
    'a link to a long-delivered parcel is 410, not 404',
    ac_req('GET', '/orders/track', null, ['token' => $linkOld]),
    410,
    static fn (array $d): bool|string => ($d['error']['code'] ?? '') === 'tracking_link_expired'
        ? true : 'code was ' . ($d['error']['code'] ?? '?')
);

// The control: a parcel delivered *yesterday* is inside the window.
$recent = ac_track_order($idA);
ac_track_parcel(
    $shipments,
    $audit,
    $recent->get_id(),
    ShipmentStatus::DELIVERED,
    gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
);

ac_check(
    '...while one delivered yesterday still opens',
    ac_req('GET', '/orders/track', null, ['token' => $links->tokenFor($recent)]),
    200
);

// Revocation — the other half of §84's "revocable, or at least expiring".
$links->revoke(wc_get_order($orderB->get_id()));

ac_check(
    'a revoked link stops working',
    ac_req('GET', '/orders/track', null, ['token' => $linkB]),
    404
);

$reissued = $links->tokenFor(wc_get_order($orderB->get_id()));

ac_assert('a fresh link differs from the revoked one', $reissued !== $linkB ?: 'the same token came back');
ac_check(
    '...and the fresh one works',
    ac_req('GET', '/orders/track', null, ['token' => $reissued]),
    200
);

echo PHP_EOL, "── the session door ──", PHP_EOL;

/*
 * §84's first door, and it inherits §59c's IDOR test unchanged: the `shipment`
 * block is added *after* `Permissions::assertOwnsOr()`, so if it were added
 * before, this pair would show it.
 */
$ownOrder = ac_check(
    "A is served their own order, parcel included",
    ac_req('GET', "/account/orders/{$orderA->get_id()}", null, ['customer_token' => $tokenA]),
    200,
    static fn (array $d): bool|string => ($d['data']['shipment']['tracking_number'] ?? '') !== ''
        ? true : 'no shipment block: ' . wp_json_encode(array_keys($d['data'] ?? []))
);

ac_check(
    "B is refused A's order and learns no parcel",
    ac_req('GET', "/account/orders/{$orderA->get_id()}", null, ['customer_token' => $tokenB]),
    403,
    static fn (array $d): bool|string => !str_contains((string) wp_json_encode($d), 'yal-16-TRACK')
        ? true : 'a tracking number leaked into a 403'
);

ac_assert(
    'the owner block carries the history',
    is_array($ownOrder['data']['shipment']['history'] ?? null)
        && count($ownOrder['data']['shipment']['history']) === 2
        ?: 'history is ' . wp_json_encode($ownOrder['data']['shipment']['history'] ?? null)
);

/*
 * §84: "The courier's label URL must never appear, under any circumstance." No
 * exception for the customer whose address is on it — it is a bearer credential,
 * and a storefront rendering it would put it in browser history and referrers.
 */
ac_assert(
    'and no label URL, even for the order\'s own owner',
    !str_contains((string) wp_json_encode($ownOrder['data']['shipment']), 'secret-bearer-value')
        ?: 'the label URL reached the owner view'
);

ac_check(
    'an order with no parcel reports shipment: null',
    ac_req('GET', "/account/orders/{$unshipped->get_id()}", null, ['customer_token' => $tokenA]),
    200,
    static fn (array $d): bool|string => array_key_exists('shipment', $d['data'] ?? [])
        && $d['data']['shipment'] === null
        ? true : 'shipment was ' . wp_json_encode($d['data']['shipment'] ?? 'missing')
);

echo PHP_EOL, "── the link in a notification ──", PHP_EOL;

/*
 * §84's precondition, and it is `PasswordResetService`'s: the link needs the
 * storefront URL, which this backend cannot derive. Unset means no link — never
 * a URL on the admin domain, which sends a customer to a login screen they have
 * no account for.
 */
$cleared = get_option(SettingsRepository::OPTION, []);
$cleared['store']['storefront_url'] = '';
update_option(SettingsRepository::OPTION, $cleared, false);

ac_assert(
    'with no storefront URL there is no link at all',
    $links->urlFor(wc_get_order($orderA->get_id())) === '' ?: 'a URL was invented'
);

(new SettingsRepository())->save(['store' => ['storefront_url' => 'https://shop.example.test']]);

$url = $links->urlFor(wc_get_order($orderA->get_id()));

ac_assert(
    'with one set, the link points at the storefront',
    str_starts_with($url, 'https://shop.example.test/orders/track?token=') ?: "url is {$url}"
);
ac_assert(
    'and carries the same token the API accepts',
    str_contains($url, rawurlencode($linkA)) || str_contains($url, $linkA) ?: 'the URL carries a different token'
);
ac_assert(
    'never the admin domain',
    !str_contains($url, (string) parse_url((string) home_url(), PHP_URL_HOST)) ?: 'the link points at this backend'
);

// The message itself — a template and a queue row, not a mechanism.
$rendered = AlgerianCommerce\Notifications\NotificationMessages::render(
    AlgerianCommerce\Notifications\NotificationEvent::SHIPMENT_SHIPPED,
    'Test Shop',
    ['order_number' => '4211', 'provider' => 'yalidine', 'tracking_number' => 'yal-1', 'tracking_url' => $url]
);

ac_assert('the shipment message carries the link', str_contains($rendered['body'], $url) ?: 'no link in the body');

$withoutLink = AlgerianCommerce\Notifications\NotificationMessages::render(
    AlgerianCommerce\Notifications\NotificationEvent::SHIPMENT_SHIPPED,
    'Test Shop',
    ['order_number' => '4211', 'provider' => 'yalidine', 'tracking_number' => 'yal-1', 'tracking_url' => '']
);

ac_assert(
    'and is still a sensible message without one',
    str_contains($withoutLink['body'], 'yal-1') && !str_contains($withoutLink['body'], 'Follow it here')
        ?: 'the message degraded badly'
);

echo PHP_EOL, "── the checkout hand-off ──", PHP_EOL;

/*
 * The token is minted at checkout, which is the one moment the caller is
 * provably the buyer. Asserted against the *service*, because driving a whole
 * cart and checkout again here would duplicate tests/Api/cart.php.
 */
$checkoutBlock = (new ReflectionMethod(AlgerianCommerce\Cart\CheckoutService::class, 'trackingBlock'));
$checkoutBlock->setAccessible(true);
$block = $checkoutBlock->invoke($plugin->checkoutService(), wc_get_order($orderA->get_id()));

ac_assert('checkout returns the token', ($block['token'] ?? '') === $linkA ?: 'token is ' . ($block['token'] ?? 'absent'));
ac_assert(
    'and this API\'s own endpoint for it',
    str_starts_with((string) ($block['endpoint'] ?? ''), '/orders/track?token=') ?: 'endpoint is ' . ($block['endpoint'] ?? 'absent')
);
ac_assert('and the storefront URL when there is one', ($block['url'] ?? '') === $url ?: 'url is ' . ($block['url'] ?? 'absent'));

echo PHP_EOL, "── the route is public and stays inside its own namespace ──", PHP_EOL;

/*
 * `AccountSession::require()` calls `wp_set_current_user()`, and this whole file
 * runs in one PHP process — so the shopper from the section above is still the
 * current user, and the two refusals below would be 403 rather than 401 for a
 * reason that has nothing to do with tracking. Cleared so the assertion is about
 * an **anonymous** caller presenting a tracking token as if it were a
 * credential, which is the thing worth proving.
 */
wp_set_current_user(0);

ac_check(
    'the tracking route needs no credential of any kind',
    ac_req('GET', '/orders/track', null, ['token' => $linkA]),
    200
);
ac_check(
    'a tracking token is not an order credential',
    ac_req('GET', "/orders/{$orderA->get_id()}", null, ['token' => $linkA]),
    401
);
ac_check(
    'nor a shipments credential',
    ac_req('GET', "/orders/{$orderA->get_id()}/shipments", null, ['token' => $linkA]),
    401
);

// ------------------------------------------------------------------ cleanup --
update_option(SettingsRepository::OPTION, $settingsBefore, false);

global $wpdb;

foreach ([$orderA, $orderB, $guest, $unshipped, $old, $recent] as $order) {
    $wpdb->delete($shipments->table(), ['order_id' => $order->get_id()], ['%d']);
    $order->delete(true);
}

foreach ([$EMAIL_A, $EMAIL_B] as $email) {
    $existing = get_user_by('email', $email);

    if ($existing) {
        wp_delete_user($existing->ID);
    }
}

remove_filter('pre_wp_mail', $silenceMail, 99);
ac_assert(
    'wp_mail is not left short-circuited for the next suite',
    !has_filter('pre_wp_mail', $silenceMail) ?: 'the filter is still attached'
);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
