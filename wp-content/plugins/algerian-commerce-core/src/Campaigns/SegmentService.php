<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;

/**
 * Saved audience definitions — roadmap §85.
 *
 * Separate from `CampaignService` because a segment is a resource in its own right
 * and, more to the point, because the capability split falls differently: **drafting
 * a definition needs `ac_manage_marketing`; finding out how many people it matches
 * needs `ac_manage_customers` as well.** A count over the customer list is a read of
 * the customer list, however small the number that comes back — §63's rule that
 * reporting may not disclose in aggregate what the caller cannot read in detail,
 * applied to a count of one.
 */
final class SegmentService
{
    public function __construct(
        private readonly SegmentRepository $repository,
        private readonly AudienceResolver $audience,
        private readonly AuditLogger $audit
    ) {
    }

    /** @return array{items: list<Segment>, total: int} */
    public function list(int $page, int $perPage, string $orderby = 'name', string $order = 'asc'): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        return [
            'items' => $this->repository->paginate($page, $perPage, $orderby, $order),
            'total' => $this->repository->count(),
        ];
    }

    public function get(int $id): Segment
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        return $this->require($id);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): Segment
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $name = self::name($payload);
        $criteria = SegmentCriteria::fromPayload(self::criteriaFrom($payload));

        if ($criteria->isEmpty()) {
            /*
             * A segment with no criteria matches **everyone**, and "everyone eligible"
             * already has its own `audience_type` on a campaign. Refusing here is
             * what stops an empty definition becoming an accidental send to the whole
             * customer list — the one mistake in this module that cannot be undone.
             */
            throw ApiException::invalidRequest('A segment needs at least one criterion.', [
                'fields' => ['criteria' => 'Empty criteria would match every customer; use audience_type "all" for that.'],
                'supported' => array_keys(SegmentCriteria::FIELDS),
            ]);
        }

        $this->guardName($name, 0);

        $now = self::now();
        $segment = new Segment(
            $name,
            $criteria,
            self::text($payload['description'] ?? null),
            get_current_user_id(),
            $now,
            $now
        );

        $id = $this->repository->insert($segment);

        if ($id === null) {
            throw ApiException::internal('The segment could not be saved.');
        }

        $this->audit->record('segment.created', 'segment', $id, [
            'name' => $segment->name,
            // The criteria are configuration, not customer data — recording them is
            // how "why did this campaign reach these people" stays answerable after
            // the recipient rows are purged.
            'criteria' => $criteria->toArray(),
        ]);

        return $this->repository->find($id) ?? $segment;
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): Segment
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $existing = $this->require($id);

        $known = ['name', 'description', 'criteria'];

        foreach (array_keys($payload) as $field) {
            if (!in_array((string) $field, $known, true)) {
                throw ApiException::invalidRequest('The segment is invalid.', [
                    'fields' => [(string) $field => 'Unknown field. Criteria go inside `criteria`.'],
                ]);
            }
        }

        $name = array_key_exists('name', $payload) ? self::name($payload) : $existing->name;
        $criteria = array_key_exists('criteria', $payload)
            ? SegmentCriteria::fromPayload(self::criteriaFrom($payload))
            : $existing->criteria;

        if ($criteria->isEmpty()) {
            throw ApiException::invalidRequest('A segment needs at least one criterion.', [
                'fields' => ['criteria' => 'Empty criteria would match every customer.'],
            ]);
        }

        $this->guardName($name, $id);

        $updated = new Segment(
            $name,
            $criteria,
            array_key_exists('description', $payload) ? self::text($payload['description']) : $existing->description,
            $existing->createdBy,
            $existing->createdAt,
            self::now(),
            $id
        );

        if (!$this->repository->update($updated)) {
            throw ApiException::internal('The segment could not be updated.');
        }

        /*
         * **Editing a segment changes every future campaign that names it**, which is
         * the point of a stored query rather than a stored list — and it is worth an
         * audit row saying so, because the effect is invisible from the segment
         * itself.
         */
        $this->audit->record('segment.updated', 'segment', $id, [
            'before' => $existing->criteria->toArray(),
            'after' => $criteria->toArray(),
            'campaigns_using' => $this->repository->campaignsUsing($id),
        ]);

        return $this->repository->find($id) ?? $updated;
    }

    public function delete(int $id): void
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $segment = $this->require($id);
        $using = $this->repository->campaignsUsing($id);

        if ($using > 0) {
            /*
             * Refused rather than cascaded. A campaign whose `segment_id` points at
             * nothing cannot be resolved and would report "that audience matches
             * nobody" — which reads as "your customers have not consented" rather
             * than "the audience you chose was deleted".
             */
            throw ApiException::conflict('That segment is used by a campaign.', [
                'campaigns' => $using,
                'fix' => 'Point those campaigns at another audience first.',
            ]);
        }

        if (!$this->repository->delete($id)) {
            throw ApiException::internal('The segment could not be deleted.');
        }

        $this->audit->record('segment.deleted', 'segment', $id, ['name' => $segment->name]);
    }

    /**
     * How many customers a definition matches right now.
     *
     * **Both capabilities**, see the class docblock. A live count on purpose: a
     * segment is a stored query, so its size is a fact about today.
     *
     * @return array<string, mixed>
     */
    public function preview(int $id): array
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $segment = $this->require($id);

        // A throwaway campaign object, so the count goes through exactly the same
        // resolver — and therefore exactly the same consent filter — that a real send
        // would use. A second code path for counting is a second place consent could
        // be forgotten.
        $probe = new Campaign(
            'preview',
            'preview',
            '',
            '',
            Campaign::AUDIENCE_SEGMENT,
            [],
            $segment->id
        );

        return [
            'segment_id' => $id,
            'matches' => $this->audience->countFor($probe, $segment),
            'criteria' => $segment->criteria->toArray(),
            'problems' => $segment->problems,
            'note' => 'Only customers who have given marketing consent are counted.',
        ];
    }

    private function require(int $id): Segment
    {
        $segment = $this->repository->find($id);

        if ($segment === null) {
            throw ApiException::notFound('No segment with that id.');
        }

        return $segment;
    }

    /**
     * The unique index would refuse a duplicate anyway; this says which one
     * collides instead of handing back a database error.
     */
    private function guardName(string $name, int $ignoreId): void
    {
        $existing = $this->repository->findByName($name, $ignoreId);

        if ($existing !== null) {
            throw ApiException::conflict('A segment already uses that name.', ['segment_id' => $existing->id]);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function name(array $payload): string
    {
        $name = self::text($payload['name'] ?? null);

        if ($name === '') {
            throw ApiException::invalidRequest('The segment is invalid.', [
                'fields' => ['name' => 'Required — a segment is referred to by name in every conversation about it.'],
            ]);
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function criteriaFrom(array $payload): array
    {
        $criteria = $payload['criteria'] ?? [];

        if (!is_array($criteria)) {
            throw ApiException::invalidRequest('The segment is invalid.', [
                'fields' => ['criteria' => 'Must be an object of criteria.'],
            ]);
        }

        return $criteria;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
