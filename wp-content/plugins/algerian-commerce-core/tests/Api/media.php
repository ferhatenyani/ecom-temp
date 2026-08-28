<?php
/**
 * Media endpoints against a real WordPress install — roadmap §61, §65 (API and
 * Security categories).
 *
 * Covers what unit tests structurally cannot: authorization on all five routes,
 * the library query against a real database, and — the ones that matter — the
 * §65 abuse cases travelling through the actual route rather than through
 * `UploadPolicy` directly.
 *
 * **The refusals run here; the acceptance does not.** `wp_handle_upload()`
 * moves the file with `move_uploaded_file()`, which by design fails for
 * anything that did not arrive over a real multipart POST — and
 * `rest_do_request()` cannot make one. Every hostile file is refused *before*
 * that point, by `UploadPolicy`, so those cases are exercised in full here; the
 * successful upload, the metadata strip and the polyglot's payload actually
 * disappearing are in scripts/test-api.sh, over real HTTP with curl. Neither
 * stage is redundant and neither can see what the other does.
 *
 * The attachments read, patched and deleted below are created with WordPress's
 * own functions rather than through the API, for the same reason.
 *
 *   scripts/test.sh                                # runs this and everything else
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/media.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array|string|null $body = null, array $query = [], array $files = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($files !== []) {
        $request->set_file_params($files);
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

// ------------------------------------------------------------------ fixtures --

function ac_jpeg_bytes(): string
{
    $image = imagecreatetruecolor(30, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, 190, 40, 40));
    ob_start();
    imagejpeg($image, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** A file in the system temp directory, as a `$_FILES` entry would name it. */
function ac_temp(string $name, string $bytes): array
{
    $path = sys_get_temp_dir() . '/ac-media-' . bin2hex(random_bytes(4));
    file_put_contents($path, $bytes);

    return [
        'name' => $name,
        'type' => 'image/jpeg',   // the client's claim, which nothing trusts
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($bytes),
    ];
}

/**
 * A real attachment, made the way WordPress makes one — not through the API,
 * which cannot complete in-process. This is the row GET/PATCH/DELETE act on.
 */
function ac_attachment(string $filename): int
{
    $uploads = wp_upload_dir();
    $path = trailingslashit($uploads['path']) . wp_unique_filename($uploads['path'], $filename);

    file_put_contents($path, ac_jpeg_bytes());

    $id = wp_insert_attachment([
        'post_mime_type' => 'image/jpeg',
        'post_title' => 'AC media fixture',
        'post_status' => 'inherit',
    ], $path);

    if (is_wp_error($id)) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata((int) $id, wp_generate_attachment_metadata((int) $id, $path));

    return (int) $id;
}

/** Is one reference in a `GET /media/{id}/usage` body? */
function ac_usage_has(array $body, string $kind, int $id, string $slot): bool
{
    foreach ($body['references'] ?? [] as $reference) {
        if (($reference['kind'] ?? '') === $kind
            && (int) ($reference['id'] ?? 0) === $id
            && ($reference['slot'] ?? '') === $slot
        ) {
            return true;
        }
    }

    return false;
}

$marketing = ac_user('ac_media_marketing', 'ac_marketing_manager');  // has ac_manage_content
$product = ac_user('ac_media_product', 'ac_product_manager');        // the documented gap
$support = ac_user('ac_media_support', 'ac_support_agent');

echo PHP_EOL, "=== authorization ===", PHP_EOL;

$routes = [
    'GET /media' => ['GET', '/media'],
    'POST /media' => ['POST', '/media'],
    'GET /media/{id}' => ['GET', '/media/1'],
    'PATCH /media/{id}' => ['PATCH', '/media/1'],
    'DELETE /media/{id}' => ['DELETE', '/media/1'],
    'GET /media/{id}/usage' => ['GET', '/media/1/usage'],
];

wp_set_current_user(0);
foreach ($routes as $label => [$method, $route]) {
    ac_check("{$label} signed out", ac_req($method, $route), 401);
}

