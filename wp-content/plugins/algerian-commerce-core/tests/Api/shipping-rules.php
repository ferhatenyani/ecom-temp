<?php
/**
 * Shipping rate rules against a real WordPress + WooCommerce install —
 * roadmap §4 step 28b, docs/PLAN.md §14, §65 (API and Security categories).
 *
 * Covers what unit tests structurally cannot: authorization (401/403), rules
 * validated against the real §51 geography, the unique-scope guard against rows
 * that actually exist, and the thing this phase exists to get right — that a
 * quote for a destination is the price the shop meant, with free delivery
 * applied when the basket earns it.
 *
 * **This suite deletes every rule it creates.** A shipping tariff is global
 * state: rules left behind would price destinations in every other suite, and
 * `shipping.php` — which sorts *after* this file — asserts what an unpriced
 * shop quotes. Cleanup runs at the end, and the DELETE endpoint gets exercised
 * on the way.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting are invisible here — those live
 * in scripts/test-api.sh, over real HTTP.
 *
 *   scripts/test.sh                                   # runs this and the rest
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/shipping-rules.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;
$GLOBALS['ac_rules'] = [];

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

/** Create a rule and remember it, so cleanup can remove it again. */
function ac_rule(array $body): int
{
    [, $data] = ac_req('POST', '/shipping/rules', $body);

    $id = (int) ($data['data']['id'] ?? 0);

    if ($id > 0) {
        $GLOBALS['ac_rules'][] = $id;
    }

    return $id;
}

/** The quotes for a destination, keyed by source. */
function ac_quotes(array $query): array
{
    [, $data] = ac_req('GET', '/shipping/rates', null, $query);

    return $data['data'] ?? [];
}

function ac_quote_from(array $quotes, string $source): ?array
{
    foreach ($quotes as $quote) {
        if (($quote['source'] ?? '') === $source) {
            return $quote;
        }
    }

    return null;
}

function ac_audit_actions(int $ruleId): array
{
    global $wpdb;

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT action FROM {$wpdb->prefix}ac_audit_logs
             WHERE resource_type = 'shipping_rule' AND resource_id = %s ORDER BY id ASC",
            (string) $ruleId
        )
    );

    return is_array($rows) ? $rows : [];
}

$manager = ac_user('ac_rules_manager', 'ac_order_manager');   // ac_manage_shipping
$support = ac_user('ac_rules_support', 'ac_support_agent');   // not

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET rules signed out', ac_req('GET', '/shipping/rules'), 401);
ac_check('POST a rule signed out', ac_req('POST', '/shipping/rules', ['amount' => '500']), 401);
ac_check('DELETE a rule signed out', ac_req('DELETE', '/shipping/rules/1'), 401);

wp_set_current_user($support);
ac_check('GET rules as support agent', ac_req('GET', '/shipping/rules'), 403);
// A price list is what the shop charges; changing it is not a support job.
ac_check('POST a rule as support agent', ac_req('POST', '/shipping/rules', ['amount' => '500']), 403);
ac_check('DELETE a rule as support agent', ac_req('DELETE', '/shipping/rules/1'), 403);

wp_set_current_user($manager);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

[, $wilayas] = ac_req('GET', '/locations/wilayas');
$wilayaId = (int) ($wilayas['data'][0]['id'] ?? 0);
$otherWilaya = (int) ($wilayas['data'][1]['id'] ?? 0);

[, $communes] = ac_req('GET', "/locations/wilayas/{$wilayaId}/communes");
$communeId = (int) ($communes['data'][0]['id'] ?? 0);

ac_assert('a wilaya to price', $wilayaId > 0 ?: 'no wilayas in the dataset');
ac_assert('a commune to price', $communeId > 0 ?: 'no communes in the dataset');

echo PHP_EOL, "=== creating rules: validation ===", PHP_EOL;

ac_check('a rule with no price', ac_req('POST', '/shipping/rules', ['wilaya_id' => $wilayaId]), 400, function ($d) {
    return isset($d['error']['details']['fields']['amount']) ?: 'expected an amount error';
});

