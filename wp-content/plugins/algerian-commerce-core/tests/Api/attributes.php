<?php
/**
 * Global attribute endpoints against a real WordPress + WooCommerce install —
 * roadmap §88, §65.
 *
 * Two things make this suite worth more than its CRUD.
 *
 * **The same-request trap.** `wc_create_attribute()` writes the row and does not
 * register the taxonomy; WooCommerce registers it on `init`, which already ran.
 * Its own REST controller lives with that, so an attribute created through
 * WooCommerce's API cannot take a term until the next request. CLAUDE.md records
 * the same trap against §82's facet counter. This file asserts the whole chain
 * inside one process, which is the only place it can be observed.
 *
 * **The delete guards.** Deleting an attribute or a term detaches every product
 * using it, and WooCommerce reports nothing. Both refusals carry positive
 * controls, because a 409 that fires for everything is not a guard.
 *
 *   scripts/test.sh                                    # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/attributes.php
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

function ac_field_error(array $data, string $field): string
{
    return (string) ($data['error']['details']['fields'][$field] ?? '');
}

/** Remove a fixture attribute by slug so a re-run starts from nothing. */
function ac_drop_attribute(string $slug): void
{
    foreach (wc_get_attribute_taxonomies() as $attribute) {
        if ($attribute->attribute_name === $slug) {
            wc_delete_attribute((int) $attribute->attribute_id);
        }
    }
}

function ac_drop_product(string $sku): void
{
    $id = (int) wc_get_product_id_by_sku($sku);

    if ($id > 0) {
        $product = wc_get_product($id);
        if ($product) {
            $product->delete(true);
        }
    }
}

$manager = ac_user('ac_attr_manager', 'ac_product_manager');   // has ac_manage_products
$denied = ac_user('ac_attr_denied', 'ac_support_agent');       // has not

foreach (['acattrsize', 'acattrcolour', 'acattrspare', 'acattrrenamed'] as $slug) {
    ac_drop_attribute($slug);
}

foreach (['AC-ATTR-RUG', 'AC-ATTR-MUG'] as $sku) {
    ac_drop_product($sku);
}

echo PHP_EOL, "=== authorization ===", PHP_EOL;

wp_set_current_user(0);
ac_check('GET /attributes signed out', ac_req('GET', '/attributes'), 401);
ac_check('POST /attributes signed out', ac_req('POST', '/attributes', ['name' => 'X']), 401);

wp_set_current_user($denied);
ac_check('GET /attributes as a Support Agent', ac_req('GET', '/attributes'), 403);
ac_check('POST /attributes as a Support Agent', ac_req('POST', '/attributes', ['name' => 'X']), 403);

// The control: no new capability was invented, so a Product Manager is served.
wp_set_current_user($manager);
ac_check('GET /attributes as a Product Manager', ac_req('GET', '/attributes'), 200);

echo PHP_EOL, "=== create ===", PHP_EOL;

$created = ac_check('create an attribute', ac_req('POST', '/attributes', [
    'name' => 'Taille',
    'slug' => 'acattrsize',
    'order_by' => 'menu_order',
]), 201, function ($d) {
    if ($d['data']['taxonomy'] !== 'pa_acattrsize') {
        return 'the taxonomy name is wrong: ' . $d['data']['taxonomy'];
    }

    if ($d['data']['name'] !== 'Taille') {
        return 'the label was not stored';
    }

    return ($d['meta']['filterable'] ?? false) === true ?: 'the attribute is not filterable in this request';
});

$sizeId = (int) ($created['data']['id'] ?? 0);
$sizeTax = (string) ($created['data']['taxonomy'] ?? '');

