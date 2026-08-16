<?php
/**
 * SEO data on the content payloads — roadmap §62, docs/PLAN.md §25.
 *
 * §62 has no endpoints of its own: `seo` is a block on the resources that
 * already exist, and it is written through the resource's own PATCH. So this
 * suite exercises it where it actually lives — inside `/products` and
 * `/cms/pages` — which is also the only way to prove the derived defaults are
 * computed from real content rather than from a fixture the resolver was handed.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/seo.php
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

/**
 * Fixtures are removed and rebuilt, not reused.
 *
 * This suite asserts on **derived** values — a title composed from the product
 * name, a description trimmed out of the body, an empty `overrides` list — so a
 * leftover row carrying yesterday's overrides makes it fail for reasons that
 * have nothing to do with the code. Every other suite here can reuse its
 * fixtures; this one cannot.
 */
function ac_drop_product(string $sku): void
{
    $id = (int) wc_get_product_id_by_sku($sku);

    if ($id > 0) {
        wp_delete_post($id, true);
    }
}

function ac_drop_page(string $slug): void
{
    $page = get_page_by_path($slug, OBJECT, 'page');

    if ($page instanceof WP_Post) {
        wp_delete_post((int) $page->ID, true);
    }
}

$admin = ac_user('ac_seo_admin', 'ac_admin');
wp_set_current_user($admin);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

foreach (['AC-SEO-TAPIS', 'AC-SEO-NOPRICE', 'AC-SEO-DRAFT'] as $sku) {
    ac_drop_product($sku);
}

ac_drop_page('ac-seo-livraison');

$created = ac_check('create a product', ac_req('POST', '/products', [
    'name' => 'Tapis berbère',
    'sku' => 'AC-SEO-TAPIS',
    'regular_price' => '7500',
    'status' => 'publish',
    'short_description' => '<p>Fait main en laine naturelle.</p>[products limit="3"]',
    'description' => '<p>La description longue.</p>',
]), 201);

$productId = (int) ($created['data']['id'] ?? 0);
ac_assert('a product to describe', $productId > 0 ?: 'no product created');

echo PHP_EOL, "=== derived defaults ===", PHP_EOL;

ac_check('a product carries seo', ac_req('GET', "/products/{$productId}"), 200, static function ($data): bool|string {
    $seo = $data['data']['seo'] ?? null;

    if (!is_array($seo)) {
        return 'no seo block';
    }

    if (!str_starts_with((string) $seo['title'], 'Tapis berbère')) {
        return 'the title was not derived from the product name: ' . $seo['title'];
    }

    // From the short description, with its HTML and its shortcode gone.
    if ($seo['description'] !== 'Fait main en laine naturelle.') {
        return 'description was ' . var_export($seo['description'], true);
    }

    return $seo['overrides'] === [] ? true : 'nothing was overridden yet';
});

ac_check('the site name is appended', ac_req('GET', "/products/{$productId}"), 200, static function ($data): bool|string {
    $expected = get_bloginfo('name');

    return str_contains((string) ($data['data']['seo']['title'] ?? ''), (string) $expected)
        ? true
        : 'the site name is missing from the title';
});

ac_check('a published product is indexable', ac_req('GET', "/products/{$productId}"), 200, static function ($data): bool|string {
    $robots = $data['data']['seo']['robots'] ?? [];

    return ($robots['index'] ?? null) === true && ($robots['directive'] ?? '') === 'index, follow'
        ? true
        : 'robots was ' . wp_json_encode($robots);
});

echo PHP_EOL, "=== structured data ===", PHP_EOL;

ac_check('a product emits Product JSON-LD', ac_req('GET', "/products/{$productId}"), 200, static function ($data): bool|string {
    $ld = $data['data']['seo']['structured_data'] ?? [];

    if (($ld['@type'] ?? '') !== 'Product') {
        return 'not a Product: ' . wp_json_encode($ld);
    }

    if (($ld['sku'] ?? '') !== 'AC-SEO-TAPIS') {
        return 'the sku is missing';
    }

    $offer = $ld['offers'] ?? [];

    return ($offer['price'] ?? '') === '7500' && str_contains((string) ($offer['availability'] ?? ''), 'InStock')
        ? true
        : 'the offer was ' . wp_json_encode($offer);
});

// A product with no price must not publish `price: ""`, which Google reads as a
// malformed offer rather than an absent one.
$priceless = ac_req('POST', '/products', [
    'name' => 'Sans prix',
    'sku' => 'AC-SEO-NOPRICE',
    'status' => 'publish',
]);
$pricelessId = (int) ($priceless[1]['data']['id'] ?? 0);

ac_check(
    'a product with no price emits no offer',
    ac_req('GET', "/products/{$pricelessId}"),
    200,
    static fn ($data): bool => !isset($data['data']['seo']['structured_data']['offers'])
);

echo PHP_EOL, "=== overrides ===", PHP_EOL;

ac_check('PATCH seo onto a product', ac_req('PATCH', "/products/{$productId}", [
    'seo' => [
        'title' => 'Tapis berbère authentique | Livraison Alger',
        'description' => 'Tapis fait main, livré partout en Algérie.',
        'canonical' => 'https://boutique.dz/tapis/berbere',
        'robots' => ['index' => true, 'follow' => false],
    ],
]), 200, static function ($data): bool|string {
    $seo = $data['data']['seo'] ?? [];

    if ($seo['title'] !== 'Tapis berbère authentique | Livraison Alger') {
        return 'the title override was not applied';
    }

    if ($seo['canonical'] !== 'https://boutique.dz/tapis/berbere') {
        return 'the canonical was not stored';
    }

    if (($seo['robots']['directive'] ?? '') !== 'index, nofollow') {
        return 'robots was ' . wp_json_encode($seo['robots']);
    }

    // An admin UI needs to tell "somebody wrote this" from "derived".
    sort($seo['overrides']);

    return $seo['overrides'] === ['canonical', 'description', 'robots', 'title']
        ? true
        : 'overrides were ' . wp_json_encode($seo['overrides']);
});

