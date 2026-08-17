<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use InvalidArgumentException;

/**
 * One row of `ac_customer_segments` — roadmap §85.
 *
 * Pure, in the manner of `Shipping\ShippingRule`: the criteria document is
 * validated by `SegmentCriteria` and this class only owns the row around it.
 */
final class Segment
{
    public const MAX_NAME = 191;
    public const MAX_DESCRIPTION = 255;

    public readonly string $name;
    public readonly string $description;

    public function __construct(
        string $name,
        public readonly SegmentCriteria $criteria,
        string $description = '',
        public readonly int $createdBy = 0,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        public readonly int $id = 0,
        /**
         * What `SegmentCriteria::fromStored()` had to drop, carried so the API can
         * report it — §61's malformed-section precedent. Never persisted.
         *
         * @var list<string>
         */
        public readonly array $problems = []
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A segment requires a name.');
        }

        $this->name = mb_substr(trim($name), 0, self::MAX_NAME);
        $this->description = mb_substr(trim($description), 0, self::MAX_DESCRIPTION);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $decoded = json_decode((string) ($row['criteria'] ?? ''), true);
        $read = SegmentCriteria::fromStored(is_array($decoded) ? $decoded : []);

        /*
         * A `criteria` column that is not JSON at all is reported rather than read
         * as "no criteria", which would make the segment mean **everyone**. That is
         * the distinction §83's `OptionSet` insisted on and it matters far more
         * here: the failure mode of a silently-empty segment is a campaign sent to
         * the whole customer list.
         */
        $problems = $read['problems'];

        if (!is_array($decoded) && trim((string) ($row['criteria'] ?? '')) !== '') {
            $problems[] = 'The stored criteria document could not be read.';
        }

        return new self(
            (string) ($row['name'] ?? ''),
            $read['criteria'],
            (string) ($row['description'] ?? ''),
            (int) ($row['created_by'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            (int) ($row['id'] ?? 0),
            $problems
        );
    }

    /**
     * Whether this segment can be resolved into an audience at all.
     *
     * A segment with no usable criterion is refused rather than resolved: see
     * `fromRow()` — "everyone" is a legitimate audience and it has its own
     * `audience_type`, so a *segment* that resolves to everyone is always a
     * mistake.
     */
    public function isResolvable(): bool
    {
        return !$this->criteria->isEmpty();
    }

    /** @return array<string, string|int|null> */
    public function toRow(): array
    {
        $encoded = json_encode($this->criteria->toArray());

        return [
            'name' => $this->name,
            'description' => $this->description,
            'criteria' => $encoded === false ? '{}' : $encoded,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** @return list<string> */
    public function rowFormats(): array
    {
        return ['%s', '%s', '%s', '%d', '%s', '%s'];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'criteria' => $this->criteria->toArray(),
            'is_resolvable' => $this->isResolvable(),
            'created_by' => $this->createdBy,
            'created_at' => self::iso($this->createdAt),
            'updated_at' => self::iso($this->updatedAt),
        ];

        if ($this->problems !== []) {
            $payload['problems'] = $this->problems;
        }

        return $payload;
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
