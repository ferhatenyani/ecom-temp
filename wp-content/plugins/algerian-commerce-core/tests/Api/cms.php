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

/**
 * Clear the rate-limit counters.
 *
 * For the **standalone** invocation in this file's header. `scripts/test.sh`
 * runs the whole `rest` stage with `AC_RATE_LIMIT_DISABLED=1`, for the reason
 * it states there — the counters live in the database, so one per-minute window
 * is shared across every suite and the sixth to run collects 429s that are
 * nothing to do with it. §89 needs the same thing *within* one suite: the write
 * cap is 120 a minute and this file issues more than that on its own, between
 * the fifteen-route authorization sweep and the writes themselves.
 *
 * **This does not leave the rate limiter untested.** `rest_do_request()` is not
 * a client — it parses no `Authorization` header and there is no remote address
 * to bucket by — so the limits are asserted over real HTTP in
 * `scripts/test-api.sh`, which is the split docs/TESTING.md already draws.
 */
function ac_clear_rate_limits(): void
{
    global $wpdb;

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ac_rl_%'");
}

ac_clear_rate_limits();

$marketing = ac_user('ac_cms_marketing', 'ac_marketing_manager');  // has ac_manage_content
$product = ac_user('ac_cms_product', 'ac_product_manager');        // manages the catalogue, not content
$support = ac_user('ac_cms_support', 'ac_support_agent');          // neither

echo PHP_EOL, "=== authorization ===", PHP_EOL;

