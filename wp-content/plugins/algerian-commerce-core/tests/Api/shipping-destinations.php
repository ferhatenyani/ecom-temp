<?php
/**
 * The Yalidine destination sync against the real geography tables — roadmap
 * §56.
 *
 * Covers what unit tests structurally cannot: that a matched plan actually
 * writes to `ac_geo_provider_destinations`, that re-running it updates rows
 * instead of duplicating them, and that the adapter reads back exactly the
 * spelling the courier published — which is the field a parcel is rejected
 * over.
 *
 * The courier is scripted rather than called: roadmap §56 has no merchant
 * account and no sandbox behind it, and this is the closest thing to an
 * end-to-end run that exists — a real database, a real 1,541-commune dataset,
 * and a recorded Yalidine.
 *
 *   scripts/test.sh
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/shipping-destinations.php
 *
 * No declare(strict_types=1): wp eval-file eval()s the body, where a strict
 * types declaration is not the first statement of a file and fatals.
 */

use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Core\Plugin;
use AlgerianCommerce\Http\HttpClientInterface;
use AlgerianCommerce\Http\HttpResponse;
use AlgerianCommerce\Integrations\Yalidine\YalidineClient;
use AlgerianCommerce\Integrations\Yalidine\YalidineCredentials;
use AlgerianCommerce\Integrations\Yalidine\YalidineProvider;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\DestinationSyncPlan;
use AlgerianCommerce\Shipping\DestinationSyncService;
use AlgerianCommerce\Shipping\GeoDestinationDirectory;
use AlgerianCommerce\Shipping\ProviderRegistry;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentStatus;

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_assert(string $label, $verdict): void
{
    $ok = $verdict === true;
    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;

    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 62);
    echo $ok ? '' : '     ' . (is_string($verdict) ? $verdict : 'failed');
    echo PHP_EOL;
}

/** A Yalidine that answers from a script instead of a network. */
final class AcScriptedHttpClient implements HttpClientInterface
{
    public array $requests = [];

    private array $script;

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

        foreach ($this->script as $needle => $payload) {
            if (str_contains($url, (string) $needle)) {
                return new HttpResponse(200, (string) wp_json_encode($payload));
            }
        }

        return new HttpResponse(404, '{"message":"not scripted"}');
    }
}

global $wpdb;

$geography = Plugin::instance()->geoRepository();

// Two real places out of the imported dataset, named the way Yalidine would —
// accented, where ours are folded.
$alger = $wpdb->get_row(
    "SELECT id, name FROM {$wpdb->prefix}ac_geo_wilayas WHERE id = 16",
    ARRAY_A
);
$commune = $wpdb->get_row(
    "SELECT id, name, slug FROM {$wpdb->prefix}ac_geo_communes WHERE wilaya_id = 16 ORDER BY id ASC LIMIT 1",
    ARRAY_A
);

if (!$alger || !$commune) {
    echo "the Algerian geography is not imported — run: wp algerian-commerce import-algeria\n";

    exit(1);
}

$communeName = $commune['name'];
$scripted = [
    'wilayas/' => [
        'has_more' => false,
        'data' => [
            ['id' => 16, 'name' => $alger['name'], 'zone' => 0, 'is_deliverable' => true],
            ['id' => 77, 'name' => 'Wilaya Fantôme', 'is_deliverable' => true],
        ],
    ],
    'communes/' => [
        'has_more' => false,
        'data' => [
            ['id' => 1601, 'name' => $communeName, 'wilaya_id' => 16, 'has_stop_desk' => true],
        ],
    ],
    'centers/' => [
        'has_more' => false,
        'data' => [[
            'center_id' => 88,
            'name' => 'Agence ' . $communeName,
            'address' => '3 rue des Frères',
            'commune_id' => 1601,
            'wilaya_id' => 16,
        ]],
    ],
];

$logger = new Logger('test', Logger::ERROR);
$settings = YalidineSettings::fromArray(['origin_wilaya_id' => 16]);
$http = new AcScriptedHttpClient($scripted);

$provider = new YalidineProvider(
    new YalidineClient($http, new YalidineCredentials('id', 'token'), $settings, $logger, 0),
    new GeoDestinationDirectory($geography),
    $settings,
    $logger
);

