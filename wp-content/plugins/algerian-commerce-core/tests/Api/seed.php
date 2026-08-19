<?php
/**
 * The development seed — roadmap §67, docs/PLAN.md §46.
 *
 * There are no REST routes here: §67 is data and a command, not an endpoint.
 * What is asserted is everything a unit test cannot reach — that the *shipped*
 * fixtures load, that what they produce is a shop the API could have built
 * itself, and that a second run changes nothing.
 *
 * The rules in `SeedDataset` are covered by tests/Unit/SeedDatasetTest against
 * synthetic input. This suite points them at `data/seed/` instead, which is the
 * half that catches a fixture edited after the validator was written.
 *
 * In-process. No declare(strict_types=1): wp eval-file eval()s the body, where
 * that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/seed.php
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
use AlgerianCommerce\Notifications\NotificationRepository;
use AlgerianCommerce\Seed\SeedDataset;
use AlgerianCommerce\Seed\Seeder;

global $wpdb;

$plugin = Plugin::instance();
$seeder = $plugin->seeder();
$notifications = $plugin->notificationRepository();
$path = $seeder->dataPath();

/*
 * The seeder writes through services, and every service asserts a capability.
 * A suite that ran as nobody would be asserting that Permissions works, which
 * tests/Api/security.php already does.
 */
$admin = get_user_by('login', 'ac_seed_admin');

if (!$admin) {
    $id = wp_insert_user([
        'user_login' => 'ac_seed_admin',
        'user_pass' => wp_generate_password(24),
        'user_email' => 'ac_seed_admin@example.test',
        'role' => 'administrator',
    ]);
    $admin = get_user_by('id', $id);
}

wp_set_current_user($admin->ID);

// ---------------------------------------------------------------- fixtures --
echo PHP_EOL, "── the shipped fixtures ──", PHP_EOL;

$read = static function (string $file) use ($path) {
    $raw = @file_get_contents(rtrim($path, '/') . '/' . $file);

    return $raw === false ? null : json_decode($raw, true);
};

$catalogue = SeedDataset::catalogue($read('catalogue.json'));
$slugs = array_fill_keys(array_column($catalogue['categories'], 'slug'), true);

$skus = [];
foreach ($catalogue['products'] as $product) {
    $skus[strtolower($product['sku'])] = true;
    foreach ($product['variations'] as $variation) {
        $skus[strtolower($variation['sku'])] = true;
    }
}

$customers = SeedDataset::customers($read('customers.json'));
$emails = array_fill_keys(array_column($customers['rows'], 'email'), true);
$coupons = SeedDataset::coupons($read('coupons.json'), $slugs);
$orders = SeedDataset::orders($read('orders.json'), $skus, $emails);

foreach ([
    'catalogue.json' => $catalogue,
    'customers.json' => $customers,
    'coupons.json' => $coupons,
    'orders.json' => $orders,
] as $file => $result) {
    ac_assert(
        "{$file} validates",
        $result['errors'] === [] ? true : implode(' | ', array_slice($result['errors'], 0, 3))
    );
}

// The check that keeps the check honest: a validator pointed at the wrong
// directory would report a clean bill of health for nothing at all.
ac_assert('the fixtures are not empty', count($catalogue['products']) > 0
    && count($customers['rows']) > 0 && count($orders['rows']) > 0
    ? true : 'nothing was read from ' . $path);

/*
 * docs/PLAN.md §46 asserted against the shipped file rather than against a
 * synthetic one. This is the rule with consequences outside the test suite:
 * every seeded order queues a notification, and a drain would mail whoever
 * these addresses belong to.
 */
$real = array_filter(
    array_column($customers['rows'], 'email'),
    static fn (string $email): bool => !SeedDataset::isTestAddress($email)
);
ac_assert('every seeded shopper is on a reserved domain', $real === []
    ? true : 'real addresses: ' . implode(', ', $real));

/*
 * §85's flag is seedable, and the fixture uses it exactly once.
 *
 * The reason it is seedable at all: every customer in the development shop read
 * `marketing_consent: false`, so the affirmative branch of any screen showing it
 * had no data that could reach it and no test that had ever seen it rendered.
 *
 * The reason the count is asserted rather than just the presence: a fixture file
 * is one of the two places a pre-ticked consent box gets into a system by accident
 * — the other is a form — and "most customers consented" is a seed that quietly
 * teaches a shop the wrong default.
 */
$consenting = array_values(array_filter(
    $customers['rows'],
    static fn (array $row): bool => $row['marketing_consent'] === true
));

ac_assert('exactly one seeded shopper consents to marketing', count($consenting) === 1
    ? true : count($consenting) . ' of ' . count($customers['rows']) . ' consent');
ac_assert('...and the rest default to no', count($customers['rows']) > 1 ? true : 'only one shopper to compare');

// ---------------------------------------------------------------- dry run ---
echo PHP_EOL, "── dry run ──", PHP_EOL;

$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");
$dry = $seeder->seed(true);
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");

