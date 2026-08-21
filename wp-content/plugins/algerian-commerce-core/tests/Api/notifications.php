<?php
/**
 * The notification queue — docs/PLAN.md §29, §30, roadmap step 34.
 *
 * **The property this suite exists for is that nothing is sent.** Every hook
 * below runs inside an order save or a status transition, and if any of them
 * put an SMTP connection on that path, a slow mail server becomes a slow
 * checkout and a dead one becomes a failed order. So the assertions are about
 * rows appearing in `ac_notifications`, never about mail arriving — and the
 * drain is asserted to *fail cleanly*, because this stack has no SMTP server
 * and a test that needed one would be a test nobody could run.
 *
 * There are no REST routes here: §29 is a layer, not an endpoint. It is
 * exercised through the hooks and the service, which is where it lives.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/notifications.php
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

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
use AlgerianCommerce\Notifications\Notification;
use AlgerianCommerce\Notifications\NotificationEvent;
use AlgerianCommerce\Notifications\NotificationRepository;

global $wpdb;
$table = $wpdb->prefix . 'ac_notifications';
$service = Plugin::instance()->notificationService();

$MAIL = 'ac-notif@example.test';

/*
 * Start from an empty queue, for the reason scripts/test-api.sh clears the
 * rate-limit counters before it asserts anything: a queue left full by an
 * earlier suite makes every assertion below meaningless.
 *
 * Specifically, `drain()` takes the oldest N rows **globally**, so the orders
 * that tests/Api/cart.php and tests/Api/coupons.php create — each of which
 * legitimately queues a confirmation — filled the budget and this suite's own
 * rows were never attempted. It passed when run alone and failed in the full
 * run, which is the worst way to find out.
 */
$wpdb->query("DELETE FROM {$table}");

echo PHP_EOL, "── the channel registry ──", PHP_EOL;

ac_assert('email is configured', $service->channels()->has('email') ?: 'no email channel');
ac_assert(
    'a channel that is not configured is absent, not an error',
    !$service->channels()->has('whatsapp') ?: 'whatsapp reported as configured'
);

echo PHP_EOL, "── an order queues, and does not send ──", PHP_EOL;

$order = wc_create_order(['status' => 'pending']);
$order->set_billing_email($MAIL);
$order->set_billing_first_name('Amina');
$order->set_total('4500');
$order->save();
$orderId = $order->get_id();

$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", $orderId));

do_action('woocommerce_new_order', $orderId, $order);

$rows = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$table} WHERE subject_id = %d ORDER BY id", $orderId),
    ARRAY_A
);

ac_assert('two rows: one for the customer, one for the shop', count($rows) === 2
    ?: 'queued ' . count($rows));
ac_assert('every row is pending, not sent', array_column($rows, 'status') === ['pending', 'pending']
    ?: 'statuses were ' . implode(',', array_column($rows, 'status')));

$events = array_column($rows, 'event');
sort($events);
ac_assert('the events are the ones §30 names', $events === ['admin.new_order', 'order.placed']
    ?: 'events were ' . implode(',', $events));

$byEvent = array_column($rows, null, 'event');
ac_assert(
    'the customer message went to the customer',
    ($byEvent['order.placed']['recipient'] ?? '') === $MAIL
        ?: 'went to ' . ($byEvent['order.placed']['recipient'] ?? '?')
);
ac_assert(
    'and the shop message did not',
    ($byEvent['admin.new_order']['recipient'] ?? '') !== $MAIL
        ?: 'the shop alert was addressed to the customer'
);

echo PHP_EOL, "── the claim makes many firings one message ──", PHP_EOL;

do_action('woocommerce_new_order', $orderId, $order);
do_action('woocommerce_new_order', $orderId, $order);

$again = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE subject_id = %d", $orderId));
ac_assert('three firings, still two rows', $again === 2 ?: "there are now {$again} rows");

echo PHP_EOL, "── the message is frozen at queue time ──", PHP_EOL;

$payload = json_decode((string) ($byEvent['order.placed']['payload'] ?? '{}'), true);

