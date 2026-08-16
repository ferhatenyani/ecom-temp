<?php
/**
 * Shopper accounts and order history — roadmap §59c, §65.
 *
 * **This suite is the one docs/SECURITY.md has been pointing at since §65.**
 * Order history is where this project's first real IDOR lands, and the whole
 * point of §59c is that the check lives here rather than in the storefront. So
 * the assertion that matters is written in the shape §65 insists on:
 *
 *     customer A is refused customer B's order   AND   A is served their own
 *
 * A refusal on its own proves only that the route is broken. Both halves, and
 * against a *real* second account with a *real* order, or the pair proves
 * nothing.
 *
 * It also asserts the two rules §44 laid down for this section: a customer
 * never receives an Application Password, and a staff account cannot use the
 * customer door.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/account.php
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

// ------------------------------------------------------------------ fixtures --
$EMAIL_A = 'ac-account-a@example.test';
$EMAIL_B = 'ac-account-b@example.test';
$PASS_A = 'CorrectHorseBatteryA';
$PASS_B = 'CorrectHorseBatteryB';

foreach ([$EMAIL_A, $EMAIL_B, 'ac-account-evil@example.test'] as $email) {
    $existing = get_user_by('email', $email);

    if ($existing) {
        wp_delete_user($existing->ID);
    }
}

echo PHP_EOL, "── register and sign in ──", PHP_EOL;

$registered = ac_check(
    'register a shopper',
    ac_req('POST', '/account/register', [
        'email' => $EMAIL_A, 'password' => $PASS_A, 'first_name' => 'Amina', 'last_name' => 'B',
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['token'] ?? '') !== '' ? true : 'no session token came back'
);

$idA = (int) ($registered['data']['customer']['id'] ?? 0);
$tokenA = (string) ($registered['data']['token'] ?? '');

ac_assert('the account exists', $idA > 0 ?: 'no id');
ac_assert(
    'and it is a plain customer',
    in_array('customer', (array) get_user_by('id', $idA)->roles, true)
        ?: 'roles are ' . implode(',', (array) get_user_by('id', $idA)->roles)
);

/*
 * §44: "customers never receive an Application Password (server-to-server
 * only)". Asserted rather than assumed, because the failure is silent — an
 * account that quietly held one would authenticate against every staff route
 * in the API with the credential this module handed it.
 */
ac_assert(
    'no Application Password was issued to the shopper',
    WP_Application_Passwords::get_user_application_passwords($idA) === []
        ?: 'the shopper holds an application password'
);

ac_check(
    'the same email cannot register twice',
    ac_req('POST', '/account/register', ['email' => $EMAIL_A, 'password' => $PASS_A]),
    409
);

$registeredB = ac_check(
    'register a second shopper',
    ac_req('POST', '/account/register', ['email' => $EMAIL_B, 'password' => $PASS_B, 'first_name' => 'Yacine']),
    201
);
$idB = (int) ($registeredB['data']['customer']['id'] ?? 0);
$tokenB = (string) ($registeredB['data']['token'] ?? '');

ac_check(
    'sign in with the right password',
    ac_req('POST', '/account/login', ['email' => $EMAIL_A, 'password' => $PASS_A]),
    200,
    static fn (array $d): bool|string => ($d['data']['token'] ?? '') !== '' ? true : 'no token'
);

ac_check(
    'a wrong password is refused',
    ac_req('POST', '/account/login', ['email' => $EMAIL_A, 'password' => 'not the password']),
    401
);

ac_check(
    'an unknown address is refused the same way',
    ac_req('POST', '/account/login', ['email' => 'nobody@example.test', 'password' => $PASS_A]),
    401,
    // The same message for both, or the endpoint tells an attacker which
    // addresses are registered.
    static fn (array $d): bool|string => ($d['error']['message'] ?? '') === 'Those credentials are not valid.'
        ? true
        : 'the message differs from the wrong-password one: ' . ($d['error']['message'] ?? '')
);

echo PHP_EOL, "── THE IDOR ──", PHP_EOL;

// Real orders for both shoppers, plus a guest order that belongs to nobody.
$orderA = wc_create_order(['status' => 'processing', 'customer_id' => $idA]);
$orderA->set_total('100');
$orderA->save();

$orderB = wc_create_order(['status' => 'processing', 'customer_id' => $idB]);
$orderB->set_total('200');
$orderB->save();

$guestOrder = wc_create_order(['status' => 'processing']);
$guestOrder->set_total('300');
$guestOrder->save();

