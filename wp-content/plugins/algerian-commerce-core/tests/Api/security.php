<?php
/**
 * The security half of roadmap §65, and the one place its list is answered
 * line by line — docs/SECURITY.md → "Security tests to write".
 *
 * §65 names nine categories: SQL injection, XSS, CSRF, IDOR, privilege
 * escalation, rate limits, file upload abuse, webhook forgery, replay. Four of
 * them were already covered under other names by the suites written alongside
 * the features, and duplicating those here would produce a second set of
 * assertions to keep in step with the first. So this file holds **only what
 * nothing else covered**, and names the covering test for everything else. The
 * map, with the reasoning, is docs/TESTING.md.
 *
 * Already covered, deliberately not repeated:
 *
 *   rate limits        scripts/test-api.sh — the only stage that can see them,
 *                      because rest_do_request() never parses an Authorization
 *                      header; plus tests/Unit/RateLimiterTest for the window
 *   file upload abuse  tests/Api/media.php (every hostile file refused) and
 *                      tests/Unit/UploadPolicyTest (the four checks, pure),
 *                      with the re-encode and the non-executable directory in
 *                      scripts/test-api.sh
 *   webhook forgery    tests/Api/shipping-webhooks.php, tests/Api/payments.php
 *   replay             the same two — the idempotency claim is a write-once
 *                      insert whose duplicate-key failure is the answer
 *
 * **CSRF is ruled out rather than tested here, and the argument is in
 * docs/SECURITY.md → "CSRF".** The short form: this API has no ambient
 * credential. An Application Password travels in an `Authorization` header,
 * which a cross-origin page cannot set without a preflight the CORS allowlist
 * refuses; and WordPress core forces `wp_set_current_user(0)` on any REST
 * request that arrives with cookies but no `X-WP-Nonce`, so a browser's
 * automatic cookies are never sufficient. Neither half can be observed from
 * here — rest_do_request() has no headers and no cookies — so both are asserted
 * over real HTTP in scripts/test-api.sh.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/security.php
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

/** How many rows a list endpoint says it matched, whatever it calls the field. */
function ac_total(array $data): ?int
{
    $total = $data['meta']['pagination']['total'] ?? $data['meta']['total'] ?? null;

    return is_int($total) ? $total : null;
}

$admin = ac_user('ac_sec_admin', 'ac_super_admin');
$support = ac_user('ac_sec_support', 'ac_support_agent');

wp_set_current_user($admin);

echo PHP_EOL, "── every route declares a guard ──", PHP_EOL;

/*
 * docs/SECURITY.md: "Every private route needs an explicit authorization check
 * — registering a route without a real permission_callback is a bug."
 *
 * That rule has been enforced by review until now, which works right up to the
 * first route somebody adds in a hurry. This reads the routes WordPress
 * actually registered — not the source — so a guard that is present but never
 * reaches the router fails here too.
 *
 * The public list is an allowlist rather than an exception, and every entry has
 * a reason: /health and the namespace index disclose no shop data, the
 * geography routes serve the §51 dataset that ships in the plugin and is the
 * same for every client, a webhook's signature *is* its authentication
 * (AbstractWebhookController says so at the line that writes __return_true),
 * and §59b's cart and checkout are reached by shoppers who have no account at
 * all — what protects a cart is the signed token that opens it, not a
 * capability, and requiring one would mean the storefront proxying every
 * quantity change with an admin credential, which is the arrangement §44
 * exists to prevent. `tests/Api/cart.php` is where that token is proven to be
 * the owner.
 */
$publicPrefixes = [
    '/algerian-commerce/v1',                    // core's own namespace index
    '/algerian-commerce/v1/health',
    '/algerian-commerce/v1/locations/',
    '/algerian-commerce/v1/webhooks/',
    '/algerian-commerce/v1/cart',               // §59b — the token is the owner
    '/algerian-commerce/v1/checkout',           // §59b — same cart, same token
    // §59c. Not "unguarded" — a capability cannot express "the person holding
    // this session", so the check is AccountSession::require() one layer down,
    // which answers 401. tests/Api/account.php calls every one of these with no
    // session, a forged session and another shopper's session, because the
    // route table cannot show what is protecting them.
    '/algerian-commerce/v1/account',
    // §84. Same argument one step further out: a guest order has no owner at
    // all, so no capability and no session could express "whoever holds this
    // link". What protects it is a 128-bit HMAC the shop handed to the buyer,
    // checked in `TrackingService::track()`, with its own rate-limit group
    // because it is unauthenticated. `tests/Api/tracking.php` calls it with no
    // token, a malformed one, a tampered MAC, another order's MAC, a revoked
    // link and an expired one — each beside a positive control.
    '/algerian-commerce/v1/orders/track',
];