$routes = [
    'GET /cms/homepage' => ['GET', '/cms/homepage'],
    'GET /cms/banners' => ['GET', '/cms/banners'],
    'GET /cms/faqs' => ['GET', '/cms/faqs'],
    'GET /cms/pages/{path}' => ['GET', '/cms/pages/anything'],
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

/*
 * §89's write routes are swept the same way, and the sweep is the point: a
 * route added later with a copied-and-edited registration is exactly what a
 * per-route test misses. Each carries a body that would be valid, so a 403 is
 * the guard answering rather than validation answering first — §87 found that
 * ordering bug (authentication must answer before input validation) and this is
 * the same shape one layer along.
 */
$writeRoutes = [
    'PUT /cms/homepage' => ['PUT', '/cms/homepage', ['sections' => []]],
    'POST /cms/pages' => ['POST', '/cms/pages', ['title' => 'X']],
    'PATCH /cms/pages/{path}' => ['PATCH', '/cms/pages/ac-legal', ['title' => 'X']],
    'DELETE /cms/pages/{path}' => ['DELETE', '/cms/pages/ac-legal', null],
    'POST /cms/banners' => ['POST', '/cms/banners', ['title' => 'X']],
    'PATCH /cms/banners/{id}' => ['PATCH', '/cms/banners/1', ['title' => 'X']],
    'DELETE /cms/banners/{id}' => ['DELETE', '/cms/banners/1', null],
    'POST /cms/faqs' => ['POST', '/cms/faqs', ['question' => 'X']],
    'PATCH /cms/faqs/{id}' => ['PATCH', '/cms/faqs/1', ['question' => 'X']],
    'DELETE /cms/faqs/{id}' => ['DELETE', '/cms/faqs/1', null],
    'GET /cms/faq-categories' => ['GET', '/cms/faq-categories', null],
    'POST /cms/faq-categories' => ['POST', '/cms/faq-categories', ['name' => 'X']],
    'PATCH /cms/faq-categories/{id}' => ['PATCH', '/cms/faq-categories/1', ['name' => 'X']],
    'DELETE /cms/faq-categories/{id}' => ['DELETE', '/cms/faq-categories/1', null],
    'PUT /cms/menus/{location}' => ['PUT', '/cms/menus/primary', ['items' => []]],
];

wp_set_current_user(0);
foreach ($writeRoutes as $label => [$method, $route, $body]) {
    ac_check("{$label} signed out", ac_req($method, $route, $body), 401);
}

wp_set_current_user($support);
foreach ($writeRoutes as $label => [$method, $route, $body]) {
    ac_check("{$label} as support agent", ac_req($method, $route, $body), 403);
}

// The floor. A loop over an empty list reports success exactly as a passing one
// does — this repository has shipped that failure once already.
ac_assert(
    'the write sweep actually had routes to sweep',
    count($writeRoutes) >= 15 ?: 'only ' . count($writeRoutes) . ' write routes swept'
);

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

// ============================================================== §89 writes ==
//
// Everything above is §61's read half. What follows is the write surface §61
// deferred to PLAN §52.

// Again, and see `ac_clear_rate_limits()` for why: the sweep above spent most
// of a minute's write allowance on requests that were refused for the reason
// being tested.
ac_clear_rate_limits();

echo PHP_EOL, "=== §89: the URL addresses, the body renames ===", PHP_EOL;

/*
 * The reason `/cms/pages` captures `path` and not `slug`, asserted as the pair
 * it is. `AbstractController::pinRouteParams()` makes the URL authoritative for
 * every captured param — so had the capture kept §61's name, a `PATCH` body
 * carrying `slug` would have been overwritten by the path and the rename would
 * have answered **200 having renamed nothing**. §88 wrote that prediction down
 * for this section; these two checks are it, and one without the other proves
 * nothing.
 */
$created = ac_check(
    'POST a page',
    ac_req('POST', '/cms/pages', ['title' => 'Mentions légales', 'slug' => 'ac89-legal', 'content' => '<p>Texte.</p>']),
    201,
    static fn ($d): bool|string => ($d['data']['path'] ?? '') === 'ac89-legal'
        ?: 'path was ' . ($d['data']['path'] ?? 'absent')
);
$pageId = (int) ($created['data']['id'] ?? 0);

ac_check(
    'POST a child page under it',
    ac_req('POST', '/cms/pages', ['title' => 'Conditions', 'slug' => 'terms', 'parent_path' => 'ac89-legal']),
    201,
    static fn ($d): bool|string => ($d['data']['path'] ?? '') === 'ac89-legal/terms'
        ?: 'path was ' . ($d['data']['path'] ?? 'absent')
);

// Half one: `path` in the body is the address, and the address is the URL's.
ac_check(
    'a body "path" cannot move a page',
    ac_req('PATCH', '/cms/pages/ac89-legal/terms', ['path' => 'somewhere/else', 'title' => 'Conditions générales']),
    200,
    static fn ($d): bool|string => ($d['data']['path'] ?? '') === 'ac89-legal/terms'
        && ($d['data']['title'] ?? '') === 'Conditions générales'
        ?: 'path ' . ($d['data']['path'] ?? '?') . ', title ' . ($d['data']['title'] ?? '?')
);

// Half two, and the one that fails if the capture is ever renamed back: the
// rename really happens. A route that refused both would pass the check above.
ac_check(
    'and a body "slug" really does rename it',
    ac_req('PATCH', '/cms/pages/ac89-legal/terms', ['slug' => 'conditions']),
    200,
    static fn ($d): bool|string => ($d['data']['path'] ?? '') === 'ac89-legal/conditions'
        && ($d['meta']['path_changed'] ?? false) === true
        ?: 'path ' . ($d['data']['path'] ?? '?') . ', meta ' . wp_json_encode($d['meta'] ?? [])
);

ac_check('the old path is gone', ac_req('GET', '/cms/pages/ac89-legal/terms'), 404);
ac_check('the new one answers', ac_req('GET', '/cms/pages/ac89-legal/conditions'), 200);

echo PHP_EOL, "=== §89: wp_kses runs on save ===", PHP_EOL;

/*
 * Run as an **administrator**, and that is the whole point of the section.
 *
 * §61 recorded that WordPress "already runs `wp_kses_post` over anything saved
 * by a user without `unfiltered_html`". An administrator holds that capability,
 * so `kses_init()` removes every filter for exactly the caller most able to do
 * damage. Measured 2026-08-17 in this stack and asserted below as the control:
 * the same markup through `wp_insert_post()` as the same user is stored byte
 * for byte. Testing this as the Marketing Manager would pass on core's
 * sanitiser and prove nothing about ours.
 */
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$adminId = $admin === [] ? 0 : (int) $admin[0]->ID;
ac_assert('there is an administrator to test with', $adminId > 0 ?: 'no administrator on this install');

wp_set_current_user($adminId);
ac_assert('who bypasses core\'s own kses filters', current_user_can('unfiltered_html') === true
    ?: 'this administrator does not hold unfiltered_html, so the control below proves nothing');

$hostile = '<p onclick="steal()">a</p><script>alert(1)</script>'
    . '<a href="javascript:alert(1)">j</a><iframe src="https://evil.test"></iframe>'
    . '<style>body{display:none}</style><strong>garder</strong>';

$xss = ac_check(
    'POST a page carrying script, onclick, javascript: and an iframe',
    ac_req('POST', '/cms/pages', ['title' => 'AC89 XSS', 'slug' => 'ac89-xss', 'content' => $hostile]),
    201
);
$xssId = (int) ($xss['data']['id'] ?? 0);

// Read the row, not the response: a sanitiser on the way out is one a second
// reader does not run, and this asserts which side of the pipe it is on.
$stored = (string) get_post($xssId)->post_content;

foreach (['<script' => 'a script element', 'onclick' => 'an event handler',
          'javascript:' => 'a javascript: URL', '<iframe' => 'an iframe', '<style' => 'a style block'] as $needle => $what) {
    ac_assert(
        'the stored row carries no ' . $what,
        !str_contains(strtolower($stored), $needle) ?: 'stored: ' . substr($stored, 0, 160)
    );
}

// The positive control. A sanitiser that stored an empty string would pass
// every assertion above.
ac_assert(
    'while ordinary markup survived',
    str_contains($stored, '<strong>garder</strong>') ?: 'the allowlist ate everything: ' . substr($stored, 0, 160)
);

/*
 * The check proved able to fail, against a deliberately broken fixture: the
 * same markup, the same user, WordPress's own writer and no `ContentHtml`.
 * Without this the assertions above would pass just as well on an install where
 * core happened to be filtering, and the section would be redundant code
 * nobody could tell was redundant.
 */
$unfiltered = wp_insert_post([
    'post_type' => 'page',
    'post_title' => 'AC89 control',
    'post_name' => 'ac89-control',
    'post_status' => 'draft',
    'post_content' => $hostile,
]);
ac_assert(
    'and WordPress alone would have stored the script verbatim',
    str_contains((string) get_post($unfiltered)->post_content, '<script>alert(1)</script>')
        ?: 'core filtered it after all — this control no longer proves the sanitiser is load-bearing'
);
wp_delete_post((int) $unfiltered, true);

wp_set_current_user($marketing);

echo PHP_EOL, "=== §89: draft and publish ===", PHP_EOL;

$draftPage = ac_check(
    'POST a draft page',
    ac_req('POST', '/cms/pages', ['title' => 'AC89 brouillon', 'slug' => 'ac89-brouillon', 'status' => 'draft']),
    201,
    static fn ($d): bool => ($d['data']['status'] ?? '') === 'draft'
);

ac_check('which the default read does not serve', ac_req('GET', '/cms/pages/ac89-brouillon'), 404);
ac_check(
    'and ?status=any does',
    ac_req('GET', '/cms/pages/ac89-brouillon', null, ['status' => 'any']),
    200,
    static fn ($d): bool => ($d['data']['status'] ?? '') === 'draft'
);
ac_check(
    'publishing it makes the default read answer',
    ac_req('PATCH', '/cms/pages/ac89-brouillon', ['status' => 'publish']),
    200,
    static fn ($d): bool => ($d['data']['status'] ?? '') === 'publish'
);
ac_check('the default read now answers', ac_req('GET', '/cms/pages/ac89-brouillon'), 200);

echo PHP_EOL, "=== §89: refusals, each beside a control ===", PHP_EOL;

ac_check(
    'a page refuses "author" by name',
    ac_req('POST', '/cms/pages', ['title' => 'X', 'author' => 3]),
    400,
    static fn ($d): bool|string => isset($d['error']['details']['fields']['author'])
        ?: 'the field was not named: ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_check(
    'and a slug carrying a path, because parent_path is the field that moves it',
    ac_req('POST', '/cms/pages', ['title' => 'X', 'slug' => 'legal/terms']),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['slug'])
);