ac_check('a negative price', ac_req('POST', '/shipping/rules', ['amount' => '-100']), 400);
ac_check('a price that is not a number', ac_req('POST', '/shipping/rules', ['amount' => 'gratuit']), 400);
ac_check('an unknown field', ac_req('POST', '/shipping/rules', ['amount' => '500', 'zone' => 'north']), 400);

// A commune belongs to exactly one wilaya, so this describes a place nothing
// can match — and it would sit in the table looking as though it worked.
ac_check('a commune with no wilaya', ac_req('POST', '/shipping/rules', [
    'commune_id' => $communeId,
    'amount' => '300',
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['wilaya_id']) ?: 'expected a wilaya_id error';
});

ac_check('a wilaya that does not exist', ac_req('POST', '/shipping/rules', [
    'wilaya_id' => 9999,
    'amount' => '300',
]), 400);

ac_check('a commune from a different wilaya', ac_req('POST', '/shipping/rules', [
    'wilaya_id' => $otherWilaya,
    'commune_id' => $communeId,
    'amount' => '300',
]), 400, function ($d) {
    return isset($d['error']['details']['fields']['wilaya_id']) ?: 'expected a wilaya_id error';
});

// A rule naming a courier the shop does not have can never match.
ac_check('a rule for a courier we do not have', ac_req('POST', '/shipping/rules', [
    'amount' => '500',
    'provider' => 'yalidine',
]), 400, function ($d) {
    return ($d['error']['details']['available'] ?? []) === ['manual'] ?: 'the refusal does not name what is available';
});

ac_check('an invented delivery type', ac_req('POST', '/shipping/rules', [
    'amount' => '500',
    'delivery_type' => 'drone',
]), 400);

echo PHP_EOL, "=== a tariff: a national rate and its exceptions ===", PHP_EOL;

$national = ac_rule(['amount' => '800', 'free_over' => '10000']);
ac_assert('a national rate', $national > 0 ?: 'not created');

$wilayaRule = ac_rule(['wilaya_id' => $wilayaId, 'amount' => '500', 'estimated_days' => 2]);
ac_assert('a cheaper rate for one wilaya', $wilayaRule > 0 ?: 'not created');

$communeRule = ac_rule(['wilaya_id' => $wilayaId, 'commune_id' => $communeId, 'amount' => '300']);
ac_assert('a cheaper rate again for one commune', $communeRule > 0 ?: 'not created');

ac_check('the same scope twice', ac_req('POST', '/shipping/rules', [
    'wilaya_id' => $wilayaId,
    'amount' => '650',
]), 409, function ($d) use ($wilayaRule) {
    return ($d['error']['details']['rule_id'] ?? 0) === $wilayaRule ?: 'the refusal does not name the colliding rule';
});

ac_check('the rules list is narrowest first', ac_req('GET', '/shipping/rules'), 200, function ($d) {
    $scores = array_column($d['data'], 'specificity');
    $sorted = $scores;
    rsort($sorted);

    return $scores === $sorted ?: 'the list is not ordered by specificity';
});

ac_check('filter the list by wilaya', ac_req('GET', '/shipping/rules', null, [
    'wilaya_id' => $wilayaId,
]), 200, function ($d) use ($wilayaId) {
    foreach ($d['data'] as $rule) {
        if ((int) $rule['wilaya_id'] !== $wilayaId) {
            return 'got a rule for wilaya ' . $rule['wilaya_id'];
        }
    }

    return $d['data'] !== [] ?: 'expected the two rules for this wilaya';
});

ac_check('read a rule back', ac_req('GET', "/shipping/rules/{$communeRule}"), 200, function ($d) use ($communeId) {
    return ((int) $d['data']['commune_id'] === $communeId && $d['data']['amount'] === '300.00')
        ?: 'wrong rule';
});

ac_check('a rule that does not exist', ac_req('GET', '/shipping/rules/99999999'), 404);

echo PHP_EOL, "=== quoting ===", PHP_EOL;