if ($sizeId === 0) {
    echo "\033[31mthe create fixture failed; the rest of this suite cannot run\033[0m", PHP_EOL;
    printf("\033[1m%d passed, %d failed\033[0m%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);
    exit(1);
}

echo PHP_EOL, "=== the same-request trap (§82, §88) ===", PHP_EOL;

/*
 * The section's headline. WooCommerce's own REST controller cannot do any of
 * this: wc_create_attribute() leaves the taxonomy unregistered for the rest of
 * the request, so taxonomy_exists() is false, wp_insert_term() fails and §82's
 * facet counter skips it — answering 200 with an empty list, which reads as
 * working code.
 */
ac_assert('the taxonomy is registered in this same request', taxonomy_exists($sizeTax)
    ?: 'taxonomy_exists() is false right after the create');

ac_assert('and WooCommerce recognises it as a product attribute',
    taxonomy_is_product_attribute($sizeTax)
        ?: 'taxonomy_is_product_attribute() is false, so §82 would skip it');

$termM = ac_check('a term can be added in the same request', ac_req('POST', "/attributes/{$sizeId}/terms",
    ['name' => 'M']), 201, function ($d) {
        return $d['data']['slug'] === 'm' ?: 'the slug was not derived';
    });

$termMId = (int) ($termM['data']['id'] ?? 0);

$termL = ac_check('and a second', ac_req('POST', "/attributes/{$sizeId}/terms",
    ['name' => 'L', 'menu_order' => 2]), 201, function ($d) {
        return $d['data']['menu_order'] === 2 ?: 'menu_order did not round trip';
    });

$termLId = (int) ($termL['data']['id'] ?? 0);

ac_check('§82 reports it as facetable immediately', ac_req('GET', '/products', null,
    ['facets' => 'attributes', 'per_page' => 1]), 200, function ($d) use ($sizeTax) {
        $facetable = $d['meta']['facets']['attributes']['facetable'] ?? [];

        return in_array($sizeTax, $facetable, true)
            ?: 'the new attribute is not in facetable: ' . wp_json_encode($facetable);
    });

ac_check('and filtering on it is accepted rather than a 400', ac_req('GET', '/products', null,
    ['attributes' => ['pa_acattrsize' => 'm'], 'per_page' => 1]), 200);

echo PHP_EOL, "=== create: refusals ===", PHP_EOL;

ac_check('a name is required', ac_req('POST', '/attributes', ['slug' => 'acattrnameless']), 400,
    function ($d) {
        return str_contains(ac_field_error($d, 'name'), 'label') ?: 'the message does not explain what name means';
    });

/*
 * === WooCommerce's own refusals, and the key they arrive under ===
 *
 * Everything from here to the end of this block is the fix round's item 8.
 * `AttributeRepository::fromWpError()` used to file **every** non-conflict
 * `WP_Error` under `details.fields.attribute` — one literal string, and no form
 * control has or could have that key. A screen binding `details.fields` to its
 * inputs by key therefore rendered nothing at all for the two slug failures a
 * real shop actually meets, and the duplicate beside them carried no `details`
 * whatsoever. These assertions are what stops that coming back: they check the
 * *key*, not only the status, because the status was right the whole time.
 */
ac_check('a duplicate slug is a conflict naming the slug', ac_req('POST', '/attributes', [
    'name' => 'Taille encore',
    'slug' => 'acattrsize',
]), 409, function ($d) {
    // The offending value at the top of `details`, never under `fields` —
    // `fields` is the 400 validation channel and no 409 in this plugin writes
    // to it. Same shape `AttributeService::createTerm()` uses for the duplicate
    // it catches itself, so a client meets one refusal and not two.
    if (isset($d['error']['details']['fields'])) {
        return 'a state refusal grew a fields key';
    }

    return ($d['error']['details']['slug'] ?? null) === 'acattrsize'
        ?: 'details are ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('an over-long slug is refused', ac_req('POST', '/attributes', [
    'name' => 'Long',
    'slug' => str_repeat('a', 40),
]), 400, function ($d) {
    return str_contains(ac_field_error($d, 'slug'), '32') ?: 'the message does not explain the byte budget';
});

