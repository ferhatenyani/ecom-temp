<?php
/**
 * CMS endpoints against a real WordPress install — roadmap §61, §65 (API and
 * Security categories).
 *
 * Covers what unit tests structurally cannot: that the post types are actually
 * registered, that `ac_manage_content` is the boundary on every route, that a
 * draft is invisible, and that a nav menu comes back as a tree.
 *
 * The authorized role here is **Marketing Manager**, not Admin, on purpose:
 * `ac_manage_content` is one of the four capabilities that role holds, and a
 * suite that only ever tests the most privileged account cannot tell a working
 * capability from a missing check. Product Manager is the negative case, and is
 * also the gap `MediaService` documents — the person who edits the catalogue
 * cannot upload the picture.
 *
 * In-process via rest_do_request(), which exercises routing, args schemas,
 * permission callbacks and services. It does **not** parse an Authorization
 * header, so authentication and rate limiting live in scripts/test-api.sh.
 *
 *   scripts/test.sh                              # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/cms.php
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

/** Found-or-created, so re-running this file does not fill the database. */
function ac_post(string $type, string $slug, array $fields = []): int
{
    $existing = get_posts([
        'post_type' => $type,
        'name' => $slug,
        'post_status' => 'any',
        'numberposts' => 1,
    ]);

    $data = array_replace([
        'post_type' => $type,
        'post_name' => $slug,
        'post_title' => ucfirst(str_replace('-', ' ', $slug)),
        'post_status' => 'publish',
    ], $fields);

    if ($existing !== []) {
        $data['ID'] = (int) $existing[0]->ID;

        return (int) wp_update_post($data);
    }

    return (int) wp_insert_post($data);
}

$marketing = ac_user('ac_cms_marketing', 'ac_marketing_manager');  // has ac_manage_content
$product = ac_user('ac_cms_product', 'ac_product_manager');        // manages the catalogue, not content
$support = ac_user('ac_cms_support', 'ac_support_agent');          // neither

echo PHP_EOL, "=== authorization ===", PHP_EOL;

$routes = [
    'GET /cms/homepage' => ['GET', '/cms/homepage'],
    'GET /cms/banners' => ['GET', '/cms/banners'],
    'GET /cms/faqs' => ['GET', '/cms/faqs'],
    'GET /cms/pages/{slug}' => ['GET', '/cms/pages/anything'],
    'GET /cms/menus/{location}' => ['GET', '/cms/menus/primary'],
];

wp_set_current_user(0);
foreach ($routes as $label => [$method, $route]) {
    ac_check("{$label} signed out", ac_req($method, $route), 401);
}

wp_set_current_user($support);
foreach ($routes as $label => [$method, $route]) {
    ac_check("{$label} as support agent", ac_req($method, $route), 403);
}

// The documented gap, asserted rather than described: managing the catalogue
// does not carry managing content.
wp_set_current_user($product);
ac_check('GET /cms/banners as product manager', ac_req('GET', '/cms/banners'), 403);

wp_set_current_user($marketing);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$parent = ac_post('page', 'ac-legal', ['post_content' => 'Legal.']);
$child = ac_post('page', 'ac-terms', [
    'post_content' => "Conditions générales.\n\nDeuxième paragraphe.",
    'post_excerpt' => 'Les conditions.',
    'post_parent' => $parent,
]);
$draft = ac_post('page', 'ac-unpublished', ['post_status' => 'draft']);

$banner = ac_post(AlgerianCommerce\CMS\ContentTypes::BANNER, 'ac-soldes', [
    'post_title' => 'Soldes',
    'post_content' => 'Jusqu\'à -50%',
    'menu_order' => 1,
]);
update_post_meta($banner, AlgerianCommerce\CMS\ContentTypes::BANNER_LINK, 'https://example.test/soldes');
update_post_meta($banner, AlgerianCommerce\CMS\ContentTypes::BANNER_PLACEMENT, 'home_hero');

$footerBanner = ac_post(AlgerianCommerce\CMS\ContentTypes::BANNER, 'ac-livraison', ['menu_order' => 2]);
update_post_meta($footerBanner, AlgerianCommerce\CMS\ContentTypes::BANNER_PLACEMENT, 'footer');

$draftBanner = ac_post(AlgerianCommerce\CMS\ContentTypes::BANNER, 'ac-brouillon', ['post_status' => 'draft']);

$faq = ac_post(AlgerianCommerce\CMS\ContentTypes::FAQ, 'ac-delai', [
    'post_title' => 'Quel est le délai de livraison ?',
    'post_content' => 'Entre 2 et 5 jours.',
    'menu_order' => 1,
]);
wp_set_object_terms($faq, ['livraison'], AlgerianCommerce\CMS\ContentTypes::FAQ_CATEGORY);

