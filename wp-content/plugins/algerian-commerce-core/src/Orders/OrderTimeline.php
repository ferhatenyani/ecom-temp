<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

/**
 * Merges everything that ever happened to an order into one feed.
 *
 * Pure — no WordPress, no database — so the merge, the ordering and the
 * summaries are testable directly.
 *
 * Three sources, because the order's history is genuinely spread across three
 * stores and none of them should be duplicated into the others:
 *
 *   notes       WooCommerce comments — what a person wrote
 *   audit       ac_audit_logs        — who did what, append-only
 *   stock       ac_inventory_movements — what happened to the shelf
 *
 * They are merged on read rather than written to a fourth table. A timeline
 * table would be a copy that can disagree with its sources, and the sources are
 * the records people actually trust: the audit trail is append-only for
 * security reasons and the ledger has to balance.
 *
 * All three store UTC. Sorting happens on integer timestamps rather than on the
 * formatted strings, because 'Y-m-d H:i:s' and ISO-8601 do not compare against
 * each other and a feed silently out of order is worse than no feed.
 */
final class OrderTimeline
{
    public const NOTE = 'note';
    public const AUDIT = 'audit';
    public const STOCK = 'stock';

    /**
     * Ordering within the same second.
     *
     * A status change and the stock it moves land in the same second, so a
     * tie-break is needed or the feed reorders itself between requests. Lower
     * sorts first in a newest-first feed, which puts the consequence above the
     * cause — the same way a chat log reads.
     */
    private const RANK = [self::STOCK => 0, self::NOTE => 1, self::AUDIT => 2];

    /**
     * Audit actions the timeline drops.
     *
     * `order.note_added` exists so the append-only trail records that someone
     * wrote a note — WooCommerce notes are comments and an administrator can
     * delete them. The note itself is already in this feed from its own source,
     * so showing the audit row too would print every note twice.
     *
     * @var list<string>
     */
    private const REDUNDANT_ACTIONS = ['order.note_added'];

    /**
     * @param list<array<string, mixed>> $notes     id, content, customer_note, added_by, created_at
     * @param list<array<string, mixed>> $audit     AuditRepository rows
     * @param list<array<string, mixed>> $movements MovementRepository rows
     * @return list<array<string, mixed>>
     */
    public static function merge(array $notes, array $audit, array $movements, int $limit = 50): array
    {
        $entries = [];

        foreach ($notes as $note) {
            $entries[] = self::noteEntry($note);
        }

        foreach ($audit as $event) {
            if (in_array((string) ($event['action'] ?? ''), self::REDUNDANT_ACTIONS, true)) {
                continue;
            }

            $entries[] = self::auditEntry($event);
        }

        foreach ($movements as $movement) {
            $entries[] = self::stockEntry($movement);
        }

        // Newest first, then rank, then id — a total order, so the same three
        // sources always produce the same feed.
        usort($entries, static fn (array $a, array $b): int => ($b['_ts'] <=> $a['_ts'])
            ?: (self::RANK[$a['type']] <=> self::RANK[$b['type']])
            ?: ($b['_id'] <=> $a['_id']));

        if ($limit > 0) {
            $entries = array_slice($entries, 0, $limit);
        }

        // The sort keys are an implementation detail; the wire format carries
        // the formatted timestamp only.
        return array_values(array_map(static function (array $entry): array {
            unset($entry['_ts'], $entry['_id']);

            return $entry;
        }, $entries));
    }