ac_check(
    'and a parent_path naming no page',
    ac_req('POST', '/cms/pages', ['title' => 'X', 'slug' => 'ac89-orphan', 'parent_path' => 'nowhere']),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['parent_path'])
);

// The same rule one resource over: a page whose SEO names something that is not
// an image is refused *before* the page exists, not after.
ac_check(
    'a page whose seo.image_id is not an attachment is refused',
    ac_req('POST', '/cms/pages', [
        'title' => 'X',
        'slug' => 'ac89-badseo',
        'seo' => ['image_id' => $pageId],
    ]),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['seo.image_id'])
);

ac_assert(
    'and that page was not created anyway',
    get_posts(['post_type' => 'page', 'name' => 'ac89-badseo', 'post_status' => 'any', 'numberposts' => 5]) === []
        ?: 'a refused create left a page behind'
);

// The control: the same write, with a parent that exists.
ac_check(
    'while a parent_path that exists is accepted',
    ac_req('POST', '/cms/pages', ['title' => 'X', 'slug' => 'ac89-orphan', 'parent_path' => 'ac89-legal']),
    201,
    static fn ($d): bool => ($d['data']['path'] ?? '') === 'ac89-legal/ac89-orphan'
);

ac_check(
    'a second page cannot take an occupied path',
    ac_req('POST', '/cms/pages', ['title' => 'Y', 'slug' => 'ac89-orphan', 'parent_path' => 'ac89-legal']),
    409,
    static fn ($d): bool => ($d['error']['code'] ?? '') === 'conflict'
);