$faqTwo = ac_post(AlgerianCommerce\CMS\ContentTypes::FAQ, 'ac-paiement', [
    'post_title' => 'Puis-je payer à la livraison ?',
    'menu_order' => 2,
]);

ac_assert('a page tree', $parent > 0 && $child > 0 ?: 'pages were not created');
ac_assert('banners', $banner > 0 && $footerBanner > 0 ?: 'banners were not created');
ac_assert('faqs', $faq > 0 && $faqTwo > 0 ?: 'faqs were not created');

// A menu with a nested item, assigned to the `primary` location.
$menu = wp_get_nav_menu_object('AC Test Menu');
$menuId = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu('AC Test Menu');

foreach (wp_get_nav_menu_items($menuId) ?: [] as $stale) {
    wp_delete_post((int) $stale->ID, true);
}

$top = (int) wp_update_nav_menu_item($menuId, 0, [
    'menu-item-title' => 'Boutique',
    'menu-item-url' => 'https://example.test/boutique',
    'menu-item-status' => 'publish',
    'menu-item-position' => 1,
]);
$nested = (int) wp_update_nav_menu_item($menuId, 0, [
    'menu-item-title' => 'Tapis',
    'menu-item-url' => 'https://example.test/boutique/tapis',
    'menu-item-status' => 'publish',
    'menu-item-parent-id' => $top,
    'menu-item-position' => 2,
]);

$locations = get_nav_menu_locations();
$locations['primary'] = $menuId;
set_theme_mod('nav_menu_locations', $locations);

ac_assert('a menu with a child item', $top > 0 && $nested > 0 ?: 'menu items were not created');

echo PHP_EOL, "=== pages ===", PHP_EOL;

ac_check('GET a page', ac_req('GET', '/cms/pages/ac-legal'), 200, static function ($data): bool|string {
    $page = $data['data'] ?? [];

    if (($page['slug'] ?? '') !== 'ac-legal') {
        return 'wrong page';
    }

    // Rendered, not raw: `the_content` has run.
    return str_contains((string) ($page['content'] ?? ''), '<p>') ? true : 'content was not rendered';
});

ac_check(
    'GET a page by its nested path',
    ac_req('GET', '/cms/pages/ac-legal/ac-terms'),
    200,
    static function ($data): bool|string {
        $page = $data['data'] ?? [];

        return ($page['slug'] ?? '') === 'ac-terms' && ($page['excerpt'] ?? '') !== ''
            ? true
            : 'the child page or its excerpt is missing';
    }
);

/*
 * A page is addressed by its **path**, exactly as WordPress addresses it: a
 * child's bare slug is not its address, and answering with it would make two
 * children called "terms" under different parents the same request. The
 * storefront routes by path anyway.
 */
ac_check('GET a child page by its bare slug', ac_req('GET', '/cms/pages/ac-terms'), 404);

ac_check(
    'GET a page that does not exist',
    ac_req('GET', '/cms/pages/nothing-here'),
    404,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'not_found'
);

// A draft is something somebody has not finished writing.
ac_check('GET an unpublished page', ac_req('GET', '/cms/pages/ac-unpublished'), 404);

// The route pattern is the first line of defence on a slug that reaches
// get_page_by_path().
ac_check('GET a page with a traversal slug', ac_req('GET', '/cms/pages/../../wp-config'), 404);

echo PHP_EOL, "=== banners ===", PHP_EOL;

ac_check('GET banners', ac_req('GET', '/cms/banners'), 200, static function ($data): bool|string {
    $slugsInOrder = array_column($data['data'] ?? [], 'title');

    if (count($slugsInOrder) < 2) {
        return 'expected at least two banners, got ' . count($slugsInOrder);
    }

    $first = ($data['data'][0] ?? []);

    return ($first['link'] ?? '') === 'https://example.test/soldes'
        ? true
        : 'menu_order did not decide the order, or the link is missing';
});

ac_check(
    'GET banners filtered by placement',
    ac_req('GET', '/cms/banners', null, ['placement' => 'footer']),
    200,
    static fn ($data): bool => count($data['data'] ?? []) === 1
        && ($data['data'][0]['placement'] ?? '') === 'footer'
);

ac_check(
    'GET banners for a placement nobody uses',
    ac_req('GET', '/cms/banners', null, ['placement' => 'nowhere']),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? -1) === 0
);

ac_check(
    'GET banners with a placement that is not a key',
    ac_req('GET', '/cms/banners', null, ['placement' => 'Home Hero!']),
    400
);

ac_check(
    'GET banners with an oversized page size',
    ac_req('GET', '/cms/banners', null, ['per_page' => 100000]),
    400
);

ac_check(
    'GET banners paginated',
    ac_req('GET', '/cms/banners', null, ['per_page' => 1, 'page' => 1]),
    200,
    static fn ($data): bool => count($data['data'] ?? []) === 1 && ($data['meta']['total'] ?? 0) >= 2
);

