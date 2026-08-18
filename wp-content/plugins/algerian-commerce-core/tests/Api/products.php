<?php
/**
 * Product endpoints — roadmap §47, §65.
 *
 * **This suite exists because the §65 audit found it missing.** Products were
 * the first commerce module built, before `tests/Api/` was a convention, and
 * every module from §49 onward got a suite while this one kept the incidental
 * coverage it picked up from `tests/Api/inventory.php` (four calls) and
 * `tests/Api/seo.php` (three). Nothing drove product CRUD hard, and the price of
 * that showed up immediately: the first walkthrough that trashed a product and
 * re-created its SKU got a **500** out of WooCommerce, which is the regression
 * at the bottom of this file.
 *
 * It covers §65's API list against one resource, in the order §65 writes it:
 * success, bad input, unauthenticated, unauthorized, not found, duplicate,
 * pagination, boundary values.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/products.php
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

/** Purge anything a previous run left behind, trash included. */
function ac_purge_sku(string $sku): void
{
    foreach (['publish', 'draft', 'pending', 'private', 'trash'] as $status) {
        foreach (wc_get_products(['sku' => $sku, 'status' => $status, 'limit' => 20, 'return' => 'ids']) as $id) {
            wp_delete_post((int) $id, true);
        }
    }
}

$SKU = 'ac-test-sku-1';
$SKU_OTHER = 'ac-test-sku-2';

foreach ([$SKU, $SKU_OTHER] as $sku) {
    ac_purge_sku($sku);
}

$admin = ac_user('ac_prod_admin', 'ac_super_admin');
$support = ac_user('ac_prod_support', 'ac_support_agent');

wp_set_current_user($admin);

echo PHP_EOL, "── success ──", PHP_EOL;

$created = ac_check(
    'create a simple product',
    ac_req('POST', '/products', [
        'name' => 'Tapis berbère',
        'type' => 'simple',
        'regular_price' => '4500.00',
        'sku' => $SKU,
        'description' => 'Handwoven.',
        'manage_stock' => true,
        'stock_quantity' => 7,
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['name'] ?? '') === 'Tapis berbère'
        ? true
        : 'name came back as ' . var_export($d['data']['name'] ?? null, true)
);

$productId = (int) ($created['data']['id'] ?? 0);
ac_assert('the created product has an id', $productId > 0 ?: 'no id in the response');

ac_check(
    'read it back',
    ac_req('GET', "/products/{$productId}"),
    200,
    static function (array $d) use ($SKU): bool|string {
        $p = $d['data'] ?? [];

        return ($p['sku'] ?? '') === $SKU && ($p['regular_price'] ?? '') === '4500.00'
            ? true
            : 'sku/price came back as ' . var_export([$p['sku'] ?? null, $p['regular_price'] ?? null], true);
    }
);

ac_check(
    'patch one field without sending the rest',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '3900.00']),
    200,
    static fn (array $d): bool|string => ($d['data']['regular_price'] ?? '') === '3900.00'
        ? true
        : 'price is ' . var_export($d['data']['regular_price'] ?? null, true)
);

ac_check(
    'the name survived a patch that did not mention it',
    ac_req('GET', "/products/{$productId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['name'] ?? '') === 'Tapis berbère'
        ? true
        : 'name is now ' . var_export($d['data']['name'] ?? null, true)
);

/*
 * The round trip a client actually performs: GET the object, change one field,
 * PATCH the whole thing back. `ProductInput::READ_ONLY` drops `id`, `price`,
 * `permalink` and the rest silently for exactly this, so a client is not
 * required to know which of the fields it was handed are writable.
 */
$whole = ac_req('GET', "/products/{$productId}")[1]['data'] ?? [];
$whole['name'] = 'Tapis berbère (grand)';
ac_check('the whole GET body can be PATCHed back', ac_req('PATCH', "/products/{$productId}", $whole), 200);

ac_check('list products', ac_req('GET', '/products', null, ['per_page' => 5]), 200, static function (array $d): bool|string {
    $meta = $d['meta'] ?? [];

    return isset($meta['total'], $meta['page'], $meta['per_page'], $meta['total_pages'])
        && is_array($d['data'] ?? null)
        && count($d['data']) <= 5
        ? true
        : 'pagination meta was ' . wp_json_encode($meta);
});

ac_check(
    'search finds it by name',
    ac_req('GET', '/products', null, ['search' => 'berbère', 'per_page' => 20]),
    200,
    static function (array $d) use ($productId): bool|string {
        foreach ($d['data'] ?? [] as $row) {
            if ((int) ($row['id'] ?? 0) === $productId) {
                return true;
            }
        }

        return 'the product was not in its own search results';
    }
);

$copy = ac_check(
    'duplicate a product',
    ac_req('POST', "/products/{$productId}/duplicate"),
    201,
    static fn (array $d): bool|string => (int) ($d['data']['id'] ?? 0) > 0 ? true : 'no copy id'
);
$copyId = (int) ($copy['data']['id'] ?? 0);

// A copy must not carry the original's SKU: SKUs are unique and WooCommerce
// would refuse the next save.
ac_assert(
    'the copy did not inherit the SKU',
    $copyId > 0 && ($copy['data']['sku'] ?? '') !== $SKU
        ?: 'the copy came back with sku ' . var_export($copy['data']['sku'] ?? null, true)
);

echo PHP_EOL, "── bad input ──", PHP_EOL;

ac_check('a product needs a name', ac_req('POST', '/products', ['regular_price' => '10']), 400);
ac_check('an empty name is refused', ac_req('POST', '/products', ['name' => '   ']), 400);
ac_check(
    'a negative price is refused',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '-1']),
    400
);
ac_check(
    'a non-numeric price is refused',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => 'free']),
    400
);
ac_check(
    'a sale price above the regular price is refused',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '100', 'sale_price' => '200']),
    400
);
ac_check(
    'a sale price above the *stored* regular price is refused',
    ac_req('PATCH', "/products/{$productId}", ['sale_price' => '999999']),
    400
);
ac_check('an unknown type is refused', ac_req('POST', '/products', ['name' => 'x', 'type' => 'bundle']), 400);
ac_check('an unknown status is refused', ac_req('POST', '/products', ['name' => 'x', 'status' => 'live']), 400);
ac_check(
    'an unknown field is refused',
    ac_req('PATCH', "/products/{$productId}", ['nonsense_field' => 1]),
    400
);
ac_check('a malformed body is refused', ac_req('POST', '/products', '{"name": '), 400);
ac_check(
    'an unknown orderby is refused',
    ac_req('GET', '/products', null, ['orderby' => 'post_password']),
    400
);