/*
 * The one above is `GlobalAttributeInput`'s own check and always named `slug`.
 * This one is **WooCommerce's**, reached by stating no slug at all: a 40-letter
 * label derives a slug past `wc_get_attribute_slug_max_byte_length()` (29 —
 * WordPress caps a taxonomy name at 32 and `pa_` takes three), so
 * `wc_create_attribute()` refuses the string it derived rather than one the
 * caller wrote.
 *
 * **It is filed under `name`, and that is the argued part.** The slug box was
 * empty; reddening it would point at the control the person did not fill and
 * say nothing about what to change. The name is what produced the string and is
 * what can fix it — either shorten it or state a slug.
 */
ac_check('a derived slug that is too long is filed under name', ac_req('POST', '/attributes', [
    'name' => str_repeat('Longueur', 5),
]), 400, function ($d) {
    if (isset($d['error']['details']['fields']['attribute'])) {
        return 'still filed under the catch-all key no control has';
    }

    if (!str_contains(ac_field_error($d, 'name'), 'too long')) {
        return 'fields are ' . wp_json_encode($d['error']['details']['fields'] ?? null);
    }

    // WooCommerce's own sentence, not a translation of it: it names the derived
    // slug, which is the only place the person can see what was built for them.
    return str_contains(ac_field_error($d, 'name'), 'Slug')
        ?: 'the message does not quote the derived slug: ' . ac_field_error($d, 'name');
});

/*
 * A reserved word, which is the refusal a real shop reaches first: "Type" is a
 * plausible attribute label and `type` is in `$wp_rewrite`'s rewrite codes, so
 * `wc_check_if_attribute_name_is_reserved()` refuses it.
 *
 * Stated here, so the same code lands under `slug` — the pair with the check
 * above is what proves the mapping is about *which field the caller used* and
 * not about the error code alone.
 */
ac_check('a reserved slug is filed under slug when the caller stated one', ac_req('POST', '/attributes', [
    'name' => 'Type',
    'slug' => 'type',
]), 400, function ($d) {
    if (isset($d['error']['details']['fields']['attribute'])) {
        return 'still filed under the catch-all key no control has';
    }

    return str_contains(ac_field_error($d, 'slug'), 'reserved')
        ?: 'fields are ' . wp_json_encode($d['error']['details']['fields'] ?? null);
});

// And derived from the name, the same refusal moves to the control that caused
// it. One code, two keys, decided by the payload.
ac_check('and under name when it was derived', ac_req('POST', '/attributes', [
    'name' => 'Type',
]), 400, function ($d) {
    return str_contains(ac_field_error($d, 'name'), 'reserved')
        ?: 'fields are ' . wp_json_encode($d['error']['details']['fields'] ?? null);
});

/*
 * The negative control, and it is the half that keeps the rest honest. A key
 * that names a field must not be invented for a refusal that is not about one —
 * an unregistered taxonomy is the shop's state, not the operator's typing — so
 * the sentence goes into the message and `details` stays absent. A screen
 * renders that above the form, which is exactly where it belongs.
 *
 * Reached through the term route, because a taxonomy that does not exist is
 * what `requireRegistered()` guards and 9999901 has no attribute at all.
 */
ac_check('a refusal that is not about a field invents no key', ac_req('POST', '/attributes/9999901/terms', [
    'name' => 'Nowhere',
]), 404, function ($d) {
    return !isset($d['error']['details']['fields'])
        ?: 'a 404 grew a fields key: ' . wp_json_encode($d['error']['details'] ?? null);
});

ac_check('an unknown order_by is refused', ac_req('POST', '/attributes', [
    'name' => 'Bad', 'slug' => 'acattrbad', 'order_by' => 'sideways',
]), 400);

ac_check('an unknown type names the available ones', ac_req('POST', '/attributes', [
    'name' => 'Bad', 'slug' => 'acattrbad2', 'type' => 'hologram',
]), 400, function ($d) {
    return in_array('select', $d['error']['details']['available_types'] ?? [], true)
        ?: 'the refusal does not list the available types';
});

