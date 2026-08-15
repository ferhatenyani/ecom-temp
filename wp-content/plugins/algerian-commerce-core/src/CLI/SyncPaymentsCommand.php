<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Payments\PaymentPoller;
use WP_CLI;

/**
 * wp algerian-commerce sync-payments
 *
 * Asks each gateway what became of the payments still waiting — roadmap §59.
 *
 * **This is the safety net under the webhook rule**, not a duplicate of it.
 * docs/SECURITY.md refuses a signed event whose timestamp is more than five
 * minutes old, and Chargily does not document its retry schedule — so a late
 * retry is turned away on purpose, and this is what stops that costing a
 * payment. It also covers the shop being down for the whole retry window, the
 * customer closing the tab before the redirect, and a checkout expiring quietly,
 * which no gateway need announce at all.
 *
 * The same work the hourly cron event does, runnable by hand. WP-Cron only fires
 * when someone visits the site, which is the wrong property for a shop that is
 * quiet at night with checkouts open; a deployment with a real scheduler points
 * it at this command every few minutes instead.
 */
final class SyncPaymentsCommand
{
    public function __construct(private readonly PaymentPoller $poller)
    {
    }

    /**
     * Poll pending payments for their outcome.
     *
     * ## OPTIONS
     *
     * [--provider=<name>]
     * : Only this gateway. Defaults to all of them.
     *
     * [--limit=<count>]
     * : How many payments to ask about. Default 50.
     *
     * [--min-age=<minutes>]
     * : Leave a payment alone if it was checked more recently than this.
     * Default 5 — a customer three minutes into typing a card number has not
     * finished. Use 0 to check every pending payment regardless.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce sync-payments
     *     wp algerian-commerce sync-payments --provider=chargily --min-age=0
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $report = $this->poller->run(
            (string) ($assocArgs['provider'] ?? ''),
            isset($assocArgs['limit']) ? (int) $assocArgs['limit'] : 50,
            isset($assocArgs['min-age']) ? max(0, (int) $assocArgs['min-age']) : 5
        );

        WP_CLI\Utils\format_items(
            'table',
            [[
                'checked' => $report['checked'],
                'settled' => $report['updated'],
                'unchanged' => $report['unchanged'],
                'abandoned' => $report['abandoned'],
                'failed' => $report['failed'],
            ]],
            ['checked', 'settled', 'unchanged', 'abandoned', 'failed']
        );

        foreach ($report['problems'] as $problem) {
            WP_CLI::log('  ' . $problem);
        }

        if ($report['failed'] > 0) {
            /*
             * A warning, and the exit code stays zero, for the reason
             * sync-shipments gives: this runs on a schedule and the ordinary
             * failure — one checkout a gateway has forgotten — must not page
             * somebody nightly. A run that could not talk to the gateway at all
             * stops early, and that is in the problems list above.
             */
            WP_CLI::warning(sprintf('%d payment(s) could not be checked.', $report['failed']));
        }

        if ($report['checked'] === 0) {
            WP_CLI::success('No payment was due a check.');

            return;
        }

        WP_CLI::success(sprintf('%d of %d payments changed.', $report['updated'], $report['checked']));
    }
}
