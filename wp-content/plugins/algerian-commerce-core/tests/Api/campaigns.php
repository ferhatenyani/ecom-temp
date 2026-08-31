<?php
/**
 * Email marketing campaigns — roadmap §85, §86's definition of done.
 *
 * §85's own test list, in the order it writes it, minus the pure half:
 *
 *   Consent       a customer without the flag is absent from a resolved audience,
 *                 **and** the same customer with the flag is present. The positive
 *                 control is what catches a resolver returning [] for an unrelated
 *                 reason.
 *   Idempotency   a drain interrupted and resumed sends each recipient exactly once.
 *                 This is what the per-recipient row is for and it is the test that
 *                 proves the table earned its place.
 *   Isolation     a queued campaign does not delay the transactional queue.
 *   Authorization `ac_manage_marketing` alone can draft and cannot send; both
 *                 capabilities can send.
 *
 * The renderer, the allowlist and the criteria are unit tests
 * (`TemplateRendererTest`, `EmailHtmlTest`, `SegmentCriteriaTest`, `CampaignInputTest`).
 * The unsubscribe route with no credential at all is `scripts/test-api.sh`, since
 * `rest_do_request()` does not exercise the real request path.
 *
 * **Nothing here asserts a successful send.** There is no SMTP server in this stack, so
 * `wp_mail()` fails with `sendmail: can't connect` — `EmailChannel`'s docblock records
 * that the queue is doing its job when that happens. `pre_wp_mail` is short-circuited
 * to `true` so the drain's *bookkeeping* is what gets tested, which is the half that
 * has rules.
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/campaigns.php
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

        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
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

/**
 * A campaign's `body_fields` as an array.
 *
 * The presenter emits it as an object on purpose — an empty document has to
 * reach the panel as `{}` rather than `[]`, which PHP cannot express with an
 * array — so every assertion about its *contents* has to come back through JSON.
 * That is also the shape the admin app receives, so asserting against it is
 * asserting against what the panel actually gets.
 */
function ac_fields(array $data): mixed
{
    return json_decode((string) wp_json_encode($data['body_fields'] ?? null), true);
}

function ac_user(string $login, string $role): int
{
    $user = get_user_by('login', $login);

    if ($user) {
        $user->set_role($role);

        return (int) $user->ID;
    }

    return (int) wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(24),
        'user_email' => $login . '@example.test',
        'role' => $role,
    ]);
}

use AlgerianCommerce\Campaigns\Campaign;
use AlgerianCommerce\Campaigns\CampaignStatus;
use AlgerianCommerce\Campaigns\Consent;
use AlgerianCommerce\Campaigns\EmailTemplates;
use AlgerianCommerce\Campaigns\RecipientRepository;
use AlgerianCommerce\Campaigns\UnsubscribeToken;
use AlgerianCommerce\Core\Plugin;
use AlgerianCommerce\Permissions\Capabilities;

/*
 * WooCommerce's own mailer sends synchronously inside a status transition, and the
 * campaign drain calls `wp_mail()` directly. Short-circuited so the drain's
 * bookkeeping is what is exercised — and removed at the end, asserted, because a
 * filter left behind silences every later suite in the same process (§67 found this).
 */
$silenceMail = static fn (): bool => true;
add_filter('pre_wp_mail', $silenceMail, 99);

global $wpdb;

$plugin = Plugin::instance();
$campaignRepo = $plugin->campaignRepository();
$recipientRepo = $plugin->recipientRepository();
$segmentRepo = $plugin->segmentRepository();
$consent = $plugin->consent();
$notifications = $plugin->notificationRepository();

$admin = ac_user('ac_campaign_admin', Capabilities::ADMIN);
$marketer = ac_user('ac_campaign_marketer', Capabilities::MARKETING_MANAGER);
$support = ac_user('ac_campaign_support', Capabilities::SUPPORT_AGENT);

// ------------------------------------------------------------------ fixtures --
$SUFFIX = 'ac-camp';
$EMAILS = [
    'yes-a' => "{$SUFFIX}-yes-a@example.test",
    'yes-b' => "{$SUFFIX}-yes-b@example.test",
    'no' => "{$SUFFIX}-no@example.test",
];

$customers = [];

foreach ($EMAILS as $key => $email) {
    $existing = get_user_by('email', $email);

    if ($existing) {
        wp_delete_user($existing->ID);
    }

    $customers[$key] = (int) wp_insert_user([
        'user_login' => $email,
        'user_email' => $email,
        'user_pass' => wp_generate_password(24),
        'display_name' => 'Amina ' . strtoupper($key),
        'role' => 'customer',
    ]);
}

/*
 * **This suite was not re-runnable, and the second run said so unhelpfully.**
 *
 * It deletes and recreates its three customers above but left its segments
 * behind, so a second run answered **409 "A segment already uses that name"** on
 * the very first assertion and then fatalled forty lines later on a campaign id
 * of 0 — a failure that reads like a broken feature and is a dirty fixture. The
 * campaigns clean themselves up at the end; the segments never did.
 *
 * Deleted by name prefix rather than by the ids of this run, because the rows
 * that break the next run are the ones a *previous* run left behind. Straight
 * SQL: `DELETE /segments/{id}` refuses a segment a campaign still uses, which is
 * correct for an operator and wrong for a teardown that has to work regardless
 * of what the last run managed to finish.
 */
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}ac_customer_segments WHERE name LIKE %s",
    $wpdb->esc_like($SUFFIX) . '%'
));

// Consent for two of the three. The third is the control that must be absent.
$consent->set($customers['yes-a'], true, 'test');
$consent->set($customers['yes-b'], true, 'test');

// One order each, so an order-count segment has something to match.
$orders = [];

foreach ($customers as $key => $id) {
    $order = wc_create_order(['status' => 'completed', 'customer_id' => $id]);
    $order->set_total('5000.00');
    $order->set_currency(get_woocommerce_currency());
    $order->save();
    $orders[$key] = $order;
}

wp_set_current_user($admin);

echo PHP_EOL, "── segments ──", PHP_EOL;

$segment = ac_check(
    'a segment is created from criteria',
    ac_req('POST', '/segments', ['name' => "{$SUFFIX} buyers", 'criteria' => ['min_orders' => 1]]),
    201,
    static fn (array $d): bool|string => ($d['data']['criteria']['min_orders'] ?? 0) === 1
        ? true : 'criteria came back ' . wp_json_encode($d['data']['criteria'] ?? null)
);
$segmentId = (int) ($segment['data']['id'] ?? 0);

