<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;

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
        private readonly Logger $logger,
        /*
         * Optional so every existing construction keeps working — the §84
         * precedent `AuditLogger` itself follows. Only §90's retry writes an
         * audit row; `notify()` and `drain()` never did and never should.
         */
        private readonly ?AuditLogger $audit = null
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

    // ------------------------------------------------- §90: read and retry --
    //
    // **Why these three assert a capability and the two above do not.**
    // `notify()` and `drain()` run with no user at all: a queue row is written
    // inside an order save fired by a webhook, by the poller, by wp-admin, and
    // the drain is a WP-CLI command. Asserting there would refuse every one of
    // them — CLAUDE.md records the same shape for `OrderStockSubscriber`. What
    // §90 adds is a *door*, and a door needs a lock.
    //
    // The lock is `ac_manage_customers` and **no new capability is invented**.
    // A row holds a customer's address and, on the single read, the frozen body
    // of an order confirmation — their name and what they bought. §63's rule
    // applies: reporting may not disclose in aggregate what the caller cannot
    // already read in detail, and the capability that already reads a
    // customer's record is the honest gate. §61's media gap set the precedent
    // for naming a gap rather than minting a capability to close one.

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        return $this->repository->search($criteria);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        return $this->require($id);
    }

    /**
     * Put a row back in the queue for the next drain.
     *
     * **It never sends.** That was §59d's whole design and it is stronger here
     * than anywhere else it applies: an SMTP server that hangs would hang the
     * admin panel, and the operator most likely to press this button is the one
     * whose mail server is already misbehaving. The row goes back to `pending`
     * and `wp algerian-commerce send-notifications` does the rest — the
     * response names the command rather than leaving somebody to wonder whether
     * anything is going to happen.
     *
     * **An already-sent row is refused**, and the refusal is the `UPDATE`'s own
     * `WHERE` rather than a check above it. A sent row is a record of something
     * that left the building; re-queueing it would deliver a body frozen weeks
     * ago to whoever it was addressed to. Doing it as one conditional statement
     * closes the window in which a drain sends the row between a read and a
     * write — §85's campaign claim, at one row's scale.
     *
     * @return array{row: array<string, mixed>, already_pending: bool}
     */
    public function retry(int $id): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $before = $this->require($id);
        $wasPending = (string) $before['status'] === NotificationRepository::STATUS_PENDING;

        if (!$this->repository->requeue($id)) {
            /*
             * Either it was already sent, or a drain sent it a moment ago. Both
             * answer the same way and the timestamp says which — this is the
             * one refusal in §90, so it names what an operator needs to decide
             * what to do next.
             */
            $now = $this->require($id);

            throw ApiException::conflict(
                'That notification has already been sent. Re-sending would deliver a message frozen when it was queued.',
                [
                    'status' => (string) $now['status'],
                    'sent_at' => NotificationPresenter::row($now)['sent_at'],
                ]
            );
        }

        /*
         * The row, never the recipient. §71's rule: an audit table nobody
         * cleans is not where a customer's email address belongs, and `channel`
         * plus `dedupe_key` identify the message precisely without it. The
         * status it came from is recorded by value, because "was this a retry
         * of a failure or of something already queued" is the question the row
         * is read to answer.
         */
        $this->audit?->record('notification.retried', 'notification', $id, [
            'channel' => (string) $before['channel'],
            'event' => (string) $before['event'],
            'dedupe_key' => (string) $before['dedupe_key'],
            'status_from' => (string) $before['status'],
            'attempts_before' => (int) $before['attempts'],
        ]);

        return ['row' => $this->require($id), 'already_pending' => $wasPending];
    }

    /** @return array<string, mixed> */
    private function require(int $id): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            throw ApiException::notFound('No notification with that id.');
        }

        return $row;
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
