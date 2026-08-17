<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use InvalidArgumentException;

/**
 * One row of `ac_campaigns`, validated and shaped for storage — roadmap §85.
 *
 * Pure — no WordPress, no database — in the manner of `Shipping\Shipment` and for
 * the same reasons: the rules that keep the table readable are testable directly,
 * and values are truncated to their column widths because MySQL in strict mode
 * rejects an over-length value outright. A subject one character too long must not
 * fail a write *after* a five-thousand-recipient audience has been resolved.
 *
 * Timestamps are `'Y-m-d H:i:s'` in UTC, matching every other table here, and are
 * presented as ISO-8601 on the wire.
 */
final class Campaign
{
    public const MAX_NAME = 191;
    public const MAX_SUBJECT = 255;

    /** How the audience is decided — §85's "three ways". */
    public const AUDIENCE_IDS = 'ids';
    public const AUDIENCE_SEGMENT = 'segment';
    public const AUDIENCE_ALL = 'all';

    /** @var list<string> */
    public const AUDIENCES = [self::AUDIENCE_IDS, self::AUDIENCE_SEGMENT, self::AUDIENCE_ALL];

    /**
     * A hard ceiling on an explicit id list.
     *
     * An explicit list is "a list the admin picked in the admin app"; a hundred
     * thousand ids pasted into one is a segment wearing the wrong hat, and the
     * JSON column would be the wrong place to keep it.
     */
    public const MAX_EXPLICIT_IDS = 1_000;

    public readonly string $name;
    public readonly string $subject;
    public readonly string $audienceType;
    public readonly string $status;

    /** @param list<int> $audienceIds */
    public function __construct(
        string $name,
        string $subject,
        public readonly string $bodyHtml = '',
        public readonly string $bodyText = '',
        string $audienceType = self::AUDIENCE_SEGMENT,
        public readonly array $audienceIds = [],
        public readonly int $segmentId = 0,
        public readonly int $templateId = 0,
        string $status = CampaignStatus::DRAFT,
        public readonly int $recipientsTotal = 0,
        public readonly int $recipientsSent = 0,
        public readonly int $recipientsFailed = 0,
        public readonly int $createdBy = 0,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly ?string $claimedAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $purgedAt = null,
        public readonly int $id = 0
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A campaign requires a name.');
        }

        if (!in_array($audienceType, self::AUDIENCES, true)) {
            throw new InvalidArgumentException("Unknown audience type \"{$audienceType}\".");
        }

        if (!CampaignStatus::isKnown($status)) {
            throw new InvalidArgumentException("Unknown campaign status \"{$status}\".");
        }