$refusals = [
    'terms' => '/attributes/{id}/terms',
    'attribute_id' => 'The id is in the URL',
    'attribute_name' => 'Use "slug"',
];

foreach ($refusals as $field => $needle) {
    ac_check("{$field} is refused by name", ac_req('POST', '/attributes', [
        'name' => 'Refused', 'slug' => 'acattrrefused', $field => 'anything',
    ]), 400, function ($d) use ($field, $needle) {
        $message = ac_field_error($d, $field);

        if ($message === 'Unknown field.') {
            return "{$field} was refused generically rather than by name";
        }

        return str_contains($message, $needle) ?: "the reason for {$field} does not mention \"{$needle}\"";
    });
}

/*
 * The API publishes the taxonomy as `pa_acattrsize` and §82 accepts either
 * form, so refusing the prefixed form on write would break the round trip the
 * API itself invites.
 */
ac_check('a pa_ prefix on the slug is stripped, not refused', ac_req('POST', '/attributes', [
    'name' => 'Couleur', 'slug' => 'pa_acattrcolour',
]), 201, function ($d) {
    return $d['data']['slug'] === 'acattrcolour' && $d['data']['taxonomy'] === 'pa_acattrcolour'
        ?: 'the prefix was not handled: ' . wp_json_encode($d['data']);
});

$colourId = 0;
foreach (wc_get_attribute_taxonomies() as $attribute) {
    if ($attribute->attribute_name === 'acattrcolour') {
        $colourId = (int) $attribute->attribute_id;
    }
}

echo PHP_EOL, "=== read ===", PHP_EOL;

ac_check('the list carries both attributes', ac_req('GET', '/attributes'), 200, function ($d) use ($sizeId, $colourId) {
    $ids = array_column($d['data'], 'id');

    if (!in_array($sizeId, $ids, true) || !in_array($colourId, $ids, true)) {
        return 'a created attribute is missing from the list';
    }

    return isset($d['meta']['total']) ?: 'no total in meta';
});

ac_check('the single read carries usage', ac_req('GET', "/attributes/{$sizeId}"), 200, function ($d) {
    if (($d['data']['term_count'] ?? -1) !== 2) {
        return 'term_count is ' . ($d['data']['term_count'] ?? 'absent') . ', expected 2';
    }

    return ($d['data']['product_count'] ?? -1) === 0 ?: 'product_count should be 0 before anything is tagged';
});

ac_check('the list omits usage', ac_req('GET', '/attributes'), 200, function ($d) {
    foreach ($d['data'] as $row) {
        if (array_key_exists('term_count', $row)) {
            return 'the list computes usage per row';
        }
    }

    return true;
});

ac_check('an unknown attribute is not found', ac_req('GET', '/attributes/9999901'), 404);
ac_check('terms of an unknown attribute are not found', ac_req('GET', '/attributes/9999901/terms'), 404);

echo PHP_EOL, "=== terms ===", PHP_EOL;

ac_check('list terms', ac_req('GET', "/attributes/{$sizeId}/terms"), 200, function ($d) {
    return count($d['data']) === 2 && isset($d['meta']['total']) ?: 'expected two terms and pagination meta';
});

ac_check('terms paginate', ac_req('GET', "/attributes/{$sizeId}/terms", null, ['per_page' => 1]), 200,
    function ($d) {
        return count($d['data']) === 1 && $d['meta']['total'] === 2 ?: 'pagination is wrong';
    });

/*
 * This one found a defect in every controller, not just this one.
 * `WP_REST_Request::get_param()` reads the JSON body *before* the URL, so a
 * term body carrying its own `id` outranked the attribute id in the path and
 * the write answered 404. `AbstractController::pinRouteParams()` is the fix;
 * `tests/Api/security.php` asserts the general rule.
 */
