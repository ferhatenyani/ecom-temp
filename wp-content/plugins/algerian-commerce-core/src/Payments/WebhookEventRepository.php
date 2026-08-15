<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use wpdb;

/**
 * The idempotency claim — docs/SECURITY.md → "Webhooks", roadmap §60.
 *
 * One method matters, and its name is the rule: **claim, not check.** A
 * read-then-write test — "have we seen this event? no, then process it" — races
 * exactly when a provider retries in parallel, which is the one case it exists
 * for. So the insert *is* the test: it either succeeds, and this delivery is the
 * one that gets to act, or it fails on migration 008's unique key, and the event
 * has already been handled by whoever won.
 *
 * Nothing here reads the table to decide anything. A `has()` method would be the
 * defect wearing the fix's clothes, so there is not one.
 *
 * Lives in `Payments/` because payments are what needs it today, and is keyed by
 * provider so the courier webhooks unblocked by the same §55 review can claim
 * into the same table without a second one.
 */
final class WebhookEventRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_webhook_events';
    }

    /**
     * Take the exclusive right to process one event.
     *
     * @return bool true when this delivery is the first — false when the unique
     *              key refused it, which is the idempotency answer and not an
     *              error to report
     */
    public function claim(string $provider, string $eventId, string $eventType = ''): bool
    {
        $provider = mb_substr(trim($provider), 0, 32);
        $eventId = mb_substr(trim($eventId), 0, 191);

        if ($provider === '' || $eventId === '') {
            return false;
        }

        /*
         * `$wpdb->insert()` logs a duplicate-key failure as an error and, with
         * `show_errors` on, prints it. That is noise for the one outcome this
         * method exists to produce, so errors are suppressed across the call and
         * the return value is read instead.
         */
        $suppressed = $this->wpdb->suppress_errors(true);
        $hidden = $this->wpdb->hide_errors();

        $inserted = $this->wpdb->insert(
            $this->table(),
            [
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => mb_substr(trim($eventType), 0, 64),
                'received_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s']
        );

        $this->wpdb->suppress_errors($suppressed);

        if ($hidden) {
            $this->wpdb->show_errors();
        }

        return $inserted !== false;
    }
}