ac_check(
    'a segment with no criteria is refused',
    ac_req('POST', '/segments', ['name' => "{$SUFFIX} everyone", 'criteria' => []]),
    400,
    // Empty criteria would match every customer, which is the one mistake here that
    // cannot be undone. "Everyone eligible" has its own audience_type.
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['criteria'])
        ? true : 'no reason given'
);

ac_check(
    'a duplicate segment name is a conflict',
    ac_req('POST', '/segments', ['name' => "{$SUFFIX} buyers", 'criteria' => ['min_orders' => 1]]),
    409
);

ac_check(
    'consent cannot be a criterion',
    ac_req('POST', '/segments', ['name' => "{$SUFFIX} consenting", 'criteria' => ['marketing_consent' => true]]),
    400
);

ac_check(
    'criteria at the top level are refused rather than ignored',
    ac_req('PATCH', "/segments/{$segmentId}", ['min_orders' => 2]),
    400
);

echo PHP_EOL, "── CONSENT: the pair ──", PHP_EOL;

/*
 * §85's central test, in §65's shape. Neither half means anything alone: a resolver
 * that returned nothing at all would pass the negative and fail the positive.
 */
$preview = ac_check(
    'a consenting customer is counted',
    ac_req('GET', "/segments/{$segmentId}/preview"),
    200,
    static fn (array $d): bool|string => ($d['data']['matches'] ?? 0) >= 2
        ? true : 'matches was ' . var_export($d['data']['matches'] ?? null, true)
);
$withConsent = (int) ($preview['data']['matches'] ?? 0);

// Withdraw one and the count must fall by exactly one.
$consent->set($customers['yes-b'], false, 'test');

$after = ac_check(
    '...and withdrawing consent removes exactly that one',
    ac_req('GET', "/segments/{$segmentId}/preview"),
    200,
    static fn (array $d): bool|string => (int) ($d['data']['matches'] ?? -1) === $withConsent - 1
        ? true : 'matches went from ' . $withConsent . ' to ' . var_export($d['data']['matches'] ?? null, true)
);

$consent->set($customers['yes-b'], true, 'test');

ac_check(
    '...and giving it back restores the count',
    ac_req('GET', "/segments/{$segmentId}/preview"),
    200,
    static fn (array $d): bool|string => (int) ($d['data']['matches'] ?? -1) === $withConsent
        ? true : 'matches is ' . var_export($d['data']['matches'] ?? null, true)
);

ac_assert(
    'the never-consenting customer holds no flag',
    !Consent::has($customers['no']) ?: 'the control customer has consent'
);

echo PHP_EOL, "── the consent filter cannot be argued away ──", PHP_EOL;

/*
 * §85: "the consent filter lives in the repository that resolves an audience, not in
 * the caller." An explicit id list is the path most likely to be treated as an
 * override — an admin typed these ids, after all — so it is the one worth asserting.
 */
$explicit = ac_check(
    'a campaign naming a non-consenting customer by id',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} explicit",
        'subject' => 'Hello {{first_name}}',
        'body_html' => '<p>Hi {{first_name}}</p>',
        'body_text' => 'Hi {{first_name}}',
        'audience_type' => 'ids',
        'customer_ids' => [$customers['no']],
    ]),
    201
);
$explicitId = (int) ($explicit['data']['id'] ?? 0);

/**
 * A `CampaignService` whose mail configuration this file controls.
 *
 * **Both halves of the mail precondition are asserted in-process, and that is a
 * fix rather than a style choice.** The 503 half used to go over the route and
 * therefore depended on the *deployment* having no `SMTP_HOST` — so the moment a
 * stack configured one, two assertions here failed and said "sending is 503
 * rather than a lie" about a shop that could now send. A suite that breaks when
 * the environment is made more capable is asserting the environment, not the
 * code.
 *
 * The positive half already worked this way (see `$sendable` below) and the
 * comment there states the rule: `Config` is built from the environment once per
 * process, so the service is rebuilt with a `Config` this file controls, and a
 * production class must not grow a door for a test. This just applies it to both
 * sides.
 */
$serviceWithMail = static function (string $host) use (
    $campaignRepo,
    $recipientRepo,
    $segmentRepo,
    $plugin,
    $consent
): AlgerianCommerce\Campaigns\CampaignService {
    return new AlgerianCommerce\Campaigns\CampaignService(
        $campaignRepo,
        $recipientRepo,
        $segmentRepo,
        $plugin->audienceResolver(),
        $consent,
        new AlgerianCommerce\Settings\SettingsRepository(),
        new AlgerianCommerce\Notifications\MailTransport(
            new AlgerianCommerce\Core\Config($host === '' ? [] : ['SMTP_HOST' => $host]),
            new AlgerianCommerce\Core\Logger('test', AlgerianCommerce\Core\Logger::ERROR)
        ),
        $plugin->auditLogger(),
        new AlgerianCommerce\Core\Logger('test', AlgerianCommerce\Core\Logger::ERROR)
    );
};

$unsendable = $serviceWithMail('');

/** Run a service call and report the ApiException it was supposed to throw. */
$refusalFrom = static function (callable $call): array {
    try {
        $call();

        return ['code' => 'none', 'status' => 0];
    } catch (AlgerianCommerce\API\ApiException $e) {
        return ['code' => $e->errorCode(), 'status' => $e->statusCode()];
    }
};

/*
 * The mail precondition answers before the audience does, which is the right
 * order: a shop that cannot send should not resolve five thousand people to find
 * out. The consent assertion this campaign exists for is made below with a
 * transport configured, where the answer is a 409 naming "nobody".
 */
$refusal = $refusalFrom(static fn () => $unsendable->send($explicitId));
ac_assert(
    '...is refused, and the mail precondition answers first',
    $refusal['code'] === 'mail_not_configured' && $refusal['status'] === 503
        ?: 'got ' . wp_json_encode($refusal)
);

echo PHP_EOL, "── the mail precondition ──", PHP_EOL;

$campaign = ac_check(
    'a campaign is drafted',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} august",
        'subject' => '{{shop_name}} — for {{first_name}}',
        'body_html' => '<p>Hello {{first_name}}</p><script>alert(1)</script>',
        'body_text' => 'Hello {{first_name}}',
        'audience_type' => 'segment',
        'segment_id' => $segmentId,
    ]),
    201,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === CampaignStatus::DRAFT
        ? true : 'status was ' . ($d['data']['status'] ?? '?')
);
$campaignId = (int) ($campaign['data']['id'] ?? 0);

