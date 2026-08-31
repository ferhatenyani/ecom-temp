<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use InvalidArgumentException;
use stdClass;

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

    /**
     * How `body_fields` is encoded, both for storage and for the size check that
     * guards it — the two must agree or the bound is measured against a form the
     * column never sees.
     *
     * `JSON_UNESCAPED_UNICODE`, which the neighbouring documents (`encodedIds()`,
     * `Segment::toRow()`) do not use, and the divergence is deliberate. PHP's
     * default escapes every non-ASCII character to a six-byte `\uXXXX`, which is
     * invisible in a document of ids and `Y-m-d` dates and enormous in one made of
     * prose: **this is the only JSON column here that holds a shop's own
     * sentences.** With the default, a 64 KiB bound holds about 10,900 Arabic
     * characters and about 65,000 French ones — the same limit biting an Arabic
     * newsletter six times sooner than a French one, in a shop that publishes in
     * both, arrived at by an encoder's default rather than by anyone's decision.
     *
     * `JSON_UNESCAPED_SLASHES` is deliberately **not** set. It would be harmless
     * today — nothing embeds this document in a `<script>` block, which is the
     * only place an unescaped `</` matters — but "harmless given what renders it"
     * is exactly the kind of claim this field must not rely on, since the thing
     * that renders it is the panel and the panel is not ours.
     */
    public const FIELDS_JSON_FLAGS = JSON_UNESCAPED_UNICODE;

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
        public readonly int $id = 0,
        /**
         * The composer form's own answers — migration 014.
         *
         * `null` and `[]` are **different values** and the difference is the
         * whole point of the field. `null` says no answers were ever recorded, so
         * the panel opens the HTML editor over whatever `body_html` holds; `[]`
         * says the form was used and every answer is currently blank, so the
         * panel opens the form. Collapsing them would let a reopened campaign
         * regenerate empty HTML over a body somebody wrote by hand.
         *
         * Last in the list, after `$id`, in the manner of `Segment::$problems`
         * and for a duller reason: every caller of this constructor is positional
         * and stops well before the end, so growing the signature anywhere else
         * would silently shift `$status` into `$audienceType` at three call
         * sites. `create()` names it.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $bodyFields = null
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
            (int) ($row['id'] ?? 0),
            self::decodedFields($row['body_fields'] ?? null)
        );
    }

    /**
     * The stored answers, read leniently — and failing towards `null`.
     *
     * Lenient where `CampaignInput` is strict, which is the pair `SegmentCriteria`
     * (§85), `OptionSet` (§83) and `HomepageSections` (§61) all establish: a
     * document that stopped being readable must not make the campaign around it
     * unreadable, because a campaign that cannot be read cannot be *fixed*.
     *
     * **Which way it fails is the interesting half, and `Segment` failed it the
     * other way on purpose.** There, an unreadable `criteria` column must not
     * read as "no criteria", because a segment with no criteria means *everyone*
     * and the failure mode is a campaign sent to the whole customer list. Here
     * the two candidate answers are `[]` and `null`, and the dangerous one is
     * `[]`: it tells the panel this campaign was composed with the form and every
     * answer is blank, and a panel that believes it will regenerate empty HTML
     * over a `body_html` that is still perfectly good. `null` says only "there
     * are no answers here", which is true of an unreadable column, sends the
     * panel to the HTML editor, and destroys nothing. Same instinct as `Segment`,
     * opposite direction, because the safe answer is a property of the field
     * rather than of the rule.
     */
    private static function decodedFields(mixed $stored): ?array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
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
            $this->id,
            /*
             * `array_key_exists` rather than `??`, because `null` is a value this
             * field can be *set to* and not merely the absence of one: a PATCH
             * carrying `"body_fields": null` is how the panel says "this campaign
             * is no longer form-composed", which is what makes an undo back to the
             * template survive a reload. `??` would read that as "not supplied"
             * and leave the old answers in place, so the undo would come back on
             * the next load.
             */
            array_key_exists('body_fields', $fields)
                ? (is_array($fields['body_fields']) ? $fields['body_fields'] : null)
                : $this->bodyFields
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
            'body_fields' => $this->encodedFields(),
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
        return ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];
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

    /**
     * The answers, as the column stores them — or `null` for the column's own
     * `NULL`, which is what a campaign that predates migration 014 has.
     *
     * `new stdClass()` for an empty document rather than `[]`, because PHP cannot
     * tell `{}` from `[]` once `json_decode(..., true)` has run and would write
     * the string `"[]"` into a column whose every other value is an object. The
     * panel would then have to handle a JSON array where it expects an object, for
     * no reason other than a language's array type. `JSON_FORCE_OBJECT` is not
     * used because it would do the same to *nested* lists, and a repeater of
     * blocks is a list on purpose.
     *
     * `json_encode` cannot fail here — `CampaignInput::bodyFields()` already
     * refused a document that would not encode — but the fallback is `null`
     * rather than `'{}'`, keeping the one rule this field follows everywhere:
     * when in doubt, say there are no answers, never say the answers are blank.
     */
    public function encodedFields(): ?string
    {
        if ($this->bodyFields === null) {
            return null;
        }

        $encoded = json_encode(
            $this->bodyFields === [] ? new stdClass() : $this->bodyFields,
            self::FIELDS_JSON_FLAGS
        );

        return $encoded === false ? null : $encoded;
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
            /*
             * **The read shape gains whatever the write shape gains, in the same
             * change** — the rule this API follows everywhere, and the one that
             * makes this field worth having at all: a `body_fields` that could be
             * written and not read back would leave the composer exactly as
             * single-use as it is without it.
             *
             * It needs no `READ_ONLY` entry, which is worth saying because the
             * orders work in this build needed several. Those were keys the
             * presenter emits that the writer refuses, so echoing the read shape
             * back as a PATCH body 400s on them. This key is emitted under the
             * same name the writer accepts, carrying a value the writer accepts,
             * so it round-trips as it stands. (A campaign's read shape as a
             * *whole* still cannot be PATCHed back — `id`, `status` and the
             * `recipients_*` counts are in `REFUSED` on purpose, and `audience`,
             * `is_editable` and `allowed_transitions` are not write fields at all.
             * That was already true and this does not change it.)
             *
             * Cast to an object so an empty document reaches the panel as `{}`
             * and not as `[]` — see `encodedFields()`. The cast is shallow, which
             * is right: a nested list must stay a list.
             */
            'body_fields' => $this->bodyFields === null ? null : (object) $this->bodyFields,
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
