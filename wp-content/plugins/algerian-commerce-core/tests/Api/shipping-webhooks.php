<?php
/**
 * Courier webhooks against a real WordPress + WooCommerce install — roadmap §60,
 * §65 (Security test categories: webhook forgery and replay).
 *
 * Two halves, because they can only be tested two ways:
 *
 *   through the route   that an unconfigured courier has no endpoint at all,
 *                       and that a courier which receives no webhooks says so
 *   through the service  the claim, the parcel lookup, the re-fetch and the
 *                       audit row — against a real $wpdb, with a stand-in
 *                       courier so no network is involved
 *
 * The verifiers themselves — Svix for ZR Express, the body token for Yalidine,
 * forgery, tampering and replay — are in tests/Unit/CourierWebhookTest, where
 * they need no database at all.
 *
 *   scripts/test.sh
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/shipping-webhooks.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ProviderRegistry;
use AlgerianCommerce\Shipping\Shipment;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentResult;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Shipping\ShipmentWebhookResult;
use AlgerianCommerce\Shipping\ShippingProviderInterface;
use AlgerianCommerce\Shipping\ShippingService;
use AlgerianCommerce\Shipping\StatusReport;

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array|string|null $body = null, array $headers = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($headers as $name => $value) {
        $request->set_header($name, $value);
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

/**
 * A courier that verifies a fixed token and reports whatever it is told to.
 *
 * Standing in for Yalidine and ZR Express so this file exercises the *pipeline*
 * — claim, lookup, re-fetch, audit — without a network. That it takes fifteen
 * lines to write is the property the abstraction exists for.
 */
final class AcFakeCourier implements ShippingProviderInterface
{
    public int $statusCalls = 0;

    public function __construct(
        private readonly string $token = 'let-me-in',
        private string $reports = ShipmentStatus::IN_TRANSIT
    ) {
    }

    public function reportNext(string $status): void
    {
        $this->reports = $status;
    }

    public function name(): string
    {
        return 'acfake';
    }

    public function label(): string
    {
        return 'Test courier';
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return new ShipmentResult('FAKE-1', 'TRACK-1', ShipmentStatus::CREATED);
    }

    public function cancelShipment(string $providerShipmentId): bool
    {
        return true;
    }

    public function getShipmentStatus(string $providerShipmentId): StatusReport
    {
        $this->statusCalls++;

        return new StatusReport($this->reports, 'RAW_' . strtoupper($this->reports));
    }

    /** @return list<\AlgerianCommerce\Shipping\RateQuote> */
    public function getShippingRates(Destination $destination): array
    {
        return [];
    }

    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): ShipmentWebhookResult
    {
        $token = isset($payload['token']) && is_scalar($payload['token']) ? (string) $payload['token'] : '';

        if ($token === '' || !hash_equals($this->token, $token)) {
            throw new ApiException('webhook_unverified', 'This request could not be verified.', 401);
        }

        return new ShipmentWebhookResult(
            (string) ($payload['event_id'] ?? ''),
            (string) ($payload['parcel_id'] ?? ''),
            (string) ($payload['tracking'] ?? ''),
            (string) ($payload['event'] ?? 'parcel.updated')
        );
    }
}

echo PHP_EOL, "=== routes ===", PHP_EOL;

$plugin = AlgerianCommerce\Core\Plugin::instance();
$registered = $plugin->shippingProviders()->names();
echo '  registered couriers: ', implode(', ', $registered), PHP_EOL;

// The §55 property: an unconfigured provider has no endpoint at all. Yalidine
// and ZR Express are absent here because no credentials are set in this stack.
foreach (['yalidine', 'zrexpress'] as $courier) {
    if (in_array($courier, $registered, true)) {
        ac_check("{$courier} is configured, so its route exists", ac_req('POST', "/webhooks/{$courier}", ['x' => 1]), 401);

        continue;
    }

    ac_check("an unconfigured {$courier} has no endpoint", ac_req('POST', "/webhooks/{$courier}", ['x' => 1]), 404);
}

ac_check(
    'in-house delivery receives no webhooks',
    ac_req('POST', '/webhooks/manual', ['anything' => true]),
    400,
    static fn ($data): bool => ($data['error']['code'] ?? '') === 'webhook_unsupported'
);

ac_check('a courier nobody has ever registered', ac_req('POST', '/webhooks/dhl', ['x' => 1]), 404);

echo PHP_EOL, "=== the pipeline, with a stand-in courier ===", PHP_EOL;

global $wpdb;

$courier = new AcFakeCourier();
$service = new ShippingService(
    $plugin->shipmentRepository(),
    new ProviderRegistry([$courier]),
    $plugin->orderRepository(),
    $plugin->geoRepository(),
    $plugin->auditLogger(),
    $plugin->shippingRuleRepository(),
    $plugin->webhookEventRepository(),
    $plugin->logger()
);

// An order and a parcel to talk about.
$productId = (int) wc_get_product_id_by_sku('AC-WEBHOOK-BOX');
$product = $productId > 0 ? wc_get_product($productId) : new WC_Product_Simple();
$product->set_name('Webhook test box');
$product->set_sku('AC-WEBHOOK-BOX');
$product->set_regular_price('1500');
$product->set_status('publish');
$product->save();

$order = wc_create_order();
$order->add_product(wc_get_product($product->get_id()), 1);
$order->calculate_totals();
$order->save();
$orderId = $order->get_id();

