<?php
/**
 * Client configuration — roadmap §71, docs/PLAN.md §48, §65's eight API lines.
 *
 * `SettingsInputTest` covers the rules against synthetic payloads. What only
 * this suite can prove is the property the whole module exists for: **the
 * document is assembled from the systems that own each value, not copied from
 * them.** So the assertions that matter here change a value at its source —
 * `blogname` through WordPress, the currency through WooCommerce — and then
 * check the document followed, which a copy would not.
 *
 * It also proves the capability. `ac_manage_settings` had existed since §45's
 * matrix with no call site; the interesting half is not that Super Admin may
 * read it, but that **Admin may not**, because that is what makes it a real
 * boundary rather than a name.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/settings.php
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, ?array $body = null, array $query = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);
    foreach ($query as $key => $value) { $request->set_param($key, $value); }
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
        if ($verdict !== true) { $ok = false; $detail = ' — ' . (is_string($verdict) ? $verdict : 'body check failed'); }
    }
    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;
    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 60), ' ', str_pad((string) $status, 4);
    if (!$ok) { echo "(expected {$expect}){$detail} ", substr((string) wp_json_encode($data), 0, 260); }
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
    if ($user) { $user->set_role($role); return (int) $user->ID; }
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(24),
                          'user_email' => $login . '@example.test', 'role' => $role]);
    return is_wp_error($id) ? 0 : (int) $id;
}

use AlgerianCommerce\Settings\SettingsRepository;

// Restored at the end: this suite writes to the live configuration, and the
// next suite along should not inherit a shop called "AC Suite Store".
$RESTORE = [
    'option' => get_option(SettingsRepository::OPTION, []),
    'name' => get_option('blogname'),
    'description' => get_option('blogdescription'),
];

$super = ac_user('ac_settings_super', 'ac_super_admin');
$admin = ac_user('ac_settings_admin', 'ac_admin');
wp_set_current_user($super);

// ----------------------------------------------------------------- success --
echo PHP_EOL, "── success ──", PHP_EOL;

$document = ac_check('GET /settings', ac_req('GET', '/settings'), 200, static function (array $d): bool|string {
    foreach (['store', 'contact', 'legal', 'social', 'features', 'providers'] as $block) {
        if (!isset($d['data'][$block])) { return "missing block: {$block}"; }
    }
    return true;
});

ac_check(
    'PATCH /settings writes the blocks it names',
    ac_req('PATCH', '/settings', [
        'store' => ['name' => 'AC Suite Store', 'storefront_url' => 'https://suite.example.test'],
        'contact' => ['email' => 'suite@example.test', 'phone' => '0551020304'],
        'legal' => ['rc' => '16/00-7654321B25'],
    ]),
    200,
    static fn (array $d): bool|string => ($d['data']['store']['name'] ?? '') === 'AC Suite Store'
        && ($d['data']['legal']['rc'] ?? '') === '16/00-7654321B25'
        ? true : 'the response did not carry the write back'
);

ac_check(
    'a partial write leaves the other blocks alone',
    ac_req('PATCH', '/settings', ['social' => ['facebook' => 'https://facebook.com/suite']]),
    200,
    static fn (array $d): bool|string => ($d['data']['contact']['email'] ?? '') === 'suite@example.test'
        ? true : 'the contact block was blanked by a write that never mentioned it'
);

ac_check(
    'a field clears',
    ac_req('PATCH', '/settings', ['contact' => ['phone' => null]]),
    200,
    static fn (array $d): bool|string => ($d['data']['contact']['phone'] ?? 'x') === ''
        ? true : 'the phone survived being cleared'
);

// ------------------------------------------------- assembled, never copied --
echo PHP_EOL, "── assembled from the owners, not copied ──", PHP_EOL;

/*
 * The property the module exists for. `store.name` is written *through* to
 * WordPress rather than kept in `ac_client_settings`, so changing `blogname`
 * behind the API's back must move the document. A copy would not follow.
 */
update_option('blogname', 'Renamed Behind Its Back');
[$status, $data] = ac_req('GET', '/settings');
ac_assert(
    'the store name follows WordPress, live',
    ($data['data']['store']['name'] ?? '') === 'Renamed Behind Its Back'
        ? true : 'got ' . ($data['data']['store']['name'] ?? '?')
);

ac_assert(
    'and it is not duplicated into the settings option',
    !isset(((array) get_option(SettingsRepository::OPTION, []))['store']['name'])
        ? true : 'ac_client_settings holds its own copy of store.name'
);

$currency = get_option('woocommerce_currency');
update_option('woocommerce_currency', 'EUR');
[$status, $data] = ac_req('GET', '/settings');
ac_assert(
    'the currency follows WooCommerce, live',
    ($data['data']['store']['currency'] ?? '') === 'EUR'
        ? true : 'got ' . ($data['data']['store']['currency'] ?? '?')
);
update_option('woocommerce_currency', $currency);

/*
 * The flags are the environment's, and the registries are what actually loaded.
 * Reporting both is the point: a flag that is on with no credentials produces a
 * provider that never registers, and this is the only place that gap shows.
 */