ac_assert('the body was rendered and stored', str_contains((string) ($payload['body'] ?? ''), 'Amina')
    ?: 'the stored body does not greet the customer');
ac_assert('the total was captured as it stood', str_contains((string) ($payload['body'] ?? ''), '4500.00')
    ?: 'the stored body does not carry the total');

// Change the order underneath the queued row. The message must not follow.
$order->set_total('99999');
$order->save();

$after = $wpdb->get_var($wpdb->prepare(
    "SELECT payload FROM {$table} WHERE subject_id = %d AND event = %s", $orderId, 'order.placed'
));
ac_assert(
    'and it does not follow the order afterwards',
    str_contains((string) $after, '4500.00') && !str_contains((string) $after, '99999')
        ?: 'the queued message changed with the order'
);

echo PHP_EOL, "── status transitions ──", PHP_EOL;

do_action('woocommerce_order_status_changed', $orderId, 'pending', 'cancelled', $order);
ac_assert(
    'a cancellation queues one more',
    (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE subject_id = %d AND event = %s", $orderId, 'order.cancelled'
    )) === 1 ?: 'the cancellation did not queue exactly one'
);

// COD reaches `processing` without being paid, so it must not claim a payment.
do_action('woocommerce_order_status_changed', $orderId, 'cancelled', 'processing', $order);
ac_assert(
    'an unpaid order moving to processing sends no payment message',
    (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE subject_id = %d AND event = %s", $orderId, 'payment.received'
    )) === 0 ?: 'a COD order was told its payment arrived'
);

echo PHP_EOL, "── the drain ──", PHP_EOL;

/*
 * There is no SMTP server in this stack, so the honest assertion is that the
 * drain tries, fails cleanly, records why, and leaves the row for the next run.
 * Asserting a successful send would need a mail server the suite cannot have.
 */
$result = $service->drain(50);

ac_assert('the drain attempted the queued rows', $result['attempted'] >= 3
    ?: 'attempted ' . $result['attempted']);

$drained = $wpdb->get_results(
    $wpdb->prepare("SELECT status, attempts, last_error FROM {$table} WHERE subject_id = %d", $orderId),
    ARRAY_A
);

$attempts = array_map(static fn (array $r): int => (int) $r['attempts'], $drained);
ac_assert('every row recorded an attempt', min($attempts) >= 1 ?: 'some rows were not attempted');
ac_assert(
    'a transient failure stays pending for the next run',
    count(array_filter($drained, static fn (array $r): bool => $r['status'] === 'pending')) === count($drained)
        ?: 'a row was parked after one failure'
);
ac_assert(
    'and the reason is readable',
    trim((string) ($drained[0]['last_error'] ?? '')) !== '' ?: 'no reason was recorded'
);

echo PHP_EOL, "── permanent failures are not retried forever ──", PHP_EOL;

$repository = $service->repository();
$bad = Notification::toCustomer(NotificationEvent::ORDER_PLACED, 'not-an-address', 's', 'b', 'order', 987654);

ac_assert(
    'a channel refuses a notification it cannot carry',
    $service->notify($bad) === 0 ?: 'an undeliverable notification was queued'
);

echo PHP_EOL, "── an unknown event is refused ──", PHP_EOL;

$unknown = Notification::toCustomer('order.exploded', $MAIL, 's', 'b', 'order', 987655);
ac_assert('an event outside the vocabulary is not queued', $service->notify($unknown) === 0
    ?: 'an unknown event reached the queue');

echo PHP_EOL, "── low stock claims once, and can be re-armed ──", PHP_EOL;

$product = new WC_Product_Simple();
$product->set_name('AC notif fixture');
$product->set_regular_price('100');
$product->set_manage_stock(true);
$product->set_stock_quantity(1);
$product->set_status('publish');
$productId = $product->save();

$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", $productId));

do_action('woocommerce_low_stock', wc_get_product($productId));
do_action('woocommerce_low_stock', wc_get_product($productId));

$lowRows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE subject_id = %d AND event = %s", $productId, 'stock.low'
));
ac_assert('two warnings about one product queue once', $lowRows === 1 ?: "queued {$lowRows}");