ac_assert('a dry run reports no errors', $dry['errors'] === [] ? true : implode(' | ', $dry['errors']));
ac_assert('a dry run writes nothing', $before === $after
    ? true : "products went from {$before} to {$after}");
ac_assert('a dry run counts every product', $dry['products']['created'] === count($catalogue['products']));

// -------------------------------------------------------------------- seed --
echo PHP_EOL, "── seeding ──", PHP_EOL;

$result = $seeder->seed();

ac_assert('the seed run reports no errors', $result['errors'] === []
    ? true : implode(' | ', array_slice($result['errors'], 0, 3)));

// The seeded consent actually landed — and only on the row that asked for it.
// The negative half is the load-bearing one: a seeder that consented everybody
// would satisfy the positive check on its own.
$consentedIds = array_map(
    static fn (array $row): int => (int) email_exists($row['email']),
    $customers['rows']
);
$actuallyConsenting = array_values(array_filter(
    $consentedIds,
    static fn (int $id): bool => $id > 0 && \AlgerianCommerce\Campaigns\Consent::has($id)
));

ac_assert('the seeded consent reached exactly one shopper', count($actuallyConsenting) === 1
    ? true : count($actuallyConsenting) . ' shoppers ended up consenting');
ac_assert('...and it carries a date and a source', (function () use ($actuallyConsenting): bool|string {
    $id = $actuallyConsenting[0] ?? 0;

    return $id > 0
        && \AlgerianCommerce\Campaigns\Consent::changedAt($id) !== null
        && \AlgerianCommerce\Campaigns\Consent::source($id) === 'registration'
        ? true : 'a seeded consent with no record is not a consent record';
})());

foreach ([
    'categories' => count($catalogue['categories']),
    'products' => count($catalogue['products']),
    'customers' => count($customers['rows']),
    'coupons' => count($coupons['rows']),
    'orders' => count($orders['rows']),
] as $dataset => $expected) {
    $total = $result[$dataset]['created'] + $result[$dataset]['updated'];
    ac_assert("every {$dataset} row was written", $total === $expected
        ? true : "{$total} of {$expected}");
}

// ------------------------------------------------------------- the result ---
echo PHP_EOL, "── what the shop looks like afterwards ──", PHP_EOL;

$missing = [];
foreach (array_keys($skus) as $sku) {
    if ((int) wc_get_product_id_by_sku($sku) === 0) {
        $missing[] = $sku;
    }
}
ac_assert('every fixture SKU resolves to a product', $missing === []
    ? true : 'missing: ' . implode(', ', $missing));

$variable = null;
foreach ($catalogue['products'] as $product) {
    if ($product['type'] === 'variable') {
        $variable = $product;
        break;
    }
}

if ($variable === null) {
    ac_assert('the catalogue has a variable product', 'none in the fixtures');
} else {
    $parent = wc_get_product(wc_get_product_id_by_sku($variable['sku']));
    ac_assert('the variable product is variable', $parent && $parent->get_type() === 'variable');
    ac_assert(
        'it has every variation the fixture declares',
        $parent && count($parent->get_children()) === count($variable['variations'])
            ? true : 'children: ' . ($parent ? count($parent->get_children()) : 0)
    );
    // A variable product with no price is one WooCommerce will not sell, and it
    // is the state a variation whose attributes did not match produces.
    ac_assert('it has a price derived from its variations', $parent && $parent->get_price() !== '');
}

$notCustomers = [];
foreach (array_keys($emails) as $email) {
    $user = get_user_by('email', $email);

    if (!$user || !in_array('customer', (array) $user->roles, true)) {
        $notCustomers[] = $email;
    }
}
// The same role AccountService::register() forces, so a seeded shopper can sign
// in at /account/login and read their own orders — which is what makes §59c's
// IDOR pair testable against real data.
ac_assert('every seeded shopper is a customer account', $notCustomers === []
    ? true : implode(', ', $notCustomers));

$missingCoupons = [];
foreach (array_column($coupons['rows'], 'code') as $code) {
    if ((int) wc_get_coupon_id_by_code($code) === 0) {
        $missingCoupons[] = $code;
    }
}
ac_assert('every fixture coupon exists', $missingCoupons === []
    ? true : 'missing: ' . implode(', ', $missingCoupons));

$ledger = get_option(Seeder::ORDER_LEDGER_OPTION, []);
ac_assert('the order ledger holds every ref', count($ledger) === count($orders['rows'])
    ? true : count($ledger) . ' of ' . count($orders['rows']));

$statuses = [];
$empty = [];
foreach ($orders['rows'] as $row) {
    $order = isset($ledger[$row['ref']]) ? wc_get_order((int) $ledger[$row['ref']]) : false;

    if (!$order) {
        $empty[] = $row['ref'] . ' (no order)';
        continue;
    }

    $statuses[$order->get_status()] = ($statuses[$order->get_status()] ?? 0) + 1;

    // The whole reason the seeder goes through OrderService rather than
    // writing rows: WooCommerce priced these lines. A total of zero means the
    // products were attached without their prices, which looks fine in every
    // list endpoint and breaks every figure in §63.
    if ((float) $order->get_total() <= 0) {
        $empty[] = $row['ref'] . ' (total 0)';
    }
}
ac_assert('every seeded order exists and is priced', $empty === [] ? true : implode(', ', $empty));