[$status, $data] = ac_req('GET', '/settings');
ac_assert(
    'every declared feature flag is reported',
    count($data['data']['features'] ?? []) === count(AlgerianCommerce\Core\Config::FLAGS)
        ? true : count($data['data']['features'] ?? []) . ' of ' . count(AlgerianCommerce\Core\Config::FLAGS)
);
ac_assert(
    'the registered providers are reported',
    in_array('cod', $data['data']['providers']['payment'] ?? [], true)
        && in_array('manual', $data['data']['providers']['shipping'] ?? [], true)
        ? true : wp_json_encode($data['data']['providers'] ?? [])
);

// -------------------------------------------------------------- bad input --
echo PHP_EOL, "── bad input ──", PHP_EOL;

ac_check(
    'currency is refused by name, with the reason',
    ac_req('PATCH', '/settings', ['currency' => 'USD']),
    400,
    static fn (array $d): bool|string => str_contains(
        (string) ($d['error']['details']['fields']['currency'] ?? ''),
        'per order'
    ) ? true : 'the refusal did not explain itself'
);

ac_check(
    'feature flags are refused by name',
    ac_req('PATCH', '/settings', ['features' => ['cod' => false]]),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['features'])
);

ac_check(
    'a secret is refused by name',
    ac_req('PATCH', '/settings', ['api_key' => 'sk_live_whatever']),
    400,
    static fn (array $d): bool|string => str_contains(
        (string) ($d['error']['details']['fields']['api_key'] ?? ''),
        'environment'
    ) ? true : 'the refusal did not point at the environment'
);

ac_check(
    'a javascript: URL is refused',
    ac_req('PATCH', '/settings', ['store' => ['storefront_url' => 'javascript:alert(1)']]),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['store.storefront_url'])
);

ac_check('an unknown block is refused', ac_req('PATCH', '/settings', ['shipping' => []]), 400);
ac_check('an empty write is refused', ac_req('PATCH', '/settings', []), 400);

/*
 * WordPress keeps posts, products, orders and attachments in one id space, so
 * an unchecked logo_id is the type confusion §65 asserted everywhere else.
 */
$page = wp_insert_post(['post_title' => 'ac-settings-not-an-image', 'post_type' => 'page', 'post_status' => 'draft']);
ac_check(
    'a logo_id that is not an image is refused',
    ac_req('PATCH', '/settings', ['store' => ['logo_id' => $page]]),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['store.logo_id'])
);
wp_delete_post($page, true);

// ----------------------------------------- unauthenticated and unauthorized --
echo PHP_EOL, "── unauthenticated and unauthorized ──", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /settings unauthenticated', ac_req('GET', '/settings'), 401);
ac_check('PATCH /settings unauthenticated', ac_req('PATCH', '/settings', ['contact' => []]), 401);

/*
 * The control that makes the refusal mean something. An Admin holds ten of the
 * eleven management capabilities and is refused here — so the 403 below is the
 * capability working, not an unreachable route.
 */
wp_set_current_user($admin);
ac_check('an Admin may read products', ac_req('GET', '/products'), 200);
ac_check('...but is refused the settings', ac_req('GET', '/settings'), 403);
ac_check('...and cannot write them', ac_req('PATCH', '/settings', ['legal' => ['rc' => 'x']]), 403);

wp_set_current_user($super);
ac_check('a Super Admin may read them', ac_req('GET', '/settings'), 200);

// ------------------------------------------------------------------ audit ---
echo PHP_EOL, "── the audit trail ──", PHP_EOL;

ac_req('PATCH', '/settings', ['legal' => ['nif' => '000116009999999']]);

global $wpdb;
$row = $wpdb->get_row(
    "SELECT action, metadata FROM {$wpdb->prefix}ac_audit_logs
      WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1",
    ARRAY_A
);

ac_assert('a settings write is audited', is_array($row) ? true : 'no audit row');
ac_assert(
    'the audit names the field',
    is_array($row) && str_contains((string) $row['metadata'], 'legal.nif')
        ? true : 'metadata: ' . substr((string) ($row['metadata'] ?? ''), 0, 120)
);
/*
 * By name, never by value. The configuration carries the client's legal
 * identity, and copying it into a table nobody ever cleans is how a trade
 * register number ends up in a log export years later.
 */
ac_assert(
    'the audit does not record the value',
    is_array($row) && !str_contains((string) $row['metadata'], '000116009999999')
        ? true : 'the audit row carries the value itself'
);

// ---------------------------------------------------------------- restore ---
update_option(SettingsRepository::OPTION, $RESTORE['option'], false);
update_option('blogname', $RESTORE['name']);
update_option('blogdescription', $RESTORE['description']);

[$status, $data] = ac_req('GET', '/settings');
ac_assert(
    'the suite left the configuration as it found it',
    ($data['data']['store']['name'] ?? '') === $RESTORE['name']
        ? true : 'store name is now ' . ($data['data']['store']['name'] ?? '?')
);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