echo PHP_EOL, "── a refused create leaves nothing behind ──", PHP_EOL;

/*
 * **The status is not the assertion; the absence of the product is.**
 *
 * `seo.image_id` and a bundle's component ids are meta, so they are written
 * after `$product->save()` — and they used to be *validated* after it too,
 * which turned a refusal into a write. Measured 2026-08-18: this exact request
 * answered 400 **and created the product**. Nothing errored, and the checks in
 * "bad input" above all passed, because every one of them asserts a status
 * code.
 *
 * What a person meets is the second attempt: they fix the field, resubmit, and
 * get a 409 on a duplicate SKU with nothing to say where the first product came
 * from. `docs/ADMIN_PANEL.md` Part V has a product form with an SEO image
 * picker, so this is the panel's first screen.
 *
 * §89 found the same defect three times in `src/CMS/` — inherited from here,
 * because the CMS SEO writer was modelled on `ProductRepository::applySeo()`.
 * The rule: resolve every reference before the first write.
 */
$ORPHAN = 'ac-test-orphan-1';
ac_purge_sku($ORPHAN);

ac_check(
    'a create naming an seo.image_id that is not an attachment is refused',
    ac_req('POST', '/products', [
        'name' => 'Orphan probe',
        'sku' => $ORPHAN,
        'regular_price' => '100',
        // The product created two lines above is emphatically not an image.
        'seo' => ['image_id' => $productId],
    ]),
    400,
    static fn ($d): bool|string => isset($d['error']['details']['fields']['seo.image_id'])
        ?: 'the field was not named: ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_assert(
    'and no product was created by it',
    wc_get_product_id_by_sku($ORPHAN) === 0
        ?: 'a refused create left product ' . wc_get_product_id_by_sku($ORPHAN) . ' behind'
);

ac_check(
    'a create whose option choice names a bad image is refused',
    ac_req('POST', '/products', [
        'name' => 'Orphan probe 2',
        'sku' => $ORPHAN,
        'regular_price' => '100',
        'options' => ['groups' => [[
            'key' => 'wrap',
            'label' => 'Emballage',
            'type' => 'select',
            'choices' => [['key' => 'gold', 'label' => 'Or', 'image_id' => $productId]],
        ]]],
    ]),
    400
);

ac_assert(
    'and that one left nothing either',
    wc_get_product_id_by_sku($ORPHAN) === 0
        ?: 'a refused create left product ' . wc_get_product_id_by_sku($ORPHAN) . ' behind'
);

/*
 * The positive control, and it is the half that matters: a fix that refused
 * every `seo` block, or stopped writing SEO at all, would pass both assertions
 * above. So the same write with a real attachment must succeed **and** store
 * what it was given.
 */
$probeImage = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1]);
$probeImageId = $probeImage === [] ? 0 : (int) $probeImage[0]->ID;

ac_assert('there is an image to control against', $probeImageId > 0 ?: 'no image attachment on this install');

ac_check(
    'while the same write with a real attachment is created, SEO and all',
    ac_req('POST', '/products', [
        'name' => 'Orphan probe 3',
        'sku' => $ORPHAN,
        'regular_price' => '100',
        'seo' => ['image_id' => $probeImageId, 'title' => 'Titre SEO'],
    ]),
    201,
    static fn ($d): bool|string => ($d['data']['seo']['title'] ?? '') === 'Titre SEO'
        ?: 'the SEO block was not stored: ' . wp_json_encode($d['data']['seo'] ?? [])
);

ac_purge_sku($ORPHAN);

echo PHP_EOL, "── unauthenticated and unauthorized ──", PHP_EOL;

wp_set_current_user(0);
ac_check('listing needs a credential', ac_req('GET', '/products'), 401);
ac_check('reading one needs a credential', ac_req('GET', "/products/{$productId}"), 401);
ac_check('creating needs a credential', ac_req('POST', '/products', ['name' => 'x']), 401);
ac_check('deleting needs a credential', ac_req('DELETE', "/products/{$productId}"), 401);

// Support Agent holds ac_manage_customers and ac_view_analytics — never
// ac_manage_products. The capability boundary, not the login.
wp_set_current_user($support);
ac_check('a Support Agent cannot list products', ac_req('GET', '/products'), 403);
ac_check('a Support Agent cannot read one', ac_req('GET', "/products/{$productId}"), 403);
ac_check('a Support Agent cannot create', ac_req('POST', '/products', ['name' => 'x']), 403);
ac_check('a Support Agent cannot delete', ac_req('DELETE', "/products/{$productId}"), 403);

wp_set_current_user($admin);

echo PHP_EOL, "── not found ──", PHP_EOL;

ac_check('read a missing product', ac_req('GET', '/products/99999999'), 404);
ac_check('patch a missing product', ac_req('PATCH', '/products/99999999', ['name' => 'x']), 404);
ac_check('delete a missing product', ac_req('DELETE', '/products/99999999'), 404);
ac_check('duplicate a missing product', ac_req('POST', '/products/99999999/duplicate'), 404);
ac_check('variations of a missing product', ac_req('GET', '/products/99999999/variations'), 404);

echo PHP_EOL, "── a variation's SKU is its own ──", PHP_EOL;

/*
 * `WC_Product_Variation::get_sku()` falls back to the parent's SKU in `view`
 * context, so a variation with none of its own used to read back as carrying
 * the parent's — a value this API then refused to accept, because the parent
 * already owns it. `GET` then `PATCH` the whole object, which docs/API.md
 * promises works, was a 409 on `sku`.
 *
 * `sku` on a variation now means the variation's own, and empty means it
 * inherits. That is the invariant: the API emits what it accepts.
 */