/*
 * §85's sanitiser, over the real `wp_kses`: the pure test asserts the allowlist, this
 * asserts that the allowlist is actually applied on the way *into* the database.
 */
ac_assert(
    'a script tag never reaches storage',
    !str_contains((string) ($campaign['data']['body_html'] ?? ''), '<script')
        ?: 'stored html is ' . ($campaign['data']['body_html'] ?? '')
);

$refusal = $refusalFrom(static fn () => $unsendable->send($campaignId));
ac_assert(
    'with no mail transport, sending is 503 rather than a lie',
    $refusal['code'] === 'mail_not_configured' && $refusal['status'] === 503
        ?: 'got ' . wp_json_encode($refusal)
);

ac_assert(
    'and nothing was written to the recipient table',
    $recipientRepo->counts($campaignId)['total'] === 0 ?: 'rows were written before the refusal'
);

/*
 * From here on a transport exists. The service reads SMTP_HOST through Config, which is built from the environment
 * through Config, which is built from the environment once per process, so the service
 * is rebuilt with a Config this file controls — the same device tests/Api/account.php
 * uses for password reset. A production class must not grow a door for a test.
 */
$sendable = $serviceWithMail('smtp.example.test');

echo PHP_EOL, "── the consent filter, with a transport ──", PHP_EOL;

try {
    $sendable->send($explicitId);
    ac_assert('a campaign to a non-consenting customer is refused', 'it was accepted');
} catch (AlgerianCommerce\API\ApiException $e) {
    ac_assert(
        'a campaign to a non-consenting customer is refused',
        $e->statusCode() === 409 && str_contains($e->getMessage(), 'nobody')
            ? true : 'threw ' . $e->statusCode() . ' ' . $e->getMessage()
    );
}

// THE POSITIVE CONTROL: the same shape of campaign, to a customer who *has* consented.
$consenting = $sendable->create([
    'name' => "{$SUFFIX} explicit-yes",
    'subject' => 'Hello',
    'body_html' => '<p>Hi {{first_name}}</p>',
    'body_text' => 'Hi',
    'audience_type' => 'ids',
    'customer_ids' => [$customers['yes-a']],
]);

$sent = $sendable->send($consenting->id);

ac_assert(
    '...while the identical campaign to a consenting one goes out',
    ($sent['recipients'] ?? 0) === 1 ?: 'recipients was ' . var_export($sent['recipients'] ?? null, true)
);

echo PHP_EOL, "── preview and test send ──", PHP_EOL;

$rendered = $sendable->preview($campaignId);

ac_assert(
    'the preview merges the subject',
    str_contains($rendered['subject'], 'Amina') && !str_contains($rendered['subject'], '{{')
        ?: 'subject is ' . $rendered['subject']
);
ac_assert(
    'the preview appends an unsubscribe link',
    $rendered['unsubscribe_appended'] === true && str_contains($rendered['html'], 'marketing/unsubscribe')
        ?: 'html is ' . substr($rendered['html'], 0, 200)
);
ac_assert(
    'the preview uses a sample rather than a real customer',
    !str_contains($rendered['html'], 'example.test') || !str_contains($rendered['html'], $EMAILS['yes-a'])
        ?: 'a real address reached the preview'
);
ac_assert(
    'the preview reports an unknown token rather than mailing it',
    $sendable->preview($sendable->create([
        'name' => "{$SUFFIX} typo", 'subject' => 'Bonjour {{prenom}}',
        'body_html' => '<p>x</p>', 'body_text' => 'x',
        'audience_type' => 'all',
    ])->id)['unknown_tokens'] === ['prenom']
        ?: 'unknown tokens were not reported'
);

$test = $sendable->test($campaignId, 'operator@example.test');
ac_assert('a test send reports what it sent', ($test['subject'] ?? '') !== '' ?: 'no subject came back');
ac_assert(
    'a test send writes no recipient row',
    $recipientRepo->counts($campaignId)['total'] === 0 ?: 'a test consumed a recipient row'
);

echo PHP_EOL, "── IDEMPOTENCY: interrupt and resume ──", PHP_EOL;

/*
 * §85: "a drain interrupted and resumed sends each recipient exactly once. This is
 * what the per-recipient row is for and it is the test that proves the table earned
 * its place."
 */
$queued = $sendable->send($campaignId);
$total = (int) ($queued['recipients'] ?? 0);