wp_set_current_user($support);
foreach ($routes as $label => [$method, $route]) {
    ac_check("{$label} as support agent", ac_req($method, $route), 403);
}

/*
 * MediaService documents this as a deliberate gap: a Product Manager can point
 * a product at an image that exists (roadmap §47c takes an attachment id) and
 * cannot create one, because docs/PLAN.md §3 defines no media capability and
 * writing files to the server is the privilege to be strictest about.
 */
wp_set_current_user($product);
ac_check('POST /media as product manager', ac_req('POST', '/media'), 403);
ac_check('GET /media as product manager', ac_req('GET', '/media'), 403);

wp_set_current_user($marketing);

echo PHP_EOL, "=== fixtures ===", PHP_EOL;

$attachmentId = ac_attachment('ac-fixture.jpg');
ac_assert('an attachment to read', $attachmentId > 0 ?: 'no attachment created');

echo PHP_EOL, "=== the upload guard ===", PHP_EOL;

ac_check(
    'POST with no file at all',
    ac_req('POST', '/media'),
    400,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'invalid_upload'
);

ac_check(
    'POST with a file field that is not a file',
    ac_req('POST', '/media', null, [], ['file' => 'just-a-string']),
    400
);

// A web shell wearing an image's extension. Nothing about the name gives it
// away — the content sniff is what refuses it.
ac_check(
    'POST a PHP file renamed to .jpg',
    ac_req('POST', '/media', null, [], ['file' => ac_temp('innocent.jpg', "<?php system(\$_GET['c']); ?>" . str_repeat('#', 300))]),
    415,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'unsupported_media_type'
);

ac_check(
    'POST a double extension',
    ac_req('POST', '/media', null, [], ['file' => ac_temp('shell.php.jpg', ac_jpeg_bytes())]),
    400,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'invalid_upload'
);

ac_check(
    'POST a path-traversal filename',
    ac_req('POST', '/media', null, [], ['file' => ac_temp('../../../evil.jpg', ac_jpeg_bytes())]),
    400
);

ac_check(
    'POST a null byte in the filename',
    ac_req('POST', '/media', null, [], ['file' => ac_temp("evil.php\0.jpg", ac_jpeg_bytes())]),
    400
);