$varParent = ac_check('a variable product for the SKU check', ac_req('POST', '/products', [
    'name' => 'Variation SKU probe',
    'sku' => 'AC-PROD-VARSKU',
    'type' => 'variable',
    'status' => 'publish',
    'attributes' => [['name' => 'Size', 'options' => ['S', 'M'], 'variation' => true]],
]), 201);

$varParentId = (int) ($varParent['data']['id'] ?? 0);

$inherited = ac_check('a variation created with no SKU of its own', ac_req('POST',
    "/products/{$varParentId}/variations", ['attributes' => ['size' => 'S'], 'regular_price' => '100']),
    201, function ($d) {
        return ($d['data']['sku'] ?? null) === ''
            ?: 'the variation reported sku ' . var_export($d['data']['sku'] ?? null, true)
                . ' — the parent\'s, which this API will not accept back';
    });

$inheritedId = (int) ($inherited['data']['id'] ?? 0);

ac_check('its read body PATCHes back unchanged', ac_req('GET',
    "/products/{$varParentId}/variations/{$inheritedId}"), 200,
    function ($d) use ($varParentId, $inheritedId) {
        [$status, $body] = ac_req('PATCH', "/products/{$varParentId}/variations/{$inheritedId}", $d['data']);

        if ($status !== 200) {
            return "round-tripping the variation returned {$status}: "
                . substr((string) wp_json_encode($body), 0, 160);
        }

        return ($body['data']['sku'] ?? null) === '' ?: 'the round trip gave the variation a SKU';
    });

// The control: a variation that owns a SKU still reports and round-trips it.
$owned = ac_check('a variation with its own SKU keeps it', ac_req('POST',
    "/products/{$varParentId}/variations",
    ['attributes' => ['size' => 'M'], 'regular_price' => '120', 'sku' => 'AC-PROD-VARSKU-M']),
    201, function ($d) {
        return ($d['data']['sku'] ?? '') === 'AC-PROD-VARSKU-M' ?: 'its own SKU was not stored';
    });

$ownedId = (int) ($owned['data']['id'] ?? 0);

ac_check('and that one round-trips too', ac_req('GET',
    "/products/{$varParentId}/variations/{$ownedId}"), 200, function ($d) use ($varParentId, $ownedId) {
        [$status] = ac_req('PATCH', "/products/{$varParentId}/variations/{$ownedId}", $d['data']);

        return $status === 200 ?: "round-tripping returned {$status}";
    });

// The other control: the duplicate-SKU guard must still refuse a real clash.
ac_check('a SKU another product owns is still refused', ac_req('PATCH',
    "/products/{$varParentId}/variations/{$ownedId}", ['sku' => $SKU]), 409);

/*
 * The audit trail has to agree with the API about what `sku` means, or a reader
 * comparing the two finds a variation that reports no SKU beside a row saying
 * it was created with the parent's. And a deleted variation is identified by
 * its attribute combination — without it the row says "a variation of product
 * 1909 was deleted" and cannot say which.
 */
ac_req('DELETE', "/products/{$varParentId}/variations/{$inheritedId}", null, ['force' => true]);

ac_check('the audit records the variation\'s own sku, not its parent\'s', ac_req('GET', '/audit-logs',
    null, ['action' => 'product.variation_deleted', 'per_page' => 20]), 200,
    function ($d) use ($inheritedId) {
        foreach ($d['data'] as $row) {
            if ($row['resource_id'] !== (string) $inheritedId) {
                continue;
            }

            if (($row['metadata']['sku'] ?? null) !== '') {
                return 'the row records sku ' . var_export($row['metadata']['sku'] ?? null, true)
                    . ' for a variation that had none';
            }

            return ($row['metadata']['attributes'] ?? []) !== []
                ?: 'the row cannot say which variation was deleted';
        }

        return 'no product.variation_deleted row for this variation';
    });

echo PHP_EOL, "── replacing a variable product's attributes ──", PHP_EOL;

/*
 * **This answered 500 before the repair**, and it is the ordinary path: Part V's
 * product form GETs the whole object and PATCHes the whole object back, so
 * editing anything on a variable product resends `attributes`.
 *
 * `WC_Product_Variable::save()` sets a *dropped* variation attribute's key to
 * `null` in the in-memory array rather than unsetting it — the variations are
 * re-synced against those keys — so the object the write returns carries
 * `['size' => null, 'pa_… ' => WC_Product_Attribute]` while a fresh read carries
 * only the second. `ProductPresenter::attributes()` called `is_taxonomy()` on
 * the null and fataled. Measured 2026-08-18 on products 12 and 21.
 *
 * The assertion is deliberately on the *body*, not on the status: returning 200
 * with a stale object would pass a status check, and the stale object is the
 * actual defect — `docs/API.md` promises a read body can be written back, which
 * is only true if a write body reads back the same.
 */
$replaced = ac_check('replacing a variable product\'s attributes is not a 500', ac_req('PATCH',
    "/products/{$varParentId}",
    ['attributes' => [['name' => 'Matter', 'options' => ['Wool'], 'variation' => false]]]), 200);

ac_assert('the write response is what was stored', (function () use ($varParentId, $replaced) {
    [$status, $fresh] = ac_req('GET', "/products/{$varParentId}");

    if ($status !== 200) {
        return "a fresh read answered {$status}";
    }

    return ($replaced['data']['attributes'] ?? null) === ($fresh['data']['attributes'] ?? false)
        ?: 'the PATCH returned ' . wp_json_encode($replaced['data']['attributes'] ?? null)
            . ' and a GET returned ' . wp_json_encode($fresh['data']['attributes'] ?? null);
})());

wp_delete_post($varParentId, true);

echo PHP_EOL, "── duplicate ──", PHP_EOL;