ac_assert('the audience was frozen', $total >= 2 ?: "only {$total} recipients");
ac_check(
    'the campaign is now sending',
    ac_req('GET', "/campaigns/{$campaignId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === CampaignStatus::SENDING
        ? true : 'status is ' . ($d['data']['status'] ?? '?')
);

// A second send must change nothing at all — the claim is one UPDATE with
// `WHERE status = 'draft'`, so the loser writes nothing rather than racing on.
try {
    $sendable->send($campaignId);
    ac_assert('a second send is refused', 'it was accepted');
} catch (AlgerianCommerce\API\ApiException $e) {
    ac_assert('a second send is refused', $e->statusCode() === 409 ?: 'threw ' . $e->statusCode());
}

ac_assert(
    '...and did not duplicate a single recipient row',
    $recipientRepo->counts($campaignId)['total'] === $total
        ?: 'rows went from ' . $total . ' to ' . $recipientRepo->counts($campaignId)['total']
);

// THE INTERRUPTION: drain one row, then the rest.
$first = $sendable->drain(1, $campaignId);

ac_assert('the first batch attempted exactly one', ($first['attempted'] ?? 0) === 1 ?: 'attempted ' . ($first['attempted'] ?? 0));
ac_assert('and the campaign is not finished', ($first['completed'] ?? 0) === 0 ?: 'it completed early');

$sentIds = array_map(
    static fn (array $r): int => (int) $r['customer_id'],
    $recipientRepo->paginate($campaignId, ['status' => RecipientRepository::STATUS_SENT], 1, 100)
);

ac_assert('exactly one row is marked sent', count($sentIds) === 1 ?: count($sentIds) . ' rows are sent');

$second = $sendable->drain(50, $campaignId);

ac_assert(
    'the resume picked up where it stopped',
    ($second['attempted'] ?? 0) === $total - 1
        ?: 'attempted ' . ($second['attempted'] ?? 0) . ' of a remaining ' . ($total - 1)
);
ac_assert('and finished the campaign', ($second['completed'] ?? 0) === 1 ?: 'it did not complete');

$finalCounts = $recipientRepo->counts($campaignId);
$allSent = $recipientRepo->paginate($campaignId, ['status' => RecipientRepository::STATUS_SENT], 1, 100);
$customerIds = array_map(static fn (array $r): int => (int) $r['customer_id'], $allSent);

ac_assert(
    'EVERY recipient was sent EXACTLY once',
    count($customerIds) === count(array_unique($customerIds)) && count($customerIds) === $finalCounts['sent']
        ?: 'ids were ' . wp_json_encode($customerIds)
);
ac_assert(
    'a third drain finds nothing left to do',
    ($sendable->drain(50, $campaignId)['attempted'] ?? -1) === 0 ?: 'it sent again'
);

ac_check(
    'the campaign reports sent, with its counts',
    ac_req('GET', "/campaigns/{$campaignId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === CampaignStatus::SENT
        && (int) ($d['data']['recipients']['sent'] ?? 0) > 0
        ? true : 'campaign is ' . wp_json_encode($d['data']['recipients'] ?? null)
);

echo PHP_EOL, "── consent is re-checked at send time ──", PHP_EOL;

/*
 * A customer who unsubscribes *after* the audience was frozen — quite possibly from
 * the first batch of this same campaign — must not be mailed by the second. The frozen
 * row is what the admin previewed; consent is the one fact allowed to have changed.
 */
$late = $sendable->create([
    'name' => "{$SUFFIX} late-withdrawal",
    'subject' => 'Hello',
    'body_html' => '<p>x</p>', 'body_text' => 'x',
    'audience_type' => 'ids',
    'customer_ids' => [$customers['yes-a'], $customers['yes-b']],
]);

$sendable->send($late->id);
$consent->set($customers['yes-b'], false, 'test');
$sendable->drain(50, $late->id);

$lateRows = $recipientRepo->paginate($late->id, [], 1, 100);
$byCustomer = [];

foreach ($lateRows as $row) {
    $byCustomer[(int) $row['customer_id']] = (string) $row['status'];
}

ac_assert(
    'the customer who withdrew mid-send was not mailed',
    ($byCustomer[$customers['yes-b']] ?? '') === RecipientRepository::STATUS_FAILED
        ?: 'their row is ' . ($byCustomer[$customers['yes-b']] ?? 'missing')
);
ac_assert(
    '...while the one who did not was',
    ($byCustomer[$customers['yes-a']] ?? '') === RecipientRepository::STATUS_SENT
        ?: 'their row is ' . ($byCustomer[$customers['yes-a']] ?? 'missing')
);

$consent->set($customers['yes-b'], true, 'test');

echo PHP_EOL, "── ISOLATION: two queues, two drains ──", PHP_EOL;

/*
 * §85: "a queued campaign does not delay the transactional queue." Asserted as the
 * property that makes it true — the two tables are separate, so queueing a campaign
 * adds nothing to `ac_notifications` and draining notifications attempts none of the
 * campaign's rows.
 */
$notificationsBefore = $notifications->summary();

$isolated = $sendable->create([
    'name' => "{$SUFFIX} isolation",
    'subject' => 'Hello',
    'body_html' => '<p>x</p>', 'body_text' => 'x',
    'audience_type' => 'ids',
    'customer_ids' => [$customers['yes-a'], $customers['yes-b']],
]);
$sendable->send($isolated->id);

$notificationsAfter = $notifications->summary();

ac_assert(
    'queueing a campaign adds nothing to the transactional queue',
    $notificationsAfter === $notificationsBefore
        ?: 'notifications went from ' . wp_json_encode($notificationsBefore) . ' to ' . wp_json_encode($notificationsAfter)
);

$drained = $plugin->notificationService()->drain(50);

ac_assert(
    'and the notification drain attempts none of the campaign rows',
    $recipientRepo->counts($isolated->id)[RecipientRepository::STATUS_PENDING] === 2
        ?: 'the notification drain touched campaign rows'
);
ac_assert(
    'the two tables are genuinely different tables',
    $recipientRepo->table() !== $notifications->table() ?: 'one table, two queues'
);

echo PHP_EOL, "── AUTHORIZATION: draft without sending ──", PHP_EOL;

/*
 * §85: "`ac_manage_marketing` alone can draft and cannot send; both capabilities can
 * send." A Marketing Manager holds the first and not the second (§45's matrix), so this
 * is a live restriction.
 */
wp_set_current_user($marketer);

ac_assert(
    'a Marketing Manager holds marketing and not customers',
    current_user_can(Capabilities::MANAGE_MARKETING) && !current_user_can(Capabilities::MANAGE_CUSTOMERS)
        ?: 'the role matrix changed'
);

$draft = ac_check(
    'a Marketing Manager can draft',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} marketer draft",
        'subject' => 'Hello',
        'body_html' => '<p>x</p>', 'body_text' => 'x',
        'audience_type' => 'all',
    ]),
    201
);
$draftId = (int) ($draft['data']['id'] ?? 0);

ac_check('...and preview', ac_req('GET', "/campaigns/{$draftId}/preview"), 200);
ac_check(
    '...and cannot send',
    ac_req('POST', "/campaigns/{$draftId}/send"),
    403
);
ac_check(
    '...and cannot read the recipient list',
    ac_req('GET', "/campaigns/{$campaignId}/recipients"),
    403
);
ac_check(
    '...nor count a segment',
    ac_req('GET', "/segments/{$segmentId}/preview"),
    403
);
ac_check(
    'the preview hides the audience count from them',
    ac_req('GET', "/campaigns/{$draftId}/preview"),
    200,
    // `array_key_exists`, not `??`: the value under test *is* null, and `?? 'missing'`
    // cannot tell "present and null" from "absent" — which is exactly what this
    // assertion is about.
    static fn (array $d): bool|string => array_key_exists('audience_count', $d['data'] ?? [])
        && $d['data']['audience_count'] === null
        ? true : 'count was ' . var_export($d['data']['audience_count'] ?? 'absent', true)
);

// THE POSITIVE CONTROL for every 403 above.
wp_set_current_user($admin);

ac_check(
    'an Admin holding both can read the recipient list',
    ac_req('GET', "/campaigns/{$campaignId}/recipients"),
    200,
    static fn (array $d): bool|string => count($d['data'] ?? []) > 0 ? true : 'no rows came back'
);