$service = new DestinationSyncService(
    new ProviderRegistry([$provider]),
    $geography,
    Plugin::instance()->auditLogger(),
    $logger
);

// ---------------------------------------------------------------- dry run

$dry = $service->sync('yalidine', true);

ac_assert('a dry run writes nothing', $dry['written']['inserted'] === 0 && $dry['written']['updated'] === 0);
ac_assert('a dry run still matches places', $dry['mapped'] === 2 ?: 'mapped ' . $dry['mapped']);

// ---------------------------------------------------------------- the sync

$first = $service->sync('yalidine');

ac_assert('the sync writes a row per matched place', $first['written']['inserted'] === 2
    ?: 'inserted ' . $first['written']['inserted']);

$rows = $geography->destinations('yalidine');
ac_assert('both rows are in ac_geo_provider_destinations', count($rows) === 2 ?: 'found ' . count($rows));

$directory = new GeoDestinationDirectory($geography);
$wilayaRow = $directory->find('yalidine', 16, 0);
$communeRow = $directory->find('yalidine', 16, (int) $commune['id']);

ac_assert('the wilaya is addressable by our id', $wilayaRow !== null && $wilayaRow->destinationId === '16');
ac_assert('the commune is addressable by our id', $communeRow !== null && $communeRow->destinationId === '1601');

// The whole point of the table: what the courier calls the place, so a parcel
// can be addressed in their spelling rather than ours.
ac_assert(
    'the courier\'s own spelling is stored',
    $communeRow !== null && $communeRow->name() === $communeName
        ?: 'stored "' . ($communeRow ? $communeRow->name() : '') . '"'
);

ac_assert(
    'the stop desk is recorded against its commune',
    $communeRow !== null && ($communeRow->centers()[0]['id'] ?? '') === '88'
);

// ------------------------------------------------------------------- gaps

/** @var DestinationSyncPlan $plan */
$plan = $first['plan'];

ac_assert(
    'a place we do not know is reported, not written',
    count($plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED)) === 1
);

// 69 wilayas and 1,541 communes against a courier publishing one of each: the
// gap report is the thing that makes that visible before a customer finds it.
$uncovered = $plan->gapsOfType(DestinationSyncPlan::UNCOVERED);
ac_assert('every place the courier does not serve is named', count($uncovered) > 60
    ?: 'only ' . count($uncovered) . ' uncovered');

// ------------------------------------------------------------- re-running

$second = $service->sync('yalidine');

ac_assert('re-running updates rather than duplicating', $second['written']['updated'] === 2
    ?: 'inserted ' . $second['written']['inserted'] . ', updated ' . $second['written']['updated']);
ac_assert('the table did not grow', count($geography->destinations('yalidine')) === 2);

// -------------------------------------------------- addressing a parcel

$request = new ShipmentRequest(
    1,
    new Destination(16, (int) $commune['id']),
    'Ahmed Ben Salah',
    '+213555112233',
    '12 rue Didouche Mourad',
    '4500.00',
    '',
    '1-1',
    'Chemise x2'
);

$http->requests = [];
$creating = new YalidineProvider(
    new YalidineClient(
        new AcScriptedHttpClient(['parcels/' => ['1-1' => ['success' => true, 'tracking' => 'yal-TEST-1']]]),
        new YalidineCredentials('id', 'token'),
        $settings,
        $logger,
        0
    ),
    new GeoDestinationDirectory($geography),
    $settings,
    $logger
);

$result = $creating->createShipment($request);

ac_assert('a parcel is created against the synced destination', $result->trackingNumber === 'yal-TEST-1');
ac_assert('and it comes back in our vocabulary', $result->status === ShipmentStatus::CREATED);

// ------------------------------------------------------------------ tidy

$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->prefix}ac_geo_provider_destinations WHERE provider = %s",
    'yalidine'
));

ac_assert('the test left no destinations behind', $geography->destinations('yalidine') === []);

echo PHP_EOL;
printf(
    "\033[1m%d passed, %d failed\033[0m%s",
    $GLOBALS['ac_pass'],
    $GLOBALS['ac_fail'],
    PHP_EOL
);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