ac_check(
    'a second product cannot take the same SKU',
    ac_req('POST', '/products', ['name' => 'Copy', 'regular_price' => '10', 'sku' => $SKU]),
    409
);
ac_check(
    'a product cannot be patched onto another SKU that is taken',
    ac_req('PATCH', "/products/{$copyId}", ['sku' => $SKU]),
    409
);
ac_check(
    'a product may keep its own SKU on patch',
    ac_req('PATCH', "/products/{$productId}", ['sku' => $SKU]),
    200
);

echo PHP_EOL, "── pagination and boundaries ──", PHP_EOL;

ac_check(
    'per_page is honoured',
    ac_req('GET', '/products', null, ['per_page' => 2]),
    200,
    static fn (array $d): bool|string => count($d['data'] ?? []) <= 2
        ? true
        : 'got ' . count($d['data'] ?? []) . ' rows for per_page=2'
);
ac_check('per_page above the cap is refused', ac_req('GET', '/products', null, ['per_page' => 101]), 400);
ac_check('per_page of zero is refused', ac_req('GET', '/products', null, ['per_page' => 0]), 400);
ac_check('a negative page is refused', ac_req('GET', '/products', null, ['page' => -1]), 400);
ac_check('per_page at the cap is allowed', ac_req('GET', '/products', null, ['per_page' => 100]), 200);
ac_check(
    'a page past the end is empty, not an error',
    ac_req('GET', '/products', null, ['page' => 99999, 'per_page' => 5]),
    200,
    static fn (array $d): bool|string => ($d['data'] ?? []) === [] ? true : 'rows came back past the last page'
);

// Price precision: WooCommerce stores prices as strings and this shop runs two
// decimals. A price that arrives with more must not be silently truncated into
// a different number without the response saying so.
ac_check(
    'a price keeps two decimals',
    ac_req('PATCH', "/products/{$productId}", ['regular_price' => '1234.50']),
    200,
    static fn (array $d): bool|string => ($d['data']['regular_price'] ?? '') === '1234.50'
        ? true
        : 'price came back as ' . var_export($d['data']['regular_price'] ?? null, true)
);

echo PHP_EOL, "── delete, and the SKU the trash keeps ──", PHP_EOL;

/*
 * THE REGRESSION TEST.
 *
 * `DELETE` trashes; `force=true` is permanent. A trashed product still holds
 * its SKU in `wc_product_meta_lookup`, but `wc_get_product_id_by_sku()`
 * excludes trashed rows — so the conflict check said the SKU was free and
 * WooCommerce's own insert then threw "already present in the lookup table",
 * which reached the client as **500 internal_error**. An admin who trashed a
 * product and re-created it hit this every time.
 *
 * `ProductRepository::skuExists()` now looks in the trash as well, and the
 * message names the trashed product rather than saying "already in use" about
 * something that is not in the catalogue.
 */
ac_check('delete trashes by default', ac_req('DELETE', "/products/{$copyId}"), 200);
ac_assert(
    'the trashed product is really in the trash',
    get_post_status($copyId) === 'trash' ?: 'status is ' . var_export(get_post_status($copyId), true)
);

ac_check('patch the surviving product onto a free SKU', ac_req('PATCH', "/products/{$productId}", ['sku' => $SKU_OTHER]), 200);

// $SKU is now held only by the trashed original... after we trash it too.
ac_check('trash the original as well', ac_req('DELETE', "/products/{$productId}"), 200);

$reborn = ac_check(
    'a SKU held by a trashed product is a conflict, not a 500',
    ac_req('POST', '/products', ['name' => 'Reborn', 'regular_price' => '10', 'sku' => $SKU_OTHER]),
    409,
    static fn (array $d): bool|string => isset($d['error']['details']['trashed_product_id'])
        ? true
        : 'the conflict did not name the trashed product: ' . substr((string) wp_json_encode($d), 0, 200)
);

ac_check('force=true removes it permanently', ac_req('DELETE', "/products/{$productId}", null, ['force' => true]), 200);
ac_assert(
    'the forced delete really removed the row',
    get_post_status($productId) === false ?: 'status is still ' . var_export(get_post_status($productId), true)
);

ac_check(
    'and now the SKU can be used again',
    ac_req('POST', '/products', ['name' => 'Reborn', 'regular_price' => '10', 'sku' => $SKU_OTHER]),
    201
);

/*
 * ── ROADMAP §82: FILTERING AND FACETED SEARCH ─────────────────────────────
 *
 * The suite builds its own catalogue rather than leaning on §67's seed, for a
 * reason worth writing down: **the seeded shop has nothing to facet.** Its two
 * attribute-bearing products carry *custom* attributes — "Taille" and
 * "Finition" as plain strings on one product each — and this install registers
 * no global attribute taxonomies at all (measured 2026-08-17). A suite written
 * against it would assert counts of zero and pass whatever the code did.
 *
 * So: two global attributes, five terms, six products with known prices, and
 * every count below is exact. It is deleted again at the end, as the SKU
 * fixtures above are.
 *
 *   n  price  sale  matière  couleur  stock  featured  cat  tag
 *   1   100    —    laine    rouge    in       no       C    —
 *   2   200   190   laine    bleu     in      yes       C    T
 *   3   300    —    cuivre   rouge    in       no       —    T
 *   4   400    —    cuivre   bleu     out      no       —    —
 *   5   500    —    argent   rouge    in       no       C    —
 *   6   600   590   argent   bleu     in       no       —    —
 *
 * Effective prices are therefore 100, 190, 300, 400, 500, 590 — the sale price
 * is what a shopper filters on, and two of the six are on sale to prove it.
 */

echo PHP_EOL, "── §82 filtering and faceted search: fixture ──", PHP_EOL;

const AC82 = 'F82Fixture';