/*
 * **`meta.total` has to follow `?status=`, and once did not.** Measured
 * 2026-08-21: the rows were filtered by `RecipientRepository::paginate()` and the
 * total came from the unfiltered `counts()`, so `?status=failed` answered **0
 * rows with `meta.total: 9`** — a paginating client showed "9 recipients" above
 * an empty table and offered pages that do not exist.
 *
 * It is the one filter this route exists to serve: `send-campaigns` ends with
 * *"see GET /campaigns/{id}/recipients?status=failed"*, so the URL the drain
 * hands an operator was the one that reported wrong.
 *
 * Asserted as **the sum, not as a number**. Hard-coding "3 failed" would pin how
 * many rows this suite's fixture happens to have; asserting that the three
 * filtered totals add up to the unfiltered one holds whatever the fixture does,
 * and it is the property that was actually broken.
 */
/*
 * One row parked as permanently failed first, through `markFailed()` — the
 * drain's own method, with the drain's own error string.
 *
 * Without it every recipient in this fixture is `sent`, so each filter returns
 * either everything or nothing and never a **subset**: the floor below caught
 * exactly that on the first run. A partition of 9 and 1 is what makes
 * "returns only rows of that status" a claim about filtering rather than about
 * an empty result.
 */
$parked = $recipientRepo->paginate($campaignId, [], 1, 1);
$recipientRepo->markFailed((int) ($parked[0]['id'] ?? 0), 'wp_mail() did not accept the message.', false);

$whole = ac_req('GET', "/campaigns/{$campaignId}/recipients", null, ['per_page' => 100]);
$wholeTotal = (int) ($whole[1]['meta']['total'] ?? 0);
$byStatus = [];

foreach ([RecipientRepository::STATUS_PENDING, RecipientRepository::STATUS_SENT, RecipientRepository::STATUS_FAILED] as $status) {
    $response = ac_req('GET', "/campaigns/{$campaignId}/recipients", null, [
        'status' => $status,
        'per_page' => 100,
    ]);
    $body = $response[1];
    $byStatus[$status] = (int) ($body['meta']['total'] ?? -1);

    ac_assert(
        "recipients ?status={$status} reports what it returns",
        $byStatus[$status] === count($body['data'] ?? [])
            ?: "meta.total {$byStatus[$status]} against " . count($body['data'] ?? []) . ' rows'
    );

    // And every row it returned really is that status, which is the half a
    // count check cannot see.
    $foreign = array_filter($body['data'] ?? [], static fn (array $r): bool => ($r['status'] ?? '') !== $status);
    ac_assert(
        "...and returns only {$status} rows",
        $foreign === [] ?: count($foreign) . ' row(s) of another status came back'
    );
}

ac_assert(
    'the three filtered totals account for every recipient',
    array_sum($byStatus) === $wholeTotal
        ?: 'the parts sum to ' . array_sum($byStatus) . " against a whole of {$wholeTotal}"
);

// The floor. If the fixture were all one status, every assertion above would
// pass against a filter that does nothing at all.
ac_assert(
    'and the fixture spans more than one status, or none of that proved anything',
    count(array_filter($byStatus, static fn (int $n): bool => $n > 0)) >= 2
        ?: 'recipients are all one status: ' . wp_json_encode($byStatus)
);

/*
 * Put the parked row back, because the purge section downstream compares the
 * **live** counts it reads before the purge against the campaign's **stored
 * columns** after it — and those columns were written by the drain, before this
 * block existed. Leaving the row failed made "the counts survive the purge"
 * report a drift this block had caused, which is a fixture bleeding into an
 * assertion about something else.
 */
$recipientRepo->markSent((int) ($parked[0]['id'] ?? 0));
ac_check('...and count a segment', ac_req('GET', "/segments/{$segmentId}/preview"), 200);
ac_check(
    '...and sees the audience count in a preview',
    ac_req('GET', "/campaigns/{$draftId}/preview"),
    200,
    static fn (array $d): bool|string => is_int($d['data']['audience_count'] ?? null)
        ? true : 'count was ' . var_export($d['data']['audience_count'] ?? null, true)
);

wp_set_current_user($support);

foreach ([
    ['GET', '/campaigns'],
    ['GET', "/campaigns/{$campaignId}"],
    ['GET', '/segments'],
    ['GET', '/email-templates'],
] as [$method, $route]) {
    ac_check(
        "a Support Agent is refused {$method} {$route}" . str_pad('', 22 - strlen($route)),
        ac_req($method, $route),
        403
    );
}

wp_set_current_user($admin);

echo PHP_EOL, "── unsubscribe ──", PHP_EOL;

$token = UnsubscribeToken::mint($customers['yes-a'], wp_salt('auth'));

ac_assert('the customer is consenting to begin with', Consent::has($customers['yes-a']) ?: 'no consent');

ac_check(
    'one click, no login, and it works',
    ac_req('GET', '/marketing/unsubscribe', null, ['token' => $token]),
    200,
    static fn (array $d): bool|string => ($d['data']['unsubscribed'] ?? false) === true ? true : 'not unsubscribed'
);

ac_assert('the consent is gone', !Consent::has($customers['yes-a']) ?: 'still consenting');

ac_check('clicking again is idempotent', ac_req('GET', '/marketing/unsubscribe', null, ['token' => $token]), 200);

ac_assert('and still gone', !Consent::has($customers['yes-a']) ?: 'consent came back');

/*
 * A forged token answers **identically** to a valid one. This route is
 * unauthenticated, so distinguishing them would make it an oracle for "is this a
 * customer id" — and there is nothing a legitimate holder of a link learns from the
 * difference.
 */
$forged = ac_req('GET', '/marketing/unsubscribe', null, ['token' => $customers['yes-b'] . '.' . str_repeat('a', 32)]);
$valid = ac_req('GET', '/marketing/unsubscribe', null, ['token' => $token]);

ac_assert(
    'a forged token answers identically to a real one',
    $forged === $valid ?: 'the responses differ, which is an oracle'
);
ac_assert(
    '...and unsubscribed nobody',
    Consent::has($customers['yes-b']) ?: 'a forged token withdrew somebody\'s consent'
);

ac_check('no token at all still answers 200', ac_req('GET', '/marketing/unsubscribe'), 200);

// A POST for a storefront that prefers one; same token, same answer.
$consent->set($customers['yes-b'], true, 'test');
$tokenB = UnsubscribeToken::mint($customers['yes-b'], wp_salt('auth'));