ac_check(
    'a page cannot move under its own child',
    ac_req('PATCH', '/cms/pages/ac89-legal', ['parent_path' => 'ac89-legal/conditions']),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['parent_path'])
);

ac_check(
    'a page cannot be its own parent',
    ac_req('PATCH', '/cms/pages/ac89-legal', ['parent_path' => 'ac89-legal']),
    400
);

// `parent_id` is emitted by the presenter, so it is dropped rather than
// refused — and the drop is asserted both ways: the move did not happen, and
// the field that came with it did.
ac_check(
    'a body "parent_id" is dropped, not honoured',
    ac_req('PATCH', '/cms/pages/ac89-brouillon', ['parent_id' => $pageId, 'title' => 'Toujours à la racine']),
    200,
    static fn ($d): bool|string => ($d['data']['parent_path'] ?? 'x') === ''
        && ($d['data']['title'] ?? '') === 'Toujours à la racine'
        ?: 'parent_path ' . ($d['data']['parent_path'] ?? '?') . ', title ' . ($d['data']['title'] ?? '?')
);

echo PHP_EOL, "=== §89: banners and FAQs ===", PHP_EOL;

ac_check(
    'a banner link may not be a javascript: URL',
    ac_req('POST', '/cms/banners', ['title' => 'Soldes', 'link' => 'javascript:alert(1)']),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['link'])
);

$newBanner = ac_check(
    'while an http URL is accepted',
    ac_req('POST', '/cms/banners', [
        'title' => 'AC89 Soldes',
        'caption' => '<p>-50% <script>alert(1)</script></p>',
        'link' => 'https://example.test/soldes',
        'placement' => 'home_hero',
        'position' => 3,
    ]),
    201
);
$bannerId = (int) ($newBanner['data']['id'] ?? 0);

ac_assert(
    'and its caption was sanitised on the way in',
    !str_contains(strtolower((string) get_post($bannerId)->post_content), '<script')
        ?: 'the caption stored a script element'
);

ac_check(
    'a banner refuses "menu_order", naming the field that replaces it',
    ac_req('PATCH', "/cms/banners/{$bannerId}", ['menu_order' => 2]),
    400,
    static fn ($d): bool => str_contains((string) ($d['error']['details']['fields']['menu_order'] ?? ''), 'position')
);

ac_check(
    'while "position" moves it',
    ac_req('PATCH', "/cms/banners/{$bannerId}", ['position' => 2]),
    200,
    static fn ($d): bool => ($d['data']['position'] ?? 0) === 2
);

ac_check(
    'an image_id that is not an attachment is refused',
    ac_req('PATCH', "/cms/banners/{$bannerId}", ['image_id' => $pageId]),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['image_id'])
);

ac_check('a banner that does not exist', ac_req('PATCH', '/cms/banners/99999999', ['title' => 'X']), 404);

$category = ac_check(
    'POST an FAQ category',
    ac_req('POST', '/cms/faq-categories', ['name' => 'AC89 Retours']),
    201,
    static fn ($d): bool => ($d['data']['slug'] ?? '') !== ''
);
$categoryId = (int) ($category['data']['id'] ?? 0);
$categorySlug = (string) ($category['data']['slug'] ?? '');

ac_check(
    'a duplicate category name is a conflict',
    ac_req('POST', '/cms/faq-categories', ['name' => 'AC89 Retours']),
    409
);

ac_check(
    'an FAQ naming a category that does not exist is refused',
    ac_req('POST', '/cms/faqs', ['question' => 'AC89 orpheline ?', 'categories' => ['ac89-inexistante']]),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['categories'])
);

/*
 * **A refused create must leave nothing behind**, and this file is the reason
 * the rule is written down. The first version attached categories after the
 * insert, so this exact request created and published an FAQ and *then*
 * answered 400. Nothing failed — the refusal was correct and the shop gained an
 * answer nobody wrote. It surfaced on the second run of this suite, where the
 * leftover row broke an ordering assertion in §61's half thirty checks earlier.
 * So: assert the row, not the status.
 */
