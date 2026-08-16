<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * One thing worth telling somebody — docs/PLAN.md §29.
 *
 * Pure, immutable, and the only shape that crosses the channel boundary. A
 * channel receives *this*, never a `WC_Order`, for the same reason a shipping
 * or payment adapter never does (roadmap §53, §58): the day an SMS provider is
 * added, it must not be able to reach into the order model and grow a
 * dependency the business does not have.
 *
 * **The body is rendered before the row is queued, not at send time.** That is
 * what freezes the message: an order refunded between queueing and sending must
 * still deliver the confirmation that was true when it was placed, or a
 * customer receives a receipt describing a state they never saw. It is the same
 * argument migration 009 makes about a marketing payload.
 */
final class Notification
{
    public const AUDIENCE_CUSTOMER = 'customer';
    public const AUDIENCE_ADMIN = 'admin';

    /** @param array<string, mixed> $context */
    private function __construct(
        public readonly string $event,
        public readonly string $audience,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly array $context
    ) {
    }

    /** @param array<string, mixed> $context */
    public static function toCustomer(
        string $event,
        string $recipient,
        string $subject,
        string $body,
        string $subjectType = '',
        ?int $subjectId = null,
        array $context = []
    ): self {
        return new self(
            $event,
            self::AUDIENCE_CUSTOMER,
            $recipient,
            $subject,
            $body,
            $subjectType,
            $subjectId,
            $context
        );
    }

    /** @param array<string, mixed> $context */
    public static function toAdmin(
        string $event,
        string $recipient,
        string $subject,
        string $body,
        string $subjectType = '',
        ?int $subjectId = null,
        array $context = []
    ): self {
        return new self($event, self::AUDIENCE_ADMIN, $recipient, $subject, $body, $subjectType, $subjectId, $context);
    }

    /**
     * What makes this notification the same as another one.
     *
     * The event and its subject, never a timestamp: the point is that a
     * double-saved order, a retried CLI drain and a second status transition
     * back to the same status all produce **one** message. `stock.low` on the
     * same product is deliberately one row forever until it is cleared — a shop
     * with a persistently low line does not want an email an hour about it.
     */
    public function dedupeKey(): string
    {
        $subject = $this->subjectId !== null ? (string) $this->subjectId : $this->recipient;

        return substr($this->event . ':' . $subject, 0, 191);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'audience' => $this->audience,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'body' => $this->body,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'context' => $this->context,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['event'] ?? ''),
            (string) ($row['audience'] ?? self::AUDIENCE_CUSTOMER),
            (string) ($row['recipient'] ?? ''),
            (string) ($row['subject'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['subject_type'] ?? ''),
            isset($row['subject_id']) && $row['subject_id'] !== null ? (int) $row['subject_id'] : null,
            is_array($row['context'] ?? null) ? $row['context'] : []
        );
    }
}
