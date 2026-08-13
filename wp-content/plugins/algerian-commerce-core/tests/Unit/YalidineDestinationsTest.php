<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Integrations\Yalidine\YalidineClient;
use AlgerianCommerce\Integrations\Yalidine\YalidineCredentials;
use AlgerianCommerce\Integrations\Yalidine\YalidineDestinations;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Shipping\ProviderPlace;
use AlgerianCommerce\Tests\Support\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Reading Yalidine's three destination lists.
 *
 * Field names outside `centers/` are marked as assumptions in the adapter; what
 * is tested here is that a row missing what it needs is skipped rather than
 * guessed at, which is what keeps an assumption from turning into a silently
 * wrong map.
 */
final class YalidineDestinationsTest extends TestCase
{
    /** @param list<\AlgerianCommerce\Http\HttpResponse> $script */
    private function destinations(array $script): array
    {
        $http = new RecordedHttpClient($script);

        $client = new YalidineClient(
            $http,
            new YalidineCredentials('id', 'token'),
            YalidineSettings::fromArray([]),
            new Logger('test', Logger::ERROR),
            0
        );

        return [new YalidineDestinations($client), $http];
    }

    public function testWilayasAndCommunesAreReadIntoPlaces(): void
    {
        [$destinations, $http] = $this->destinations([
            RecordedHttpClient::json([
                'has_more' => false,
                'data' => [
                    ['id' => 16, 'name' => 'Alger', 'zone' => 0, 'is_deliverable' => true],
                    ['id' => 33, 'name' => 'Illizi', 'zone' => 4, 'is_deliverable' => false],
                ],
            ]),
        ]);

        $places = $destinations->wilayas();

        self::assertCount(2, $places);
        self::assertSame(ProviderPlace::WILAYA, $places[0]->kind);
        // Their id, kept as a string: it is theirs to define and ours to store.
        self::assertSame('16', $places[0]->id);
        self::assertSame('Alger', $places[0]->name);
        self::assertFalse($places[1]->isDeliverable);
        // Their row survives next to our mapping, without this class having to
        // know what "zone" means.
        self::assertSame(4, $places[1]->metadata['zone']);
        self::assertStringContainsString('page_size=1000', $http->lastRequest()['url']);
    }

    public function testACommuneCarriesTheWilayaItBelongsTo(): void
    {
        [$destinations] = $this->destinations([
            RecordedHttpClient::json([
                'has_more' => false,
                'data' => [
                    ['id' => 1601, 'name' => 'Bouzaréah', 'wilaya_id' => 16, 'has_stop_desk' => true],
                ],
            ]),
        ]);

        $places = $destinations->communes();

        self::assertSame('1601', $places[0]->id);
        self::assertSame('16', $places[0]->wilayaId);
        self::assertTrue($places[0]->metadata['has_stop_desk']);
    }

    /** The one shape taken verbatim from a DTO rather than assumed. */
    public function testACentreIsReadWithItsCommune(): void
    {
        [$destinations] = $this->destinations([
            RecordedHttpClient::json([
                'has_more' => false,
                'data' => [[
                    'center_id' => 88,
                    'name' => 'Agence Bouzaréah',
                    'address' => '3 rue des Frères',
                    'gps' => '36.79,3.00',
                    'commune_id' => 1601,
                    'commune_name' => 'Bouzaréah',
                    'wilaya_id' => 16,
                    'wilaya_name' => 'Alger',
                ]],
            ]),
        ]);

        $places = $destinations->centers();

        self::assertSame('88', $places[0]->id);
        self::assertSame('1601', $places[0]->communeId);
        self::assertSame('3 rue des Frères', $places[0]->metadata['address']);
    }

    /**
     * A row without an id or a name cannot be matched to anything, and a
     * *guessed* match is worse than a reported gap.
     */
    public function testARowMissingWhatItNeedsIsSkipped(): void
    {
        [$destinations] = $this->destinations([
            RecordedHttpClient::json([
                'has_more' => false,
                'data' => [
                    ['id' => 16],
                    ['name' => 'Alger'],
                    ['id' => 6, 'name' => 'Béjaïa'],
                ],
            ]),
        ]);

        $places = $destinations->wilayas();

        self::assertCount(1, $places);
        self::assertSame('Béjaïa', $places[0]->name);
    }

    public function testPagingFollowsHasMore(): void
    {
        [$destinations, $http] = $this->destinations([
            RecordedHttpClient::json(['has_more' => true, 'data' => [['id' => 1, 'name' => 'Adrar']]]),
            RecordedHttpClient::json(['has_more' => false, 'data' => [['id' => 2, 'name' => 'Chlef']]]),
        ]);

        self::assertCount(2, $destinations->wilayas());
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('page=2', $http->requests[1]['url']);
    }

    /**
     * `is_deliverable` may arrive as 0 or "0" from a provider storing it in
     * MySQL, and "0" is truthy in PHP — the one place this would silently
     * publish coverage the account does not have.
     */
    public function testAZeroDeliverableFlagIsNotReadAsTrue(): void
    {
        [$destinations] = $this->destinations([
            RecordedHttpClient::json([
                'has_more' => false,
                'data' => [
                    ['id' => 1, 'name' => 'Adrar', 'is_deliverable' => 0],
                    ['id' => 2, 'name' => 'Chlef', 'is_deliverable' => '0'],
                    ['id' => 3, 'name' => 'Laghouat', 'is_deliverable' => 1],
                    ['id' => 4, 'name' => 'Batna'],
                ],
            ]),
        ]);

        $places = $destinations->wilayas();

        self::assertFalse($places[0]->isDeliverable);
        self::assertFalse($places[1]->isDeliverable);
        self::assertTrue($places[2]->isDeliverable);
        // Absent means deliverable: defaulting to "no" would empty the map the
        // first time the field is renamed, and an empty map fails every parcel.
        self::assertTrue($places[3]->isDeliverable);
    }
}