// The narrowest rule wins, and only that one: a shop that has priced a commune
// means that price, not that price plus the wilaya's.
ac_check('the commune price wins', [200, ['data' => ac_quotes([
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
])]], 200, function ($d) {
    $quote = ac_quote_from($d['data'], 'rules');

    return ($quote !== null && $quote['amount'] === '300.00')
        ?: 'quoted ' . wp_json_encode($d['data']);
});

ac_check('the quote says where the number came from', [200, ['data' => ac_quotes([
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
])]], 200, function ($d) {
    $quote = ac_quote_from($d['data'], 'rules');

    return ($quote['provider'] ?? '') === 'manual' ?: 'the quote does not name its courier';
});

// Another commune of the same wilaya falls back to the wilaya's price.
$otherCommune = (int) ($communes['data'][1]['id'] ?? 0);

if ($otherCommune > 0) {
    ac_check('a commune with no rule of its own', [200, ['data' => ac_quotes([
        'wilaya_id' => $wilayaId,
        'commune_id' => $otherCommune,
    ])]], 200, function ($d) {
        $quote = ac_quote_from($d['data'], 'rules');

        return ($quote !== null && $quote['amount'] === '500.00' && $quote['estimated_days'] === 2)
            ?: 'quoted ' . wp_json_encode($quote);
    });
}

[, $otherCommunes] = ac_req('GET', "/locations/wilayas/{$otherWilaya}/communes");
$farCommune = (int) ($otherCommunes['data'][0]['id'] ?? 0);

ac_check('a wilaya with no rule falls back to the country', [200, ['data' => ac_quotes([
    'wilaya_id' => $otherWilaya,
    'commune_id' => $farCommune,
])]], 200, function ($d) {
    $quote = ac_quote_from($d['data'], 'rules');

    return ($quote !== null && $quote['amount'] === '800.00') ?: 'quoted ' . wp_json_encode($quote);
});

echo PHP_EOL, "=== free delivery ===", PHP_EOL;

ac_check('a basket over the threshold ships free', [200, ['data' => ac_quotes([
    'wilaya_id' => $otherWilaya,
    'commune_id' => $farCommune,
    'subtotal' => '12000.00',
])]], 200, function ($d) {
    $quote = ac_quote_from($d['data'], 'rules');

    return ($quote['amount'] === '0.00' && $quote['free_shipping'] === true)
        ?: 'quoted ' . wp_json_encode($quote);
});

// "Free over 10000" is read as "spend 10000 and delivery is free".
ac_check('a basket exactly at the threshold ships free', [200, ['data' => ac_quotes([
    'wilaya_id' => $otherWilaya,
    'commune_id' => $farCommune,
    'subtotal' => '10000.00',
])]], 200, function ($d) {
    return ac_quote_from($d['data'], 'rules')['free_shipping'] === true ?: 'the exact threshold was charged';
});

ac_check('a centime short pays in full', [200, ['data' => ac_quotes([
    'wilaya_id' => $otherWilaya,
    'commune_id' => $farCommune,
    'subtotal' => '9999.99',
])]], 200, function ($d) {
    $quote = ac_quote_from($d['data'], 'rules');

    return ($quote['amount'] === '800.00' && $quote['free_shipping'] === false)
        ?: 'quoted ' . wp_json_encode($quote);
});

// Asking what delivery costs is not the same as asking what delivering *this
// basket* costs, and answering the second with an empty basket would quote full
// price to a customer who qualifies.
ac_check('no basket means no threshold', [200, ['data' => ac_quotes([
    'wilaya_id' => $otherWilaya,
    'commune_id' => $farCommune,
])]], 200, function ($d) {
    return ac_quote_from($d['data'], 'rules')['amount'] === '800.00' ?: 'a threshold was applied without a basket';
});

ac_check('a malformed subtotal is refused', ac_req('GET', '/shipping/rates', null, [
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
    'subtotal' => '12 000,00',
]), 400);

echo PHP_EOL, "=== editing a tariff ===", PHP_EOL;

