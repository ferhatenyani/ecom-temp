<?php
/**
 * Staff account endpoints against a real WordPress install — roadmap §87, §65.
 *
 * This suite exists for one reason: `/users` is the only endpoint in this API
 * that can hand out capabilities, and every other capability boundary in the
 * system is downstream of it. §45's test list names privilege escalation
 * explicitly, so the escalation cases here are the point of the file and the
 * CRUD around them is scaffolding.
 *
 * **Every refusal carries a positive control.** A refusal and an unreachable
 * route look identical from outside, so "an Admin is refused" proves nothing
 * without "a Super Admin is served the same URL", and "you cannot grant a role
 * above your own" proves nothing without "you can grant one at or below it".
 *
 *   scripts/test.sh                               # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/users.php
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

function ac_user(string $login, string $role, string $email = ''): int
{
    $user = get_user_by('login', $login);

    if ($user) {
        $user->set_role($role);
        delete_user_meta((int) $user->ID, '_ac_user_status');

        return (int) $user->ID;
    }

    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(24),
        'user_email' => $email !== '' ? $email : $login . '@example.test',
        'role' => $role,
    ]);

    return is_wp_error($id) ? 0 : (int) $id;
}

/** Remove a fixture account outright so a re-run starts from nothing. */
function ac_drop(string $login): void
{
    require_once ABSPATH . 'wp-admin/includes/user.php';

    $user = get_user_by('login', $login);

    if ($user) {
        wp_delete_user((int) $user->ID);
    }
}

/** A field's error message from a 400 body, or ''. */
function ac_field_error(array $data, string $field): string
{
    return (string) ($data['error']['details']['fields'][$field] ?? '');
}

/**
 * The seeded suite fixtures.
 *
 * `ac_super` is the caller for most of this file. `ac_boss` is a *second*
 * Super Admin, because several rules are about what you may not do to
 * yourself and testing them needs somebody else to be the target.
 */
$super = ac_user('ac_usr_super', 'ac_super_admin');
$boss = ac_user('ac_usr_boss', 'ac_super_admin');
$admin = ac_user('ac_usr_admin', 'ac_admin');
$agent = ac_user('ac_usr_agent', 'ac_support_agent');
$shopper = ac_user('ac_usr_shopper', 'customer');

foreach (['ac_usr_new', 'ac_usr_promote', 'ac_usr_doomed', 'ac_usr_ordered'] as $login) {
    ac_drop($login);
}

echo PHP_EOL, "=== authorization: ac_manage_users is Super Admin's alone ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /users signed out', ac_req('GET', '/users'), 401);
ac_check('GET /roles signed out', ac_req('GET', '/roles'), 401);
ac_check('POST /users signed out', ac_req('POST', '/users', ['username' => 'x']), 401);

wp_set_current_user($admin);
ac_check('GET /users as an Admin', ac_req('GET', '/users'), 403);
ac_check('GET /roles as an Admin', ac_req('GET', '/roles'), 403);
ac_check('POST /users as an Admin', ac_req('POST', '/users', ['username' => 'x']), 403);
ac_check('DELETE /users/{id} as an Admin', ac_req('DELETE', "/users/{$agent}"), 403);

ac_assert(
    'the Admin really does hold the other capabilities',
    user_can($admin, 'ac_manage_orders') && user_can($admin, 'ac_manage_payments')
        && !user_can($admin, 'ac_manage_users')
        ?: 'the Admin fixture is not the role this test describes'
);

wp_set_current_user($agent);
ac_check('GET /users as a Support Agent', ac_req('GET', '/users'), 403);

// The control: the same URLs, served.
wp_set_current_user($super);
ac_check('GET /users as a Super Admin', ac_req('GET', '/users'), 200);
ac_check('GET /roles as a Super Admin', ac_req('GET', '/roles'), 200);

echo PHP_EOL, "=== roles ===", PHP_EOL;