ac_check(
    'POST an SVG',
    ac_req('POST', '/media', null, [], ['file' => ac_temp('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]),
    415
);

ac_check(
    'POST an empty file',
    ac_req('POST', '/media', null, [], ['file' => ac_temp('empty.jpg', '')]),
    400
);

// PHP discards an oversized body before the application sees it and leaves an
// error code behind; the endpoint must answer 413 rather than "empty file".
$oversize = ac_temp('big.jpg', ac_jpeg_bytes());
$oversize['error'] = UPLOAD_ERR_INI_SIZE;
ac_check(
    'POST a file PHP already rejected as too large',
    ac_req('POST', '/media', null, [], ['file' => $oversize]),
    413,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'file_too_large'
);

// Over our own cap, which is the check that runs before anything reads bytes.
$declared = ac_temp('huge.jpg', ac_jpeg_bytes());
$declared['size'] = AlgerianCommerce\Core\Plugin::instance()->uploadPolicy()->maxBytes() + 1;
ac_check(
    'POST a file over the configured cap',
    ac_req('POST', '/media', null, [], ['file' => $declared]),
    413
);

ac_check(
    'POST more than one file',
    ac_req('POST', '/media', null, [], ['file' => [
        'name' => ['a.jpg', 'b.jpg'],
        'type' => ['image/jpeg', 'image/jpeg'],
        'tmp_name' => ['/tmp/a', '/tmp/b'],
        'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        'size' => [10, 10],
    ]]),
    400
);

// A valid image gets past every policy check and stops at move_uploaded_file(),
// which is the boundary this stage cannot cross — see the file header.
ac_check(
    'POST a valid image in-process',
    ac_req('POST', '/media', null, [], ['file' => ac_temp('tapis.jpg', ac_jpeg_bytes())]),
    400,
    static fn ($data): bool|string => ($data['error']['code'] ?? '') === 'invalid_upload'
        ? true
        : 'expected the move to fail, not the policy'
);

echo PHP_EOL, "=== reading the library ===", PHP_EOL;

ac_check(
    'GET the library',
    ac_req('GET', '/media'),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? 0) >= 1
);

ac_check('GET an item', ac_req('GET', "/media/{$attachmentId}"), 200, static function ($data): bool|string {
    $item = $data['data'] ?? [];

    if (($item['mime_type'] ?? '') !== 'image/jpeg') {
        return 'wrong mime type';
    }

    return ($item['width'] ?? 0) === 30 && ($item['height'] ?? 0) === 20
        ? true
        : 'dimensions were not read from the attachment metadata';
});

ac_check('GET an item that does not exist', ac_req('GET', '/media/99999999'), 404);

// A post that is not an attachment must not be readable through this route —
// the media library is not a way to read the whole posts table.
$page = wp_insert_post(['post_title' => 'AC media not-an-attachment', 'post_type' => 'page', 'post_status' => 'publish']);
ac_check('GET a post id that is not an attachment', ac_req('GET', '/media/' . (int) $page), 404);

ac_check(
    'GET the library filtered by type family',
    ac_req('GET', '/media', null, ['type' => 'image']),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? 0) >= 1
);

ac_check(
    'GET the library filtered by an exact type',
    ac_req('GET', '/media', null, ['type' => 'image/jpeg']),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? 0) >= 1
);

ac_check(
    'GET the library filtered by a type nobody has',
    ac_req('GET', '/media', null, ['type' => 'video/mp4']),
    200,
    static fn ($data): bool => ($data['meta']['total'] ?? -1) === 0
);

ac_check(
    'GET the library with a type that is not a mime type',
    ac_req('GET', '/media', null, ['type' => '../../etc']),
    400
);

ac_check(
    'GET the library with an oversized page size',
    ac_req('GET', '/media', null, ['per_page' => 100000]),
    400
);

ac_check(
    'GET the library ordered by something unsupported',
    ac_req('GET', '/media', null, ['orderby' => 'rand']),
    400
);

ac_check(
    'GET the library paginated',
    ac_req('GET', '/media', null, ['per_page' => 1]),
    200,
    static fn ($data): bool => count($data['data'] ?? []) === 1
);

echo PHP_EOL, "=== editing ===", PHP_EOL;

ac_check(
    'PATCH alt text, title and caption',
    ac_req('PATCH', "/media/{$attachmentId}", ['alt' => 'Tapis berbère', 'title' => 'Tapis', 'caption' => 'Fait main']),
    200,
    static function ($data): bool|string {
        $item = $data['data'] ?? [];

        return ($item['alt'] ?? '') === 'Tapis berbère' && ($item['title'] ?? '') === 'Tapis'
            ? true
            : 'the edit did not stick';
    }
);

ac_check(
    'PATCH nothing',
    ac_req('PATCH', "/media/{$attachmentId}", []),
    400,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'invalid_request'
);

ac_check(
    'PATCH an unknown field',
    ac_req('PATCH', "/media/{$attachmentId}", ['description' => 'nope']),
    400
);

// The bytes are not editable: swapping the file behind an id would change a
// product's photograph with nothing recording that it happened.
ac_check(
    'PATCH the stored file',
    ac_req('PATCH', "/media/{$attachmentId}", ['file' => 'other.jpg']),
    400,
    static fn ($data): bool => str_contains(
        (string) ($data['error']['details']['fields']['file'] ?? ''),
        'upload a new one'
    )
);

// Privilege escalation through a write path: an attachment is a post, so these
// are the fields that must never be reachable.
foreach (['post_type', 'post_status', 'post_author'] as $field) {
    ac_check("PATCH {$field}", ac_req('PATCH', "/media/{$attachmentId}", [$field => 'page']), 400);
}

