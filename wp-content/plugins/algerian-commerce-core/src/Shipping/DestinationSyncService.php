<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Geography\GeoRepository;

/**
 * Loads a courier's own destination list into `ac_geo_provider_destinations`
 * — roadmap §56, and the table §51 left deliberately empty.
 *
 * Everything that decides anything is in `DestinationMatcher`, which is pure;
 * this class is the part that talks to a provider and a database, and it is
 * kept thin for that reason.
 *
 * **Coverage is data, and gaps are reported rather than fixed.** The temptation
 * is to fall back to a fuzzy match when a commune name does not line up — the
 * reference implementation does exactly that at parcel-creation time, matching
 * on substrings — and it is how a parcel ends up addressed to a place nobody
 * chose. A commune that cannot be matched stays unmatched, is named in the
 * report, and fails loudly later at creation rather than quietly at delivery.
 *
 * No `Permissions::assert()` here, unlike the rest of the shipping services, and
 * that is not an omission: this has no route. It runs from WP-CLI, where the
 * operator already has shell access to the site, exactly like the geography
 * importer it feeds. **If a route is ever added, it needs both a real
 * `permission_callback` and an assert here.**
 */
final class DestinationSyncService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly GeoRepository $geography,
        private readonly AuditLogger $audit,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array<string, mixed> the plan's summary, plus what was written
     *
     * @throws ApiException when the provider cannot list its destinations
     */
    public function sync(string $providerName, bool $dryRun = false): array
    {
        $provider = $this->providers->get($providerName);

        if (!$provider instanceof DestinationCatalogueInterface) {
            throw ApiException::conflict(
                'This provider publishes no destination list.',
                ['provider' => $provider->name()]
            );
        }

        $wilayas = $this->geography->wilayas();
        $communes = $this->geography->communes();

        if ($wilayas === [] || $communes === []) {
            // Without the dataset every place the courier publishes would be
            // reported as unmatched, and the report would read as the courier
            // having no coverage rather than as this install having no
            // geography.
            throw ApiException::conflict(
                'Import the Algerian geography before syncing a provider: wp algerian-commerce import-algeria.',
                ['wilayas' => count($wilayas), 'communes' => count($communes)]
            );
        }

        $plan = DestinationMatcher::plan($provider->name(), $provider->destinations(), $wilayas, $communes);

        $written = ['inserted' => 0, 'updated' => 0];

        if (!$dryRun) {
            $written = $this->geography->upsertDestinations($plan->rows);

            // The resource is the courier, named — there is no row id to point
            // at, and "which provider's map changed" is the question anyone
            // reading this event back is asking.
            $this->audit->record('shipping.destinations_synced', 'shipping_provider', $provider->name(), [
                'written' => $written,
            ] + $plan->summary());
        }

        $this->logger->info('Provider destinations synced', [
            'provider' => $provider->name(),
            'dry_run' => $dryRun,
            'written' => $written,
        ] + $plan->summary());

        return [
            'provider' => $provider->name(),
            'dry_run' => $dryRun,
            'written' => $written,
            'plan' => $plan,
        ] + $plan->summary();
    }
}
