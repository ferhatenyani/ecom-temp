<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Shipping\DestinationSyncPlan;
use AlgerianCommerce\Shipping\DestinationSyncService;
use AlgerianCommerce\Shipping\ProviderPlace;
use WP_CLI;

/**
 * wp algerian-commerce sync-destinations
 *
 * Loads a courier's own wilaya, commune and stop-desk lists into
 * `ac_geo_provider_destinations` — roadmap §56.
 *
 * A command rather than anything automatic. It calls a third party several
 * times against a rate limit nobody publishes, it is the step that has to
 * happen *before* the first parcel, and its report is the thing an operator
 * needs to read: coverage is data, and the gaps are what this prints.
 */
final class SyncDestinationsCommand
{
    public function __construct(private readonly DestinationSyncService $service)
    {
    }

    /**
     * Sync one courier's destination list.
     *
     * ## OPTIONS
     *
     * [--provider=<name>]
     * : Which courier. Defaults to the store's default provider.
     *
     * [--dry-run]
     * : Fetch and match, report, write nothing.
     *
     * [--gaps=<count>]
     * : How many places to name per gap type. Default 20, 0 for all of them.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce sync-destinations --provider=yalidine --dry-run
     *     wp algerian-commerce sync-destinations --provider=yalidine
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $provider = (string) ($assocArgs['provider'] ?? '');
        $dryRun = !empty($assocArgs['dry-run']);
        $limit = isset($assocArgs['gaps']) ? max(0, (int) $assocArgs['gaps']) : 20;

        try {
            $result = $this->service->sync($provider, $dryRun);
        } catch (ApiException $exception) {
            // The provider's own refusals are already worded for a human —
            // missing credentials, no geography imported, a courier that
            // publishes no list.
            WP_CLI::error($exception->getMessage());

            return;
        }

        /** @var DestinationSyncPlan $plan */
        $plan = $result['plan'];
        $fetched = $result['fetched'];

        WP_CLI\Utils\format_items(
            'table',
            [[
                'provider' => $result['provider'],
                'wilayas' => $fetched[ProviderPlace::WILAYA] ?? 0,
                'communes' => $fetched[ProviderPlace::COMMUNE] ?? 0,
                'stop desks' => $fetched[ProviderPlace::CENTER] ?? 0,
                'mapped' => $result['mapped'],
                'inserted' => $result['written']['inserted'],
                'updated' => $result['written']['updated'],
            ]],
            ['provider', 'wilayas', 'communes', 'stop desks', 'mapped', 'inserted', 'updated']
        );

        $this->reportGaps($plan, $limit);

        if ($dryRun) {
            WP_CLI::success('Destinations matched. Nothing was written.');

            return;
        }

        if ($result['mapped'] === 0) {
            // Looks like success and is not: every subsequent parcel would be
            // refused for an unmapped destination.
            WP_CLI::error('Nothing could be matched to this store\'s geography — no destination was written.');

            return;
        }

        WP_CLI::success(sprintf('%d destinations recorded for %s.', $result['mapped'], $result['provider']));
    }

    private function reportGaps(DestinationSyncPlan $plan, int $limit): void
    {
        $headings = [
            DestinationSyncPlan::MATCHED_BY_CODE => 'Matched on the official code — the two names disagree',
            DestinationSyncPlan::PROVIDER_UNMATCHED => 'Published by the courier, not in this store\'s geography',
            DestinationSyncPlan::UNCOVERED => 'In this store\'s geography, not published by the courier',
            DestinationSyncPlan::NOT_DELIVERABLE => 'Published, and this account cannot deliver there',
        ];

        foreach ($headings as $type => $heading) {
            $gaps = $plan->gapsOfType($type);

            if ($gaps === []) {
                continue;
            }

            WP_CLI::log('');
            WP_CLI::log(sprintf('%s (%d):', $heading, count($gaps)));

            $shown = $limit === 0 ? $gaps : array_slice($gaps, 0, $limit);

            foreach ($shown as $gap) {
                $line = sprintf('  %-8s %s', (string) ($gap['kind'] ?? ''), (string) ($gap['provider_name'] ?? $gap['name'] ?? ''));

                if ($type === DestinationSyncPlan::MATCHED_BY_CODE) {
                    // Both names, because the pair is the whole point: someone
                    // has to decide whether they are the same place.
                    $line .= sprintf(' → %s (code %s)', (string) ($gap['name'] ?? ''), (string) ($gap['code'] ?? ''));
                } elseif (($gap['nearest'] ?? '') !== '') {
                    $line .= sprintf(' — nearest of ours: %s', (string) $gap['nearest']);
                } elseif (isset($gap['reason'])) {
                    $line .= ' (' . $gap['reason'] . ')';
                }

                WP_CLI::log($line);
            }

            $hidden = count($gaps) - count($shown);

            if ($hidden > 0) {
                WP_CLI::log("  … and {$hidden} more (--gaps=0 for all).");
            }
        }

        if ($plan->gapsOfType(DestinationSyncPlan::PROVIDER_UNMATCHED) !== []
            || $plan->gapsOfType(DestinationSyncPlan::UNCOVERED) !== []
            || $plan->gapsOfType(DestinationSyncPlan::NOT_DELIVERABLE) !== []) {
            /*
             * A warning, not an error. A courier not covering every commune in
             * Algeria is ordinary — that is what the "cannot reach" list is —
             * and failing the command would stop a deploy over a fact about the
             * provider rather than a fault in this store.
             */
            WP_CLI::warning('Parcels to the places listed above will be refused before they reach the courier.');
        }
    }
}