ac_check('a term GET body PATCHes back unchanged', ac_req('GET', "/attributes/{$sizeId}/terms", null,
    ['per_page' => 1]), 200, function ($d) use ($sizeId) {
        [$status, $body] = ac_req('PATCH', "/attributes/{$sizeId}/terms/{$d['data'][0]['id']}", $d['data'][0]);

        if ($status !== 200) {
            return "round-tripping a term returned {$status}: " . substr((string) wp_json_encode($body), 0, 160);
        }

        return (int) $body['data']['id'] === (int) $d['data'][0]['id']
            ?: 'the round trip edited a different term';
    });

ac_check('rename a term', ac_req('PATCH', "/attributes/{$sizeId}/terms/{$termMId}",
    ['name' => 'Moyen']), 200, function ($d) {
        return $d['data']['name'] === 'Moyen' && $d['data']['slug'] === 'm'
            ?: 'the rename changed the wrong field';
    });

/*
 * These two are caught by `AttributeService::createTerm()` and `updateTerm()`
 * before WordPress sees them — `termSlugExists()` — and they are asserted for
 * their `details` shape anyway, because after the fix round's item 8 the shape
 * is the *contract*: the service's own duplicate and the one
 * `AttributeRepository::fromWpError()` translates out of `duplicate_term_slug`
 * now answer identically, and a screen binding one binds both.
 */
ac_check('a duplicate term slug is a conflict naming the slug', ac_req('POST', "/attributes/{$sizeId}/terms",
    ['name' => 'Autre', 'slug' => 'm']), 409, function ($d) {
        if (isset($d['error']['details']['fields'])) {
            return 'a state refusal grew a fields key';
        }

        return ($d['error']['details']['slug'] ?? null) === 'm'
            ?: 'details are ' . wp_json_encode($d['error']['details'] ?? null);
    });

ac_check('and so is renaming onto one', ac_req('PATCH', "/attributes/{$sizeId}/terms/{$termLId}",
    ['slug' => 'm']), 409, function ($d) {
        return ($d['error']['details']['slug'] ?? null) === 'm'
            ?: 'details are ' . wp_json_encode($d['error']['details'] ?? null);
    });

/*
 * A duplicate **name**, which is a different refusal and reaches
 * `fromWpError()` rather than the service's pre-check: nothing validates a name
 * for uniqueness here, so `wp_insert_term()` raises `term_exists` and puts the
 * colliding term's id in the error data.
 *
 * The id is read out and published, the idiom
 * `CmsRepository::createFaqCategory()` already uses for this exact WordPress
 * error — so a client can offer *"open the term you already have"* instead of
 * asking the person to go and find it. `M` was renamed to `Moyen` above, and
 * `Moyen` is what collides.
 */
ac_check('a duplicate term name is a conflict carrying the id it clashed with',
    ac_req('POST', "/attributes/{$sizeId}/terms", ['name' => 'Moyen']), 409, function ($d) use ($termMId) {
        if (isset($d['error']['details']['fields'])) {
            return 'a state refusal grew a fields key';
        }

        return ($d['error']['details']['term_id'] ?? null) === $termMId
            ?: 'details are ' . wp_json_encode($d['error']['details'] ?? null);
    });

ac_check('a term slug change is reported', ac_req('PATCH', "/attributes/{$sizeId}/terms/{$termLId}",
    ['slug' => 'large']), 200, function ($d) {
        return ($d['meta']['slug_changed'] ?? false) === true ?: 'the slug change was not reported';
    });

ac_check('an ordinary term write reports no slug change', ac_req('PATCH',
    "/attributes/{$sizeId}/terms/{$termLId}", ['description' => 'Grande taille']), 200, function ($d) {
        return !isset($d['meta']['slug_changed']) ?: 'a slug change was reported on an ordinary write';
    });

ac_check('an empty term patch is refused', ac_req('PATCH', "/attributes/{$sizeId}/terms/{$termLId}", []), 400);
ac_check('an unknown term is not found', ac_req('PATCH', "/attributes/{$sizeId}/terms/9999901",
    ['name' => 'x']), 404);