ac_assert('both shoppers have an order', $orderA->get_id() > 0 && $orderB->get_id() > 0 ?: 'orders missing');

// THE PAIR. Neither half means anything alone.
$orderAId = $orderA->get_id();

ac_check(
    'A is served their own order',
    ac_req('GET', "/account/orders/{$orderAId}", null, ['customer_token' => $tokenA]),
    200,
    static fn (array $d): bool|string => (int) ($d['data']['id'] ?? 0) === $orderAId
        ? true
        : 'the wrong order came back'
);

ac_check(
    "A is refused B's order",
    ac_req('GET', "/account/orders/{$orderB->get_id()}", null, ['customer_token' => $tokenA]),
    403
);

ac_check(
    "B is refused A's order",
    ac_req('GET', "/account/orders/{$orderA->get_id()}", null, ['customer_token' => $tokenB]),
    403
);

ac_check(
    'B is served their own',
    ac_req('GET', "/account/orders/{$orderB->get_id()}", null, ['customer_token' => $tokenB]),
    200
);

/*
 * A guest order belongs to nobody (`customer_id` 0), so no session can claim
 * it. Deliberate rather than an oversight: the only evidence linking a shopper
 * to a guest order is an email address, and treating that as proof of ownership
 * would make the order readable by anyone who could name the address on it.
 */
ac_check(
    'nobody can claim a guest order',
    ac_req('GET', "/account/orders/{$guestOrder->get_id()}", null, ['customer_token' => $tokenA]),
    403
);

echo PHP_EOL, "── the list cannot be redirected ──", PHP_EOL;

$listA = ac_check(
    "A's list holds only A's orders",
    ac_req('GET', '/account/orders', null, ['customer_token' => $tokenA]),
    200,
    static function (array $d) use ($orderA): bool|string {
        $ids = array_map(static fn (array $o): int => (int) $o['id'], $d['data'] ?? []);

        return $ids === [$orderA->get_id()] ? true : 'ids were ' . wp_json_encode($ids);
    }
);

/*
 * §59c's first rule, tested as a property rather than trusted: there is no
 * parameter that could name another customer, so trying every plausible
 * spelling must change nothing.
 */
foreach (['customer_id', 'customer', 'user_id', 'id', 'author'] as $param) {
    ac_check(
        "?{$param}=B does not redirect the list" . str_pad('', 22 - strlen($param)),
        ac_req('GET', '/account/orders', null, ['customer_token' => $tokenA, $param => $idB]),
        200,
        static function (array $d) use ($orderA): bool|string {
            $ids = array_map(static fn (array $o): int => (int) $o['id'], $d['data'] ?? []);

            return $ids === [$orderA->get_id()] ? true : 'the list changed to ' . wp_json_encode($ids);
        }
    );
}

echo PHP_EOL, "── no session, forged session ──", PHP_EOL;

foreach ([
    ['GET', '/account'],
    ['PATCH', '/account'],
    ['POST', '/account/password'],
    ['GET', '/account/orders'],
    ['POST', '/account/logout'],
] as [$method, $route]) {
    ac_check(
        "{$method} {$route} with no session" . str_pad('', 26 - strlen($route)),
        ac_req($method, $route, $method === 'GET' ? null : []),
        401
    );
}

ac_check(
    'a forged session token is refused',
    ac_req('GET', '/account', null, ['customer_token' => 'ac-account-a@example.test|9999999999|abc|def']),
    401
);

ac_check(
    'a truncated session token is refused',
    ac_req('GET', '/account', null, ['customer_token' => substr($tokenA, 0, 40)]),
    401
);

// The control: the same route with the real token must work, or every refusal
// above is indistinguishable from a broken route.
ac_check(
    'the real session still works',
    ac_req('GET', '/account', null, ['customer_token' => $tokenA]),
    200,
    static fn (array $d): bool|string => ($d['data']['email'] ?? '') === $EMAIL_A
        ? true
        : 'the profile was for ' . ($d['data']['email'] ?? '?')
);

echo PHP_EOL, "── escalation ──", PHP_EOL;

foreach (['role', 'roles', 'capabilities', 'user_pass', 'id', 'user_login'] as $field) {
    ac_check(
        "registration refuses {$field}" . str_pad('', 30 - strlen($field)),
        ac_req('POST', '/account/register', [
            'email' => 'ac-account-evil@example.test', 'password' => 'CorrectHorseBattery9', $field => 'administrator',
        ]),
        400
    );
}

