<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use Throwable;

/**
 * Asks every courier where its live parcels are — roadmap §56.
 *
 * Polling first and webhooks later is deliberate, not an ordering accident. A
 * webhook is an unauthenticated request from the internet that moves data in
 * this shop: it needs §55's security review, signature verification and replay
 * protection before it is wired to anything, and Yalidine's "signature" is a
 * shared secret in the request body. Polling needs none of that, works from
 * behind any firewall, and is what the webhook will be checked *against* —
 * roadmap §56 requires the webhook payload to be treated as a hint and the
 * parcel re-fetched anyway, which is exactly this code path.
 *
 * Provider-agnostic on purpose: it knows `ShippingProviderInterface`, so ZR
 * Express is polled by this same class the day its adapter is registered.
 *
 * **No `Permissions::assert()`, and no route.** This runs from WP-CLI and from
 * cron, where there is no user to check — the same position as the geography
 * importer. Nothing here accepts a status from a caller: every value written
 * comes from a courier that was asked directly. If this ever gains a route, it
 * needs a `permission_callback` and an assert.
 */
final class ShipmentPoller
{
    public function __construct(
        private readonly ShipmentRepository $repository,
        private readonly ProviderRegistry $providers,
        private readonly AuditLogger $audit,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param string $provider   only this courier, or all of them when empty
     * @param int    $limit      parcels per run — an unpublished rate limit is
     *                           a reason to move in batches
     * @param int    $minMinutes leave a parcel alone if it was checked more
     *                           recently than this
     *
     * @return array{checked: int, updated: int, unchanged: int, skipped: int, failed: int, problems: list<string>}
     */
    public function run(string $provider = '', int $limit = 50, int $minMinutes = 30): array
    {
        $staleBefore = $minMinutes > 0
            ? gmdate('Y-m-d H:i:s', time() - ($minMinutes * 60))
            : '';

        $shipments = $this->repository->live($provider, $limit, $staleBefore);

        $report = [
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'problems' => [],
        ];

        foreach ($shipments as $shipment) {
            $report['checked']++;

            try {
                $this->pollOne($shipment, $report);
            } catch (ApiException $exception) {
                /*
                 * A courier with nothing to report is not a failure. In-house
                 * delivery refuses the question by design — a person moves
                 * those shipments by hand — and counting that as an error every
                 * half hour would bury the failures that matter.
                 */
                if ($exception->errorCode() === 'sync_unsupported') {
                    $report['skipped']++;

                    continue;
                }

                $report['failed']++;
                $report['problems'][] = sprintf(
                    '#%d (%s %s): %s',
                    $shipment->id,
                    $shipment->provider,
                    $shipment->trackingNumber,
                    $exception->getMessage()
                );

                $this->logger->warning('Shipment poll failed', [
                    'shipment_id' => $shipment->id,
                    'provider' => $shipment->provider,
                    'error' => $exception->errorCode(),
                ]);

                // One provider being rate limited or down says nothing useful
                // about the next parcel in the queue, but hammering it for
                // another 49 does. Quota answers end the run.
                if (in_array($exception->errorCode(), ['provider_rate_limited', 'provider_auth_failed'], true)) {
                    break;
                }
            } catch (Throwable $throwable) {
                $report['failed']++;
                $report['problems'][] = sprintf('#%d: %s', $shipment->id, $throwable::class);

                $this->logger->error('Shipment poll threw', [
                    'shipment_id' => $shipment->id,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        $this->logger->info('Shipment poll finished', [
            'provider' => $provider,
            'checked' => $report['checked'],
            'updated' => $report['updated'],
            'failed' => $report['failed'],
        ]);

        return $report;
    }

    /** @param array<string, mixed> $report */
    private function pollOne(Shipment $shipment, array &$report): void
    {
        if (!$this->providers->has($shipment->provider)) {
            // The shop dropped a courier that still has parcels in the air.
            // Skipped rather than failed: nothing is broken, and there is
            // nobody to ask.
            $report['skipped']++;

            return;
        }

        $reported = $this->providers->get($shipment->provider)->getShipmentStatus($shipment->providerShipmentId);

        if (!ShipmentStatus::accepts($shipment->status, $reported->status)) {
            // Most polls find a parcel exactly where it was; that is not a
            // write, and it is not news.
            $report['unchanged']++;

            return;
        }

        if (!$this->repository->update($shipment->withReport($reported, gmdate('Y-m-d H:i:s')))) {
            $report['failed']++;
            $report['problems'][] = sprintf('#%d: the status could not be saved.', $shipment->id);

            return;
        }

        $report['updated']++;

        $this->audit->record('shipment.status_changed', 'order', $shipment->orderId, [
            'shipment_id' => $shipment->id,
            'provider' => $shipment->provider,
            'from' => $shipment->status,
            'to' => $reported->status,
            'provider_status' => $reported->providerStatus,
            // Distinguishable from a manual update and from a one-off sync, so
            // "who moved this parcel" has an answer.
            'source' => 'poll',
        ]);
    }
}
