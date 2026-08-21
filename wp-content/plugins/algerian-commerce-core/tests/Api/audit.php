<?php
/**
 * GET /audit-logs — the trail, and the five filters it publishes.
 *
 * There was no suite for this route. Its assertions lived scattered across the
 * eight suites whose writes it records — `users.php` checks that a role change
 * names both roles, `cms.php` that a page edit records field names — which
 * covers the *writing* thoroughly and the *reading* not at all. The gap showed
 * on the admin panel's `feat/admin` branch: two of the five filters
 * ADMIN_PANEL.md names were **accepted and silently ignored**, and nothing
 * anywhere could have noticed, because every existing caller filters by
 * `action` and every one of them works.
 *
 * Measured 2026-08-21, before this branch, against 16 632 live rows:
 *
 *     ?actor_id=475                 874 of 16 632   honoured
 *     ?action=notification.retried   84             honoured
 *     ?resource_type=notification    84             honoured
 *     ?resource_id=4640          16 632             ACCEPTED AND IGNORED
 *     ?date_from= / ?date_to=    16 632             ACCEPTED AND IGNORED
 *
 * §65's failure mode exactly: a filter that does not filter is indistinguishable
 * from a collection that all matches. So the floor below is not "the filter
 * answered 200" — every one of them already did — but **that the filtered set is
 * strictly smaller than the unfiltered one, and that the rows it left out are
 * the ones it should have**. Both directions, on every filter, including the
 * three that already worked: a positive control on a filter that works is what
 * proves the assertion is capable of failing.
 *
 * In-process via rest_do_request(), which exercises routing, the args schema
 * and the permission callback. It does not parse an Authorization header, so
 * authentication lives in scripts/test-api.sh.
 *
 *   scripts/test.sh                              # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/audit.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array|string|null $body = null, array $query = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(is_string($body) ? $body : wp_json_encode($body));
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

function ac_clear_rate_limits(): void
{
    global $wpdb;

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ac_rl_%'");
}

/** `meta.total` off a paginated envelope, as an int. */
function ac_total(array $result): int
{
    return (int) ($result[1]['meta']['total'] ?? -1);
}

ac_clear_rate_limits();

use AlgerianCommerce\Audit\AuditEvent;
use AlgerianCommerce\Audit\AuditRepository;
use AlgerianCommerce\Core\Plugin;

global $wpdb;

$super = ac_user('ac_audit_super', 'ac_super_admin');
$manager = ac_user('ac_audit_manager', 'ac_manager');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /audit-logs signed out', ac_req('GET', '/audit-logs'), 401);

/*
 * A **Manager** rather than a Support Agent, because the Manager is the live
 * tier after the two-tier collapse and holds ten other management capabilities
 * — so a 403 here is the trail refusing somebody who can already read almost
 * everything else, which is the boundary worth asserting.
 */
wp_set_current_user($manager);
ac_check('GET /audit-logs as manager', ac_req('GET', '/audit-logs'), 403);

wp_set_current_user($super);
ac_check('GET /audit-logs as super admin', ac_req('GET', '/audit-logs'), 200);

echo PHP_EOL, "=== the fixture ===", PHP_EOL;

/*
 * Written straight into the repository rather than provoked through the eight
 * routes that write the trail. Deliberate, and the opposite of what
 * seed-notifications.mjs argues: there, the states only mean something if the
 * drain produced them, because the *state machine* was under test. Here the
 * query is under test, and what it needs is rows whose actor, action, type, id
 * and **timestamp** are all known exactly — a date range cannot be asserted
 * against rows the writer stamps `now`, because every one of them lands in the
 * same day and no bound separates them.
 *
 * `AuditRepository::insert()` is the production writer and `AuditEvent` the
 * production shape, so nothing here bypasses validation, clipping or
 * redaction; only the clock is chosen.
 */
$repo = new AuditRepository($wpdb);
$table = $repo->table();
$marker = 'ac_audit_suite';

// Re-runnable: this file's own rows go before it writes them again, and
// nothing else's do. Every suite here has to survive a second run.
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE resource_type = %s", $marker));

$before = ac_total(ac_req('GET', '/audit-logs', null, ['per_page' => 1]));

$fixtures = [
    // action                 resource_id   created_at (UTC)
    ['suite.alpha',   'r-100', '2019-03-01 08:00:00'],
    ['suite.alpha',   'r-100', '2019-03-02 08:00:00'],
    ['suite.alpha',   'r-200', '2019-03-03 08:00:00'],
    ['suite.beta',    'r-200', '2019-03-04 08:00:00'],
    ['suite.beta',    'conditions', '2019-03-05 08:00:00'],
];

