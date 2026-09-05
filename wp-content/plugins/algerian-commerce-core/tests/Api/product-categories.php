<?php
/**
 * Product category CRUD — the write endpoints that let the admin panel
 * create categories like "Tapis" without dropping into wp-admin.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/product-categories.php
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

// ------------------------------------------------------------ auth as staff --
// The suite runs unauthenticated by default. These endpoints require
// MANAGE_PRODUCTS, so set a user with the role.
$staff = get_user_by('login', 'admin');
if (!$staff instanceof WP_User) {
    $staff = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
}
ac_assert('an administrator user exists', $staff instanceof WP_User ?: 'no admin found');
wp_set_current_user($staff->ID);

// Tidy up any leftover fixtures from prior runs.
foreach (['ac-cat-tapis', 'ac-cat-tapis-2', 'ac-cat-tapis-child', 'ac-cat-rename'] as $slug) {
    $term = get_term_by('slug', $slug, 'product_cat');
    if ($term instanceof WP_Term) {
        wp_delete_term($term->term_id, 'product_cat');
    }
}
foreach (['Tapis', 'Tapis 2', 'Tapis moderne'] as $name) {
    $term = get_term_by('name', $name, 'product_cat');
    if ($term instanceof WP_Term) {
        wp_delete_term($term->term_id, 'product_cat');
    }
}

echo PHP_EOL, "── create ──", PHP_EOL;

$created = ac_check(
    'a well-formed create succeeds',
    ac_req('POST', '/product-categories', [
        'name' => 'Tapis',
        'slug' => 'ac-cat-tapis',
        'description' => 'Berber wool rugs.',
    ]),
    201,
    static function (array $d): bool|string {
        $data = $d['data'] ?? [];

        return ($data['name'] ?? '') === 'Tapis'
            && ($data['slug'] ?? '') === 'ac-cat-tapis'
            && (int) ($data['parent'] ?? -1) === 0
            && (int) ($data['count'] ?? -1) === 0
            ? true
            : 'body was ' . wp_json_encode($data);
    }
);

$tapisId = (int) ($created['data']['id'] ?? 0);
ac_assert('the created category has an id', $tapisId > 0 ?: "no id in {$tapisId}");

// WordPress auto-suffixes a duplicate slug rather than erroring. The
// admin form should rely on the returned slug (a `-2` suffix), not the
// sent one. Documented as behaviour so a caller cannot be surprised.
$dup = ac_check(
    'a duplicate slug is auto-suffixed by WordPress',
    ac_req('POST', '/product-categories', ['name' => 'Tapis 2', 'slug' => 'ac-cat-tapis']),
    201,
    static fn (array $d): bool|string => ($d['data']['slug'] ?? '') !== 'ac-cat-tapis'
        ?: 'slug came back unchanged: ' . wp_json_encode($d['data'] ?? null)
);
$dupId = (int) ($dup['data']['id'] ?? 0);
if ($dupId > 0) {
    wp_delete_term($dupId, 'product_cat');
}

ac_check(
    'a missing name is refused',
    ac_req('POST', '/product-categories', ['description' => 'oops']),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['name']) ?: 'no name error'
);

ac_check(
    'a malformed slug is refused',
    ac_req('POST', '/product-categories', ['name' => 'x', 'slug' => 'Bad Slug!']),
    400
);

ac_check(
    'an unknown parent is refused',
    ac_req('POST', '/product-categories', ['name' => 'Orphan', 'parent' => 999999]),
    400
);

echo PHP_EOL, "── nested ──", PHP_EOL;

$child = ac_check(
    'a child under the created category',
    ac_req('POST', '/product-categories', [
        'name' => 'Tapis moderne',
        'slug' => 'ac-cat-tapis-child',
        'parent' => $tapisId,
    ]),
    201,
    static fn (array $d): bool|string => (int) ($d['data']['parent'] ?? 0) === $tapisId ?: 'wrong parent'
);
$childId = (int) ($child['data']['id'] ?? 0);

echo PHP_EOL, "── update ──", PHP_EOL;

ac_check(
    'a description PATCH works',
    ac_req('PATCH', "/product-categories/{$tapisId}", ['description' => 'Rugs, updated.']),
    200,
    static fn (array $d): bool|string => ($d['data']['description'] ?? '') === 'Rugs, updated.' ?: 'not updated'
);

ac_check(
    'a category cannot be its own parent',
    ac_req('PATCH', "/product-categories/{$tapisId}", ['parent' => $tapisId]),
    400
);

echo PHP_EOL, "── read ──", PHP_EOL;

ac_check(
    'the category shows up in the list',
    ac_req('GET', '/product-categories', null, ['search' => 'Tapis', 'per_page' => 100]),
    200,
    static function (array $d) use ($tapisId): bool|string {
        foreach ($d['data'] ?? [] as $entry) {
            if ((int) $entry['id'] === $tapisId) {
                return true;
            }
        }

        return 'created category not returned by index';
    }
);

ac_check(
    'the show endpoint returns it',
    ac_req('GET', "/product-categories/{$tapisId}"),
    200,
    static fn (array $d): bool|string => (int) ($d['data']['id'] ?? 0) === $tapisId ?: 'wrong id'
);

ac_check(
    'a non-existent id 404s',
    ac_req('GET', '/product-categories/999999'),
    404
);

echo PHP_EOL, "── delete ──", PHP_EOL;

// Reassign the child away first, then delete the parent. A different order
// is the whole point of the count>0 check tested below.
ac_check(
    'the child deletes fine while empty',
    ac_req('DELETE', "/product-categories/{$childId}"),
    200
);

// Attach a product to the tapis category so the count>0 rule fires.
$sku = 'ac-cat-fixture';
foreach (wc_get_products(['sku' => $sku, 'limit' => 5, 'return' => 'ids']) as $id) {
    wp_delete_post((int) $id, true);
}
$product = new WC_Product_Simple();
$product->set_name('Tapis fixture');
$product->set_sku($sku);
$product->set_regular_price('1000');
$product->set_status('publish');
$product->set_category_ids([$tapisId]);
$productId = $product->save();
ac_assert('a fixture product on the category exists', $productId > 0 ?: 'could not create fixture');

// wp_count_terms is cached; force refresh.
clean_term_cache([$tapisId], 'product_cat');

ac_check(
    'a non-empty category refuses delete',
    ac_req('DELETE', "/product-categories/{$tapisId}"),
    400,
    static fn (array $d): bool|string => str_contains(
        (string) ($d['error']['message'] ?? ''),
        'still contains'
    ) ?: 'wrong message'
);

ac_check(
    'force=true deletes even a non-empty category',
    ac_req('DELETE', "/product-categories/{$tapisId}", null, ['force' => true]),
    200
);

ac_check(
    'the deleted id is now a 404',
    ac_req('GET', "/product-categories/{$tapisId}"),
    404
);

// Teardown.
wp_delete_post($productId, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
