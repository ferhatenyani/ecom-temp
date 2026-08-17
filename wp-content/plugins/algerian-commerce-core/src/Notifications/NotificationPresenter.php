<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * What a notification row looks like from outside — roadmap §90.
 *
 * **Two shapes, and the difference is the message.** A list row describes the
 * *delivery* — which channel, which event, what status, how many attempts, what
 * the last error was. The single read adds the frozen message itself. §90 draws
 * that line so a support agent scanning a queue does not pull five hundred
 * customers' order contents into one response; `NotificationRepository::search()`
 * enforces it one layer down by never selecting `payload` at all.
 *
 * `Tracking\TrackingPresenter` is the model for the second half: **it filters
 * what it is handed rather than what it is promised.** `payload` is a JSON
 * document written by `Notification::toArray()`, and a future channel could add
 * keys to it — so the message block is an allowlist read *out* of the payload
 * rather than the payload with a few keys removed. A key nobody has thought
 * about yet does not get published by default.
 */
final class NotificationPresenter
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function list(array $rows): array
    {
        return array_values(array_map([self::class, 'row'], $rows));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function row(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'channel' => (string) ($row['channel'] ?? ''),
            'event' => (string) ($row['event'] ?? ''),
            /*
             * Published because it is the handle §90 exists to give an
             * operator: it is `event:subject_id` by construction, so
             * `?dedupe_key=order.placed:1234` answers "did the customer get
             * their confirmation?" without a second lookup.
             */
            'dedupe_key' => (string) ($row['dedupe_key'] ?? ''),
            'audience' => (string) ($row['audience'] ?? ''),
            'recipient' => (string) ($row['recipient'] ?? ''),
            'subject_type' => (string) ($row['subject_type'] ?? ''),
            'subject_id' => isset($row['subject_id']) && $row['subject_id'] !== null
                ? (int) $row['subject_id']
                : null,
            'status' => (string) ($row['status'] ?? ''),
            'attempts' => (int) ($row['attempts'] ?? 0),
            /*
             * The one field here that carries a third party's words: it is
             * whatever the SMTP server said. Truncated at 500 bytes on the way
             * in by `markFailed()`, so there is nothing to cap again — but it
             * is worth knowing that this is a provider string and not ours.
             */
            'last_error' => ($row['last_error'] ?? null) === null ? null : (string) $row['last_error'],
            'created_at' => self::time($row['created_at'] ?? null),
            'sent_at' => self::time($row['sent_at'] ?? null),
        ];
    }

    /**
     * The single read: the row, plus the message that was frozen at queue time.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function full(array $row): array
    {
        return self::row($row) + ['message' => self::message($row['payload'] ?? null)];
    }

    /**
     * The frozen message, read out of the payload by allowlist.
     *
     * `subject`, `body` and `context` and nothing else — `event`, `audience`,
     * `recipient`, `subject_type` and `subject_id` are in the payload too and
     * are already columns, so republishing them from a second source is how the
     * two come to disagree. The column wins; it is what every query filters on.
     *
     * A payload that will not decode is reported as unreadable rather than as
     * an empty message: this is the field `drain()` marks permanently failed
     * over, and an operator looking at that row needs to see the same thing the
     * drain saw.
     *
     * @return array<string, mixed>
     */
    public static function message(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        if (!is_array($decoded)) {
            return ['readable' => false, 'subject' => '', 'body' => '', 'context' => []];
        }

        return [
            'readable' => true,
            'subject' => (string) ($decoded['subject'] ?? ''),
            'body' => (string) ($decoded['body'] ?? ''),
            'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
        ];
    }

    /**
     * `gmdate()` rather than `mysql_to_rfc3339()`, for `TrackingPresenter`'s
     * reason: it keeps this class pure, so §90's disclosure list is a unit test
     * rather than a claim. It is also the more exact answer — every writer here
     * stores `current_time('mysql', true)`, which is UTC, and the string is
     * read back as UTC instead of through the shop's timezone.
     */
    private static function time(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