// Restocked above the threshold: the claim is cleared so the next fall warns.
$restocked = wc_get_product($productId);
$restocked->set_stock_quantity(500);
$restocked->save();
do_action('woocommerce_product_set_stock', $restocked);

$cleared = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE subject_id = %d AND event = %s", $productId, 'stock.low'
));
ac_assert('restocking clears the claim', $cleared === 0 ?: 'the claim survived a restock');

do_action('woocommerce_low_stock', wc_get_product($productId));
$rearmed = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE subject_id = %d AND event = %s", $productId, 'stock.low'
));
ac_assert('so the shop is warned again next time', $rearmed === 1 ?: 'the second warning was swallowed');

echo PHP_EOL, "── the summary an operator reads ──", PHP_EOL;

$summary = $service->summary();
ac_assert(
    'the summary counts every status',
    array_keys($summary) === [NotificationRepository::STATUS_PENDING, NotificationRepository::STATUS_SENT, NotificationRepository::STATUS_FAILED]
        ?: 'summary keys were ' . implode(',', array_keys($summary))
);

// ================================================ §90: the queue, read back ==
//
// Everything above is §59d's layer, exercised through hooks and the service
// because it has no routes. §90 gives it three, and the property they must not
// break is the one this whole file exists for: **nothing sends on a request
// path.**

echo PHP_EOL, "── §90: routes, and the capability on them ──", PHP_EOL;

/**
 * The same request helper every other tests/Api suite uses. Defined here rather
 * than at the top of the file because §59d's half needs no HTTP at all.
 */
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

/*
 * **Support Agent is the authorized role here, and that is the section's
 * argument made testable.** §90 invents no capability: a row holds a customer's
 * address and, on the single read, the frozen body of their order confirmation
 * — so the gate is `ac_manage_customers`, the capability that already reads a
 * customer's record in detail (§63's rule). Support Agent holds it. Product
 * Manager is the negative case and holds ten other management capabilities,
 * which is what makes a 403 from them mean something.
 */
$support = ac_user('ac_notif_support', 'ac_support_agent');   // has ac_manage_customers
$product = ac_user('ac_notif_product', 'ac_product_manager'); // manages the catalogue, not customers

// Two rows to read, written through the service so they are shaped exactly as a
// real one — a hand-built INSERT would test a row nothing else produces.
$service->notify(Notification::toCustomer(
    NotificationEvent::ORDER_PLACED,
    $MAIL,
    'Commande 90001 confirmée',
    "Bonjour Amina,\n\nVotre commande de 4 500,00 DA est confirmée.",
    'order',
    90001,
    ['order_number' => '90001']
));
$service->notify(Notification::toAdmin(
    NotificationEvent::ADMIN_NEW_ORDER,
    'shop@example.test',
    'Nouvelle commande 90001',
    'Une commande de 4 500,00 DA vient d\'être passée.',
    'order',
    90001
));

$customerRow = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$table} WHERE dedupe_key = %s",
    NotificationEvent::ORDER_PLACED . ':90001'
));
$adminRow = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$table} WHERE dedupe_key = %s",
    NotificationEvent::ADMIN_NEW_ORDER . ':90001'
));

ac_assert('two rows to read', $customerRow > 0 && $adminRow > 0 ?: 'the fixture rows were not queued');

$routes = [
    'GET /notifications' => ['GET', '/notifications', null],
    'GET /notifications/{id}' => ['GET', "/notifications/{$customerRow}", null],
    'POST /notifications/{id}/retry' => ['POST', "/notifications/{$customerRow}/retry", []],
];

wp_set_current_user(0);
foreach ($routes as $label => [$method, $route, $body]) {
    ac_check("{$label} signed out", ac_req($method, $route, $body), 401);
}

wp_set_current_user($product);
foreach ($routes as $label => [$method, $route, $body]) {
    ac_check("{$label} as product manager", ac_req($method, $route, $body), 403);
}