ac_check(
    'PATCH clears a field with null',
    ac_req('PATCH', "/media/{$attachmentId}", ['caption' => null]),
    200,
    static fn ($data): bool => ($data['data']['caption'] ?? 'x') === ''
);

ac_check('PATCH an item that does not exist', ac_req('PATCH', '/media/99999999', ['alt' => 'x']), 404);

echo PHP_EOL, "=== deleting ===", PHP_EOL;

$doomed = ac_attachment('ac-doomed.jpg');
$doomedPath = (string) get_attached_file($doomed);
ac_assert('a second attachment to delete', $doomed > 0 && is_file($doomedPath) ?: 'no file on disk');

ac_check('DELETE an item that does not exist', ac_req('DELETE', '/media/99999999'), 404);

ac_check(
    'DELETE an item',
    ac_req('DELETE', "/media/{$doomed}"),
    200,
    static fn ($data): bool => ($data['data']['deleted'] ?? false) === true
);

// Permanent, files included: an attachment in the trash still answers at the
// same URL, so "deleted" would not be true of the only thing anyone can reach.
ac_assert('the file is gone from disk', !is_file($doomedPath) ?: 'the file survived the delete');
ac_check('GET the deleted item', ac_req('GET', "/media/{$doomed}"), 404);
ac_check('DELETE it again', ac_req('DELETE', "/media/{$doomed}"), 404);

echo PHP_EOL, "=== usage ===", PHP_EOL;

/*
 * `GET /media/{id}/usage` exists because DELETE is
 * `wp_delete_attachment($id, true)` — the row goes, the file leaves the disk,
 * and nothing about it is recoverable. The fixtures below put **one**
 * attachment into every place this codebase can store an attachment id, so the
 * endpoint is asserted against all five stores at once rather than one at a
 * time; a store that stops being queried fails one assertion by name.
 */
$used = ac_attachment('ac-usage-used.jpg');
$unused = ac_attachment('ac-usage-unused.jpg');
ac_assert('two attachments for the usage fixtures', $used > 0 && $unused > 0 ?: 'fixtures missing');

$usageProduct = new WC_Product_Simple();
$usageProduct->set_name('AC usage probe');
$usageProduct->set_regular_price('1000');
$usageProduct->set_image_id($used);
$usageProduct->set_gallery_image_ids([$used]);
$usageProductId = (int) $usageProduct->save();

/*
 * The SEO override and the option set exactly as their own repositories write
 * them: one scalar meta key (`SeoRepository::save()`), and one JSON document
 * under a protected key (`OptionSetRepository::save()`, `wp_slash()` included
 * because `update_post_meta()` unslashes what it is handed).
 */
update_post_meta($usageProductId, '_ac_seo_image_id', $used);

$usageOptionSet = static fn (int $choiceImageId, array $foreign = []): string => wp_slash((string) wp_json_encode([
    'groups' => [[
        'id' => 'wrap',
        'type' => 'choice',
        'label' => 'Gift wrap',
        'required' => false,
        'min' => 0,
        'max' => 1,
        'choices' => [
            ['id' => 'gold', 'label' => 'Or', 'price_delta' => '250', 'image_id' => $choiceImageId] + $foreign,
        ],
    ]],
]));

update_post_meta($usageProductId, '_ac_option_set', $usageOptionSet($used));

// A variation's image is `_thumbnail_id` on the variation post — the same key
// WooCommerce uses for the parent's featured image, which is why one query
// answers both. Attributes are irrelevant to that and are left off.
$usageVariable = new WC_Product_Variable();
$usageVariable->set_name('AC usage probe variable');
$usageVariableId = (int) $usageVariable->save();

$usageVariation = new WC_Product_Variation();
$usageVariation->set_parent_id($usageVariableId);
$usageVariation->set_image_id($used);
$usageVariationId = (int) $usageVariation->save();