    /** @param array<string, mixed> $note */
    private static function noteEntry(array $note): array
    {
        $isCustomerNote = (bool) ($note['customer_note'] ?? false);
        $timestamp = self::timestamp($note['created_at'] ?? '');

        return [
            'type' => self::NOTE,
            'at' => self::iso($timestamp),
            'actor' => (string) ($note['added_by'] ?? ''),
            'summary' => (string) ($note['content'] ?? ''),
            'data' => [
                'id' => (int) ($note['id'] ?? 0),
                // Whether WooCommerce emailed this to the buyer.
                'customer_note' => $isCustomerNote,
            ],
            '_ts' => $timestamp,
            '_id' => (int) ($note['id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $event */
    private static function auditEntry(array $event): array
    {
        $timestamp = self::timestamp($event['created_at'] ?? '');
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        return [
            'type' => self::AUDIT,
            'at' => self::iso($timestamp),
            'actor' => (string) ($event['actor_login'] ?? ''),
            'summary' => self::auditSummary((string) ($event['action'] ?? ''), $metadata),
            'data' => [
                'id' => (int) ($event['id'] ?? 0),
                'action' => (string) ($event['action'] ?? ''),
                'metadata' => $metadata,
            ],
            '_ts' => $timestamp,
            '_id' => (int) ($event['id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $metadata */
    private static function auditSummary(string $action, array $metadata): string
    {
        switch ($action) {
            case 'order.created':
                return 'Order created';

            case 'order.status_changed':
                return sprintf(
                    'Status changed from %s to %s',
                    (string) ($metadata['from'] ?? '?'),
                    (string) ($metadata['to'] ?? '?')
                );

            case 'order.cancelled':
                $reason = trim((string) ($metadata['reason'] ?? ''));

                return $reason === '' ? 'Order cancelled' : "Order cancelled — {$reason}";

            /*
             * COD writes its confirmation history here rather than into a
             * table of its own, so these two are what a shop reads to see who
             * called a customer and what was said. Keyed by action string —
             * this is a table of sentences, not a dependency on the module.
             */
            case 'cod.attempt_recorded':
                $reason = trim((string) ($metadata['reason'] ?? ''));

                return sprintf(
                    'COD confirmation attempt %d — %s%s',
                    (int) ($metadata['attempt'] ?? 0),
                    (string) ($metadata['outcome'] ?? '?'),
                    $reason === '' ? '' : " — {$reason}"
                );

            case 'cod.settings_changed':
                return ($metadata['enabled'] ?? false) ? 'COD enabled' : 'COD disabled';

            /*
             * Shipping records its events against the *order*, so a parcel's
             * progress lands in the feed a shop already reads to see what
             * happened to it — the tracking number included, because that is
             * the thing an operator is looking for when a customer calls.
             */
            case 'shipment.created':
                return sprintf(
                    'Shipment created with %s — %s',
                    (string) ($metadata['provider'] ?? '?'),
                    (string) ($metadata['tracking_number'] ?? '')
                );

            case 'shipment.status_changed':
                return sprintf(
                    'Shipment %s → %s (%s)',
                    (string) ($metadata['from'] ?? '?'),
                    (string) ($metadata['to'] ?? '?'),
                    (string) ($metadata['source'] ?? 'provider')
                );

            case 'shipment.cancelled':
                return 'Shipment cancelled at ' . (string) ($metadata['provider'] ?? 'the provider');

            /*
             * The parcel exists at the courier and could not be written down.
             * It reads as an alarm because that is what it is — this line is
             * the only place the tracking number survives.
             */
            case 'shipment.record_failed':
                return sprintf(
                    'Shipment created at %s but NOT SAVED — tracking %s',
                    (string) ($metadata['provider'] ?? '?'),
                    (string) ($metadata['tracking_number'] ?? '')
                );

            case 'order.updated':
                $fields = is_array($metadata['fields'] ?? null) ? $metadata['fields'] : [];

                return $fields === []
                    ? 'Order updated'
                    : 'Updated ' . implode(', ', array_map('strval', $fields));

            default:
                // An action nobody wrote a sentence for still appears, named.
                // Silently dropping it would hide exactly the unusual event a
                // timeline is being read to find.
                return $action;
        }
    }

    /** @param array<string, mixed> $movement */
    private static function stockEntry(array $movement): array
    {
        $delta = (int) ($movement['delta'] ?? 0);
        $timestamp = self::timestamp($movement['created_at'] ?? '');

        return [
            'type' => self::STOCK,
            'at' => self::iso($timestamp),
            'actor' => '',
            'summary' => sprintf(
                'Stock %s by %d on product %d (%d → %d)',
                $delta < 0 ? 'reduced' : 'restored',
                abs($delta),
                (int) ($movement['product_id'] ?? 0),
                (int) ($movement['quantity_before'] ?? 0),
                (int) ($movement['quantity_after'] ?? 0)
            ),
            'data' => [
                'id' => (int) ($movement['id'] ?? 0),
                'product_id' => (int) ($movement['product_id'] ?? 0),
                'delta' => $delta,
                'quantity_before' => (int) ($movement['quantity_before'] ?? 0),
                'quantity_after' => (int) ($movement['quantity_after'] ?? 0),
                'reason' => (string) ($movement['reason'] ?? ''),
            ],
            '_ts' => $timestamp,
            '_id' => (int) ($movement['id'] ?? 0),
        ];
    }

    /**
     * 'Y-m-d H:i:s' in UTC — how all three tables store a time — to a Unix
     * timestamp. An unparseable value becomes 0 and sorts to the bottom rather
     * than vanishing: a row with a broken date is still a thing that happened.
     */
    private static function timestamp(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        $parsed = strtotime($value . ' UTC');

        return $parsed === false ? 0 : $parsed;
    }

    private static function iso(int $timestamp): string
    {
        return gmdate('c', $timestamp);
    }
}