function ac82_attribute(string $slug, string $label): int
{
    $id = (int) wc_attribute_taxonomy_id_by_name($slug);

    if ($id === 0) {
        $created = wc_create_attribute([
            'name' => $label,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ]);

        $id = is_wp_error($created) ? 0 : (int) $created;
    }

    /*
     * WooCommerce registers attribute taxonomies on `init`, which ran long
     * before this line. Without registering it here the terms below cannot be
     * inserted and every query against it matches nothing — silently.
     */
    $taxonomy = wc_attribute_taxonomy_name($slug);

    if (!taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, ['product'], [
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);
    }

    /*
     * AND the `$wc_product_attributes` global, which is the half that is easy
     * to miss and cost an hour here.
     *
     * `ProductCollectionData` skips any taxonomy that fails
     * `taxonomy_is_product_attribute()`, and that function tests
     * `taxonomy_exists()` **and** membership of this global — which
     * `WC_Post_Types::register_taxonomies()` fills on `init`, from the same
     * table `wc_get_attribute_taxonomies()` reads. So an attribute created
     * *after* `init` is registered, queryable, and invisible to the facet
     * counter, which returns an empty list rather than an error.
     *
     * On a live shop the two never disagree: the attribute is created by one
     * request and counted by a later one, and every request runs `init` first.
     * It is only a fixture built inside a single process that can see the gap,
     * so this line is the test harness doing what the next request would.
     */
    $GLOBALS['wc_product_attributes'][$taxonomy] = (object) [
        'attribute_id' => $id,
        'attribute_name' => $slug,
        'attribute_label' => $label,
        'attribute_type' => 'select',
        'attribute_orderby' => 'menu_order',
        'attribute_public' => 0,
    ];

    return $id;
}

function ac82_term(string $taxonomy, string $slug, string $name): int
{
    $term = get_term_by('slug', $slug, $taxonomy);

    if (is_object($term)) {
        return (int) $term->term_id;
    }

    $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);

    return is_wp_error($created) ? 0 : (int) $created['term_id'];
}

/**
 * @param array<string, array{int, int}> $attributes taxonomy => [attribute id, term id]
 * @param list<int>                      $categories
 * @param list<int>                      $tags
 */
function ac82_product(
    string $sku,
    string $price,
    ?string $sale,
    array $attributes,
    bool $inStock,
    bool $featured,
    array $categories,
    array $tags
): int {
    ac_purge_sku($sku);

    $product = new WC_Product_Simple();
    $product->set_name(AC82 . ' ' . $sku);
    $product->set_sku($sku);
    $product->set_regular_price($price);

    if ($sale !== null) {
        $product->set_sale_price($sale);
    }

    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_stock_status($inStock ? 'instock' : 'outofstock');
    $product->set_featured($featured);
    $product->set_category_ids($categories);
    $product->set_tag_ids($tags);

    $built = [];

    foreach ($attributes as $taxonomy => [$attributeId, $termId]) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_id($attributeId);
        $attribute->set_name($taxonomy);
        $attribute->set_options([$termId]);
        $attribute->set_visible(true);
        $attribute->set_variation(false);
        $built[] = $attribute;
    }

    $product->set_attributes($built);

    return (int) $product->save();
}

/** The `total` of a filtered listing — the number every assertion below is about. */
function ac82_total(array $query): int
{
    [$status, $data] = ac_req('GET', '/products', null, ['search' => AC82, 'per_page' => 50] + $query);

    return $status === 200 ? (int) ($data['meta']['total'] ?? -1) : -$status;
}

/**
 * The fixture's SKUs in the order a listing returned them.
 *
 * An ordering assertion has to compare a *sequence*, which is why this exists
 * beside `ac82_total()`. Asserting a status code, or a count, is exactly what
 * let five of the eight `orderby` values ship without sorting: they answered 200
 * with all six rows, in date order, and every test that looked at them passed.
 */
function ac82_order(string $orderby, string $order): array
{
    [$status, $data] = ac_req('GET', '/products', null, [
        'search' => AC82,
        'per_page' => 50,
        'orderby' => $orderby,
        'order' => $order,
    ]);

    if ($status !== 200) {
        return ["HTTP {$status}"];
    }

    return array_column($data['data'], 'sku');
}

/** One attribute facet group as `slug => count`. */
function ac82_attribute_counts(array $data, string $taxonomy): array
{
    $counts = [];

    foreach ($data['meta']['facets']['attributes']['groups'] ?? [] as $group) {
        if (($group['taxonomy'] ?? '') !== $taxonomy) {
            continue;
        }

        foreach ($group['values'] ?? [] as $value) {
            $counts[$value['slug']] = (int) $value['count'];
        }
    }

    return $counts;
}

$matiereId = ac82_attribute('f82matiere', 'F82 Matière');
$couleurId = ac82_attribute('f82couleur', 'F82 Couleur');
$MATIERE = wc_attribute_taxonomy_name('f82matiere');
$COULEUR = wc_attribute_taxonomy_name('f82couleur');

ac_assert(
    'two global attributes exist for the fixture',
    ($matiereId > 0 && $couleurId > 0 && taxonomy_exists($MATIERE) && taxonomy_exists($COULEUR))
        ?: "attributes came back as {$matiereId}/{$couleurId}"
);

$laine = ac82_term($MATIERE, 'f82-laine', 'Laine');
$cuivre = ac82_term($MATIERE, 'f82-cuivre', 'Cuivre');
$argent = ac82_term($MATIERE, 'f82-argent', 'Argent');
$rouge = ac82_term($COULEUR, 'f82-rouge', 'Rouge');
$bleu = ac82_term($COULEUR, 'f82-bleu', 'Bleu');

$catTerm = wp_insert_term('F82 Categorie', 'product_cat', ['slug' => 'f82-cat']);
$CAT = is_wp_error($catTerm) ? (int) get_term_by('slug', 'f82-cat', 'product_cat')->term_id : (int) $catTerm['term_id'];
$tagTerm = wp_insert_term('F82 Etiquette', 'product_tag', ['slug' => 'f82-tag']);
$TAG = is_wp_error($tagTerm) ? (int) get_term_by('slug', 'f82-tag', 'product_tag')->term_id : (int) $tagTerm['term_id'];