        $this->name = mb_substr(trim($name), 0, self::MAX_NAME);
        $this->subject = mb_substr(trim($subject), 0, self::MAX_SUBJECT);
        $this->audienceType = $audienceType;
        $this->status = $status;
    }

    /** @param array<string, mixed> $row as read from the table */
    public static function fromRow(array $row): self
    {
        $ids = json_decode((string) ($row['audience_ids'] ?? ''), true);

        return new self(
            (string) ($row['name'] ?? ''),
            (string) ($row['subject'] ?? ''),
            (string) ($row['body_html'] ?? ''),
            (string) ($row['body_text'] ?? ''),
            (string) ($row['audience_type'] ?? self::AUDIENCE_SEGMENT),
            is_array($ids) ? array_values(array_map('intval', $ids)) : [],
            (int) ($row['segment_id'] ?? 0),
            (int) ($row['template_id'] ?? 0),
            (string) ($row['status'] ?? CampaignStatus::DRAFT),
            (int) ($row['recipients_total'] ?? 0),
            (int) ($row['recipients_sent'] ?? 0),
            (int) ($row['recipients_failed'] ?? 0),
            (int) ($row['created_by'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            self::nullable($row['claimed_at'] ?? null),
            self::nullable($row['completed_at'] ?? null),
            self::nullable($row['purged_at'] ?? null),
            (int) ($row['id'] ?? 0)
        );
    }

    /**
     * The same campaign with a validated write applied.
     *
     * @param array<string, mixed> $fields already validated by `CampaignInput`
     */
    public function with(array $fields, string $now): self
    {
        return new self(
            (string) ($fields['name'] ?? $this->name),
            (string) ($fields['subject'] ?? $this->subject),
            (string) ($fields['body_html'] ?? $this->bodyHtml),
            (string) ($fields['body_text'] ?? $this->bodyText),
            (string) ($fields['audience_type'] ?? $this->audienceType),
            isset($fields['audience_ids']) ? array_values(array_map('intval', (array) $fields['audience_ids'])) : $this->audienceIds,
            (int) ($fields['segment_id'] ?? $this->segmentId),
            (int) ($fields['template_id'] ?? $this->templateId),
            $this->status,
            $this->recipientsTotal,
            $this->recipientsSent,
            $this->recipientsFailed,
            $this->createdBy,
            $this->createdAt,
            $now,
            $this->claimedAt,
            $this->completedAt,
            $this->purgedAt,
            $this->id
        );
    }

    /**
     * Row for `$wpdb->insert()`/`update()`, matching migration 011.
     *
     * @return array<string, string|int|null>
     */
    public function toRow(): array
    {
        return [
            'name' => $this->name,
            'subject' => $this->subject,
            'template_id' => $this->templateId,
            'body_html' => $this->bodyHtml,
            'body_text' => $this->bodyText,
            'audience_type' => $this->audienceType,
            'audience_ids' => $this->encodedIds(),
            'segment_id' => $this->segmentId,
            'status' => $this->status,
            'recipients_total' => $this->recipientsTotal,
            'recipients_sent' => $this->recipientsSent,
            'recipients_failed' => $this->recipientsFailed,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'claimed_at' => $this->claimedAt,
            'completed_at' => $this->completedAt,
            'purged_at' => $this->purgedAt,
        ];
    }

    /** Placeholders for $wpdb, in `toRow()` order. */
    /** @return list<string> */
    public function rowFormats(): array
    {
        return ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];
    }

    public function isEditable(): bool
    {
        return CampaignStatus::isEditable($this->status);
    }

    /**
     * `json_encode`, not `wp_json_encode`: this class must stay loadable without
     * WordPress so the rules above can be unit-tested — the same reason
     * `Shipping\Shipment` and `Audit\AuditEvent` encode their own JSON.
     */
    public function encodedIds(): string
    {
        if ($this->audienceIds === []) {
            return '';
        }

        $encoded = json_encode(array_values($this->audienceIds));

        return $encoded === false ? '' : $encoded;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            'template_id' => $this->templateId,
            'body_html' => $this->bodyHtml,
            'body_text' => $this->bodyText,
            'audience' => [
                'type' => $this->audienceType,
                'segment_id' => $this->segmentId,
                'customer_ids' => $this->audienceIds,
            ],
            'status' => $this->status,
            'is_editable' => $this->isEditable(),
            'allowed_transitions' => CampaignStatus::allowedFrom($this->status),
            'recipients' => [
                'total' => $this->recipientsTotal,
                'sent' => $this->recipientsSent,
                'failed' => $this->recipientsFailed,
                // Survives the purge, which is the whole reason these are
                // columns rather than a COUNT(*) over migration 012.
                'purged' => $this->purgedAt !== null,
            ],
            'created_by' => $this->createdBy,
            'created_at' => self::iso($this->createdAt),
            'updated_at' => self::iso($this->updatedAt),
            'claimed_at' => self::iso($this->claimedAt ?? ''),
            'completed_at' => self::iso($this->completedAt ?? ''),
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || str_starts_with($value, '0000-00-00') ? null : $value;
    }

    private static function iso(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        $timestamp = strtotime($stored . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
