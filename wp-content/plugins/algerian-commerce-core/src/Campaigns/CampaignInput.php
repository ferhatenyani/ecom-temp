<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\API\ApiException;

/**
 * What a client may say about a campaign — roadmap §85.
 *
 * Pure, and the `CustomerInput` device throughout: **every refusal is by name with
 * the reason**, because a schema that silently drops an unknown field turns a typo
 * into something that vanished rather than an error.
 *
 * ## The refusals are the interesting half
 *
 * `status` is refused because a campaign's status is reached by *doing* something —
 * `POST /campaigns/{id}/send`, `DELETE` — and a payload that could set it to
 * `sent` would mark a campaign delivered without mailing anybody, which is the one
 * lie about a campaign nothing downstream could detect.
 *
 * `recipients_total`, `recipients_sent` and `recipients_failed` are refused for the
 * same reason and one more: they are the record that survives §85's purge, so a
 * caller that could write them could rewrite the only remaining evidence of what a
 * campaign actually did.
 *
 * `recipients`, `emails` and `to` are refused because **an audience is a
 * definition, never a list of addresses**. A campaign that could name arbitrary
 * addresses would bypass the consent filter entirely — which is the one thing §85
 * says must live in the resolver rather than in the caller — and would turn this
 * API into an open mail relay for anyone holding `ac_manage_marketing`.
 *
 * `tracking_pixel` and `open_tracking` are refused because §85 rules them out of
 * v1: a tracking pixel is a per-recipient identifier in a URL, which is a consent
 * question and a PII question at once, and when it is built it is built *with* the
 * consent machinery rather than around it.
 */
final class CampaignInput
{
    /** @var array<string, string> */
    public const REFUSED = [
        'status' => 'A campaign\'s status is reached by sending or cancelling it, never set directly — a payload that could write "sent" would mark a campaign delivered without mailing anybody.',
        'recipients_total' => 'The counts are written by the send and are the record that survives the purge.',
        'recipients_sent' => 'The counts are written by the drain, not by the caller.',
        'recipients_failed' => 'The counts are written by the drain, not by the caller.',
        'recipients' => 'An audience is a definition — explicit customer ids, a saved segment, or everyone eligible. It is never a list of addresses.',
        'emails' => 'An audience is never a list of addresses: that would bypass the consent filter, which lives in the resolver on purpose.',
        'to' => 'An audience is never a list of addresses. Use audience_type with customer_ids or segment_id.',
        'bcc' => 'A campaign sends one message per recipient so each carries its own unsubscribe link. There is no bulk header.',
        'from' => 'The From address is deployment configuration (AC_MAIL_FROM), not a per-campaign field — SPF and DKIM are bound to it.',
        'tracking_pixel' => 'Open tracking is deliberately out of scope in v1: a per-recipient identifier in a URL is a consent question and a PII question at once.',
        'open_tracking' => 'Open tracking is deliberately out of scope in v1 — see the campaign section of the roadmap.',
        'claimed_at' => 'Written by POST /campaigns/{id}/send, which is what makes a second send a no-op rather than a race.',
        'completed_at' => 'Written by the drain once every recipient row has reached a terminal state.',
        'created_by' => 'Taken from the caller\'s own identity, never from the payload.',
        'id' => 'The campaign id is assigned by the shop.',
    ];

    /** @var list<string> */
    private const KNOWN = [
        'name', 'subject', 'template_id', 'body_html', 'body_text',
        'audience_type', 'customer_ids', 'segment_id',
    ];

    public const MAX_BODY = 200_000;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        $input = self::validate($payload, true);