$guarded = [];
$ungoverned = [];
$routes = rest_get_server()->get_routes();

foreach ($routes as $route => $handlers) {
    if (!str_starts_with($route, '/algerian-commerce/v1')) {
        continue;
    }

    $public = false;

    foreach ($publicPrefixes as $prefix) {
        if ($route === $prefix || str_starts_with($route, $prefix)) {
            $public = true;
            break;
        }
    }

    foreach ($handlers as $handler) {
        $callback = $handler['permission_callback'] ?? null;
        $open = $callback === null || $callback === '__return_true';

        if ($open && !$public) {
            $ungoverned[] = $route;
        }

        if (!$open) {
            $guarded[] = $route;
        }
    }
}

ac_assert(
    'the route table was actually read',
    count($guarded) >= 60 ?: 'only ' . count($guarded) . ' guarded handlers found'
);
ac_assert(
    'no private route is left open',
    $ungoverned === [] ?: 'open: ' . implode(', ', array_unique($ungoverned))
);

// The couriers' inbound routes are absent until their secret is set, which is
// what makes an unconfigured webhook a 404 rather than a door (§60). Asserted
// as an observation about this install, not a requirement: it flips the day a
// secret is configured, and CLAUDE.md wants that noticed.
$courierRoutes = array_values(array_filter(
    array_keys($routes),
    static fn (string $r): bool => str_contains($r, '/webhooks/yalidine') || str_contains($r, '/webhooks/zr-express')
));
ac_assert(
    'no courier webhook route exists while no secret is set',
    $courierRoutes === [] ?: 'registered: ' . implode(', ', $courierRoutes)
);

echo PHP_EOL, "── SQL injection ──", PHP_EOL;

/*
 * Every repository builds SQL through $wpdb->prepare(), and
 * tests/Unit/SqlSafetyTest proves that statically over every call site in the
 * plugin. This is the other half: the payloads actually travelling through the
 * routes, ending at the widest SQL surface in the codebase —
 * Analytics/AnalyticsRepository, which takes a window, a status list and a
 * currency from above it and is the only file running raw aggregate SQL against
 * WooCommerce's order tables.
 *
 * **The discriminating assertion is that a payload must not widen a result
 * set.** Asserting only "HTTP 200, no crash" would pass just as happily against
 * a concatenated query — `' OR '1'='1` returning the whole catalogue is a 200.
 * So each payload is compared against a benign nonsense value: both must match
 * nothing. That is the assertion a string-concatenated WHERE actually fails.
 */
$payloads = [
    "' OR '1'='1",
    "' OR 1=1 -- ",
    "'; DROP TABLE {$GLOBALS['wpdb']->posts}; -- ",
    "\\' OR 1=1 -- ",
    "' UNION SELECT 1,2,3,4,5,6,7,8 -- ",
    "1 AND (SELECT 1 FROM (SELECT SLEEP(0))x)",
    "%",
    "_",
];

// Free-text filters that reach a LIKE or a meta query. A payload here is data.
foreach ([
    ['/products', 'search'],
    ['/customers', 'search'],
    ['/inventory', 'search'],
    ['/shipments', 'tracking_number'],
] as [$route, $arg]) {
    [, $benign] = ac_req('GET', $route, null, [$arg => 'zzz-no-such-value-xyzzy']);
    $floor = ac_total($benign);

    foreach ($payloads as $payload) {
        [$status, $data] = ac_req('GET', $route, null, [$arg => $payload]);
        $total = ac_total($data);

        // `%` and `_` are LIKE wildcards rather than injection: a filter that
        // sanitises them to nothing is unfiltered by definition, and that is a
        // documented meaning of an empty filter, not a leak.
        $wildcard = in_array($payload, ['%', '_'], true);

        ac_assert(
            sprintf('%s?%s=%s', $route, $arg, str_pad(substr($payload, 0, 22), 22)),
            $status === 200 && ($wildcard || $total === $floor)
                ?: "status {$status}, total " . var_export($total, true) . ", benign " . var_export($floor, true)
        );
    }
}