foreach ($fixtures as [$action, $resourceId, $createdAt]) {
    $repo->insert(new AuditEvent(
        $action,
        $marker,
        $resourceId,
        $super,
        'ac_audit_super',
        '203.0.113.9',
        ['field' => 'value', 'password' => 'must-not-survive'],
        $createdAt
    ));
}

$mine = ac_check('the fixture is readable as its own resource type',
    ac_req('GET', '/audit-logs', null, ['resource_type' => $marker, 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 5 ? true : 'got ' . count($d['data']) . ' rows');

$total = ac_total(ac_req('GET', '/audit-logs', null, ['per_page' => 1]));

ac_assert('the trail grew by exactly five', $total === $before + 5
    ? true : "{$before} → {$total}");

/*
 * The floor every assertion below rests on. If the shop's trail were only five
 * rows, "the filter returned fewer than the whole" would be true of a filter
 * that returns nothing and of one that works, and this suite would pass either
 * way. It is 16 000-odd rows in this shop and at least six in the emptiest
 * possible one.
 */
ac_assert('and there is a corpus to filter against', $total > 5 ? true : "only {$total} rows");

echo PHP_EOL, "=== redaction, which is written before the row is ===", PHP_EOL;

ac_assert('metadata records the field name', ($mine['data'][0]['metadata']['field'] ?? null) === 'value');
ac_assert('and redacts what it must',
    ($mine['data'][0]['metadata']['password'] ?? '') === '[redacted]'
        ? true : wp_json_encode($mine['data'][0]['metadata'] ?? null));

ac_assert('created_at carries no offset — it is UTC by construction',
    (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($mine['data'][0]['created_at'] ?? '')));

echo PHP_EOL, "=== the three filters that already worked ===", PHP_EOL;

/*
 * Positive controls. Asserting a *newly fixed* filter narrows proves nothing
 * unless an already-working one narrows the same way in the same run — that is
 * what distinguishes "the clause landed" from "the corpus happens to be small".
 */
$byAction = ac_check('?action= narrows',
    ac_req('GET', '/audit-logs', null, ['action' => 'suite.alpha', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 3 ? true : 'got ' . count($d['data']));

ac_assert('?action= is strictly smaller than the whole',
    ac_total(ac_req('GET', '/audit-logs', null, ['action' => 'suite.alpha', 'per_page' => 1])) < $total);

ac_assert('and every row it returned matches',
    array_values(array_unique(array_column($byAction['data'], 'action'))) === ['suite.alpha']);

ac_check('?resource_type= narrows',
    ac_req('GET', '/audit-logs', null, ['resource_type' => $marker, 'per_page' => 1]), 200,
    fn ($d) => (int) $d['meta']['total'] === 5 ? true : 'total ' . $d['meta']['total']);

ac_check('?actor_id= narrows',
    ac_req('GET', '/audit-logs', null, ['actor_id' => $super, 'resource_type' => $marker, 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 5 ? true : 'got ' . count($d['data']));

ac_check('a nonexistent actor is an empty page, not a 400',
    ac_req('GET', '/audit-logs', null, ['actor_id' => 99999999]), 200,
    fn ($d) => $d['data'] === [] && (int) $d['meta']['total'] === 0);

echo PHP_EOL, "=== ?resource_id=, which was accepted and ignored ===", PHP_EOL;

/*
 * This is how somebody gets from an audited object to its history, and it is
 * named in ADMIN_PANEL.md as though it had always worked. The clause was in
 * AuditRepository::buildWhere() from the beginning; the route never declared
 * the argument, so WP_REST_Request dropped it before the controller looked.
 */
ac_check('?resource_id= narrows within a type',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'resource_id' => 'r-100', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 2 ? true : 'got ' . count($d['data']));

ac_check('and pairs with an action',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'resource_id' => 'r-200', 'action' => 'suite.beta', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 1 ? true : 'got ' . count($d['data']));

$loose = ac_check('?resource_id= alone is strictly smaller than the whole',
    ac_req('GET', '/audit-logs', null, ['resource_id' => 'r-100', 'per_page' => 1]), 200,
    fn ($d) => (int) $d['meta']['total'] < $total ? true : 'total ' . $d['meta']['total'] . " of {$total}");

ac_assert('and it matched something rather than nothing',
    (int) ($loose['meta']['total'] ?? 0) >= 2
        ? true : 'total ' . ($loose['meta']['total'] ?? '?'));

/*
 * A string, not an integer, and this is the assertion that pins it. The column
 * is varchar(64) because a page is audited by **path**, a FAQ category by
 * **slug** and a menu by **location**; `absint` on the argument would turn
 * `conditions` into 0 and match every row that has no resource id at all —
 * which, on an unpruned trail, is most of them.
 */
ac_check('a non-numeric resource id is a real filter, not a zero',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'resource_id' => 'conditions', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 1 && $d['data'][0]['action'] === 'suite.beta'
        ? true : 'got ' . wp_json_encode(array_column($d['data'], 'resource_id')));

ac_check('an unmatched resource id is an empty page, not a 400',
    ac_req('GET', '/audit-logs', null, ['resource_id' => 'no-such-thing-anywhere']), 200,
    fn ($d) => $d['data'] === [] && (int) $d['meta']['total'] === 0);

ac_check('and one over the column width is refused rather than clipped',
    ac_req('GET', '/audit-logs', null, ['resource_id' => str_repeat('x', 65)]), 400);

echo PHP_EOL, "=== the date range, which was accepted and ignored ===", PHP_EOL;

/*
 * 16 632 rows at 20 a page is 832 pages. The range is the difference between a
 * screen an operator can use and one they scroll; `KEY created_at` has been on
 * the table since migration 001 for exactly this query.
 */
ac_check('?date_from= excludes what is older',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'date_from' => '2019-03-03', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 3 ? true : 'got ' . count($d['data']));

ac_check('?date_to= excludes what is newer',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'date_to' => '2019-03-02', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 2 ? true : 'got ' . count($d['data']));

ac_check('the two together are a window',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'date_from' => '2019-03-02', 'date_to' => '2019-03-04', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 3 ? true : 'got ' . count($d['data']));

// Both ends cover the whole day: the fixture rows are stamped 08:00:00, so a
// bound that stopped at midnight would return nothing for a single-day window.
ac_check('a single day covers the whole of it',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'date_from' => '2019-03-05', 'date_to' => '2019-03-05', 'per_page' => 50]), 200,
    fn ($d) => count($d['data']) === 1 ? true : 'got ' . count($d['data']));

ac_check('a range over the whole trail is strictly smaller than the whole',
    ac_req('GET', '/audit-logs', null, ['date_to' => '2019-12-31', 'per_page' => 1]), 200,
    fn ($d) => (int) $d['meta']['total'] < $total && (int) $d['meta']['total'] >= 5
        ? true : 'total ' . $d['meta']['total'] . " of {$total}");

ac_check('an inverted window is empty, not an error',
    ac_req('GET', '/audit-logs', null,
        ['resource_type' => $marker, 'date_from' => '2019-03-05', 'date_to' => '2019-03-01']), 200,
    fn ($d) => $d['data'] === [] && (int) $d['meta']['total'] === 0);

/*
 * `Y-m-d`, refused by pattern rather than coerced. A date the query cannot use
 * has to fail loudly here: coercing it is how the range silently stopped
 * filtering in the first place.
 */
ac_check('a malformed date is a 400', ac_req('GET', '/audit-logs', null, ['date_from' => 'yesterday']), 400);
ac_check('and so is a datetime', ac_req('GET', '/audit-logs', null, ['date_to' => '2019-03-05 08:00:00']), 400);

echo PHP_EOL, "=== what is still not a filter ===", PHP_EOL;

/*
 * Recorded as facts rather than fixed. `?search=` has no column to search — the
 * trail stores field *names*, not values, so a free-text box would search
 * nothing a reader is looking for; `?orderby=` has no second ordering worth
 * offering over an append-only table whose id order is its time order. The
 * admin panel offers neither control, and these two assertions are why.
 */
ac_assert('?search= is accepted and ignored',
    ac_total(ac_req('GET', '/audit-logs', null, ['search' => 'zzzz', 'per_page' => 1])) === $total);

ac_assert('?orderby= is accepted and ignored',
    ac_total(ac_req('GET', '/audit-logs', null, ['orderby' => 'nonsense', 'per_page' => 1])) === $total);

$asc = ac_req('GET', '/audit-logs', null, ['resource_type' => $marker, 'order' => 'asc', 'per_page' => 50]);
ac_assert('the order is newest first and cannot be reversed',
    ($asc[1]['data'][0]['action'] ?? '') === 'suite.beta'
        ? true : 'first row was ' . ($asc[1]['data'][0]['action'] ?? 'nothing'));

echo PHP_EOL, "=== teardown ===", PHP_EOL;

$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE resource_type = %s", $marker));

ac_assert('the fixture rows are gone',
    ac_total(ac_req('GET', '/audit-logs', null, ['resource_type' => $marker, 'per_page' => 1])) === 0);

ac_assert('and the trail is back to what it was',
    ac_total(ac_req('GET', '/audit-logs', null, ['per_page' => 1])) === $before);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