$parcelId = 'FAKE-' . wp_generate_password(10, false);
$tracking = 'TRK-' . wp_generate_password(8, false);
$now = gmdate('Y-m-d H:i:s');

$shipmentId = $plugin->shipmentRepository()->insert(new Shipment(
    $orderId,
    'acfake',
    $parcelId,
    $tracking,
    ShipmentStatus::CREATED,
    [],
    $now,
    $now
));

ac_assert('a parcel to be told about', is_int($shipmentId) && $shipmentId > 0 ?: 'no shipment row');

$event = static fn (array $overrides = []): array => array_replace([
    'token' => 'let-me-in',
    'event_id' => 'evt-' . wp_generate_password(12, false),
    'event' => 'parcel.state.updated',
    'parcel_id' => $GLOBALS['ac_parcel_id'],
    'tracking' => '',
], $overrides);

$GLOBALS['ac_parcel_id'] = $parcelId;

// Forgery, through the service rather than the adapter.
try {
    $service->handleWebhook('acfake', ['token' => 'wrong'], [], '{"token":"wrong"}');
    ac_assert('a forged event is refused', 'it was accepted');
} catch (ApiException $e) {
    ac_assert(
        'a forged event is refused',
        ($e->errorCode() === 'webhook_unverified' && $e->statusCode() === 401) ?: 'got ' . $e->errorCode()
    );
}

$first = $event();
$answer = $service->handleWebhook('acfake', $first, [], (string) wp_json_encode($first));
ac_assert('a verified event is processed', ($answer['status'] ?? '') === 'processed' ?: 'got ' . ($answer['status'] ?? '?'));
ac_assert('the courier was asked, not believed', $courier->statusCalls === 1 ?: 'status calls: ' . $courier->statusCalls);

$moved = $plugin->shipmentRepository()->find($shipmentId);
ac_assert('the parcel moved', $moved->status === ShipmentStatus::IN_TRANSIT ?: 'status is ' . $moved->status);

// Replay: the identical delivery again. The claim refuses it, and — the part
// that matters — the courier is not asked a second time.
$replay = $service->handleWebhook('acfake', $first, [], (string) wp_json_encode($first));
ac_assert('a replayed event is dropped', ($replay['status'] ?? '') === 'duplicate' ?: 'got ' . ($replay['status'] ?? '?'));
ac_assert('and the courier was not asked again', $courier->statusCalls === 1 ?: 'status calls: ' . $courier->statusCalls);

// A fresh event that reports nothing new is not a write.
$courier->reportNext(ShipmentStatus::IN_TRANSIT);
$again = $service->handleWebhook('acfake', $event(), [], '{}' !== '' ? (string) wp_json_encode($event()) : '');
ac_assert('an event with no news is unchanged', ($again['status'] ?? '') === 'unchanged' ?: 'got ' . ($again['status'] ?? '?'));

// A parcel this shop has never heard of.
$unknown = $event(['parcel_id' => 'FAKE-nobody-knows']);
$missing = $service->handleWebhook('acfake', $unknown, [], (string) wp_json_encode($unknown));
ac_assert(
    'an event about an unknown parcel is dropped',
    ($missing['status'] ?? '') === 'unknown_parcel' ?: 'got ' . ($missing['status'] ?? '?')
);

// A verified event that names no parcel at all.
$empty = $event(['parcel_id' => '', 'tracking' => '']);
$ignored = $service->handleWebhook('acfake', $empty, [], (string) wp_json_encode($empty));
ac_assert('an event naming no parcel is dropped', ($ignored['status'] ?? '') === 'ignored' ?: 'got ' . ($ignored['status'] ?? '?'));

// The parcel finishes, and a late event does not reopen it.
$courier->reportNext(ShipmentStatus::DELIVERED);
$delivery = $event();
$service->handleWebhook('acfake', $delivery, [], (string) wp_json_encode($delivery));
$finished = $plugin->shipmentRepository()->find($shipmentId);
ac_assert('the parcel is delivered', $finished->status === ShipmentStatus::DELIVERED ?: 'status is ' . $finished->status);

$courier->reportNext(ShipmentStatus::IN_TRANSIT);
$late = $event();
$reopen = $service->handleWebhook('acfake', $late, [], (string) wp_json_encode($late));
ac_assert('a late event does not reopen it', ($reopen['status'] ?? '') === 'finished' ?: 'got ' . ($reopen['status'] ?? '?'));
ac_assert(
    'and it is still delivered',
    $plugin->shipmentRepository()->find($shipmentId)->status === ShipmentStatus::DELIVERED ?: 'it moved'
);

// A parcel status never moves the order (CLAUDE.md) — webhook or not.
$orderAfter = wc_get_order($orderId);
ac_assert(
    'the order was not moved by a parcel',
    $orderAfter->get_status() === $order->get_status() ?: 'order is now ' . $orderAfter->get_status()
);

$sources = $wpdb->get_col($wpdb->prepare(
    "SELECT metadata FROM {$wpdb->prefix}ac_audit_logs
     WHERE resource_type = 'order' AND resource_id = %s AND action = 'shipment.status_changed'",
    (string) $orderId
));

$fromWebhook = 0;
foreach ($sources as $json) {
    $decoded = json_decode((string) $json, true);
    if (is_array($decoded) && ($decoded['source'] ?? '') === 'webhook') {
        $fromWebhook++;
    }
}

ac_assert('the changes are audited as webhook-sourced', $fromWebhook === 2 ?: "found {$fromWebhook}");

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

if ($GLOBALS['ac_fail'] > 0) {
    exit(1);
}