        return $input;
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        return self::validate($payload, false);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException 400 listing every bad field at once
     */
    private static function validate(array $payload, bool $creating): self
    {
        $errors = [];

        foreach (self::REFUSED as $field => $why) {
            if (array_key_exists($field, $payload)) {
                $errors[$field] = $why;
            }
        }

        foreach (array_keys($payload) as $field) {
            $field = (string) $field;

            if (!in_array($field, self::KNOWN, true) && !isset(self::REFUSED[$field])) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $fields = [];

        $name = self::text($payload['name'] ?? null);
        $subject = self::text($payload['subject'] ?? null);

        if ($creating && $name === '') {
            $errors['name'] = 'Required.';
        } elseif (array_key_exists('name', $payload)) {
            if ($name === '') {
                $errors['name'] = 'Cannot be blank.';
            } else {
                $fields['name'] = mb_substr($name, 0, Campaign::MAX_NAME);
            }
        }

        if ($creating && $subject === '') {
            $errors['subject'] = 'Required — a campaign with no subject line is not sendable.';
        } elseif (array_key_exists('subject', $payload)) {
            if ($subject === '') {
                $errors['subject'] = 'Cannot be blank.';
            } else {
                $fields['subject'] = mb_substr($subject, 0, Campaign::MAX_SUBJECT);
            }
        }

        foreach (['body_html', 'body_text'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $body = is_scalar($payload[$field]) ? (string) $payload[$field] : '';

            if (mb_strlen($body) > self::MAX_BODY) {
                $errors[$field] = 'At most ' . self::MAX_BODY . ' characters.';

                continue;
            }

            $fields[$field] = $body;
        }

        foreach (['template_id', 'segment_id'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if (!is_scalar($value) || !ctype_digit(trim((string) $value))) {
                $errors[$field] = 'Must be a positive id, or 0 for none.';

                continue;
            }

            $fields[$field] = (int) trim((string) $value);
        }

        if (array_key_exists('audience_type', $payload)) {
            $type = self::text($payload['audience_type']);

            if (!in_array($type, Campaign::AUDIENCES, true)) {
                $errors['audience_type'] = 'Must be one of: ' . implode(', ', Campaign::AUDIENCES) . '.';
            } else {
                $fields['audience_type'] = $type;
            }
        }

        if (array_key_exists('customer_ids', $payload)) {
            $fields['audience_ids'] = self::customerIds($payload['customer_ids'], $errors);
        }

        self::checkAudience($payload, $fields, $creating, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The campaign is invalid.', ['fields' => $errors]);
        }

        return new self($fields);
    }

    /**
     * An audience has to be answerable, and the three ways need different things.
     *
     * Checked here rather than at send time, because a campaign that cannot name
     * its audience is one an admin drafts, walks away from, and discovers is
     * unsendable a week later when the offer has expired.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $fields
     * @param array<string, string> $errors
     */
    private static function checkAudience(array $payload, array $fields, bool $creating, array &$errors): void
    {
        $type = $fields['audience_type'] ?? ($creating ? Campaign::AUDIENCE_SEGMENT : null);

        /*
         * Never overwrite a more specific complaint. `customerIds()` returns `[]` on
         * every failure — a name in the list, a zero, more than MAX_EXPLICIT_IDS — and
         * an unconditional "at least one customer id" here replaced the message that
         * said which of those it was. Found by the unit suite.
         */
        if ($type === Campaign::AUDIENCE_IDS && !isset($errors['customer_ids'])) {
            $ids = $fields['audience_ids'] ?? null;

            if ($ids === null && $creating) {
                $errors['customer_ids'] = 'Required when audience_type is "ids".';
            } elseif (is_array($ids) && $ids === []) {
                $errors['customer_ids'] = 'At least one customer id.';
            }
        }

        if ($type === Campaign::AUDIENCE_SEGMENT) {
            $segment = $fields['segment_id'] ?? null;

            if (($segment === null && $creating) || $segment === 0) {
                $errors['segment_id'] = 'Required when audience_type is "segment".';
            }
        }

        /*
         * A campaign to "everyone eligible" must not also carry a segment or a
         * list, because the resolver would ignore one of them and the admin would
         * have no way to know which. Refused rather than silently narrowed.
         */
        if ($type === Campaign::AUDIENCE_ALL) {
            if (($fields['segment_id'] ?? 0) > 0) {
                $errors['segment_id'] = 'Must be 0 when audience_type is "all".';
            }

            if (($fields['audience_ids'] ?? []) !== []) {
                $errors['customer_ids'] = 'Must be empty when audience_type is "all".';
            }
        }

        unset($payload);
    }

    /**
     * @param array<string, string> $errors
     * @return list<int>
     */
    private static function customerIds(mixed $value, array &$errors): array
    {
        if (!is_array($value)) {
            $errors['customer_ids'] = 'Must be an array of customer ids.';

            return [];
        }

        $ids = [];

        foreach ($value as $candidate) {
            if (!is_scalar($candidate) || !ctype_digit(trim((string) $candidate)) || (int) $candidate <= 0) {
                $errors['customer_ids'] = 'Every entry must be a positive customer id.';

                return [];
            }

            $ids[(int) $candidate] = true;
        }

        $ids = array_keys($ids);

        if (count($ids) > Campaign::MAX_EXPLICIT_IDS) {
            $errors['customer_ids'] = 'At most ' . Campaign::MAX_EXPLICIT_IDS
                . ' ids. A larger audience is a segment, which is queried rather than pasted.';

            return [];
        }

        return $ids;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