ac_check('POST works too', ac_req('POST', '/marketing/unsubscribe', ['token' => $tokenB]), 200);
ac_assert('and withdrew that consent', !Consent::has($customers['yes-b']) ?: 'still consenting');

$consent->set($customers['yes-a'], true, 'test');
$consent->set($customers['yes-b'], true, 'test');

echo PHP_EOL, "── the shopper's own door ──", PHP_EOL;

$shopperEmail = "{$SUFFIX}-shopper@example.test";
$existingShopper = get_user_by('email', $shopperEmail);

if ($existingShopper) {
    wp_delete_user($existingShopper->ID);
}

wp_set_current_user(0);

$registered = ac_check(
    'registration defaults to NO consent',
    ac_req('POST', '/account/register', [
        'email' => $shopperEmail, 'password' => 'CorrectHorseBatteryCamp', 'first_name' => 'Nadia',
    ]),
    201
);
$shopperId = (int) ($registered['data']['customer']['id'] ?? 0);
$shopperToken = (string) ($registered['data']['token'] ?? '');

ac_assert('...and it really is off', !Consent::has($shopperId) ?: 'registration opted somebody in');

ac_check(
    'the shopper can opt in themselves',
    ac_req('POST', '/account/marketing-consent', ['consent' => true], ['customer_token' => $shopperToken]),
    200,
    static fn (array $d): bool|string => ($d['data']['marketing_consent'] ?? false) === true
        && ($d['data']['changed'] ?? false) === true ? true : 'response was ' . wp_json_encode($d['data'] ?? null)
);

ac_assert('and the flag is set', Consent::has($shopperId) ?: 'not consenting');

ac_check(
    'setting it again is idempotent and says so',
    ac_req('POST', '/account/marketing-consent', ['consent' => true], ['customer_token' => $shopperToken]),
    200,
    static fn (array $d): bool|string => ($d['data']['changed'] ?? true) === false ? true : 'changed was true again'
);

ac_check(
    'no session is 401',
    ac_req('POST', '/account/marketing-consent', ['consent' => true]),
    401
);

/*
 * **No staff route can set the flag.** A shop that could tick this box on somebody's
 * behalf has no consent record worth anything, which is why `CustomerInput` was left
 * alone — and why the field is reported on a customer and refused on a write.
 */
wp_set_current_user($admin);

ac_check(
    'a staff write cannot set consent',
    ac_req('PATCH', "/customers/{$shopperId}", ['marketing_consent' => true]),
    400
);
ac_check(
    'but staff can see it',
    ac_req('GET', "/customers/{$shopperId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['marketing_consent'] ?? null) === true
        ? true : 'reported ' . var_export($d['data']['marketing_consent'] ?? null, true)
);

echo PHP_EOL, "── templates ──", PHP_EOL;

$templateId = wp_insert_post([
    'post_type' => EmailTemplates::POST_TYPE,
    'post_title' => "{$SUFFIX} template",
    'post_status' => 'publish',
    'post_content' => '<p>Hello {{first_name}}</p><script>alert(1)</script><iframe src="x"></iframe>'
        . '<a href="javascript:alert(1)">bad</a><a href="https://ok.test" onclick="alert(1)">ok</a>',
]);
update_post_meta($templateId, EmailTemplates::TEXT_META, "Hello {{first_name}}\r\n");

$stored = (string) get_post((int) $templateId)->post_content;

ac_assert('a script tag is stripped on save', !str_contains($stored, '<script') ?: 'script survived');
ac_assert('an iframe is stripped on save', !str_contains($stored, '<iframe') ?: 'iframe survived');
ac_assert('a javascript: URL is stripped', !str_contains($stored, 'javascript:') ?: 'javascript URL survived');
ac_assert('an on* handler is stripped', !str_contains($stored, 'onclick') ?: 'onclick survived');
// THE POSITIVE CONTROL: an allowlisted link survives, or the sanitiser is just
// deleting everything and every assertion above proves nothing.
ac_assert('...while an ordinary link survives', str_contains($stored, 'https://ok.test') ?: 'the safe link was stripped');
ac_assert('...and so does the merge token', str_contains($stored, '{{first_name}}') ?: 'the token was stripped');

ac_check(
    'a template reads back through the API',
    ac_req('GET', "/email-templates/{$templateId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['has_unsubscribe_token'] ?? true) === false
        && ($d['data']['unknown_tokens'] ?? null) === []
        ? true : 'template reported ' . wp_json_encode($d['data'] ?? null)
);

$fromTemplate = ac_check(
    'a campaign can name a template',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} templated", 'subject' => 'From a template',
        'template_id' => $templateId, 'audience_type' => 'all',
    ]),
    201
);

ac_check(
    'a campaign naming a template that does not exist is refused',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} nosuch", 'subject' => 'x',
        'template_id' => 99999999, 'audience_type' => 'all',
    ]),
    400
);

echo PHP_EOL, "── lifecycle and refusals ──", PHP_EOL;

ac_check(
    'a sent campaign cannot be edited',
    ac_req('PATCH', "/campaigns/{$campaignId}", ['subject' => 'Too late']),
    409
);
ac_check(
    'a sent campaign cannot be deleted',
    ac_req('DELETE', "/campaigns/{$campaignId}"),
    409
);
ac_check(
    'a sent campaign cannot be cancelled either',
    ac_req('POST', "/campaigns/{$campaignId}/cancel"),
    409
);
ac_check(
    'a draft can be cancelled',
    ac_req('POST', "/campaigns/{$draftId}/cancel"),
    200,
    static fn (array $d): bool|string => ($d['data']['status'] ?? '') === CampaignStatus::CANCELLED
        ? true : 'status is ' . ($d['data']['status'] ?? '?')
);
ac_check(
    'a segment in use cannot be deleted',
    ac_req('DELETE', "/segments/{$segmentId}"),
    409,
    static fn (array $d): bool|string => ($d['error']['details']['campaigns'] ?? 0) > 0
        ? true : 'it did not say how many campaigns use it'
);
ac_check('an unknown campaign is 404', ac_req('GET', '/campaigns/99999999'), 404);
ac_check('an unknown segment is 404', ac_req('GET', '/segments/99999999'), 404);

