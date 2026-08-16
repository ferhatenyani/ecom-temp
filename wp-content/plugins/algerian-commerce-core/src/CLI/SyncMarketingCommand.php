<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Marketing\MarketingService;
use WP_CLI;

/**
 * wp algerian-commerce sync-marketing
 *
 * Drains the marketing event queue — roadmap §62b.
 *
 * **This is where every outbound advertising call happens**, and that is the
 * point rather than a convenience. §62b forbids calling Meta on the checkout
 * path: an ad network's outage must never fail or delay somebody's order. The
 * request that records a purchase writes a row and returns; this sends it.
 *
 * The same work the cron event does, runnable by hand. WP-Cron only fires when
 * somebody visits the site — the wrong property for a shop that is quiet
 * overnight — so a real deployment points a scheduler at this every few minutes.
 * Conversions reported hours late still attribute correctly; conversions never
 * reported do not.
 */
final class SyncMarketingCommand
{
    public function __construct(private readonly MarketingService $service)
    {
    }

    /**
     * Send queued marketing events.
     *
     * ## OPTIONS
     *
     * [--provider=<name>]
     * : Only this destination. Defaults to all of them.
     *
     * [--limit=<count>]
     * : How many events to send. Default 50.
     *
     * [--summary]
     * : Report what is in the queue and send nothing.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce sync-marketing
     *     wp algerian-commerce sync-marketing --provider=meta --limit=200
     *     wp algerian-commerce sync-marketing --summary
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        if (isset($assocArgs['summary'])) {
            $summary = $this->service->summary();

            if ($summary === []) {
                WP_CLI::success('The queue is empty.');

                return;
            }

            WP_CLI\Utils\format_items(
                'table',
                array_map(
                    static fn (string $status, int $total): array => ['status' => $status, 'events' => $total],
                    array_keys($summary),
                    array_values($summary)
                ),
                ['status', 'events']
            );

            return;
        }

        $report = $this->service->drain(
            isset($assocArgs['limit']) ? max(1, (int) $assocArgs['limit']) : 50,
            isset($assocArgs['provider']) ? (string) $assocArgs['provider'] : null
        );

        WP_CLI\Utils\format_items('table', [$report], array_keys($report));

        if ($report['considered'] === 0) {
            WP_CLI::success('Nothing was waiting.');

            return;
        }

        if ($report['rejected'] > 0) {
            /*
             * A warning rather than an error, and the exit code stays zero, for
             * the reason sync-payments gives: this runs on a schedule, and one
             * event a provider refuses must not page somebody nightly. A
             * rejected event is `failed` in the table and stays there for a
             * person to look at.
             */
            WP_CLI::warning(sprintf('%d event(s) were refused and will not be retried.', $report['rejected']));
        }

        WP_CLI::success(sprintf('%d of %d events sent.', $report['sent'], $report['considered']));
    }
}
