<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\Core\Logger;

/**
 * Queue in, drain out — docs/PLAN.md §29, roadmap step 34.
 *
 * **Nothing is sent on a request path**, and that is the whole design. §62b
 * settled the argument for marketing conversions and it is stronger here: an
 * order confirmation is queued while an order is being saved, so an SMTP server
 * that takes thirty seconds would put thirty seconds on a checkout, and one
 * that is down would fail an order that had already taken money. `notify()`
 * writes a row and returns; `drain()` does the sending, from
 * `wp algerian-commerce send-notifications` or the cron beside it.
 *
 * **The rendered message is frozen at queue time.** `Notification` carries a
 * subject and a body, not an order id to look up later — see that class for
 * why a re-read would send a receipt describing a state the customer never saw.
 *
 * A shop with no channel configured queues nothing at all, which §29's "only
 * activate configured providers" asks for and which is the normal state of a
 * fresh install.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationChannelRegistry $channels,
        private readonly NotificationRepository $repository,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue a notification on every channel that can carry it.
     *
     * Returns how many rows were actually claimed — zero is a perfectly
     * ordinary answer, meaning either that no channel is configured or that
     * this exact notification is already queued.
     */
    public function notify(Notification $notification): int
    {
        if (!NotificationEvent::isKnown($notification->event)) {
            // A typo would otherwise be queued, deduplicated against nothing
            // and never recognised by anything downstream.
            $this->logger->warning('Refusing an unknown notification event', [
                'event' => $notification->event,
            ]);

            return 0;
        }

        $claimed = 0;

        foreach ($this->channels->supporting($notification) as $channel) {
            if ($this->repository->claim($channel->name(), $notification) > 0) {
                $claimed++;
            }
        }

        return $claimed;
    }

    /**
     * Send what is waiting.
     *
     * @return array{attempted: int, sent: int, failed: int}
     */
    public function drain(int $limit = 50, string $channel = ''): array
    {
        $attempted = 0;
        $sent = 0;
        $failed = 0;

        foreach ($this->repository->pending($limit, $channel) as $row) {
            $id = (int) $row['id'];
            $name = (string) $row['channel'];

            if (!$this->channels->has($name)) {
                // The row was queued when a channel was configured and the
                // configuration has since been removed. Left pending rather
                // than failed: re-adding the channel should drain the backlog,
                // not find it already marked dead.
                continue;
            }

            $payload = json_decode((string) $row['payload'], true);

            if (!is_array($payload)) {
                $this->repository->markFailed($id, 'The stored payload is not readable.', false);
                $failed++;

                continue;
            }

            $attempted++;
            $result = $this->channels->get($name)->send(Notification::fromArray($payload));

            if ($result->sent) {
                $this->repository->markSent($id);
                $sent++;

                continue;
            }

            $this->repository->markFailed($id, $result->error, $result->retryable);
            $failed++;
        }

        return ['attempted' => $attempted, 'sent' => $sent, 'failed' => $failed];
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return $this->repository->summary();
    }

    public function channels(): NotificationChannelRegistry
    {
        return $this->channels;
    }

    public function repository(): NotificationRepository
    {
        return $this->repository;
    }
}