// The positive control. Without it a 403 above could mean a broken route rather
// than a working guard, and §65's rule is that the two look identical.
wp_set_current_user($support);
ac_check('GET /notifications as support agent', ac_req('GET', '/notifications'), 200);

echo PHP_EOL, "── §90: the list omits the body, the single read carries it ──", PHP_EOL;

$list = ac_check('GET /notifications', ac_req('GET', '/notifications'), 200, static function ($d): bool|string {
    return ($d['meta']['total'] ?? 0) >= 2 ? true : 'expected at least two rows';
});

/*
 * Asserted **by key and by value**, which is §84's rule for a disclosure list:
 * the key half alone cannot catch a rename, and the value half alone cannot
 * catch a field that happens to be empty in the fixture. The body below is
 * distinctive on purpose.
 */
$encoded = (string) wp_json_encode($list['data'] ?? []);

ac_assert(
    'no list row carries a message key',
    !array_filter(
        $list['data'] ?? [],
        static fn ($row): bool => array_key_exists('message', $row) || array_key_exists('payload', $row)
    ) ?: 'a list row published the message'
);
ac_assert(
    'and none of the message text is in the list at all',
    !str_contains($encoded, 'Bonjour Amina') ?: 'the frozen body leaked into the list'
);

// The positive control for both: the same text *is* published by the single
// read. Without it, a presenter that dropped the body everywhere would pass.
$single = ac_check(
    'GET /notifications/{id} carries the frozen message',
    ac_req('GET', "/notifications/{$customerRow}"),
    200,
    static function ($d): bool|string {
        $message = $d['data']['message'] ?? [];

        if (($message['readable'] ?? false) !== true) {
            return 'the message was not readable';
        }

        return str_contains((string) ($message['body'] ?? ''), 'Bonjour Amina')
            && ($message['subject'] ?? '') === 'Commande 90001 confirmée'
            ? true
            : 'the frozen message did not come back: ' . wp_json_encode($message);
    }
);

ac_assert(
    'and the message is an allowlist, not the payload',
    array_keys($single['data']['message'] ?? []) === ['readable', 'subject', 'body', 'context']
        ?: 'message keys were ' . implode(',', array_keys($single['data']['message'] ?? []))
);

ac_check('a notification that does not exist', ac_req('GET', '/notifications/99999999'), 404);

echo PHP_EOL, "── §90: filters ──", PHP_EOL;

/*
 * `dedupe_key` is the filter §90 exists for: the key is `event:subject_id` by
 * construction, so this is "did the customer get their confirmation?" in one
 * request.
 */
ac_check(
    'filter by dedupe_key',
    ac_req('GET', '/notifications', null, ['dedupe_key' => NotificationEvent::ORDER_PLACED . ':90001']),
    200,
    static fn ($d): bool|string => ($d['meta']['total'] ?? 0) === 1
        && (int) ($d['data'][0]['id'] ?? 0) === $customerRow
        ?: 'expected exactly the customer row, got ' . wp_json_encode($d['meta'] ?? [])
);

/*
 * `recipient` and `subject_id` are the two questions `dedupe_key` cannot ask,
 * added on `feat/notification-filters` because the admin panel could not be
 * built without them: an exact `event:subject_id` cannot express "everything
 * sent to this person" or "everything about this order", and both were
 * previously accepted and silently ignored.
 *
 * The two fixture rows are the whole argument in miniature. They share
 * `subject_id` 90001 and differ in recipient and event, so `subject_id` must
 * find both and `recipient` must find one — and each assertion below carries
 * the opposite filter as its control, because a filter that returns one row for
 * every input looks identical to a working one when there are only two rows.
 */
/*
 * Asserted as a **property, not a count**: earlier sections of this suite write
 * to `$MAIL` too, so "exactly one" would be an assertion about how many
 * fixtures happen to precede this line rather than about the filter. Every row
 * returned was addressed to this person, the fixture row is among them, and the
 * set is strictly narrower than the unfiltered one — that last clause is the
 * floor, since a filter that is accepted and ignored returns every row and
 * satisfies the first two.
 */