foreach (['roles', 'capabilities', 'user_pass'] as $field) {
    ac_check(
        "PATCH /account refuses {$field}" . str_pad('', 27 - strlen($field)),
        ac_req('PATCH', '/account', [$field => 'administrator'], ['customer_token' => $tokenA]),
        400
    );
}

$after = get_user_by('id', $idA);
ac_assert(
    'the shopper is still only a customer',
    in_array('customer', (array) $after->roles, true) && count((array) $after->roles) === 1
        ?: 'roles are now ' . implode(',', (array) $after->roles)
);
ac_assert(
    'and holds no management capability',
    !user_can($after, 'manage_options') && !user_can($after, 'ac_manage_orders')
        && !user_can($after, 'ac_manage_customers')
        ?: 'the shopper gained a capability'
);

/*
 * §44 again: the customer door is not a second login for staff. Without this
 * rule an administrator could trade an Application Password for a bearer token
 * that the brute-force guard never watches.
 */
$staffEmail = 'ac-account-staff@example.test';
$staff = get_user_by('email', $staffEmail);

if (!$staff) {
    $staffId = wp_insert_user([
        'user_login' => 'ac_account_staff', 'user_email' => $staffEmail,
        'user_pass' => 'StaffPassphrase123', 'role' => 'ac_admin',
    ]);
    $staff = get_user_by('id', $staffId);
} else {
    $staff->set_role('ac_admin');
    wp_set_password('StaffPassphrase123', $staff->ID);
}

ac_check(
    'a staff account cannot sign in as a shopper',
    ac_req('POST', '/account/login', ['email' => $staffEmail, 'password' => 'StaffPassphrase123']),
    401
);

echo PHP_EOL, "── profile and password ──", PHP_EOL;

ac_check(
    'the profile can be edited',
    ac_req('PATCH', '/account', ['first_name' => 'Amina-Zohra'], ['customer_token' => $tokenA]),
    200,
    static fn (array $d): bool|string => ($d['data']['first_name'] ?? '') === 'Amina-Zohra'
        ? true
        : 'first_name is ' . ($d['data']['first_name'] ?? '?')
);

ac_check(
    'the address book can be edited',
    ac_req('PATCH', '/account', [
        'billing' => ['first_name' => 'Amina', 'address_1' => '12 rue X', 'city' => 'Alger', 'country' => 'DZ'],
    ], ['customer_token' => $tokenA]),
    200
);

ac_check(
    'a password change needs the current one',
    ac_req('POST', '/account/password', [
        'current_password' => 'wrong', 'new_password' => 'BrandNewPassphrase1',
    ], ['customer_token' => $tokenA]),
    400
);

$changed = ac_check(
    'a password change succeeds and re-issues the session',
    ac_req('POST', '/account/password', [
        'current_password' => $PASS_A, 'new_password' => 'BrandNewPassphrase1',
    ], ['customer_token' => $tokenA]),
    200,
    static fn (array $d): bool|string => ($d['data']['token'] ?? '') !== '' ? true : 'no fresh token'
);

/*
 * WordPress's auth-cookie HMAC covers a fragment of the password hash, so
 * changing the password invalidates every session that existed before it —
 * including a stolen one. Nothing in this module arranges that; it is why the
 * token is core's format rather than one of ours.
 */
ac_check(
    'the old session died with the old password',
    ac_req('GET', '/account', null, ['customer_token' => $tokenA]),
    401
);

$freshToken = (string) ($changed['data']['token'] ?? '');

ac_check(
    'the re-issued session works',
    ac_req('GET', '/account', null, ['customer_token' => $freshToken]),
    200
);

ac_check(
    'the new password signs in',
    ac_req('POST', '/account/login', ['email' => $EMAIL_A, 'password' => 'BrandNewPassphrase1']),
    200
);

echo PHP_EOL, "── logout ──", PHP_EOL;

ac_check('sign out', ac_req('POST', '/account/logout', [], ['customer_token' => $freshToken]), 200);
ac_check(
    'the session is dead afterwards',
    ac_req('GET', '/account', null, ['customer_token' => $freshToken]),
    401
);

// ------------------------------------------------------------------ cleanup --
$orderA->delete(true);
$orderB->delete(true);
$guestOrder->delete(true);

foreach ([$EMAIL_A, $EMAIL_B, 'ac-account-evil@example.test', $staffEmail] as $email) {
    $existing = get_user_by('email', $email);

    if ($existing) {
        wp_delete_user($existing->ID);
    }
}

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