ac_check('parent is refused by name', ac_req('POST', "/attributes/{$sizeId}/terms",
    ['name' => 'Nested', 'parent' => 1]), 400, function ($d) {
        return str_contains(ac_field_error($d, 'parent'), 'flat') ?: 'the reason does not explain flatness';
    });

echo PHP_EOL, "=== a product actually using it ===", PHP_EOL;

$rug = ac_check('create a product on the attribute', ac_req('POST', '/products', [
    'name' => 'Tapis §88',
    'sku' => 'AC-ATTR-RUG',
    'regular_price' => '4500',
    'status' => 'publish',
    'attributes' => [[
        'id' => $sizeId,
        'options' => ['Moyen'],
        'visible' => true,
    ]],
]), 201);

$rugId = (int) ($rug['data']['id'] ?? 0);

ac_assert('the product really carries the term', $rugId > 0
    && has_term('m', $sizeTax, $rugId) ?: 'the product is not tagged with the term');

ac_check('the attribute now reports the product', ac_req('GET', "/attributes/{$sizeId}"), 200, function ($d) {
    return ($d['data']['product_count'] ?? 0) === 1
        ?: 'product_count is ' . ($d['data']['product_count'] ?? 'absent') . ', expected 1';
});

ac_check('and the term count followed', ac_req('GET', "/attributes/{$sizeId}/terms"), 200, function ($d) {
    foreach ($d['data'] as $term) {
        if ($term['slug'] === 'm') {
            return $term['count'] === 1 ?: 'the term count is ' . $term['count'];
        }
    }

    return 'the term disappeared';
});

echo PHP_EOL, "=== update, and the rename WooCommerce migrates ===", PHP_EOL;

ac_check('rename the label', ac_req('PATCH', "/attributes/{$sizeId}", ['name' => 'Taille du tapis']), 200,
    function ($d) {
        if ($d['data']['name'] !== 'Taille du tapis') {
            return 'the label did not change';
        }

        return !isset($d['meta']['slug_changed']) ?: 'a slug change was reported on a label rename';
    });

ac_check('an empty patch is refused', ac_req('PATCH', "/attributes/{$sizeId}", []), 400);

$renamed = ac_check('change the slug', ac_req('PATCH', "/attributes/{$sizeId}",
    ['slug' => 'acattrrenamed']), 200, function ($d) {
        if ($d['data']['taxonomy'] !== 'pa_acattrrenamed') {
            return 'the taxonomy did not follow: ' . $d['data']['taxonomy'];
        }

        return ($d['meta']['slug_changed'] ?? false) === true ?: 'the slug change was not reported';
    });

/*
 * The reason this write goes through wc_update_attribute() rather than an
 * UPDATE: a rename migrates the term_taxonomy rows, every product's
 * _product_attributes meta and every variation's attribute_pa_* meta key.
 * Writing the table directly renames the attribute and orphans the catalogue.
 */
ac_assert('the product survived the rename', has_term('m', 'pa_acattrrenamed', $rugId)
    ?: 'the product lost its term when the attribute was renamed');

ac_check('and the product still reports the attribute', ac_req('GET', "/products/{$rugId}"), 200,
    function ($d) {
        foreach ($d['data']['attributes'] ?? [] as $attribute) {
            if (($attribute['name'] ?? '') === 'Taille du tapis' || ($attribute['id'] ?? 0) > 0) {
                return true;
            }
        }

        return 'the product no longer carries the attribute: ' . wp_json_encode($d['data']['attributes'] ?? []);
    });

echo PHP_EOL, "=== delete guards ===", PHP_EOL;

ac_check('an attribute in use is refused', ac_req('DELETE', "/attributes/{$sizeId}"), 409, function ($d) use ($rugId) {
    if (($d['error']['details']['products'] ?? 0) !== 1) {
        return 'the refusal does not report the product count';
    }

    if (!in_array($rugId, $d['error']['details']['product_ids'] ?? [], true)) {
        return 'the refusal does not name the product';
    }

    return str_contains((string) $d['error']['message'], 'force=true') ?: 'the refusal does not offer the override';
});