$everything = ac_req('GET', '/notifications', null, ['per_page' => 100]);
$allRows = (int) ($everything[1]['meta']['total'] ?? 0);

ac_check(
    'filter by recipient finds that person\'s rows and nobody else\'s',
    ac_req('GET', '/notifications', null, ['recipient' => $MAIL, 'per_page' => 100]),
    200,
    static function ($d) use ($MAIL, $customerRow, $allRows): bool|string {
        $rows = $d['data'] ?? [];
        $total = (int) ($d['meta']['total'] ?? 0);
        $foreign = array_filter($rows, static fn ($r): bool => ($r['recipient'] ?? '') !== $MAIL);
        $ids = array_map(static fn ($r): int => (int) ($r['id'] ?? 0), $rows);

        if ($foreign !== []) {
            return count($foreign) . ' row(s) addressed to somebody else came back';
        }

        if (!in_array($customerRow, $ids, true)) {
            return 'the fixture row was not among the ' . $total . ' returned';
        }

        return $total > 0 && $total < $allRows
            ?: "the filter did not narrow: {$total} of {$allRows}";
    }
);

ac_check(
    'and the other address finds the other row, not the same one',
    ac_req('GET', '/notifications', null, ['recipient' => 'shop@example.test']),
    200,
    static fn ($d): bool|string => ($d['meta']['total'] ?? 0) === 1
        && (int) ($d['data'][0]['id'] ?? 0) === $adminRow
        ?: 'expected the admin row alone, got ' . wp_json_encode($d['meta'] ?? [])
);

ac_check(
    'an address nobody was written to',
    ac_req('GET', '/notifications', null, ['recipient' => 'nobody@example.test']),
    200,
    static fn ($d): bool => ($d['meta']['total'] ?? -1) === 0
);

/*
 * The one `dedupe_key` genuinely cannot do: **both** notifications about one
 * order, the customer's and the shop's, in one request.
 */
ac_check(
    'filter by subject_id finds every event about that order',
    ac_req('GET', '/notifications', null, ['subject_id' => 90001]),
    200,
    static fn ($d): bool|string => ($d['meta']['total'] ?? 0) === 2
        ?: 'expected both rows for order 90001, got ' . wp_json_encode($d['meta'] ?? [])
);

ac_check(
    'while an order nothing was queued for finds none',
    ac_req('GET', '/notifications', null, ['subject_id' => 90002]),
    200,
    static fn ($d): bool => ($d['meta']['total'] ?? -1) === 0
);

// AND-ed with the rest rather than replacing them: this is the customer's own
// notification about this one order, which is the customer-detail screen's read.
ac_check(
    'recipient and subject_id narrow together',
    ac_req('GET', '/notifications', null, ['recipient' => $MAIL, 'subject_id' => 90001]),
    200,
    static fn ($d): bool|string => ($d['meta']['total'] ?? 0) === 1
        && (int) ($d['data'][0]['id'] ?? 0) === $customerRow
        ?: 'expected one row, got ' . wp_json_encode($d['meta'] ?? [])
);

// `minimum => 1`. Zero is not a row id anywhere, and `subject_id` is nullable —
// so accepting it would have to mean "the rows with no subject", which is not
// what typing a zero asks for.
ac_check(
    'subject_id zero is refused rather than reinterpreted',
    ac_req('GET', '/notifications', null, ['subject_id' => 0]),
    400
);

ac_check(
    'and a subject_id that is not a number is refused',
    ac_req('GET', '/notifications', null, ['subject_id' => 'order-90001']),
    400
);

ac_check(
    'filter by channel',
    ac_req('GET', '/notifications', null, ['channel' => 'email']),
    200,
    static fn ($d): bool => ($d['meta']['total'] ?? 0) >= 2
);

ac_check(
    'filter by a channel nobody has configured',
    ac_req('GET', '/notifications', null, ['channel' => 'whatsapp']),
    200,
    static fn ($d): bool => ($d['meta']['total'] ?? -1) === 0
);