ac_assert(
    'and the refused FAQ was not created anyway',
    get_posts([
        'post_type' => AlgerianCommerce\CMS\ContentTypes::FAQ,
        'post_status' => 'any',
        's' => 'AC89 orpheline',
        'numberposts' => 5,
    ]) === [] ?: 'a refused create left a published FAQ behind'
);

$newFaq = ac_check(
    'while one naming a real category is created',
    ac_req('POST', '/cms/faqs', [
        'question' => 'Puis-je retourner un article ?',
        'answer' => '<p>Oui, sous 7 jours.</p>',
        'categories' => [$categorySlug],
        'position' => 9,
    ]),
    201,
    static fn ($d): bool|string => ($d['data']['categories'][0]['slug'] ?? '') === '' ? 'no category attached' : true
);
$faqId = (int) ($newFaq['data']['id'] ?? 0);

ac_check(
    'an FAQ refuses "category", naming the field that replaces it',
    ac_req('PATCH', "/cms/faqs/{$faqId}", ['category' => $categorySlug]),
    400,
    static fn ($d): bool => str_contains((string) ($d['error']['details']['fields']['category'] ?? ''), 'categories')
);

ac_check(
    'a category slug rename says so',
    ac_req('PATCH', "/cms/faq-categories/{$categoryId}", ['slug' => 'ac89-retours-produits']),
    200,
    static fn ($d): bool => ($d['meta']['slug_changed'] ?? false) === true
        && ($d['data']['slug'] ?? '') === 'ac89-retours-produits'
);

ac_check(
    'while a rename that changes no slug does not',
    ac_req('PATCH', "/cms/faq-categories/{$categoryId}", ['description' => 'Retours et échanges.']),
    200,
    static fn ($d): bool => !isset($d['meta']['slug_changed'])
);

ac_check(
    'deleting a category that FAQs are in is refused, with the count',
    ac_req('DELETE', "/cms/faq-categories/{$categoryId}"),
    409,
    static fn ($d): bool|string => ($d['error']['details']['faqs'] ?? 0) === 1
        ?: 'count was ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_check(
    'and ?force=true does it anyway, reporting what it detached',
    ac_req('DELETE', "/cms/faq-categories/{$categoryId}", null, ['force' => true]),
    200,
    static fn ($d): bool => ($d['data']['faqs_detached'] ?? 0) === 1
);

echo PHP_EOL, "=== §89: the homepage refuses what the read would drop ===", PHP_EOL;

ac_check(
    'PUT the homepage',
    ac_req('PUT', '/cms/homepage', ['sections' => [
        ['type' => 'hero', 'data' => ['title' => 'Tapis & Kilims', 'body' => '<p onclick="x">Bienvenue</p>']],
        ['type' => 'featured_products', 'data' => ['limit' => 8]],
    ]]),
    200,
    static function ($d): bool|string {
        $sections = $d['data']['sections'] ?? [];

        if (count($sections) !== 2) {
            return 'expected two sections, got ' . count($sections);
        }

        // The measurement that decided the design: `wp_kses` rewrites `&` to
        // `&amp;`, so it is routed at markup rather than run over every string.
        if (($sections[0]['data']['title'] ?? '') !== 'Tapis & Kilims') {
            return 'an ordinary string was mangled: ' . ($sections[0]['data']['title'] ?? '');
        }

        return !str_contains((string) ($sections[0]['data']['body'] ?? ''), 'onclick')
            ? true
            : 'markup in section data was not sanitised';
    }
);

ac_check(
    'a malformed section is a 400 naming its index',
    ac_req('PUT', '/cms/homepage', ['sections' => [
        ['type' => 'hero', 'data' => []],
        ['type' => 'carousel', 'data' => []],
    ]]),
    400,
    static fn ($d): bool|string => isset($d['error']['details']['fields']['sections[1].type'])
        ?: 'the index was not named: ' . wp_json_encode($d['error']['details'] ?? [])
);

// The control, and it is the half that matters: the refusal must not have
// written anything. A writer that refused and saved would pass the check above.
ac_check(
    'and the refused document did not replace the stored one',
    ac_req('GET', '/cms/homepage'),
    200,
    static fn ($d): bool|string => count($d['data']['sections'] ?? []) === 2
        ?: 'the stored document changed: ' . wp_json_encode($d['data'] ?? [])
);

ac_check(
    'more sections than the cap is refused',
    ac_req('PUT', '/cms/homepage', ['sections' => array_fill(
        0,
        AlgerianCommerce\CMS\HomepageSections::MAX_SECTIONS + 1,
        ['type' => 'text', 'data' => []]
    )]),
    400
);