ac_check('the commune price is raised', ac_req('PATCH', "/shipping/rules/{$communeRule}", [
    'amount' => '350',
]), 200, function ($d) {
    return $d['data']['amount'] === '350.00' ?: 'amount is ' . $d['data']['amount'];
});

ac_check('an empty patch', ac_req('PATCH', "/shipping/rules/{$communeRule}", []), 400);
ac_check('a patch with an unknown field', ac_req('PATCH', "/shipping/rules/{$communeRule}", ['zone' => 'x']), 400);

// A PATCH changes what it names: the threshold set at creation must survive an
// edit that never mentioned it.
ac_check('raising the national price keeps its threshold', ac_req('PATCH', "/shipping/rules/{$national}", [
    'amount' => '900',
]), 200, function ($d) {
    return ($d['data']['amount'] === '900.00' && $d['data']['free_over'] === '10000.00')
        ?: 'the threshold was lost: ' . wp_json_encode($d['data']);
});

ac_check('the threshold is cleared by an explicit null', ac_req('PATCH', "/shipping/rules/{$national}", [
    'free_over' => null,
]), 200, function ($d) {
    return $d['data']['free_over'] === null ?: 'free_over is ' . wp_json_encode($d['data']['free_over']);
});

ac_check('and free delivery stops', [200, ['data' => ac_quotes([
    'wilaya_id' => $otherWilaya,
    'commune_id' => $farCommune,
    'subtotal' => '12000.00',
])]], 200, function ($d) {
    $quote = ac_quote_from($d['data'], 'rules');

    return ($quote['amount'] === '900.00' && $quote['free_shipping'] === false)
        ?: 'quoted ' . wp_json_encode($quote);
});

echo PHP_EOL, "=== suspending a rule ===", PHP_EOL;

ac_check('the commune rule is deactivated', ac_req('PATCH', "/shipping/rules/{$communeRule}", [
    'is_active' => false,
]), 200, function ($d) {
    return $d['data']['is_active'] === false ?: 'still active';
});

ac_check('so the wilaya price applies again', [200, ['data' => ac_quotes([
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
])]], 200, function ($d) {
    return ac_quote_from($d['data'], 'rules')['amount'] === '500.00' ?: 'an inactive rule was used';
});

// Suspended, not lost — that is the difference between deactivating and
// deleting, and a shop that meant to delete it can still do so.
ac_check('the suspended rule is still there', ac_req('GET', "/shipping/rules/{$communeRule}"), 200, function ($d) {
    return $d['data']['amount'] === '350.00' ?: 'the rule lost its price';
});

echo PHP_EOL, "=== the trail ===", PHP_EOL;

$actions = ac_audit_actions($communeRule);

ac_assert('the rule creation was audited', in_array('shipping.rule_created', $actions, true) ?: 'saw ' . implode(', ', $actions));
ac_assert('the price change was audited', in_array('shipping.rule_updated', $actions, true) ?: 'saw ' . implode(', ', $actions));

echo PHP_EOL, "=== cleanup: the tariff is removed again ===", PHP_EOL;

foreach ($GLOBALS['ac_rules'] as $id) {
    ac_check("rule {$id} is deleted", ac_req('DELETE', "/shipping/rules/{$id}"), 200, function ($d) {
        return ($d['data']['deleted'] ?? false) === true ?: 'not reported as deleted';
    });
}

ac_check('deleting it twice', ac_req('DELETE', '/shipping/rules/' . $communeRule), 404);

ac_assert(
    'the deletion was audited, so the price survives the rule',
    in_array('shipping.rule_deleted', ac_audit_actions($communeRule), true) ?: 'no deletion in the trail'
);

// The state every other suite expects: a shop with no tariff of its own.
ac_check('nothing is priced any more', [200, ['data' => ac_quotes([
    'wilaya_id' => $wilayaId,
    'commune_id' => $communeId,
])]], 200, function ($d) {
    return ac_quote_from($d['data'], 'rules') === null ?: 'a rule survived cleanup';
});

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
