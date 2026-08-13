<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Shipping\ShipmentPoller;
use WP_CLI;

/**
 * wp algerian-commerce sync-shipments
 *
 * Asks each courier where its live parcels are — roadmap §56.
 *
 * The same work the hourly cron event does, runnable by hand: WP-Cron only
 * fires when someone visits the site, which is exactly the wrong property for a
 * shop that is quiet overnight and has parcels moving. A deployment with a real
 * scheduler points it at this command instead.
 */
final class SyncShipmentsCommand
{
    public function __construct(private readonly ShipmentPoller $poller)
    {
    }

    /**
     * Poll live shipments for status changes.
     *
     * ## OPTIONS
     *
     * [--provider=<name>]
     * : Only this courier. Defaults to all of them.
     *
     * [--limit=<count>]
     * : How many parcels to ask about. Default 50 — couriers rate limit, and
     * Yalidine does not publish its limit.
     *
     * [--min-age=<minutes>]
     * : Leave a parcel alone if it was checked more recently than this.
     * Default 30. Use 0 to check every live parcel regardless.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce sync-shipments
     *     wp algerian-commerce sync-shipments --provider=yalidine --limit=200
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $report = $this->poller->run(
            (string) ($assocArgs['provider'] ?? ''),
            isset($assocArgs['limit']) ? (int) $assocArgs['limit'] : 50,
            isset($assocArgs['min-age']) ? max(0, (int) $assocArgs['min-age']) : 30
        );

        WP_CLI\Utils\format_items(
            'table',
            [[
                'checked' => $report['checked'],
                'moved' => $report['updated'],
                'unchanged' => $report['unchanged'],
                'skipped' => $report['skipped'],
                'failed' => $report['failed'],
            ]],
            ['checked', 'moved', 'unchanged', 'skipped', 'failed']
        );

        foreach ($report['problems'] as $problem) {
            WP_CLI::log('  ' . $problem);
        }

        if ($report['failed'] > 0) {
            /*
             * A warning rather than an error, and the exit code stays zero on
             * purpose: this runs on a schedule, some parcels always fail — a
             * tracking number a courier has forgotten, a parcel created while
             * their API was half up — and a non-zero exit would page somebody
             * nightly for the ordinary case. A run that could not talk to the
             * provider at all stops early; that is the failure worth seeing,
             * and it is in the problems list above.
             */
            WP_CLI::warning(sprintf('%d shipment(s) could not be checked.', $report['failed']));
        }

        if ($report['checked'] === 0) {
            WP_CLI::success('No parcel was due a check.');

            return;
        }

        WP_CLI::success(sprintf('%d of %d parcels moved.', $report['updated'], $report['checked']));
    }
}