ac_check(
    'the bare list the reader tolerates is not a document the writer accepts',
    ac_req('PUT', '/cms/homepage', [['type' => 'hero', 'data' => []]]),
    400
);

ac_check(
    'an unknown top-level field is refused',
    ac_req('PUT', '/cms/homepage', ['sections' => [], 'layout' => 'wide']),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['layout'])
);

ac_check('an empty document is a legitimate one', ac_req('PUT', '/cms/homepage', ['sections' => []]), 200,
    static fn ($d): bool => ($d['data']['sections'] ?? null) === []);

echo PHP_EOL, "=== §89: menus ===", PHP_EOL;

ac_check(
    'PUT a menu at a location with nothing assigned',
    ac_req('PUT', '/cms/menus/footer', ['items' => [
        ['label' => 'Conditions', 'type' => 'page', 'path' => 'ac89-legal/conditions', 'children' => []],
        ['label' => 'Instagram', 'type' => 'url', 'url' => 'https://example.test/ig', 'children' => [
            ['label' => 'Photos', 'type' => 'url', 'url' => 'https://example.test/ig/photos'],
        ]],
    ]]),
    200,
    static function ($d): bool|string {
        $items = $d['data']['items'] ?? [];

        if (count($items) !== 2) {
            return 'expected two top-level items, got ' . count($items);
        }

        if (($items[0]['title'] ?? '') !== 'Conditions' || ($items[1]['title'] ?? '') !== 'Instagram') {
            return 'the order was not preserved';
        }

        return count($items[1]['children'] ?? []) === 1 ? true : 'the child was not nested';
    }
);

// The location that answered 404 for the whole of §61 now has a menu, because
// the PUT created and assigned one.
ac_check('which the read now finds', ac_req('GET', '/cms/menus/footer'), 200,
    static fn ($d): bool => count($d['data']['items'] ?? []) === 2);

ac_check(
    'a third level is refused, naming where',
    ac_req('PUT', '/cms/menus/footer', ['items' => [
        ['label' => 'A', 'type' => 'url', 'url' => 'https://example.test/a', 'children' => [
            ['label' => 'B', 'type' => 'url', 'url' => 'https://example.test/b', 'children' => [
                ['label' => 'C', 'type' => 'url', 'url' => 'https://example.test/c'],
            ]],
        ]],
    ]]),
    400,
    static fn ($d): bool|string => isset($d['error']['details']['fields']['items[0].children[0].children'])
        ?: 'the place was not named: ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_check(
    'more items than the cap is refused',
    ac_req('PUT', '/cms/menus/footer', ['items' => array_fill(
        0,
        AlgerianCommerce\CMS\MenuInput::MAX_ITEMS + 1,
        ['label' => 'X', 'type' => 'url', 'url' => 'https://example.test/x']
    )]),
    400
);

ac_check(
    'a javascript: menu URL is refused',
    ac_req('PUT', '/cms/menus/footer', ['items' => [
        ['label' => 'Bad', 'type' => 'url', 'url' => 'javascript:alert(1)'],
    ]]),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['items[0].url'])
);

ac_check(
    'a menu item naming a page that does not exist is refused',
    ac_req('PUT', '/cms/menus/footer', ['items' => [
        ['label' => 'Ghost', 'type' => 'page', 'path' => 'ac89-nowhere'],
    ]]),
    400
);

// The control for all four refusals above: the stored menu is untouched.
ac_check(
    'and none of those refusals emptied the stored menu',
    ac_req('GET', '/cms/menus/footer'),
    200,
    static fn ($d): bool|string => count($d['data']['items'] ?? []) === 2
        ?: 'the menu was written by a refused request'
);

/*
 * The read merges its two 404s — "no menu here" and "no such location" mean the
 * same thing to a storefront. The write must not: a PUT to a location that does
 * not exist is a typo, and answering 404 would send somebody looking for a menu
 * that was never the problem.
 */