$fixture = [
    ac82_product('AC-F82-1', '100', null, [$MATIERE => [$matiereId, $laine], $COULEUR => [$couleurId, $rouge]], true, false, [$CAT], []),
    ac82_product('AC-F82-2', '200', '190', [$MATIERE => [$matiereId, $laine], $COULEUR => [$couleurId, $bleu]], true, true, [$CAT], [$TAG]),
    ac82_product('AC-F82-3', '300', null, [$MATIERE => [$matiereId, $cuivre], $COULEUR => [$couleurId, $rouge]], true, false, [], [$TAG]),
    ac82_product('AC-F82-4', '400', null, [$MATIERE => [$matiereId, $cuivre], $COULEUR => [$couleurId, $bleu]], false, false, [], []),
    ac82_product('AC-F82-5', '500', null, [$MATIERE => [$matiereId, $argent], $COULEUR => [$couleurId, $rouge]], true, false, [$CAT], []),
    ac82_product('AC-F82-6', '600', '590', [$MATIERE => [$matiereId, $argent], $COULEUR => [$couleurId, $bleu]], true, false, [], []),
];

ac_assert('six fixture products were created', count(array_filter($fixture)) === 6 ?: 'ids: ' . wp_json_encode($fixture));

/*
 * THE POSITIVE CONTROL, and §65's rule about why it is here: a filter that
 * matches nothing and a fixture that was never built look identical from
 * outside. Everything below is a *narrowing* of this number.
 */
ac_assert('the unfiltered fixture is six products', ac82_total([]) === 6 ?: 'total was ' . ac82_total([]));

echo PHP_EOL, "── each filter narrows to a known count ──", PHP_EOL;

ac_assert('min_price and max_price: 150–450 is three', ac82_total(['min_price' => 150, 'max_price' => 450]) === 3
    ?: 'got ' . ac82_total(['min_price' => 150, 'max_price' => 450]));

/*
 * `max_price=200` returning two rather than three is the assertion that proves
 * the band reads the **effective** price: product 2 is priced 200 and on sale
 * at 190, so it is in; product 3 at 300 is out. A band reading the regular
 * price would answer three.
 */
ac_assert('the band reads the sale price, not the regular one', ac82_total(['max_price' => 200]) === 2
    ?: 'got ' . ac82_total(['max_price' => 200]));

ac_assert('min_price alone: 450 and above is two', ac82_total(['min_price' => 450]) === 2
    ?: 'got ' . ac82_total(['min_price' => 450]));

ac_assert('a band matching nothing is empty, not everything', ac82_total(['min_price' => 900, 'max_price' => 1000]) === 0
    ?: 'got ' . ac82_total(['min_price' => 900, 'max_price' => 1000]));

ac_assert('attributes: laine is two', ac82_total(['attributes' => [$MATIERE => 'f82-laine']]) === 2
    ?: 'got ' . ac82_total(['attributes' => [$MATIERE => 'f82-laine']]));

ac_assert('an attribute key without the pa_ prefix resolves too',
    ac82_total(['attributes' => ['f82matiere' => 'f82-laine']]) === 2
    ?: 'got ' . ac82_total(['attributes' => ['f82matiere' => 'f82-laine']]));

ac_assert('two values of one attribute are alternatives',
    ac82_total(['attributes' => [$MATIERE => 'f82-laine,f82-cuivre']]) === 4
    ?: 'got ' . ac82_total(['attributes' => [$MATIERE => 'f82-laine,f82-cuivre']]));

ac_assert('stock_status: one is out of stock', ac82_total(['stock_status' => 'outofstock']) === 1
    ?: 'got ' . ac82_total(['stock_status' => 'outofstock']));

ac_assert('on_sale=true is two', ac82_total(['on_sale' => 'true']) === 2
    ?: 'got ' . ac82_total(['on_sale' => 'true']));

ac_assert('on_sale=false is the other four', ac82_total(['on_sale' => 'false']) === 4
    ?: 'got ' . ac82_total(['on_sale' => 'false']));

ac_assert('featured=true is one', ac82_total(['featured' => 'true']) === 1
    ?: 'got ' . ac82_total(['featured' => 'true']));

ac_assert('category is three', ac82_total(['category' => (string) $CAT]) === 3
    ?: 'got ' . ac82_total(['category' => (string) $CAT]));

ac_assert('category stays repeatable', ac82_total(['category' => $CAT . ',' . $CAT]) === 3
    ?: 'got ' . ac82_total(['category' => $CAT . ',' . $CAT]));

ac_assert('tag is two', ac82_total(['tag' => (string) $TAG]) === 2
    ?: 'got ' . ac82_total(['tag' => (string) $TAG]));

echo PHP_EOL, "── every published orderby actually sorts ──", PHP_EOL;

/*
 * `ProductInput::ORDERBY` publishes eight values. Measured 2026-08-18, before
 * the repair: `id`, `price`, `sku`, `popularity` and `rating` each returned the
 * full 28-row catalogue in **byte-identical order to `date`**, in both
 * directions — accepted with a 200 and silently unsorted, because
 * `WC_Product_Data_Store_CPT` drops the `orderby` vocabulary it does not
 * recognise. See `ProductRepository::orderingClause()`.
 *
 * The fixture's effective prices are deliberately distinct — 100, 190 (sale),
 * 300, 400, 500, 590 — so price order and SKU order are different sequences and
 * a fallback to either cannot pass as the other.
 */
$byPrice = ['AC-F82-1', 'AC-F82-2', 'AC-F82-3', 'AC-F82-4', 'AC-F82-5', 'AC-F82-6'];

ac_assert('price ascending is price order', ac82_order('price', 'asc') === $byPrice
    ?: 'got ' . wp_json_encode(ac82_order('price', 'asc')));

ac_assert('price descending is the reverse', ac82_order('price', 'desc') === array_reverse($byPrice)
    ?: 'got ' . wp_json_encode(ac82_order('price', 'desc')));

ac_assert('sku ascending is SKU order', ac82_order('sku', 'asc') === $byPrice
    ?: 'got ' . wp_json_encode(ac82_order('sku', 'asc')));