ac_check('a term in use is refused too', ac_req('DELETE', "/attributes/{$sizeId}/terms/{$termMId}"), 409,
    function ($d) {
        return ($d['error']['details']['products'] ?? 0) === 1 ?: 'the term refusal does not report the count';
    });

// The controls. Without these, the two 409s could be a guard that fires always.
ac_check('an unused term deletes', ac_req('DELETE', "/attributes/{$sizeId}/terms/{$termLId}"), 200,
    function ($d) {
        return ($d['data']['products_detached'] ?? -1) === 0 ?: 'an unused term reported detachments';
    });

ac_check('an unused attribute deletes', ac_req('DELETE', "/attributes/{$colourId}"), 200, function ($d) {
    return ($d['data']['products_detached'] ?? -1) === 0 ?: 'an unused attribute reported detachments';
});

ac_check('force deletes a term that is in use', ac_req('DELETE', "/attributes/{$sizeId}/terms/{$termMId}",
    null, ['force' => true]), 200, function ($d) {
        return ($d['data']['products_detached'] ?? 0) === 1 ?: 'the forced delete did not report what it detached';
    });

ac_check('force deletes an attribute that is in use', ac_req('DELETE', "/attributes/{$sizeId}",
    null, ['force' => true]), 200);

ac_check('and it is gone', ac_req('GET', "/attributes/{$sizeId}"), 404);

ac_assert('the taxonomy went with it', !taxonomy_exists('pa_acattrrenamed')
    || get_terms(['taxonomy' => 'pa_acattrrenamed', 'hide_empty' => false]) === []
        ?: 'terms survived the attribute delete');

echo PHP_EOL, "=== audit trail ===", PHP_EOL;

wp_set_current_user(ac_user('ac_attr_auditor', 'ac_admin'));

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

ac_check('the creation was audited', ac_req('GET', '/audit-logs', null, ['action' => 'attribute.created']), 200,
    $find('attribute.created', function ($row) use ($sizeId) {
        return $row['resource_type'] === 'attribute' && $row['resource_id'] === (string) $sizeId;
    }));

ac_check('a slug change names both slugs', ac_req('GET', '/audit-logs', null,
    ['action' => 'attribute.updated', 'per_page' => 50]), 200,
    $find('attribute.updated', function ($row) {
        return ($row['metadata']['slug_from'] ?? '') === 'acattrsize'
            && ($row['metadata']['slug_to'] ?? '') === 'acattrrenamed';
    }));

ac_check('terms are audited', ac_req('GET', '/audit-logs', null, ['action' => 'attribute.term_created']), 200,
    $find('attribute.term_created', fn ($row) => ($row['metadata']['slug'] ?? '') === 'm'));

/*
 * The forced *term* delete is the one that detached a product; by the time the
 * attribute itself was force-deleted its only tagged term was already gone, so
 * that row legitimately records zero. Asserting the wrong one of the two passed
 * a first draft of this file for the wrong reason.
 */
ac_check('a forced delete records what it detached', ac_req('GET', '/audit-logs', null,
    ['action' => 'attribute.term_deleted', 'per_page' => 50]), 200,
    $find('attribute.term_deleted', function ($row) use ($sizeId) {
        return $row['resource_id'] === (string) $sizeId
            && ($row['metadata']['forced'] ?? false) === true
            && ($row['metadata']['products_detached'] ?? 0) === 1;
    }));

ac_check('and the attribute delete records it was forced', ac_req('GET', '/audit-logs', null,
    ['action' => 'attribute.deleted', 'per_page' => 50]), 200,
    $find('attribute.deleted', function ($row) use ($sizeId) {
        return $row['resource_id'] === (string) $sizeId && ($row['metadata']['forced'] ?? false) === true;
    }));

ac_drop_product('AC-ATTR-RUG');

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