ac_check('the matrix is published', ac_req('GET', '/roles'), 200, function ($d) {
    if (count($d['data']) !== 7) {
        return 'expected seven roles, got ' . count($d['data']);
    }

    foreach ($d['data'] as $role) {
        if (!isset($role['role'], $role['name'], $role['capabilities'])) {
            return 'a role row is missing a field';
        }

        if ($role['role'] === 'ac_super_admin' && count($role['capabilities']) !== 13) {
            return 'Super Admin should hold every capability, got ' . count($role['capabilities']);
        }

        if ($role['role'] === 'ac_support_agent'
            && $role['capabilities'] !== ['ac_manage_customers', 'ac_view_analytics']) {
            return 'Support Agent capabilities do not match the matrix';
        }
    }

    return true;
});

ac_check('there is no way to create a role', ac_req('POST', '/roles', ['name' => 'Invented']), 404);

echo PHP_EOL, "=== list: staff only ===", PHP_EOL;

ac_check('list staff', ac_req('GET', '/users'), 200, function ($d) {
    return isset($d['meta']['total'], $d['meta']['page'], $d['meta']['per_page']) ?: 'no pagination meta';
});

ac_check('a customer is not in the staff list', ac_req('GET', '/users', null, ['per_page' => 100]), 200,
    function ($d) use ($shopper) {
        foreach ($d['data'] as $row) {
            if ((int) $row['id'] === $shopper) {
                return 'the shopper appears in /users';
            }
        }

        return true;
    });

ac_check('and the staff we created are', ac_req('GET', '/users', null, ['per_page' => 100]), 200,
    function ($d) use ($agent) {
        foreach ($d['data'] as $row) {
            if ((int) $row['id'] === $agent) {
                return true;
            }
        }

        return 'the support agent is missing from /users';
    });

ac_check('filter by role', ac_req('GET', '/users', null, ['role' => 'ac_support_agent', 'per_page' => 100]), 200,
    function ($d) {
        foreach ($d['data'] as $row) {
            if ($row['role'] !== 'ac_support_agent') {
                return 'the role filter returned ' . $row['role'];
            }
        }

        return true;
    });

ac_check('an unknown role filter is refused', ac_req('GET', '/users', null, ['role' => 'wizard']), 400);
ac_check('per_page above the maximum is refused', ac_req('GET', '/users', null, ['per_page' => 500]), 400);

ac_check('a customer id is not readable here', ac_req('GET', "/users/{$shopper}"), 404);
ac_check('an unknown id is not found', ac_req('GET', '/users/99999901'), 404);

echo PHP_EOL, "=== create ===", PHP_EOL;

$created = ac_check('create a staff account', ac_req('POST', '/users', [
    'username' => 'ac_usr_new',
    'email' => 'ac_usr_new@example.test',
    'role' => 'ac_order_manager',
    'first_name' => 'Karim',
    'last_name' => 'Benali',
]), 201, function ($d) {
    if ($d['data']['role'] !== 'ac_order_manager') {
        return 'the role was not applied';
    }

    if ($d['data']['role_name'] !== 'Order Manager') {
        return 'the role name is wrong';
    }

    if ($d['data']['status'] !== 'active') {
        return 'a new account should be active';
    }

    return true;
});

/*
 * Defensive: a failed create must not fatal the rest of the file. A suite that
 * stops at the first failure reports one problem where there may be five, and
 * the whole point of running it is to see all of them at once.
 */
$newId = (int) ($created['data']['id'] ?? 0);