// The control that makes the three above mean something. If `orderby` were still
// being dropped, every one of them would return this sequence instead — so an
// assertion that price order *differs* from date order is the one that fails when
// the repair is reverted.
$byDate = ac82_order('date', 'desc');

ac_assert('date order is not price order', $byDate !== $byPrice
    ?: 'date and price returned the same sequence — orderby is being ignored again');

ac_assert('ascending and descending differ', ac82_order('price', 'asc') !== ac82_order('price', 'desc')
    ?: 'order=asc and order=desc returned the same sequence');

ac_assert('every row survives being sorted', count(ac82_order('price', 'asc')) === 6
    ?: 'sorting by price returned ' . count(ac82_order('price', 'asc')) . ' of 6 rows');

echo PHP_EOL, "── two filters compose ──", PHP_EOL;

ac_assert('cuivre AND in stock is one',
    ac82_total(['attributes' => [$MATIERE => 'f82-cuivre'], 'stock_status' => 'instock']) === 1
    ?: 'got ' . ac82_total(['attributes' => [$MATIERE => 'f82-cuivre'], 'stock_status' => 'instock']));

// Different attributes narrow together; values inside one are alternatives.
ac_assert('laine AND bleu is one',
    ac82_total(['attributes' => [$MATIERE => 'f82-laine', $COULEUR => 'f82-bleu']]) === 1
    ?: 'got ' . ac82_total(['attributes' => [$MATIERE => 'f82-laine', $COULEUR => 'f82-bleu']]));

ac_assert('a price band AND a category is two',
    ac82_total(['category' => (string) $CAT, 'max_price' => 300]) === 2
    ?: 'got ' . ac82_total(['category' => (string) $CAT, 'max_price' => 300]));

ac_assert('a combination matching nothing is empty',
    ac82_total(['attributes' => [$MATIERE => 'f82-laine'], 'stock_status' => 'outofstock']) === 0
    ?: 'got ' . ac82_total(['attributes' => [$MATIERE => 'f82-laine'], 'stock_status' => 'outofstock']));

echo PHP_EOL, "── the rule that makes a facet correct ──", PHP_EOL;

/*
 * §82's central assertion, and the one that catches the wrong implementation.
 *
 * With `matière = laine` selected, the **matière** facet must still report how
 * many products exist in cuivre and argent — its own filter lifted — or
 * selecting one option makes every sibling read zero and the shopper's only way
 * out of the dead end is the back button. Every *other* facet does narrow by
 * laine, which is the second half of the same rule and the half a single-filter
 * test cannot see. That is why the fixture carries two attributes.
 */
$faceted = ac_check(
    'a faceted listing answers 200',
    ac_req('GET', '/products', null, [
        'search' => AC82,
        'per_page' => 50,
        'attributes' => [$MATIERE => 'f82-laine'],
        'facets' => 'attributes,price,category,stock_status',
    ]),
    200
);

$matiereCounts = ac82_attribute_counts($faceted, $MATIERE);
$couleurCounts = ac82_attribute_counts($faceted, $COULEUR);

ac_assert(
    'the selected facet lifts its OWN filter (2/2/2)',
    ($matiereCounts['f82-laine'] ?? 0) === 2
        && ($matiereCounts['f82-cuivre'] ?? 0) === 2
        && ($matiereCounts['f82-argent'] ?? 0) === 2
        ?: 'matière counts were ' . wp_json_encode($matiereCounts)
);

ac_assert(
    'every OTHER facet still narrows by it (rouge 1, bleu 1)',
    ($couleurCounts['f82-rouge'] ?? 0) === 1 && ($couleurCounts['f82-bleu'] ?? 0) === 1
        ?: 'couleur counts were ' . wp_json_encode($couleurCounts)
);

/*
 * The price facet, both halves of the same rule.
 *
 * Under `matière = laine` it reports 100–190, the laine products' own range:
 * a facet narrows by every filter **except its own**, and an attribute filter
 * is somebody else's. Its own filter is the price band, and lifting that is
 * the assertion below.
 */
ac_assert(
    'the price facet narrows by the other filters',
    ($faceted['meta']['facets']['price']['min'] ?? null) === '100.00'
        && ($faceted['meta']['facets']['price']['max'] ?? null) === '190.00'
        ?: 'price facet was ' . wp_json_encode($faceted['meta']['facets']['price'] ?? null)
);

$bandFaceted = ac_req('GET', '/products', null, [
    'search' => AC82,
    'per_page' => 50,
    'min_price' => 150,
    'max_price' => 450,
    'facets' => 'price',
])[1];

ac_assert(
    'the price facet lifts its OWN band (100–590, not 150–450)',
    ($bandFaceted['meta']['facets']['price']['min'] ?? null) === '100.00'
        && ($bandFaceted['meta']['facets']['price']['max'] ?? null) === '590.00'
        ?: 'price facet was ' . wp_json_encode($bandFaceted['meta']['facets']['price'] ?? null)
);

$stockFaceted = ac_req('GET', '/products', null, [
    'search' => AC82,
    'per_page' => 50,
    'stock_status' => 'instock',
    'facets' => 'stock_status',
])[1];

$stockCounts = [];
foreach ($stockFaceted['meta']['facets']['stock_status'] ?? [] as $row) {
    $stockCounts[$row['value']] = (int) $row['count'];
}

ac_assert(
    'the stock facet lifts its own filter (5 in, 1 out)',
    ($stockCounts['instock'] ?? 0) === 5 && ($stockCounts['outofstock'] ?? 0) === 1
        ?: 'stock counts were ' . wp_json_encode($stockCounts)
);

$categoryValues = $faceted['meta']['facets']['category']['values'] ?? [];
$categoryCount = 0;
foreach ($categoryValues as $value) {
    if ((int) $value['term_id'] === $CAT) {
        $categoryCount = (int) $value['count'];
    }
}

// Narrowed to laine, the category holds two of its three products.
ac_assert('the category facet narrows by the attribute filter', $categoryCount === 2
    ?: 'category count was ' . $categoryCount . ' in ' . wp_json_encode($categoryValues));