ac_check(
    'filter by status',
    ac_req('GET', '/notifications', null, ['status' => NotificationRepository::STATUS_PENDING]),
    200,
    static fn ($d): bool => ($d['meta']['total'] ?? 0) >= 2
);

ac_check(
    'a status outside the vocabulary is refused',
    ac_req('GET', '/notifications', null, ['status' => 'delivered']),
    400
);

ac_check(
    'a date range that excludes everything',
    ac_req('GET', '/notifications', null, ['date_from' => '2020-01-01', 'date_to' => '2020-01-02']),
    200,
    static fn ($d): bool => ($d['meta']['total'] ?? -1) === 0
);

// The control beside it: today's range includes them. A filter that returned
// nothing for every input would pass the check above.
ac_check(
    'while today\'s range includes them',
    ac_req('GET', '/notifications', null, ['date_from' => gmdate('Y-m-d'), 'date_to' => gmdate('Y-m-d')]),
    200,
    static fn ($d): bool|string => ($d['meta']['total'] ?? 0) >= 2
        ?: 'today\'s range found ' . ($d['meta']['total'] ?? 0)
);

ac_check(
    'a date that is not a date is refused',
    ac_req('GET', '/notifications', null, ['date_from' => 'yesterday']),
    400
);

/*
 * §65's SQL rule: the assertion is a **count**, not a status. A concatenated
 * WHERE answers 200 and returns everything, which is exactly what a working
 * filter looks like from the outside — so this asserts the payload does not
 * widen the result set beyond its honest form.
 */
$honest = ac_req('GET', '/notifications', null, ['channel' => 'email']);
$hostile = ac_req('GET', '/notifications', null, ['dedupe_key' => "x' OR '1'='1"]);
ac_assert(
    'a filter payload must not widen the result set',
    ($hostile[1]['meta']['total'] ?? -1) === 0
        ?: 'the injection payload matched ' . ($hostile[1]['meta']['total'] ?? '?')
        . ' rows, against ' . ($honest[1]['meta']['total'] ?? '?') . ' for an honest filter'
);

// The same rule applied to the filter added last, which is the one whose value
// comes from a customer record rather than from a vocabulary this code owns.
$hostileRecipient = ac_req('GET', '/notifications', null, ['recipient' => "x' OR '1'='1"]);
ac_assert(
    'and the same for recipient',
    ($hostileRecipient[1]['meta']['total'] ?? -1) === 0
        ?: 'the injection payload matched ' . ($hostileRecipient[1]['meta']['total'] ?? '?') . ' rows'
);

echo PHP_EOL, "── §90: retry queues and never sends ──", PHP_EOL;

// Park the customer row the way a real failure would.
$service->repository()->markFailed($customerRow, 'Connection refused', false);
$parked = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $customerRow));
ac_assert('a failed row is parked', $parked === NotificationRepository::STATUS_FAILED ?: "status was {$parked}");

/*
 * **The property this whole file exists for, at the new door.** A retry must
 * not send: an SMTP server that hangs would hang the admin panel, and the
 * operator most likely to press this is the one whose mail server is already
 * misbehaving. `pre_wp_mail` is short-circuited and counted — the seeder uses
 * the same device for the same reason (§67) — so "nothing was sent" is a
 * measurement rather than an inference from the status column.
 */
$GLOBALS['ac_mail_attempts'] = 0;
$countMail = static function ($short) {
    $GLOBALS['ac_mail_attempts']++;

    return true;
};
add_filter('pre_wp_mail', $countMail, 10, 1);

$retry = ac_check(
    'POST /notifications/{id}/retry answers 202',
    ac_req('POST', "/notifications/{$customerRow}/retry", []),
    202,
    /*
     * `array_key_exists` rather than `?? 'x'`: the null-coalescing operator
     * treats a present `null` as absent, so `($d['data']['last_error'] ?? 'x')
     * === null` can never be true and the check would fail against a perfectly
     * cleared row. It did, on this suite's first run.
     */
    static fn ($d): bool|string => ($d['data']['status'] ?? '') === NotificationRepository::STATUS_PENDING
        && ($d['data']['attempts'] ?? -1) === 0
        && array_key_exists('last_error', $d['data'] ?? []) && $d['data']['last_error'] === null
        ?: 'the row did not go back to pending: ' . wp_json_encode($d['data'] ?? [])
);