foreach (['status' => 'sent', 'recipients_total' => 5, 'emails' => ['x@example.test'], 'to' => 'x@example.test'] as $field => $value) {
    ac_check(
        "a campaign write refuses {$field}" . str_pad('', 28 - strlen($field)),
        ac_req('POST', '/campaigns', [
            'name' => "{$SUFFIX} refused", 'subject' => 'x', 'audience_type' => 'all', $field => $value,
        ]),
        400,
        static fn (array $d): bool|string => isset($d['error']['details']['fields'][$field])
            ? true : "{$field} was not named"
    );
}

echo PHP_EOL, "── the composer's answers ──", PHP_EOL;

/*
 * `body_fields` — migration 014. The form's own answers, stored beside the HTML
 * they generate so that reopening a saved campaign gives back something
 * re-editable instead of a wall of `<td style="…">`.
 *
 * Four things are worth testing over the real request path rather than in
 * `CampaignInputTest`, because all four need a database or `wp_kses`:
 *
 *   1. it round-trips — written by POST, read by GET, unchanged;
 *   2. a PATCH carrying only `body_fields` is a valid write, and one carrying
 *      `null` clears it back to the state a pre-composer campaign is in;
 *   3. a campaign that predates the column reads `null` and not `{}`, which is
 *      the distinction the panel branches on;
 *   4. **markup in an answer cannot reach a sent email.** This is the one the
 *      field could plausibly get wrong, so it is asserted from both ends.
 */

$composed = ac_check(
    'a campaign accepts the composer\'s answers',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} composed",
        'subject' => 'Soldes',
        'audience_type' => 'all',
        'body_html' => '<p>Soldes</p>',
        // Keys this API has never heard of, on purpose: the vocabulary belongs to
        // the panel's form and must not need a backend release to grow.
        'body_fields' => [
            'headline' => 'Soldes d’été',
            'accent' => '#c41e3a',
            'discount' => 20,
            'blocks' => [
                ['type' => 'text', 'value' => 'Tout à < 500 DA'],
                ['type' => 'button', 'label' => 'Acheter', 'url' => 'https://example.test/shop'],
            ],
        ],
    ]),
    201,
    static fn (array $d): bool|string => (ac_fields($d['data'])['headline'] ?? '') === 'Soldes d’été'
        ? true : 'the answers did not come back on create'
);

$composedId = (int) ($composed['data']['id'] ?? 0);

ac_check(
    'the answers survive a read unchanged',
    ac_req('GET', "/campaigns/{$composedId}"),
    200,
    static function (array $d): bool|string {
        $fields = ac_fields($d['data']);

        if (($fields['blocks'][1]['label'] ?? '') !== 'Acheter') {
            return 'a nested repeater did not survive';
        }

        if (!array_is_list($fields['blocks'] ?? null)) {
            return 'a repeater came back as an object rather than a list';
        }

        if (($fields['discount'] ?? null) !== 20) {
            return 'a number came back as ' . var_export($fields['discount'] ?? null, true);
        }

        // The value that would be destroyed by running the HTML sanitiser over
        // every string rather than only over the ones shaped like markup.
        return ($fields['blocks'][0]['value'] ?? '') === 'Tout à < 500 DA'
            ? true : 'an ordinary "<" in copy was mangled';
    }
);

ac_check(
    'a PATCH carrying only the answers is a valid write',
    ac_req('PATCH', "/campaigns/{$composedId}", ['body_fields' => ['headline' => 'Rentrée']]),
    200,
    static fn (array $d): bool|string => (ac_fields($d['data'])['headline'] ?? '') === 'Rentrée'
        ? true : 'the answers were not replaced'
);

ac_check(
    'an explicit null clears them, which is what an undo needs to survive a reload',
    ac_req('PATCH', "/campaigns/{$composedId}", ['body_fields' => null]),
    200,
    static fn (array $d): bool|string => array_key_exists('body_fields', $d['data'])
        && $d['data']['body_fields'] === null
            ? true : 'null did not clear the answers'
);

/*
 * `[]` rather than `new stdClass()`, and the reason is a trap rather than a
 * preference: `ac_req()` above calls `set_param()` for every body key as well as
 * setting the JSON body, and WordPress's `set_param()` **overwrites the already
 * parsed JSON parameter in place**. An object handed to it therefore reaches
 * `get_json_params()` as an object, which no real request can produce —
 * `WP_REST_Request::parse_json_params()` decodes associatively, so `{}` on the
 * wire is always `[]` by the time a controller sees it. Sending `[]` here is
 * sending what the HTTP path actually delivers.
 */
ac_check(
    'and the empty form is not the same answer as no form at all',
    ac_req('PATCH', "/campaigns/{$composedId}", ['body_fields' => []]),
    200,
    static function (array $d): bool|string {
        $encoded = (string) wp_json_encode($d['data']['body_fields'] ?? null);

        return $encoded === '{}' ? true : 'the empty document came back as ' . $encoded;
    }
);

/*
 * A campaign whose row was written before this column existed. Simulated by
 * setting the column back to NULL, which is exactly what migration 014 leaves on
 * every pre-existing row — a `DEFAULT '{}'` here would have told the panel that
 * every campaign in the shop's history was composed with a form that did not
 * exist when they were written.
 */
$wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->prefix}ac_campaigns SET body_fields = NULL WHERE id = %d",
    $composedId
));

ac_check(
    'a campaign that predates the column reads null, not {}',
    ac_req('GET', "/campaigns/{$composedId}"),
    200,
    static fn (array $d): bool|string => array_key_exists('body_fields', $d['data'])
        && $d['data']['body_fields'] === null
            ? true : 'a pre-existing campaign did not read back as null'
);

ac_check(
    'an unreadable document reads as no answers rather than blank answers',
    (static function () use ($wpdb, $composedId): array {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ac_campaigns SET body_fields = %s WHERE id = %d",
            'not json at all',
            $composedId
        ));

        return ac_req('GET', "/campaigns/{$composedId}");
    })(),
    200,
    // `array_key_exists`, not `??` — the value being looked for *is* null, so a
    // coalesce would report the success as a failure.
    static fn (array $d): bool|string => array_key_exists('body_fields', $d['data'])
        && $d['data']['body_fields'] === null
            ? true : 'an unreadable column read back as blank answers, which would let the panel clobber body_html'
);

// ------------------------------- the answers are not a way past the allowlist --

/*
 * **The security-shaped half.** `body_fields` is not HTML, so nothing renders it
 * here — but its whole purpose is to be handed to the panel's generator, which
 * interpolates it into HTML. If the stored answers could carry `<script>`, then a
 * generator that pastes a value into markup would fire it in an admin's own
 * preview, before a save and therefore before `EmailHtml` had ever seen the
 * result. So the answers are sanitised with the same allowlist `body_html` gets,
 * and this is asserted at both ends: what comes back, and what a send would mail.
 */