// `cancelled` and `refunded` are not creatable states; reaching them at all
// proves final_status ran as a second, legal transition.
ac_assert('an order was carried to cancelled', ($statuses['cancelled'] ?? 0) > 0
    ? true : json_encode($statuses));
ac_assert('an order was carried to refunded', ($statuses['refunded'] ?? 0) > 0
    ? true : json_encode($statuses));

/*
 * §64's rule, which this seeder inherits: every stock change goes through
 * InventoryService, so the ledger is real. A seeder writing `_stock` directly
 * would leave `ac_inventory_movements` empty and every stock report describing
 * a shop nobody stocked.
 */
$seededSku = $catalogue['products'][0]['sku'];
$seededId = (int) wc_get_product_id_by_sku($seededSku);
$movements = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}ac_inventory_movements WHERE product_id = %d",
    $seededId
));
ac_assert('a seeded product has stock ledger movements', $movements > 0
    ? true : "{$seededSku} has {$movements}");

// ------------------------------------------------------------ idempotency ---
echo PHP_EOL, "── running it twice ──", PHP_EOL;

$again = $seeder->seed();

ac_assert('the second run reports no errors', $again['errors'] === []
    ? true : implode(' | ', array_slice($again['errors'], 0, 3)));

foreach (['categories', 'products', 'variations', 'customers', 'coupons', 'orders'] as $dataset) {
    ac_assert("no duplicate {$dataset}", $again[$dataset]['created'] === 0
        ? true : $again[$dataset]['created'] . ' created on the second run');
}

// ---------------------------------------------------------- notifications ---
echo PHP_EOL, "── what a seeded order tells the world ──", PHP_EOL;

/*
 * Seeding an order queues a customer confirmation and an admin alert (step 34).
 * A fictional order must not tell anybody it happened: the customer addresses
 * are on domains that reach nobody, but the admin alert goes to a real inbox.
 *
 * One order is removed so the next run genuinely creates one — otherwise this
 * would assert "nothing was queued" against a run that wrote nothing at all,
 * which is the shape of test that passes forever.
 */
$victim = 'order-004';
$ledger = get_option(Seeder::ORDER_LEDGER_OPTION, []);

if (!isset($ledger[$victim])) {
    ac_assert('a seeded order could be removed for the queue test', "no {$victim} in the ledger");
} else {
    $order = wc_get_order((int) $ledger[$victim]);
    $order && $order->delete(true);
    unset($ledger[$victim]);
    update_option(Seeder::ORDER_LEDGER_OPTION, $ledger, false);

    $pendingBefore = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ac_notifications WHERE status = %s",
        NotificationRepository::STATUS_PENDING
    ));

    $rerun = $seeder->seed();

    $pendingAfter = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ac_notifications WHERE status = %s",
        NotificationRepository::STATUS_PENDING
    ));

    ac_assert('the removed order is recreated', $rerun['orders']['created'] === 1
        ? true : $rerun['orders']['created'] . ' created');
    ac_assert('it queued notifications', $rerun['notifications_discarded'] > 0
        ? true : 'nothing was queued, so nothing was proved');
    ac_assert('and they were discarded', $pendingBefore === $pendingAfter
        ? true : "pending went from {$pendingBefore} to {$pendingAfter}");

    // --keep-notifications is the opposite door, and it has to actually open.
    $ledger = get_option(Seeder::ORDER_LEDGER_OPTION, []);
    $order = isset($ledger[$victim]) ? wc_get_order((int) $ledger[$victim]) : false;
    $order && $order->delete(true);
    unset($ledger[$victim]);
    update_option(Seeder::ORDER_LEDGER_OPTION, $ledger, false);

    $watermark = $notifications->maxId();
    $kept = $seeder->seed(false, true);

    $queued = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ac_notifications WHERE id > %d",
        $watermark
    ));

    ac_assert('--keep-notifications keeps them', $queued > 0 && $kept['notifications_discarded'] === 0
        ? true : "{$queued} rows kept, {$kept['notifications_discarded']} discarded");

    // Left behind, they would be drained by the next `send-notifications` run
    // against an order nobody placed.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}ac_notifications WHERE id > %d AND status = %s",
        $watermark,
        NotificationRepository::STATUS_PENDING
    ));
}

/*
 * The other mailer, and the reason it is handled differently. WC_Emails sends
 * synchronously inside the status transition, so there is nothing to discard
 * afterwards — the seeder short-circuits wp_mail for the duration instead. What
 * is asserted is that it puts the filter back: a seeder that left wp_mail
 * short-circuited would silence every later suite's mail and every real send in
 * the same process.
 */
ac_assert('wp_mail is not left short-circuited', !has_filter('pre_wp_mail', '__return_true'));

// ------------------------------------------------------------------ summary --
echo PHP_EOL;
printf(
    "%d passed, %d failed%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