ac_check(
    'PUT to an unregistered location names the registered ones',
    ac_req('PUT', '/cms/menus/sidebar', ['items' => []]),
    400,
    static fn ($d): bool|string => in_array('footer', (array) ($d['error']['details']['locations'] ?? []), true)
        ?: 'the locations were not listed: ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_check('while the read still merges them into a 404', ac_req('GET', '/cms/menus/sidebar'), 404);

ac_check(
    'an unknown field on a menu item is refused',
    ac_req('PUT', '/cms/menus/footer', ['items' => [
        ['label' => 'X', 'type' => 'url', 'url' => 'https://example.test/x', 'onclick' => 'alert(1)'],
    ]]),
    400,
    static fn ($d): bool => isset($d['error']['details']['fields']['items[0].onclick'])
);

echo PHP_EOL, "=== §89: a read body writes back ===", PHP_EOL;

/*
 * `docs/API.md` → "Things that will bite you" promises that GET then PATCH the
 * whole object works. It is the only interaction an admin panel screen has, and
 * every field a presenter emits and an input refuses breaks it — which is why
 * read-only fields are *dropped* rather than refused. Asserted per resource,
 * because each presenter emits a different set.
 */
$roundTrips = [
    'page' => ['PATCH', '/cms/pages/ac89-legal', static fn () => ac_req('GET', '/cms/pages/ac89-legal')],
    'banner' => ['PATCH', "/cms/banners/{$bannerId}", static fn () => ac_req('GET', '/cms/banners', null, ['per_page' => 100])],
    'faq' => ['PATCH', "/cms/faqs/{$faqId}", null],
];

$page = ac_req('GET', '/cms/pages/ac89-legal');
ac_check(
    'a page read body PATCHes back unchanged',
    ac_req('PATCH', '/cms/pages/ac89-legal', $page[1]['data']),
    200,
    static fn ($d): bool|string => ($d['data']['path'] ?? '') === 'ac89-legal' ?: 'the page moved'
);

$banners = ac_req('GET', '/cms/banners', null, ['per_page' => 100]);
$mine = null;
foreach ($banners[1]['data'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $bannerId) {
        $mine = $row;
    }
}
ac_assert('the banner is in its own list', $mine !== null ?: 'the created banner was not listed');
ac_check('a banner read body PATCHes back unchanged', ac_req('PATCH', "/cms/banners/{$bannerId}", (array) $mine), 200);

$faqs = ac_req('GET', '/cms/faqs', null, ['per_page' => 100]);
$mineFaq = null;
foreach ($faqs[1]['data'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $faqId) {
        $mineFaq = $row;
    }
}
ac_assert('the FAQ is in its own list', $mineFaq !== null ?: 'the created FAQ was not listed');
ac_check('an FAQ read body PATCHes back unchanged', ac_req('PATCH', "/cms/faqs/{$faqId}", (array) $mineFaq), 200);

$storedMenu = ac_req('GET', '/cms/menus/footer');
ac_check(
    'a menu read body PUTs back unchanged, WordPress vocabulary and all',
    ac_req('PUT', '/cms/menus/footer', ['items' => $storedMenu[1]['data']['items'] ?? []]),
    200,
    static fn ($d): bool|string => count($d['data']['items'] ?? []) === 2
        && ($d['data']['items'][0]['title'] ?? '') === 'Conditions'
        ?: 'the menu did not survive its own read shape: ' . wp_json_encode($d['data']['items'] ?? [])
);

$homepage = ac_req('GET', '/cms/homepage');
ac_check(
    'the homepage document PUTs back unchanged',
    ac_req('PUT', '/cms/homepage', $homepage[1]['data']),
    200
);

echo PHP_EOL, "=== §89: deleting ===", PHP_EOL;

ac_check(
    'deleting a page that has children is refused',
    ac_req('DELETE', '/cms/pages/ac89-legal'),
    409,
    static fn ($d): bool|string => ($d['error']['details']['children'] ?? 0) >= 2
        ?: 'count was ' . wp_json_encode($d['error']['details'] ?? [])
);

ac_check(
    'a leaf page trashes',
    ac_req('DELETE', '/cms/pages/ac89-legal/ac89-orphan'),
    200,
    static fn ($d): bool => ($d['data']['trashed'] ?? false) === true
);

ac_check('and is then unreachable at any status', ac_req('GET', '/cms/pages/ac89-legal/ac89-orphan', null, ['status' => 'any']), 404);

ac_check(
    'deleting the parent with ?force=true does it, reporting the reparenting',
    ac_req('DELETE', '/cms/pages/ac89-legal', null, ['force' => true]),
    200,
    static fn ($d): bool => ($d['data']['trashed'] ?? true) === false
);

ac_check('a page that does not exist', ac_req('DELETE', '/cms/pages/ac89-nowhere'), 404);

echo PHP_EOL, "=== §89: audit ===", PHP_EOL;

wp_set_current_user(ac_user('ac_cms_auditor', 'ac_admin'));

$find = static function (string $action, callable $match): callable {
    return static function ($d) use ($action, $match) {
        foreach ($d['data'] as $row) {
            if ($row['action'] === $action && $match($row) === true) {
                return true;
            }
        }

        return "no matching {$action} row";
    };
};

ac_check('creating a page is audited with its path', ac_req('GET', '/audit-logs', null,
    ['action' => 'cms.page_created', 'per_page' => 50]), 200,
    $find('cms.page_created', static fn ($row): bool => ($row['metadata']['path'] ?? '') === 'ac89-legal'));

ac_check('a rename names both paths', ac_req('GET', '/audit-logs', null,
    ['action' => 'cms.page_updated', 'per_page' => 50]), 200,
    $find('cms.page_updated', static fn ($row): bool => ($row['metadata']['path_from'] ?? '') === 'ac89-legal/terms'
        && ($row['metadata']['path_to'] ?? '') === 'ac89-legal/conditions'));

ac_check('publishing a draft is recorded as a status transition', ac_req('GET', '/audit-logs', null,
    ['action' => 'cms.page_updated', 'per_page' => 50]), 200,
    $find('cms.page_updated', static fn ($row): bool => ($row['metadata']['status_from'] ?? '') === 'draft'
        && ($row['metadata']['status_to'] ?? '') === 'publish'));

ac_check('a forced page delete records what it reparented', ac_req('GET', '/audit-logs', null,
    ['action' => 'cms.page_deleted', 'per_page' => 50]), 200,
    $find('cms.page_deleted', static fn ($row): bool => ($row['metadata']['forced'] ?? false) === true
        && ($row['metadata']['children_reparented'] ?? 0) >= 1));

ac_check('a menu write records the location and the count', ac_req('GET', '/audit-logs', null,
    ['action' => 'cms.menu_updated', 'per_page' => 50]), 200,
    $find('cms.menu_updated', static fn ($row): bool => $row['resource_id'] === 'footer'
        && ($row['metadata']['items'] ?? -1) >= 0));

/*
 * §71's rule, asserted rather than described: the trail records field names and
 * the shape of a document, never the content of one. "Tapis & Kilims" was a
 * homepage headline three writes ago, and an audit table nobody cleans is not
 * where a shop's copy belongs.
 */
$homepageRows = ac_req('GET', '/audit-logs', null, ['action' => 'cms.homepage_updated', 'per_page' => 50]);
ac_assert(
    'the homepage trail carries section types',
    (bool) array_filter(
        $homepageRows[1]['data'] ?? [],
        static fn ($row): bool => in_array('hero', (array) ($row['metadata']['types'] ?? []), true)
    ) ?: 'no cms.homepage_updated row named its section types'
);
ac_assert(
    'and none of a section\'s content',
    !str_contains((string) wp_json_encode($homepageRows[1]['data'] ?? []), 'Tapis')
        ?: 'a headline reached the audit trail'
);

wp_set_current_user($marketing);

// ------------------------------------------------------------------ cleanup --
//
// Idempotence is a requirement of this file, not a nicety: the path checks above
// assert a 409 on an occupied path, so a leftover page from the last run would
// make the *create* fail instead.
foreach (['ac89-legal', 'ac89-xss', 'ac89-brouillon', 'ac89-control'] as $slug) {
    foreach (get_posts(['post_type' => 'page', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 5]) as $stale) {
        wp_delete_post((int) $stale->ID, true);
    }
}

foreach (get_posts(['post_type' => 'page', 'post_status' => 'trash', 'numberposts' => 50]) as $trashed) {
    if (str_starts_with((string) $trashed->post_name, 'ac89-')) {
        wp_delete_post((int) $trashed->ID, true);
    }
}

if ($bannerId > 0) {
    wp_delete_post($bannerId, true);
}

if ($faqId > 0) {
    wp_delete_post($faqId, true);
}

foreach (['ac89-retours', 'ac89-retours-produits'] as $termSlug) {
    $term = get_term_by('slug', $termSlug, AlgerianCommerce\CMS\ContentTypes::FAQ_CATEGORY);

    if ($term instanceof WP_Term) {
        wp_delete_term((int) $term->term_id, AlgerianCommerce\CMS\ContentTypes::FAQ_CATEGORY);
    }
}

$footerMenu = wp_get_nav_menu_object((int) (get_nav_menu_locations()['footer'] ?? 0));

if ($footerMenu !== false) {
    wp_delete_nav_menu((int) $footerMenu->term_id);
    $locations = get_nav_menu_locations();
    unset($locations['footer']);
    set_theme_mod('nav_menu_locations', $locations);
}

delete_option(AlgerianCommerce\CMS\CmsRepository::HOMEPAGE_OPTION);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
