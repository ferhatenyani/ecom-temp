<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Campaigns\CampaignService;
use WP_CLI;

/**
 * Drain the campaign queue — roadmap §85.
 *
 * **This is the drive, and the cron is the convenience** — the same sentence
 * `SendNotificationsCommand` carries, and it applies harder here. CLAUDE.md records
 * at length that nothing runs WP-Cron on a headless backend nobody browses; §63
 * refused a rollup table over exactly that, with 1,426 wc-admin jobs pending on this
 * install to prove it. A newsletter that goes out when somebody happens to visit the
 * admin is not a newsletter.
 *
 * ```
 * wp algerian-commerce send-campaigns [--limit=<n>] [--campaign=<id>]
 *                                     [--rate=<per-minute>] [--purge-only]
 * ```
 *
 * ## The rate cap is the batch size, and `--rate` is for a provider that needs more
 *
 * `--limit` is how many recipients one invocation attempts, and the scheduler's
 * interval turns that into a rate: 50 a minute is 3,000 an hour, inside every SMTP
 * provider's tolerance. `--rate` adds a minimum gap between sends for a provider that
 * throttles harder, and it is **off by default** because a `usleep` inside a WP-Cron
 * request holds that request open for the whole batch.
 *
 * That is §85's "batch size **and** a rate cap": the per-recipient rows are what make
 * a resume correct, and the batch ceiling is what keeps a resume from being needed.
 *
 * A deployment line for this belongs in `docs/DEPLOYMENT.md`, along with the SPF,
 * DKIM and DMARC records §85 names as the part that actually fails first.
 */
final class SendCampaignsCommand
{
    public function __construct(private readonly CampaignService $service)
    {
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        unset($args);

        if (isset($assoc['purge-only'])) {
            $purged = $this->service->purge();

            WP_CLI::log(sprintf('purged %d recipient rows', $purged));

            return;
        }

        $limit = max(1, (int) ($assoc['limit'] ?? CampaignService::DEFAULT_BATCH));
        $campaign = max(0, (int) ($assoc['campaign'] ?? 0));
        $rate = max(0, (int) ($assoc['rate'] ?? 0));

        // A per-minute rate becomes a gap between sends. 0 means no gap at all,
        // which is the default for the reason in the class docblock.
        $pause = $rate > 0 ? (int) floor(60_000_000 / $rate) : 0;

        $result = $this->service->drain($limit, $campaign, $pause);

        WP_CLI::log(sprintf(
            'campaigns %d, attempted %d, sent %d, failed %d, completed %d, purged %d',
            $result['campaigns'],
            $result['attempted'],
            $result['sent'],
            $result['failed'],
            $result['completed'],
            $result['purged']
        ));

        if ($result['failed'] > 0) {
            /*
             * A warning rather than an error: a partial drain is the normal outcome
             * when a mail server is briefly down, and the rows stay pending for the
             * next run until MAX_ATTEMPTS. `GET /campaigns/{id}/recipients?status=failed`
             * is where an operator sees which addresses are stuck and why — which is
             * the question §85 says a shop must be able to answer instead of
             * re-sending to everybody.
             */
            WP_CLI::warning('Some recipients did not receive the campaign; see GET /campaigns/{id}/recipients?status=failed.');
        }
    }
}