ac_assert(
    'and nothing was sent on the request path',
    $GLOBALS['ac_mail_attempts'] === 0 ?: $GLOBALS['ac_mail_attempts'] . ' mail(s) attempted during a retry'
);

ac_assert(
    'the response names the command that will send it',
    ($retry['meta']['drain'] ?? '') === 'wp algerian-commerce send-notifications'
        ?: 'the drain command was not named: ' . wp_json_encode($retry['meta'] ?? [])
);

ac_assert(
    'and says this was a real requeue rather than a no-op',
    ($retry['meta']['already_pending'] ?? true) === false ?: 'a failed row was reported as already pending'
);

/*
 * A row that is already queued. This answered **409 "already sent"** in the
 * first version, about a row that had never been sent: MySQL reports rows it
 * *changed*, not rows it *matched*, so the conditional UPDATE affected zero
 * rows when every value it set was already the value in the row. The status
 * assertion alone would not have caught it — the row really was pending — so
 * the check is on the status code and `already_pending` together.
 */
ac_check(
    'retrying an already-queued row is a 202, not a conflict',
    ac_req('POST', "/notifications/{$customerRow}/retry", []),
    202,
    static fn ($d): bool|string => ($d['meta']['already_pending'] ?? false) === true
        ?: 'already_pending was not reported: ' . wp_json_encode($d['meta'] ?? [])
);

// A sent row is a record of something that left the building.
$wpdb->update(
    $table,
    ['status' => NotificationRepository::STATUS_SENT, 'sent_at' => current_time('mysql', true)],
    ['id' => $adminRow]
);

ac_check(
    'retrying a sent notification is refused',
    ac_req('POST', "/notifications/{$adminRow}/retry", []),
    409,
    static fn ($d): bool|string => ($d['error']['details']['status'] ?? '') === NotificationRepository::STATUS_SENT
        && ($d['error']['details']['sent_at'] ?? null) !== null
        ?: 'the refusal did not name when it was sent: ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_assert(
    'and the sent row is untouched',
    (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id = %d", $adminRow))
        === NotificationRepository::STATUS_SENT ?: 'a refused retry re-queued a sent row'
);

ac_assert(
    'no mail was attempted by any of it',
    $GLOBALS['ac_mail_attempts'] === 0 ?: $GLOBALS['ac_mail_attempts'] . ' mail(s) attempted'
);

remove_filter('pre_wp_mail', $countMail, 10);

ac_check('retrying a notification that does not exist', ac_req('POST', '/notifications/99999999/retry', []), 404);

echo PHP_EOL, "── §90: the retry is audited, and names no recipient ──", PHP_EOL;

wp_set_current_user(ac_user('ac_notif_auditor', 'ac_admin'));

$audit = ac_req('GET', '/audit-logs', null, ['action' => 'notification.retried', 'per_page' => 50]);
$rows = $audit[1]['data'] ?? [];

ac_assert(
    'the retry was audited against the row',
    (bool) array_filter($rows, static fn ($row): bool => $row['resource_type'] === 'notification'
        && $row['resource_id'] === (string) $customerRow
        && ($row['metadata']['status_from'] ?? '') === NotificationRepository::STATUS_FAILED)
        ?: 'no notification.retried row named the failed status it came from'
);

/*
 * §71's rule, asserted rather than described: the trail identifies the message
 * by channel and dedupe key, and an audit table nobody cleans is not where a
 * customer's email address belongs.
 */
ac_assert(
    'and the recipient is nowhere in the trail',
    !str_contains((string) wp_json_encode($rows), $MAIL) ?: 'the recipient reached the audit trail'
);

wp_set_current_user($support);

// ------------------------------------------------------------------ cleanup --
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", 90001));
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", $orderId));
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", $productId));
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recipient = %s", $MAIL));
$order->delete(true);
wp_delete_post($productId, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) { exit(1); }