$usagePage = (int) wp_insert_post([
    'post_title' => 'AC usage probe page',
    'post_type' => 'page',
    'post_status' => 'draft',
]);
set_post_thumbnail($usagePage, $used);
update_post_meta($usagePage, '_ac_seo_image_id', $used);

$usageBanner = (int) wp_insert_post([
    'post_title' => 'AC usage probe banner',
    'post_type' => AlgerianCommerce\CMS\ContentTypes::BANNER,
    'post_status' => 'publish',
]);
set_post_thumbnail($usageBanner, $used);

// The one reference that is not on a post. Read back and restored below.
$settingsOption = AlgerianCommerce\Settings\SettingsRepository::OPTION;
$settingsBefore = get_option($settingsOption, []);
$settingsProbe = is_array($settingsBefore) ? $settingsBefore : [];
$settingsProbe['store'] = array_merge(
    is_array($settingsProbe['store'] ?? null) ? $settingsProbe['store'] : [],
    ['logo_id' => $used]
);
update_option($settingsOption, $settingsProbe, false);

ac_check(
    'GET usage for an attachment in use',
    ac_req('GET', "/media/{$used}/usage"),
    200,
    static function ($data) use (
        $usageProductId,
        $usageVariationId,
        $usagePage,
        $usageBanner
    ): bool|string {
        $body = $data['data'] ?? [];

        $expected = [
            ['product', $usageProductId, 'featured_image'],
            ['product', $usageProductId, 'gallery'],
            ['product', $usageProductId, 'option_choice_image'],
            ['product', $usageProductId, 'seo_image'],
            ['variation', $usageVariationId, 'featured_image'],
            ['page', $usagePage, 'featured_image'],
            ['page', $usagePage, 'seo_image'],
            ['banner', $usageBanner, 'featured_image'],
            // No row of its own: the shop's logo lives in an option, and the
            // audit trail already spells a settings id `0`.
            ['settings', 0, 'store_logo'],
        ];

        foreach ($expected as [$kind, $id, $slot]) {
            if (!ac_usage_has($body, $kind, $id, $slot)) {
                return "no {$kind} {$id} reported in {$slot}";
            }
        }

        if (($body['total'] ?? -1) !== count($body['references'] ?? [])) {
            return 'total does not count the references beside it';
        }

        foreach ($body['references'] as $reference) {
            if (trim((string) ($reference['title'] ?? '')) === '') {
                return 'a reference has no title to name it with';
            }
        }

        return true;
    }
);

/*
 * `12` must not match `120`. The gallery is one comma-separated string and the
 * option set is one JSON document, so SQL can only ever narrow — the split and
 * the decode in `MediaUsageRepository` are what decide. Each pair below is a
 * value that matches the SQL and not the meaning, then the same field holding
 * the real reference, because a negative that would also pass against a broken
 * query proves nothing.
 */
update_post_meta($usageProductId, '_product_image_gallery', "1{$used},{$used}9");
ac_check(
    'GET usage: a gallery that only contains the digits',
    ac_req('GET', "/media/{$used}/usage"),
    200,
    static fn ($data): bool|string => ac_usage_has($data['data'] ?? [], 'product', $usageProductId, 'gallery')
        ? 'a substring of another id was reported as a gallery entry'
        : true
);

update_post_meta($usageProductId, '_product_image_gallery', (string) $used);
ac_check(
    'GET usage: the control, the same gallery holding the id',
    ac_req('GET', "/media/{$used}/usage"),
    200,
    static fn ($data): bool|string => ac_usage_has($data['data'] ?? [], 'product', $usageProductId, 'gallery')
        ? true
        : 'the control did not report a gallery entry that is really there'
);

/*
 * A key nothing in this plugin writes, carrying the exact bytes the LIKE looks
 * for — `"image_id":<id>` — while the choice's own image is 0. `OptionSet` is
 * deliberately lenient about a document another plugin has touched, so this is
 * the shape a foreign write really produces. SQL returns the row; the decode
 * is what refuses it.
 */