/*
 * Args with an enum or a pattern, where validation rather than prepare() is the
 * first defence. `orderby` is the one that would reach an ORDER BY clause, and
 * an ORDER BY cannot be parameterised — every repository answers that with an
 * allowlist (`in_array($criteria['orderby'], self::ORDERBY, true)`), so a
 * payload that got past the route would still not reach the clause.
 *
 * The property is **refused, or accepted and matching nothing** — not "refused"
 * flatly. `_` is a legal character in an audit action name and its pattern says
 * so, and demanding a 400 there would be a test asserting that a valid value is
 * invalid. What matters is that no payload returns rows, which is the same
 * discriminating question as above.
 */
foreach ([
    ['/products', 'orderby'],
    ['/orders', 'orderby'],
    ['/customers', 'orderby'],
    ['/orders', 'status'],
    ['/audit-logs', 'action'],
] as [$route, $arg]) {
    $leaked = [];

    foreach ($payloads as $payload) {
        [$status, $data] = ac_req('GET', $route, null, [$arg => $payload]);

        if ($status === 400) {
            continue;
        }

        if ($status !== 200 || ac_total($data) !== 0) {
            $leaked[] = sprintf('%s → %d/%s', $payload, $status, var_export(ac_total($data), true));
        }
    }

    ac_assert(
        sprintf('%s?%s is refused or matches nothing', $route, $arg),
        $leaked === [] ?: implode('; ', $leaked)
    );
}

// The analytics window: eight aggregate queries, a 366-day cap, and the only
// raw SQL against WooCommerce's order tables.
foreach ($payloads as $payload) {
    [$status] = ac_req('GET', '/analytics/revenue', null, [
        'range' => 'custom',
        'date_from' => $payload,
        'date_to' => '2026-08-16',
    ]);

    ac_assert(
        'analytics date_from=' . str_pad(substr($payload, 0, 24), 24),
        $status === 400 ?: "status {$status} — a non-date reached the window"
    );
}

foreach ($payloads as $payload) {
    [$status] = ac_req('GET', '/analytics/overview', null, ['range' => $payload]);

    ac_assert(
        'analytics range=' . str_pad(substr($payload, 0, 27), 27),
        $status === 400 ?: "status {$status} — a non-preset reached the window"
    );
}

// Nothing above executed. Checked rather than assumed: a successful DROP is
// silent from the API's side, and the next suite to run would be the one that
// reported it.
global $wpdb;
ac_assert(
    'the posts table is still there',
    $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->posts}'") === $wpdb->posts ?: 'the posts table is gone'
);
ac_assert(
    'the shipments table is still there',
    $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ac_shipments'") === $wpdb->prefix . 'ac_shipments'
        ?: 'the shipments table is gone'
);
ac_assert(
    'no payload produced a database error',
    $wpdb->last_error === '' ?: 'last error: ' . $wpdb->last_error
);

echo PHP_EOL, "── IDOR ──", PHP_EOL;

/*
 * **This API has no owner dimension yet, and that is the finding rather than a
 * gap in the tests.** Every route carries a management capability, so a caller
 * who may read one order may read them all; `Permissions::assertOwnsOr()`
 * exists, is unused, and `OrderService` says why — a shopper reading their own
 * order needs the customer session deferred in roadmap §44. Testing "customer A
 * cannot read customer B's order" today would be testing a route that does not
 * exist.
 *
 * What *is* reachable is the other half of the same bug class: an id that
 * belongs to a different kind of object. WordPress keeps posts, products,
 * orders, attachments and refunds in one id space, so `GET /media/{id}` with a
 * product id is a real request that a repository reading `get_post()` without a
 * type check would answer. Each of these must be a 404.
 */
$probeProduct = wp_insert_post([
    'post_type' => 'product',
    'post_title' => 'ac security probe',
    'post_status' => 'publish',
]);
$probeAttachment = wp_insert_post([
    'post_type' => 'attachment',
    'post_title' => 'ac security probe attachment',
    'post_status' => 'inherit',
    'post_mime_type' => 'image/jpeg',
]);

ac_assert('a probe product exists', $probeProduct > 0 ?: 'could not create a product');
ac_assert('a probe attachment exists', $probeAttachment > 0 ?: 'could not create an attachment');