echo PHP_EOL, "── what a facet block says about itself ──", PHP_EOL;

ac_assert(
    'facets are absent unless asked for',
    !isset(ac_req('GET', '/products', null, ['search' => AC82])[1]['meta']['facets'])
        ?: 'a listing that asked for no facets carried a facet block'
);

ac_assert(
    'the block names its scope rather than leaving it to be discovered',
    ($faceted['meta']['facets']['scope'] ?? null) === 'publish'
        && is_string($faceted['meta']['facets']['scope_note'] ?? null)
        ?: 'scope was ' . wp_json_encode($faceted['meta']['facets']['scope'] ?? null)
);

$matiereGroup = null;
foreach ($faceted['meta']['facets']['attributes']['groups'] ?? [] as $group) {
    if (($group['taxonomy'] ?? '') === $MATIERE) {
        $matiereGroup = $group;
    }
}

ac_assert(
    'a bounded list says whether it was bounded',
    is_array($matiereGroup)
        && ($matiereGroup['total_values'] ?? null) === 3
        && ($matiereGroup['truncated'] ?? null) === false
        ?: 'group was ' . wp_json_encode($matiereGroup)
);

ac_assert(
    'the API reports which attributes are facetable',
    in_array($MATIERE, $faceted['meta']['facets']['attributes']['facetable'] ?? [], true)
        && is_string($faceted['meta']['facets']['attributes']['note'] ?? null)
        ?: 'facetable list was ' . wp_json_encode($faceted['meta']['facets']['attributes']['facetable'] ?? null)
);

echo PHP_EOL, "── bad filter input is a 400, never a 500 ──", PHP_EOL;

/*
 * §82: a shop that discovers its filters do not work, with no error anywhere,
 * concludes the feature is broken. A custom attribute has no term to count, so
 * naming one is refused *with the reason and the alternatives* — §61's
 * malformed-section rule applied to a filter.
 */
$unknown = ac_check(
    'an unknown attribute is a 400',
    ac_req('GET', '/products', null, ['attributes' => ['pa_nonexistent' => 'x']]),
    400
);

ac_assert(
    'and it names the facetable attributes instead of just refusing',
    in_array($MATIERE, $unknown['error']['details']['facetable_attributes'] ?? [], true)
        && str_contains((string) ($unknown['error']['details']['fields']['attributes'] ?? ''), 'global attribute')
        ?: 'details were ' . substr((string) wp_json_encode($unknown['error']['details'] ?? null), 0, 300)
);

ac_check('an unknown facet group is a 400', ac_req('GET', '/products', null, ['facets' => 'everything']), 400);
ac_check('an inverted price band is a 400', ac_req('GET', '/products', null, ['min_price' => 500, 'max_price' => 100]), 400);
ac_check('a negative price is a 400', ac_req('GET', '/products', null, ['min_price' => -1]), 400);
ac_check('a non-numeric price is a 400', ac_req('GET', '/products', null, ['max_price' => 'cheap']), 400);
ac_check('an unknown stock status is a 400', ac_req('GET', '/products', null, ['stock_status' => 'maybe']), 400);
ac_check('a rating above five is a 400', ac_req('GET', '/products', null, ['rating_min' => 6]), 400);
ac_check('a non-numeric category is a 400', ac_req('GET', '/products', null, ['category' => 'tapis']), 400);
ac_check('a scalar attributes value is a 400', ac_req('GET', '/products', null, ['attributes' => 'pa_size']), 400);

echo PHP_EOL, "── §65: a filter payload must not widen a result set ──", PHP_EOL;

/*
 * The assertion that matters, and the reason it is written as a *count* rather
 * than as a status: "200, no crash" is what a working injection returns. Six is
 * the whole fixture, so any payload below that answers six has widened the
 * result set past the filter it was given.
 */
$widening = [
    'a quoted attribute value' => ['attributes' => [$MATIERE => "f82-laine' OR '1'='1"]],
    'a UNION in an attribute value' => ['attributes' => [$MATIERE => "f82-laine' UNION SELECT 1 -- "]],
    'a comment in an attribute value' => ['attributes' => [$MATIERE => 'f82-laine%']],
    'a wildcard as an attribute value' => ['attributes' => [$MATIERE => '%']],
];

foreach ($widening as $label => $query) {
    $total = ac82_total($query);
    ac_assert("{$label} does not widen", ($total >= 0 && $total <= 2) ?: "returned {$total} of 6");
}

$refused = [
    'an OR clause in a category' => ['category' => '1 OR 1=1'],
    'a UNION in a category' => ['category' => '1 UNION SELECT 1'],
    'an injected attribute NAME' => ['attributes' => ["pa_size' OR '1'='1" => 'm']],
    'an injected stock status' => ['stock_status' => "instock' OR '1'='1"],
    'an injected price' => ['min_price' => '0 OR 1=1'],
    'an injected orderby' => ['orderby' => 'price) UNION SELECT'],
];

foreach ($refused as $label => $query) {
    ac_check("{$label} is refused outright", ac_req('GET', '/products', null, ['search' => AC82] + $query), 400);
}

echo PHP_EOL, "── §82 fixture teardown ──", PHP_EOL;

foreach (['AC-F82-1', 'AC-F82-2', 'AC-F82-3', 'AC-F82-4', 'AC-F82-5', 'AC-F82-6'] as $sku) {
    ac_purge_sku($sku);
}

foreach ([$matiereId, $couleurId] as $attributeId) {
    if ($attributeId > 0) {
        wc_delete_attribute($attributeId);
    }
}

wp_delete_term($CAT, 'product_cat');
wp_delete_term($TAG, 'product_tag');

ac_assert(
    'the fixture left nothing behind',
    wc_get_products(['limit' => 5, 'return' => 'ids', 'status' => ['publish', 'draft', 'trash'], 's' => AC82]) === []
        ?: 'fixture products survived teardown'
);

// Tidy up, so a re-run starts where it started.
foreach ([$SKU, $SKU_OTHER] as $sku) {
    ac_purge_sku($sku);
}
wp_delete_post($copyId, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