$all = ac_req('GET', '/cms/banners', null, ['per_page' => 100]);
ac_assert(
    'a draft banner is not published content',
    !in_array('Ac brouillon', array_column($all[1]['data'] ?? [], 'title'), true)
        ?: 'a draft banner was served'
);

echo PHP_EOL, "=== faqs ===", PHP_EOL;

ac_check('GET faqs', ac_req('GET', '/cms/faqs'), 200, static function ($data): bool|string {
    $first = $data['data'][0] ?? [];

    return ($first['question'] ?? '') === 'Quel est le délai de livraison ?'
        ? true
        : 'the first FAQ is not the one with menu_order 1';
});

ac_check(
    'GET faqs filtered by category',
    ac_req('GET', '/cms/faqs', null, ['category' => 'livraison']),
    200,
    static fn ($data): bool => count($data['data'] ?? []) === 1
        && ($data['data'][0]['categories'][0]['slug'] ?? '') === 'livraison'
);

ac_check(
    'GET faqs for a category that does not exist',
    ac_req('GET', '/cms/faqs', null, ['category' => 'inexistante']),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? -1) === 0
);

ac_check(
    'GET faqs searched',
    ac_req('GET', '/cms/faqs', null, ['search' => 'payer']),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? 0) === 1
);

echo PHP_EOL, "=== menus ===", PHP_EOL;

ac_check('GET a menu', ac_req('GET', '/cms/menus/primary'), 200, static function ($data): bool|string {
    $menu = $data['data'] ?? [];

    if (($menu['location'] ?? '') !== 'primary') {
        return 'the location is not echoed back';
    }

    $items = $menu['items'] ?? [];

    if (count($items) !== 1) {
        return 'expected one top-level item, got ' . count($items);
    }

    // The child is nested, not repeated at the top level.
    return count($items[0]['children'] ?? []) === 1
        ? true
        : 'the child item was not nested';
});

ac_check(
    'GET a menu for a location with nothing assigned',
    ac_req('GET', '/cms/menus/footer'),
    404,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'not_found'
);

// A location this install does not register and one that was never a location
// are the same answer: there is no menu there.
ac_check('GET a menu for an unregistered location', ac_req('GET', '/cms/menus/sidebar'), 404);
ac_check('GET a menu with a location that is not a key', ac_req('GET', '/cms/menus/Primary%20Nav'), 404);

echo PHP_EOL, "=== homepage ===", PHP_EOL;

delete_option(AlgerianCommerce\CMS\CmsRepository::HOMEPAGE_OPTION);

ac_check(
    'GET the homepage before one is written',
    ac_req('GET', '/cms/homepage'),
    200,
    static fn ($data): bool => ($data['data']['sections'] ?? null) === []
);

update_option(AlgerianCommerce\CMS\CmsRepository::HOMEPAGE_OPTION, [
    'sections' => [
        ['type' => 'hero', 'data' => ['title' => 'Soldes', 'banner_id' => $banner]],
        ['type' => 'featured_products', 'data' => ['limit' => 8]],
    ],
]);

ac_check('GET the homepage', ac_req('GET', '/cms/homepage'), 200, static function ($data): bool|string {
    $sections = $data['data']['sections'] ?? [];

    if (count($sections) !== 2) {
        return 'expected two sections, got ' . count($sections);
    }

    return ($sections[0]['data']['title'] ?? '') === 'Soldes'
        ? true
        : 'the section data did not survive the round trip';
});

// A section nobody can render is dropped — and said so, rather than vanishing.
update_option(AlgerianCommerce\CMS\CmsRepository::HOMEPAGE_OPTION, [
    'sections' => [
        ['type' => 'hero'],
        ['type' => 'carousel'],
    ],
]);

ac_check('GET a homepage with a bad section', ac_req('GET', '/cms/homepage'), 200, static function ($data): bool|string {
    if (count($data['data']['sections'] ?? []) !== 1) {
        return 'the unknown section was not dropped';
    }

    $problems = $data['meta']['problems'] ?? [];

    return count($problems) === 1 && str_contains((string) $problems[0], 'carousel')
        ? true
        : 'the problem was not reported';
});

// An option somebody edited into nonsense must degrade, never 500.
update_option(AlgerianCommerce\CMS\CmsRepository::HOMEPAGE_OPTION, 'not a document');

ac_check(
    'GET a homepage whose option is not a document',
    ac_req('GET', '/cms/homepage'),
    200,
    static fn ($data): bool => ($data['data']['sections'] ?? null) === []
        && count($data['meta']['problems'] ?? []) === 1
);

delete_option(AlgerianCommerce\CMS\CmsRepository::HOMEPAGE_OPTION);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