update_post_meta($usageProductId, '_ac_option_set', $usageOptionSet(0, ['meta' => ['image_id' => $used]]));
ac_check(
    'GET usage: an option set that only mentions the id elsewhere',
    ac_req('GET', "/media/{$used}/usage"),
    200,
    static fn ($data): bool|string => ac_usage_has($data['data'] ?? [], 'product', $usageProductId, 'option_choice_image')
        ? 'a foreign key in the document was read as a choice image'
        : true
);

update_post_meta($usageProductId, '_ac_option_set', $usageOptionSet($used));
ac_check(
    'GET usage: the control, the same choice holding the id',
    ac_req('GET', "/media/{$used}/usage"),
    200,
    static fn ($data): bool|string => ac_usage_has($data['data'] ?? [], 'product', $usageProductId, 'option_choice_image')
        ? true
        : 'the control did not report a choice image that is really there'
);

ac_check(
    'GET usage for an attachment nothing uses',
    ac_req('GET', "/media/{$unused}/usage"),
    200,
    static function ($data): bool|string {
        $body = $data['data'] ?? [];

        if (($body['total'] ?? -1) !== 0 || ($body['references'] ?? null) !== []) {
            return 'something claims to use a freshly created attachment';
        }

        /*
         * A `total` of 0 is only true of the places that were looked in, and
         * this is the pair of lists that says which those are. The homepage
         * document stores per-section `data` with no schema per type, so an id
         * can sit inside it where nothing can find it — the endpoint has to
         * name that rather than let 0 read as "no uses".
         */
        if (($body['checked'] ?? []) !== AlgerianCommerce\Media\MediaUsageRepository::SCOPES) {
            return 'checked does not name the scopes the repository searches';
        }

        return in_array('homepage_section_data', $body['incomplete'] ?? [], true)
            ? true
            : 'incomplete does not name the homepage document';
    }
);

// The positive control for both refusals below is the 200 above: the same route,
// the same shape, an id it does resolve.
ac_check(
    'GET usage for an id that does not exist',
    ac_req('GET', '/media/99999999/usage'),
    404,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'not_found'
);

/*
 * A page is a post this endpoint happily reports as a *reference* — the 200
 * above found this very id — and must refuse as a *subject*. The media library
 * is not a way to read the posts table, and the control is what makes that
 * distinction, rather than a missing row, the thing being asserted.
 */
ac_check(
    'GET usage for a post id that is not an attachment',
    ac_req('GET', "/media/{$usagePage}/usage"),
    404,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'not_found'
);

/*
 * Everything above goes. Unlike the upload fixtures, which the header says are
 * left for the next run, these are referenced by other rows — leaving them
 * would leave the next run's library full of probe products pointing at probe
 * images.
 */
update_option($settingsOption, $settingsBefore, false);
wp_delete_post($usageVariationId, true);
wp_delete_post($usageVariableId, true);
wp_delete_post($usageProductId, true);
wp_delete_post($usagePage, true);
wp_delete_post($usageBanner, true);
wp_delete_attachment($used, true);
wp_delete_attachment($unused, true);

ac_assert(
    'the usage fixtures are gone',
    get_post($usageProductId) === null && get_post($used) === null ?: 'a probe fixture survived'
);

echo PHP_EOL, "=== the audit trail ===", PHP_EOL;

global $wpdb;
$actions = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT DISTINCT action FROM {$wpdb->prefix}ac_audit_logs WHERE resource_type = %s",
        'media'
    )
);

ac_assert(
    'edits and deletions are recorded',
    in_array('media.updated', $actions, true) && in_array('media.deleted', $actions, true)
        ?: 'audit actions found: ' . implode(',', $actions)
);

// Tidy up the fixture page; the attachments are left for the next run to reuse.
wp_delete_post((int) $page, true);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
