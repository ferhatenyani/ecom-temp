<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Notifications\NotificationService;
use WP_CLI;

/**
 * Drain the notification queue — docs/PLAN.md §29, roadmap step 34.
 *
 * **This is the drive, and the cron is the convenience.** CLAUDE.md records at
 * length that nothing runs WP-Cron on a headless backend nobody browses — §63
 * refused a rollup table over exactly that, and wc-admin's own importer has
 * 1,426 jobs pending on this install to prove it. A five-minute cron is
 * registered beside this command because it costs nothing when something *does*
 * drive it, but a deployment that wants its customers to receive mail schedules
 * this command in the system's own scheduler.
 *
 *   wp algerian-commerce send-notifications [--limit=<n>] [--channel=<name>] [--summary]
 */
final class SendNotificationsCommand
{
    public function __construct(private readonly NotificationService $service)
    {
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        unset($args);

        if (isset($assoc['summary'])) {
            $summary = $this->service->summary();

            WP_CLI::log(sprintf(
                'pending %d, sent %d, failed %d',
                $summary['pending'] ?? 0,
                $summary['sent'] ?? 0,
                $summary['failed'] ?? 0
            ));

            return;
        }

        if ($this->service->channels()->isEmpty()) {
            // Not a failure. A shop with no channel configured is the normal
            // state of a fresh install, and §29 says to activate only what is
            // configured.
            WP_CLI::warning('No notification channel is configured; nothing to send.');

            return;
        }

        $limit = max(1, (int) ($assoc['limit'] ?? 50));
        $channel = (string) ($assoc['channel'] ?? '');

        $result = $this->service->drain($limit, $channel);

        WP_CLI::log(sprintf(
            'attempted %d, sent %d, failed %d',
            $result['attempted'],
            $result['sent'],
            $result['failed']
        ));

        if ($result['failed'] > 0) {
            // A warning rather than an error: a partial drain is a normal
            // outcome when a mail server is briefly down, and the rows stay
            // pending for the next run. `--summary` shows what is stuck.
            WP_CLI::warning('Some notifications did not send; they remain queued until MAX_ATTEMPTS.');
        }
    }
}