// Open Graph follows the SEO fields rather than being stored twice.
ac_check('og follows the overridden title', ac_req('GET', "/products/{$productId}"), 200, static function ($data): bool {
    $seo = $data['data']['seo'] ?? [];

    return ($seo['og']['title'] ?? '') === $seo['title']
        && ($seo['og']['description'] ?? '') === $seo['description']
        && ($seo['og']['type'] ?? '') === 'product';
});

ac_check('clearing an override restores the derived value', ac_req('PATCH', "/products/{$productId}", [
    'seo' => ['description' => null],
]), 200, static function ($data): bool|string {
    $seo = $data['data']['seo'] ?? [];

    return $seo['description'] === 'Fait main en laine naturelle.'
        && !in_array('description', $seo['overrides'], true)
        ? true
        : 'description was ' . var_export($seo['description'], true);
});

echo PHP_EOL, "=== bad input ===", PHP_EOL;

ac_check(
    'PATCH a plaintext canonical',
    ac_req('PATCH', "/products/{$productId}", ['seo' => ['canonical' => 'http://boutique.dz']]),
    400,
    static fn ($data): bool => isset($data['error']['details']['fields']['seo.canonical'])
);

ac_check(
    'PATCH an unknown seo field',
    ac_req('PATCH', "/products/{$productId}", ['seo' => ['keyword' => 'tapis']]),
    400,
    static fn ($data): bool => isset($data['error']['details']['fields']['seo.keyword'])
);

ac_check(
    'PATCH seo that is not an object',
    ac_req('PATCH', "/products/{$productId}", ['seo' => 'a title']),
    400
);

// SEO errors land in the same list as everything else, so a client fixes both
// halves in one round trip instead of two.
ac_check(
    'a bad status and a bad canonical come back together',
    ac_req('PATCH', "/products/{$productId}", [
        'status' => 'not-a-status',
        'seo' => ['canonical' => 'nope'],
    ]),
    400,
    static function ($data): bool|string {
        $fields = array_keys($data['error']['details']['fields'] ?? []);

        return in_array('status', $fields, true) && in_array('seo.canonical', $fields, true)
            ? true
            : 'fields were ' . implode(',', $fields);
    }
);

ac_check(
    'PATCH an seo image that is not an attachment',
    ac_req('PATCH', "/products/{$productId}", ['seo' => ['image_id' => 99999999]]),
    400
);

echo PHP_EOL, "=== drafts ===", PHP_EOL;

$draft = ac_req('POST', '/products', [
    'name' => 'Brouillon',
    'sku' => 'AC-SEO-DRAFT',
    'status' => 'draft',
    'regular_price' => '100',
]);
$draftId = (int) ($draft[1]['data']['id'] ?? 0);

// Reachable through this API long before it is meant to be public: a storefront
// rendering a preview must not be the reason it gets indexed.
ac_check(
    'an unpublished product defaults to noindex',
    ac_req('GET', "/products/{$draftId}"),
    200,
    static fn ($data): bool => ($data['data']['seo']['robots']['index'] ?? true) === false
);

// ...and an explicit override still wins, because somebody meant it.
ac_check('an explicit index beats the draft default', ac_req('PATCH', "/products/{$draftId}", [
    'seo' => ['robots' => ['index' => true, 'follow' => true]],
]), 200, static fn ($data): bool => ($data['data']['seo']['robots']['index'] ?? false) === true);

echo PHP_EOL, "=== pages ===", PHP_EOL;

$pageId = wp_insert_post([
    'post_type' => 'page',
    'post_name' => 'ac-seo-livraison',
    'post_title' => 'Livraison',
    'post_status' => 'publish',
    /*
     * No <script> here, and that is not an oversight: `wp_insert_post()` runs
     * kses for any user without `unfiltered_html`, so the tags are gone before
     * this module ever sees the content and the fixture would prove nothing.
     * A script element *can* reach post_content — an administrator has
     * `unfiltered_html` — and that case is covered where it can be constructed
     * honestly, in tests/Unit/SeoFieldsTest.
     */
    'post_content' => '<p>Nous livrons dans les <strong>58 wilayas</strong>.</p>',
]);

ac_check('a page carries seo', ac_req('GET', '/cms/pages/ac-seo-livraison'), 200, static function ($data): bool|string {
    $seo = $data['data']['seo'] ?? null;

    if (!is_array($seo)) {
        return 'no seo block';
    }

    if (($seo['structured_data']['@type'] ?? '') !== 'WebPage') {
        return 'a page is not a Product';
    }

    // Derived from the body, with its markup gone.
    return $seo['description'] === 'Nous livrons dans les 58 wilayas.'
        ? true
        : 'description was ' . var_export($seo['description'], true);
});

update_post_meta($pageId, AlgerianCommerce\SEO\SeoFields::META_TITLE, 'Livraison en Algérie — 58 wilayas');

ac_check(
    'a page honours a hand-written override',
    ac_req('GET', '/cms/pages/ac-seo-livraison'),
    200,
    static fn ($data): bool => ($data['data']['seo']['title'] ?? '') === 'Livraison en Algérie — 58 wilayas'
        && ($data['data']['seo']['overrides'] ?? []) === ['title']
);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