if ($newId === 0) {
    echo "\033[31mthe create fixture failed; the rest of this suite cannot run\033[0m", PHP_EOL;
    printf("\033[1m%d passed, %d failed\033[0m%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);
    exit(1);
}

ac_assert(
    'the account really holds the role capabilities',
    user_can($newId, 'ac_manage_orders') && !user_can($newId, 'ac_manage_products')
        ?: 'the created account does not hold exactly the Order Manager capabilities'
);

ac_assert(
    'no capability map is published',
    !array_key_exists('capabilities', $created['data']) ?: 'the user payload carries a capability map'
);

ac_check('a role is required', ac_req('POST', '/users', [
    'username' => 'ac_usr_noroleatall',
    'email' => 'ac_usr_norole@example.test',
]), 400, function ($d) {
    return str_contains(ac_field_error($d, 'role'), 'customers are managed at /customers')
        ?: 'the missing-role error does not point at /customers';
});

ac_check('a username is required', ac_req('POST', '/users', [
    'email' => 'ac_usr_x@example.test',
    'role' => 'ac_support_agent',
]), 400);

ac_check('a duplicate username is a conflict', ac_req('POST', '/users', [
    'username' => 'ac_usr_new',
    'email' => 'ac_usr_other@example.test',
    'role' => 'ac_support_agent',
]), 409);

ac_check('a duplicate email is a conflict', ac_req('POST', '/users', [
    'username' => 'ac_usr_another',
    'email' => 'ac_usr_new@example.test',
    'role' => 'ac_support_agent',
]), 409);

echo PHP_EOL, "=== refused by name, with the reason ===", PHP_EOL;

$refusals = [
    'password' => 'application-passwords',
    'user_pass' => 'application-passwords',
    'capabilities' => 'Capabilities come from the role',
    'roles' => 'exactly one role',
    'user_login' => 'A login is an identity',
];

foreach ($refusals as $field => $needle) {
    ac_check("{$field} is refused by name", ac_req('POST', '/users', [
        'username' => 'ac_usr_refused',
        'email' => 'ac_usr_refused@example.test',
        'role' => 'ac_support_agent',
        $field => 'anything',
    ]), 400, function ($d) use ($field, $needle) {
        $message = ac_field_error($d, $field);

        if ($message === '') {
            return "no per-field error for {$field}";
        }

        if ($message === 'Unknown field.') {
            return "{$field} was refused generically rather than by name";
        }

        return str_contains($message, $needle) ?: "the reason for {$field} does not mention \"{$needle}\"";
    });
}

ac_check('a 400 names every bad field at once', ac_req('POST', '/users', [
    'username' => 'ac_usr_multi',
    'email' => 'not-an-email',
    'role' => 'wizard',
]), 400, function ($d) {
    $fields = $d['error']['details']['fields'] ?? [];

    return (isset($fields['email'], $fields['role']) && count($fields) >= 2)
        ?: 'only ' . count($fields) . ' field(s) reported';
});

echo PHP_EOL, "=== privilege escalation ===", PHP_EOL;

ac_check('administrator cannot be granted', ac_req('POST', '/users', [
    'username' => 'ac_usr_wannabe',
    'email' => 'ac_usr_wannabe@example.test',
    'role' => 'administrator',
]), 400, function ($d) {
    return str_contains(ac_field_error($d, 'role'), 'commerce roles')
        ?: 'the administrator refusal does not explain itself';
});

foreach (['editor', 'shop_manager', 'customer'] as $coreRole) {
    ac_check("{$coreRole} cannot be granted either", ac_req('POST', '/users', [
        'username' => 'ac_usr_core',
        'email' => 'ac_usr_core@example.test',
        'role' => $coreRole,
    ]), 400);
}

ac_check('an unknown role lists the real ones', ac_req('POST', '/users', [
    'username' => 'ac_usr_unknown',
    'email' => 'ac_usr_unknown@example.test',
    'role' => 'ac_wizard',
]), 400, function ($d) {
    return str_contains(ac_field_error($d, 'role'), 'ac_super_admin')
        ?: 'the unknown-role error does not list the available roles';
});

/*
 * The rule that has no caller today and exists for the day there is one: a
 * caller may not grant a role holding capabilities they lack. Constructing it
 * needs an account that can manage users and is not Super Admin, which is not a
 * state the role matrix produces — so the capability is granted directly.
 */
$escalator = ac_user('ac_usr_escalator', 'ac_admin');
$escalatorUser = get_user_by('id', $escalator);
$escalatorUser->add_cap('ac_manage_users');
wp_set_current_user($escalator);

ac_assert(
    'the escalation fixture can manage users and is not a Super Admin',
    current_user_can('ac_manage_users') && !current_user_can('ac_manage_settings')
        ?: 'the escalation fixture is not in the state this test needs'
);

ac_check('cannot grant a role above your own', ac_req('POST', '/users', [
    'username' => 'ac_usr_escalated',
    'email' => 'ac_usr_escalated@example.test',
    'role' => 'ac_super_admin',
]), 403, function ($d) {
    return str_contains((string) $d['error']['message'], 'ac_manage_settings')
        ?: 'the refusal does not name the capability that is missing';
});

// The control. Without it, the refusal above could be any 403 at all.
ac_check('but can grant one at or below it', ac_req('POST', '/users', [
    'username' => 'ac_usr_belowme',
    'email' => 'ac_usr_belowme@example.test',
    'role' => 'ac_support_agent',
]), 201);

$escalatorUser->remove_cap('ac_manage_users');
ac_drop('ac_usr_belowme');

wp_set_current_user($super);

echo PHP_EOL, "=== update ===", PHP_EOL;

ac_check('rename a staff account', ac_req('PATCH', "/users/{$newId}", ['display_name' => 'Karim B.']), 200,
    function ($d) {
        return $d['data']['display_name'] === 'Karim B.' ?: 'the display name did not change';
    });

ac_check('move a role', ac_req('PATCH', "/users/{$newId}", ['role' => 'ac_product_manager']), 200, function ($d) {
    return $d['data']['role'] === 'ac_product_manager' ?: 'the role did not move';
});

ac_assert(
    'the old capabilities are gone, not added to',
    user_can($newId, 'ac_manage_products') && !user_can($newId, 'ac_manage_orders')
        ?: 'the account kept capabilities from its previous role'
);

ac_check('a GET body PATCHes back unchanged', ac_req('GET', "/users/{$newId}"), 200, function ($d) use ($newId) {
    [$status] = ac_req('PATCH', "/users/{$newId}", $d['data']);

    return $status === 200 ?: "round-tripping the read body returned {$status}";
});

ac_check('an empty patch is refused', ac_req('PATCH', "/users/{$newId}", []), 400);
ac_check('you cannot patch a customer', ac_req('PATCH', "/users/{$shopper}", ['display_name' => 'x']), 404);

echo PHP_EOL, "=== you, and what you may not do to yourself ===", PHP_EOL;

ac_check('you cannot change your own role', ac_req('PATCH', "/users/{$super}", ['role' => 'ac_admin']), 403,
    function ($d) {
        return str_contains((string) $d['error']['message'], 'your own role') ?: 'the wrong refusal';
    });

ac_check('you cannot suspend yourself', ac_req('PATCH', "/users/{$super}", ['status' => 'suspended']), 403);
ac_check('you cannot delete yourself', ac_req('DELETE', "/users/{$super}"), 403);

// The controls: the same three writes against somebody else.
ac_check('but you can move another Super Admin', ac_req('PATCH', "/users/{$boss}", ['role' => 'ac_manager']), 200);
ac_check('and change your own name', ac_req('PATCH', "/users/{$super}", ['display_name' => 'Le patron']), 200);

// Put the second Super Admin back.
ac_req('PATCH', "/users/{$boss}", ['role' => 'ac_super_admin']);

echo PHP_EOL, "=== promotion ===", PHP_EOL;

$promotable = ac_user('ac_usr_promote', 'customer');

ac_check('a customer with no role assignment is not found', ac_req('PATCH', "/users/{$promotable}",
    ['display_name' => 'Nope']), 404);

ac_check('promoting a customer to staff is allowed and reported', ac_req('PATCH', "/users/{$promotable}",
    ['role' => 'ac_support_agent']), 200, function ($d) {
        if (($d['meta']['promoted_from_customer'] ?? false) !== true) {
            return 'the promotion was not reported in meta';
        }

        return $d['data']['role'] === 'ac_support_agent' ?: 'the role was not applied';
    });

ac_check('and the account is staff afterwards', ac_req('GET', "/users/{$promotable}"), 200);

ac_check('an ordinary patch reports no promotion', ac_req('PATCH', "/users/{$promotable}",
    ['display_name' => 'Now staff']), 200, function ($d) {
        return !isset($d['meta']['promoted_from_customer']) ?: 'promotion was reported on an ordinary write';
    });

echo PHP_EOL, "=== suspension ===", PHP_EOL;

$suspendable = ac_user('ac_usr_suspend', 'ac_order_manager');

// The control first: this account works before it is suspended.
wp_set_current_user($suspendable);
ac_check('an active staff account is served', ac_req('GET', '/orders', null, ['per_page' => 1]), 200);

wp_set_current_user($super);
ac_check('suspend it', ac_req('PATCH', "/users/{$suspendable}", ['status' => 'suspended']), 200, function ($d) {
    return $d['data']['status'] === 'suspended' ?: 'the status did not change';
});

wp_set_current_user($suspendable);
ac_check('a suspended account is refused everywhere', ac_req('GET', '/orders'), 401, function ($d) {
    return ($d['error']['code'] ?? '') === 'account_suspended' ?: 'the wrong error code';
});
ac_check('including on its own identity', ac_req('GET', '/auth/me'), 401);
ac_check('and on a public route', ac_req('GET', '/health'), 401);

wp_set_current_user($super);
ac_check('reactivate it', ac_req('PATCH', "/users/{$suspendable}", ['status' => 'active']), 200);

ac_assert(
    'reactivating leaves no meta row behind',
    get_user_meta($suspendable, '_ac_user_status', true) === ''
        ?: 'an active account carries a status row'
);

wp_set_current_user($suspendable);
ac_check('and it works again', ac_req('GET', '/orders', null, ['per_page' => 1]), 200);

wp_set_current_user($super);

ac_check('the suspended filter finds it', ac_req('GET', '/users', null, ['status' => 'suspended', 'per_page' => 100]), 200,
    function ($d) use ($suspendable) {
        foreach ($d['data'] as $row) {
            if ((int) $row['id'] === $suspendable) {
                return 'a reactivated account still reads as suspended';
            }
        }

        return true;
    });

echo PHP_EOL, "=== application passwords ===", PHP_EOL;

ac_check('the collection starts empty', ac_req('GET', "/users/{$newId}/application-passwords"), 200, function ($d) {
    return $d['data'] === [] ?: 'the fixture account already has credentials';
});

$minted = ac_check('mint one', ac_req('POST', "/users/{$newId}/application-passwords",
    ['name' => 'Admin panel — test phone']), 201, function ($d) {
        if (!isset($d['data']['password']) || strlen((string) $d['data']['password']) < 20) {
            return 'no usable password came back';
        }

        return isset($d['data']['uuid'], $d['data']['name']) ?: 'the record is incomplete';
    });

$uuid = (string) ($minted['data']['uuid'] ?? '');
$plaintext = (string) ($minted['data']['password'] ?? '');

ac_check('the password appears exactly once', ac_req('GET', "/users/{$newId}/application-passwords"), 200,
    function ($d) {
        foreach ($d['data'] as $row) {
            if (array_key_exists('password', $row)) {
                return 'the collection publishes a password field';
            }

            if (!array_key_exists('uuid', $row) || !array_key_exists('last_used', $row)) {
                return 'the collection is missing a field the revoke screen needs';
            }
        }

        return count($d['data']) === 1 ?: 'expected one credential';
    });

ac_check('nor on the user read', ac_req('GET', "/users/{$newId}"), 200, function ($d) {
    foreach ($d['data']['application_passwords'] ?? [] as $row) {
        if (array_key_exists('password', $row) || array_key_exists('last_ip', $row)) {
            return 'the user payload leaks a credential field';
        }
    }

    return true;
});

ac_check('a duplicate name is a conflict', ac_req('POST', "/users/{$newId}/application-passwords",
    ['name' => 'Admin panel — test phone']), 409);

ac_check('a name is required', ac_req('POST', "/users/{$newId}/application-passwords", []), 400);

ac_check('a suspended account cannot be issued one', (function () use ($super, $suspendable) {
    wp_set_current_user($super);
    ac_req('PATCH', "/users/{$suspendable}", ['status' => 'suspended']);
    $result = ac_req('POST', "/users/{$suspendable}/application-passwords", ['name' => 'Nope']);
    ac_req('PATCH', "/users/{$suspendable}", ['status' => 'active']);

    return $result;
})(), 409);

ac_check('an unknown uuid is not found', ac_req('DELETE',
    "/users/{$newId}/application-passwords/00000000-0000-0000-0000-000000000000"), 404);

ac_check('a malformed uuid does not reach the lookup', ac_req('DELETE',
    "/users/{$newId}/application-passwords/nonsense"), 404);

ac_check('revoke it', ac_req('DELETE', "/users/{$newId}/application-passwords/{$uuid}"), 200);

ac_check('and it is gone', ac_req('GET', "/users/{$newId}/application-passwords"), 200, function ($d) {
    return $d['data'] === [] ?: 'the credential survived revocation';
});

echo PHP_EOL, "=== delete ===", PHP_EOL;

$doomed = ac_user('ac_usr_doomed', 'ac_support_agent');
$ordered = ac_user('ac_usr_ordered', 'ac_support_agent');

$order = new WC_Order();
$order->set_customer_id($ordered);
$order->save();

ac_check('an account that owns orders is refused', ac_req('DELETE', "/users/{$ordered}"), 409, function ($d) {
    if (($d['error']['details']['orders'] ?? 0) < 1) {
        return 'the refusal does not report the order count';
    }

    return str_contains((string) $d['error']['message'], 'suspend') ?: 'the refusal does not offer suspension';
});

ac_check('a customer id cannot be deleted here', ac_req('DELETE', "/users/{$shopper}"), 404);

// The control: an otherwise identical account with no orders.
ac_check('a clean staff account is deleted', ac_req('DELETE', "/users/{$doomed}"), 200);
ac_assert('and is really gone', get_user_by('id', $doomed) === false ?: 'the account survived the delete');

$order->delete(true);

echo PHP_EOL, "=== audit trail ===", PHP_EOL;

$find = function (string $action, callable $match): callable {
    return function ($d) use ($action, $match) {
        foreach ($d['data'] as $row) {
            if ($row['action'] === $action && $match($row) === true) {
                return true;
            }
        }

        return "no matching {$action} row";
    };
};

ac_check('the creation was audited', ac_req('GET', '/audit-logs', null, ['action' => 'user.created']), 200,
    $find('user.created', function ($row) use ($newId) {
        return $row['resource_type'] === 'user'
            && $row['resource_id'] === (string) $newId
            && ($row['metadata']['role'] ?? '') === 'ac_order_manager';
    }));

ac_check('the role change names both roles', ac_req('GET', '/audit-logs', null, ['action' => 'user.role_changed']), 200,
    $find('user.role_changed', function ($row) use ($newId) {
        return $row['resource_id'] === (string) $newId
            && ($row['metadata']['from'] ?? '') === 'ac_order_manager'
            && ($row['metadata']['to'] ?? '') === 'ac_product_manager';
    }));

ac_check('the promotion is flagged in the trail', ac_req('GET', '/audit-logs', null, ['action' => 'user.role_changed']), 200,
    $find('user.role_changed', function ($row) use ($promotable) {
        return $row['resource_id'] === (string) $promotable
            && ($row['metadata']['promoted_from_customer'] ?? false) === true;
    }));

ac_check('suspension is its own action', ac_req('GET', '/audit-logs', null, ['action' => 'user.suspended']), 200,
    $find('user.suspended', fn ($row) => $row['resource_type'] === 'user'));

ac_check('minting is audited', ac_req('GET', '/audit-logs', null, ['action' => 'user.app_password_created']), 200,
    $find('user.app_password_created', function ($row) use ($newId) {
        return $row['resource_id'] === (string) $newId
            && ($row['metadata']['name'] ?? '') === 'Admin panel — test phone';
    }));

/*
 * The one assertion this whole section exists for. `AuditEvent` runs
 * `Logger::redact()` over every metadata array, so a key called `password`
 * would be masked — but the service never puts one there, and this checks the
 * outcome rather than the mechanism: the plaintext must not appear anywhere in
 * the trail, under any key.
 */
ac_check('and the minted password is nowhere in the trail', ac_req('GET', '/audit-logs', null,
    ['action' => 'user.app_password_created', 'per_page' => 100]), 200, function ($d) use ($plaintext) {
        if ($plaintext === '') {
            return 'no plaintext was captured, so this proves nothing';
        }

        return !str_contains((string) wp_json_encode($d), $plaintext)
            ?: 'the application password is in the audit trail';
    });

ac_check('revocation is audited', ac_req('GET', '/audit-logs', null, ['action' => 'user.app_password_revoked']), 200,
    $find('user.app_password_revoked', fn ($row) => ($row['metadata']['uuid'] ?? '') === $uuid));

ac_check('deletion is audited', ac_req('GET', '/audit-logs', null, ['action' => 'user.deleted']), 200,
    $find('user.deleted', fn ($row) => ($row['metadata']['login'] ?? '') === 'ac_usr_doomed'));

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