foreach ([
    "/orders/{$probeProduct}",
    "/orders/{$probeProduct}/notes",
    "/orders/{$probeProduct}/timeline",
    "/orders/{$probeProduct}/cod",
    "/orders/{$probeProduct}/shipments",
    "/orders/{$probeProduct}/payments",
    "/media/{$probeProduct}",
    "/shipments/{$probeProduct}",
    "/payments/{$probeProduct}",
    "/customers/{$probeProduct}",
] as $route) {
    ac_check('a product id is not ' . str_pad(explode('/', $route)[1], 44), ac_req('GET', $route), 404);
}

// ...and the same in the other direction. `/inventory/{id}` is deliberately
// absent from this list: inventory *is* keyed on products, so a product id
// there is the correct id, not a confused one.
foreach ([
    "/products/{$probeAttachment}",
    "/products/{$probeAttachment}/variations",
    "/inventory/{$probeAttachment}",
] as $route) {
    ac_check('an attachment id is not a product' . str_pad('', 27), ac_req('GET', $route), 404);
}

echo PHP_EOL, "── privilege escalation ──", PHP_EOL;

/*
 * Two shapes, and the suites written alongside each feature already cover the
 * third — a caller with the wrong capability getting 403 on that feature's own
 * routes, asserted in every tests/Api suite, and the analytics money split
 * asserted in tests/Api/analytics.php.
 *
 * What nothing covered is the sweep: **one under-privileged credential against
 * every route in the namespace at once.** A feature's own suite cannot catch a
 * route registered later with the wrong guard, because it does not know that
 * route exists. This does, because it reads the router.
 */
wp_set_current_user($support);

// Fully qualified: this file is eval()'d in the global namespace, so a bare
// class name would resolve to \Capabilities and fatal.
$held = [
    AlgerianCommerce\Permissions\Capabilities::MANAGE_CUSTOMERS,
    AlgerianCommerce\Permissions\Capabilities::VIEW_ANALYTICS,
];
$reached = [];
$swept = [];
$checked = 0;

foreach ($routes as $route => $handlers) {
    if (!str_starts_with($route, '/algerian-commerce/v1')) {
        continue;
    }

    foreach ($handlers as $handler) {
        $callback = $handler['permission_callback'] ?? null;

        // Only GET. A sweep that posted to every route would, on the day the
        // guard is broken, perform the write it is trying to prove impossible.
        if (empty($handler['methods']['GET']) || !$callback instanceof Closure) {
            continue;
        }

        $capability = (new ReflectionFunction($callback))->getStaticVariables()['capability'] ?? null;

        if (!is_string($capability) || in_array($capability, $held, true)) {
            continue;
        }

        $path = str_replace('/algerian-commerce/v1', '', $route);

        // Path parameters get an id nothing owns: a 404 from an unauthorized
        // caller is still a refusal, and a 200 would not be.
        $collection = !str_contains($path, '(?P<');
        $path = (string) preg_replace('/\(\?P<\w+>[^)]*\)/', '99999999', $path);
        $checked++;

        [$status] = ac_req('GET', $path);

        if ($status === 200) {
            $reached[] = $path . ' (needs ' . $capability . ')';
        }

        // Collection routes take no id, so what an authorized caller gets back
        // does not depend on what the shop happens to contain. Those are the
        // control below.
        if ($collection) {
            $swept[$path] = $status;
        }
    }
}

ac_assert(
    'the sweep actually visited routes',
    $checked >= 20 ?: "only {$checked} routes swept"
);
ac_assert(
    'a Support Agent reaches nothing outside its two capabilities',
    $reached === [] ?: 'reached: ' . implode(', ', array_unique($reached))
);

/*
 * The control, and the sweep above does not mean anything without it.
 *
 * "Not 200" is also what a typo in a route path produces, and what a route
 * missing a required argument produces for everybody — `/inventory/lookup`
 * wants a SKU and `/shipping/rates` wants a destination, so both answer 400 to
 * an administrator too. Running the same routes as an administrator is what
 * separates "the guard refused this" from "nothing was ever reachable there":
 * the same reason scripts/test-api.sh fetches an uploaded image to a file
 * before grepping it, rather than grepping a body that might be empty.
 *
 * So the assertion is about the *kind* of refusal. Where an administrator is
 * served, the Support Agent must be refused **on authorization** — 403, not a
 * 404 or a validation error that would have happened anyway.
 */
wp_set_current_user($admin);

$wrongReason = [];
$proven = 0;