$hostile = ac_check(
    'markup in an answer is sanitised on the way in',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} hostile",
        'subject' => 'x',
        'audience_type' => 'all',
        'body_html' => '<p>ok</p>',
        'body_fields' => [
            'headline' => '<script>alert(1)</script>Soldes',
            'blocks' => [
                ['value' => '<img src=x onerror="alert(1)">'],
                ['value' => '<iframe src="//evil.test"></iframe>'],
                ['value' => '<a href="javascript:alert(1)">click</a>'],
            ],
            // Deliberately not markup: it must come back untouched, or the
            // sanitiser is a corrupter.
            'accent' => '#c41e3a',
        ],
    ]),
    201,
    static function (array $d): bool|string {
        $blob = (string) wp_json_encode($d['data']['body_fields'] ?? null);

        foreach (['script', 'onerror', 'iframe', 'javascript:'] as $needle) {
            if (stripos($blob, $needle) !== false) {
                return "\"{$needle}\" survived into the stored answers";
            }
        }

        return str_contains($blob, '#c41e3a') ? true : 'a value that was not markup was destroyed';
    }
);

$hostileId = (int) ($hostile['data']['id'] ?? 0);

ac_check(
    'the stored row itself is clean, not just the response',
    ac_req('GET', "/campaigns/{$hostileId}"),
    200,
    static fn (array $d): bool|string => stripos((string) wp_json_encode($d['data']['body_fields'] ?? null), 'script') === false
        ? true : 'the database kept the markup and only the response hid it'
);

/*
 * And the end that actually matters: the message. `body_fields` has no path into
 * `compose()` at all — the preview renders `body_html` and the template's — so
 * this asserts the *absence* of a path rather than the sanitiser doing its job.
 * If somebody later wires the answers into the renderer, this is what fails.
 */
ac_check(
    'the answers never reach the rendered message',
    ac_req('GET', "/campaigns/{$hostileId}/preview"),
    200,
    static function (array $d): bool|string {
        $rendered = (string) ($d['data']['html'] ?? '') . (string) ($d['data']['text'] ?? '');

        if (stripos($rendered, 'script') !== false || stripos($rendered, 'evil.test') !== false) {
            return 'an answer reached the rendered message';
        }

        return str_contains($rendered, 'ok') ? true : 'the preview did not render body_html at all';
    }
);

ac_check(
    'an oversize document is refused rather than truncated',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} oversize", 'subject' => 'x', 'audience_type' => 'all',
        'body_fields' => ['headline' => str_repeat('a', 70000)],
    ]),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['body_fields'])
        ? true : 'body_fields was not named'
);

ac_check(
    'a body_fields that is not an object is refused',
    ac_req('POST', '/campaigns', [
        'name' => "{$SUFFIX} notobject", 'subject' => 'x', 'audience_type' => 'all',
        'body_fields' => '{"headline":"double-encoded"}',
    ]),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['body_fields'])
        ? true : 'body_fields was not named'
);

echo PHP_EOL, "── the purge ──", PHP_EOL;

/*
 * §85: "Recipient rows are purged some fixed period after a campaign completes,
 * keeping the aggregate counts on `ac_campaigns` and dropping the addresses."
 */
$before = $recipientRepo->counts($campaignId);

ac_assert('the completed campaign still has its rows', $before['total'] > 0 ?: 'no rows to purge');

// Backdate the completion so the retention window has passed.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "UPDATE {$campaignRepo->table()} SET completed_at = %s WHERE id = %d",
    gmdate('Y-m-d H:i:s', time() - (AlgerianCommerce\Campaigns\CampaignService::PURGE_AFTER_DAYS + 5) * DAY_IN_SECONDS),
    $campaignId
));

$purged = $sendable->purge();

ac_assert('the purge removed the addresses', $purged >= $before['total'] ?: "purged {$purged} of {$before['total']}");
ac_assert('and left no recipient rows', $recipientRepo->counts($campaignId)['total'] === 0 ?: 'rows remain');

$afterPurge = ac_check(
    'the counts survive the purge, which is why they are columns',
    ac_req('GET', "/campaigns/{$campaignId}"),
    200,
    static fn (array $d): bool|string => (int) ($d['data']['recipients']['sent'] ?? 0) === $before['sent']
        && ($d['data']['recipients']['purged'] ?? false) === true
        ? true : 'counts are ' . wp_json_encode($d['data']['recipients'] ?? null)
);

ac_check(
    'the recipient list reports itself purged rather than empty',
    ac_req('GET', "/campaigns/{$campaignId}/recipients"),
    200,
    static fn (array $d): bool|string => ($d['meta']['purged'] ?? false) === true ? true : 'purged flag missing'
);

ac_assert(
    'a purge does not touch a campaign that has not finished',
    $recipientRepo->counts($isolated->id)['total'] === 2 ?: 'an unfinished campaign was purged'
);

// ------------------------------------------------------------------ cleanup --
echo PHP_EOL;

foreach ($campaignRepo->paginate(['search' => $SUFFIX], 1, 100) as $c) {
    $recipientRepo->purge($c->id);
    $campaignRepo->delete($c->id);
}

foreach ($segmentRepo->paginate(1, 100) as $s) {
    if (str_starts_with($s->name, $SUFFIX)) {
        $segmentRepo->delete($s->id);
    }
}

wp_delete_post((int) $templateId, true);

foreach ($orders as $order) {
    $order->delete(true);
}

foreach ([...array_values($EMAILS), $shopperEmail] as $email) {
    $existing = get_user_by('email', $email);

    if ($existing) {
        wp_delete_user($existing->ID);
    }
}

foreach (['ac_campaign_admin', 'ac_campaign_marketer', 'ac_campaign_support'] as $login) {
    $user = get_user_by('login', $login);

    if ($user) {
        wp_delete_user($user->ID);
    }
}

ac_assert(
    'the suite left no campaigns behind',
    $campaignRepo->count(['search' => $SUFFIX]) === 0
        ?: $campaignRepo->count(['search' => $SUFFIX]) . ' campaigns remain'
);

remove_filter('pre_wp_mail', $silenceMail, 99);
ac_assert(
    'wp_mail is not left short-circuited for the next suite',
    !has_filter('pre_wp_mail', $silenceMail) ?: 'the filter is still attached'
);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
