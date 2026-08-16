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
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recipient = %s", $MAIL));

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

// ------------------------------------------------------------------ cleanup --
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", $orderId));
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE subject_id = %d", $productId));
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE recipient = %s", $MAIL));
$order->delete(true);
wp_delete_post($productId, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) { exit(1); }