foreach ($swept as $path => $supportStatus) {
    [$adminStatus] = ac_req('GET', $path);

    if ($adminStatus !== 200) {
        // The route refuses everyone for its own reasons; it proves nothing
        // either way, so it is not evidence and not a failure.
        continue;
    }

    $proven++;

    if ($supportStatus !== 403) {
        $wrongReason[] = "{$path} → admin 200, support {$supportStatus}";
    }
}

ac_assert(
    'the control found routes an administrator can actually reach',
    $proven >= 10 ?: "only {$proven} of " . count($swept) . ' swept routes served an administrator'
);
ac_assert(
    'where an administrator is served, a Support Agent is refused 403',
    $wrongReason === [] ?: implode(', ', $wrongReason)
);

wp_set_current_user($support);

/*
 * The write shape. `ac_manage_customers` is Support Agent's one management
 * capability and a WordPress user carries a role, a capability map and a
 * password hash — so PATCH /customers/{id} is the escalation path if
 * CustomerInput ever stops refusing those fields by name.
 * tests/Unit/CustomerInputTest proves the class refuses them; this proves the
 * refusal survives the route, the sanitiser and WooCommerce's customer CRUD.
 */
$victim = ac_user('ac_sec_victim', 'customer');
ac_assert('a probe customer exists', $victim > 0 ?: 'could not create a customer');

foreach ([
    ['roles' => ['administrator']],
    ['capabilities' => ['ac_manage_users' => true]],
    ['user_pass' => 'hunter2'],
    ['role' => 'ac_super_admin'],
] as $body) {
    ac_check(
        'PATCH /customers refuses ' . str_pad((string) array_key_first($body), 34),
        ac_req('PATCH', "/customers/{$victim}", $body),
        400
    );
}

$after = get_user_by('id', $victim);
ac_assert(
    'the customer is still a customer',
    $after instanceof WP_User && in_array('customer', (array) $after->roles, true)
        ?: 'roles are now ' . implode(',', (array) ($after->roles ?? []))
);
ac_assert(
    'the customer did not gain a capability',
    $after instanceof WP_User && !user_can($after, 'ac_manage_users') && !user_can($after, 'manage_options')
        ?: 'the probe customer gained a management capability'
);

echo PHP_EOL, "── XSS ──", PHP_EOL;

/*
 * **This API answers `application/json` and never HTML, so there is no context
 * here for a script to execute in** — the escaping that matters happens in the
 * Next.js storefront, which is another repository. What this side owes is
 * narrower and is what these assert: a payload is stored and returned as
 * *data*, unchanged and un-executed, and no route can be talked into emitting
 * it into a context that runs it.
 *
 * Two places on this side genuinely do render into an executing context, and
 * both are covered where they live: a CSV cell is a formula a spreadsheet will
 * run (tests/Unit/CsvWriterTest, tests/Api/import-export.php), and an SVG or a
 * polyglot image is script in the uploads directory (tests/Unit/UploadPolicyTest
 * — SVG is refused outright, and every accepted image is re-encoded from
 * decoded pixels).
 */
wp_set_current_user($admin);

$xss = '<script>alert(1)</script>';
$created = ac_check(
    'a product name may contain markup',
    ac_req('POST', '/products', ['name' => "AC probe {$xss}", 'regular_price' => '10']),
    201
);

$xssProduct = (int) ($created['data']['id'] ?? 0);
ac_assert('the product was created', $xssProduct > 0 ?: 'no id came back');

if ($xssProduct > 0) {
    ac_check(
        'markup comes back as data, neither executed nor mangled',
        ac_req('GET', "/products/{$xssProduct}"),
        200,
        static function (array $d) use ($xss): bool|string {
            $name = (string) ($d['data']['name'] ?? '');

            // WordPress may strip the tag on the way in — that is kses doing
            // its job and is a fine outcome. What must never happen is the
            // tag surviving *and* the response claiming to be HTML.
            return !str_contains($name, '<script') || str_contains($name, '&lt;script')
                ? true
                : 'a raw <script> element came back in a name: ' . substr($name, 0, 80);
        }
    );

    // The same value through §64's export, which is the one route on this side
    // that emits a document another program will parse.
    [$status, $csv] = ac_req('GET', '/export/products', null, ['limit' => 100]);
    ac_assert(
        'an export of that product is still a CSV, not an envelope',
        $status === 200 && is_string($csv) ?: 'status ' . $status . ', body ' . gettype($csv)
    );

    wp_delete_post($xssProduct, true);
}

// Tidy up, so a re-run starts where it started.
wp_delete_post($probeProduct, true);
wp_delete_post($probeAttachment, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
