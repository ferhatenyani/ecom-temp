<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\Audit\AuditLogger;
use WP_User;

/**
 * Marketing consent — roadmap §85's legal and practical core.
 *
 * **A customer who bought something consented to an order confirmation. They did
 * not consent to a newsletter.** That distinction is the whole difference between
 * §29's module and this one, and it is built in rather than remembered:
 *
 *  - The flag is **default false**. It is stored as user meta and its *absence* is
 *    a no, so a customer who registered before this feature existed is not
 *    silently opted in.
 *  - It is set only by the customer's own explicit action — registration, or
 *    `POST /account/marketing-consent`. **No staff route can set it**, which is why
 *    `Customers\CustomerInput` was left alone: a shop that could tick this box on
 *    somebody's behalf has no consent record worth anything.
 *  - **The filter lives in `AudienceResolver`, not in the caller.** Same reason
 *    `AccountService::order()` checks ownership in the service layer: a check living
 *    only in the admin app is one the second client removes.
 *  - Unsubscribing is idempotent and is an audit event.
 *  - **Transactional mail is not gated on it.** A customer who unsubscribes from
 *    marketing still receives their order confirmation, and a shop that stopped
 *    sending those would have broken something worse than it fixed. Nothing in
 *    `Notifications/` reads this class.
 *
 * ## Checkout is deliberately not a consent surface, and the reason is named
 *
 * §85 says the flag is "set only by an explicit action at registration or
 * checkout". Registration is built; checkout is not, for two reasons. A **guest**
 * checkout has no account for the flag to live on — §85's audience is customer
 * records, and a shop that mailed guests from an order row would be mailing people
 * who never made an account. And an authenticated checkout can set it through
 * `POST /account/marketing-consent` in the same session, so putting a consent
 * checkbox on a payment form buys nothing and is exactly where a pre-ticked box
 * ends up by accident.
 *
 * **The trigger for revisiting is `ac_marketing_contacts`**: a table of consenting
 * addresses with no account behind them. That is the day guest consent becomes
 * expressible, and it is a bigger question than a checkbox — it needs its own
 * unsubscribe identity, its own purge rule and its own answer to "who is this
 * person".
 *
 * ## §54's rule applies to law as much as to APIs
 *
 * **Do not write the consent rule from memory.** Algeria's Law 18-07 on the
 * protection of natural persons in the processing of personal data governs this,
 * and the implementer reads the current text — or has the client confirm with
 * counsel — before deciding what an opt-in must look like and how long a recipient
 * record may be kept. Nothing in this class is a legal opinion; the engineering
 * requirements above are the floor, not the ceiling.
 */
final class Consent
{
    /**
     * User meta. Underscored so it is not exposed as a public meta field, and
     * prefixed `ac_` like every other name this plugin puts in a shared namespace.
     */
    public const META = '_ac_marketing_consent';

    /** When it was given or withdrawn, for the record a shop may have to produce. */
    public const META_AT = '_ac_marketing_consent_at';

    /**
     * How it was given or withdrawn — the other half of the record.
     *
     * The audit log has carried this since the class was written, and that turned
     * out not to be enough: `/audit-logs` stops at Admin while a customer record
     * is read by Support Agent, and an audit query cannot be joined to a customer
     * row without a heuristic. "How did this person opt in" is the question the
     * docblock above says a consent record exists to answer, so the answer lives
     * on the customer beside the flag as well as in the log.
     *
     * Absent on a consent recorded before this meta existed, which is why
     * `source()` is nullable rather than defaulting to a guess.
     */
    public const META_SOURCE = '_ac_marketing_consent_source';

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Whether this customer has opted in.
     *
     * Absence is a no. `'1'` is the only yes, so a meta value left behind by
     * something else cannot read as consent.
     */
    public static function has(int $customerId): bool
    {
        return $customerId > 0 && get_user_meta($customerId, self::META, true) === '1';
    }

    /**
     * When the customer last decided, as ISO 8601 — or null if they never have.
     *
     * **The offset is added here on purpose.** The meta is stored as
     * `gmdate('Y-m-d H:i:s')`, which is a UTC instant written without saying so,
     * and this project has already shipped that trap twice: `notes[].created_at`
     * and `movements[].created_at` both hand a client a naive string beside sibling
     * fields that carry `+00:00`, and `new Date()` reads the naive one as local
     * time and shifts it silently. A consent date shifted by an hour is a date in
     * the wrong day for a shop in a positive offset, so the ambiguity is resolved
     * at the boundary rather than documented for every reader.
     *
     * Present on a `false` as well as a `true` — see `set()`.
     */
    public static function changedAt(int $customerId): ?string
    {
        $raw = self::rawChangedAt($customerId);

        if ($raw === '') {
            return null;
        }

        $when = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $raw,
            new \DateTimeZone('UTC')
        );

        // A meta value that is not the format this class writes is reported as no
        // record rather than as a guess. Nothing else writes it today; something
        // hand-editing the database is not a reason to emit an invalid date.
        return $when === false ? null : $when->format('c');
    }

    /**
     * How they decided — `registration`, `account`, `unsubscribe_link`, or whatever
     * else a caller passed to `set()`.
     *
     * Null when the decision predates `META_SOURCE`, which is why nothing derives a
     * default from it. An unknown source is information; an invented one is not.
     */
    public static function source(int $customerId): ?string
    {
        if ($customerId <= 0) {
            return null;
        }

        $source = (string) get_user_meta($customerId, self::META_SOURCE, true);

        return $source === '' ? null : $source;
    }

    private static function rawChangedAt(int $customerId): string
    {
        return $customerId > 0 ? (string) get_user_meta($customerId, self::META_AT, true) : '';
    }

    /**
     * Record the customer's own decision.
     *
     * Idempotent in both directions: unsubscribing twice is the same as
     * unsubscribing once, which is what a one-click link in an email needs when
     * somebody clicks it and then clicks it again from a second device.
     *
     * `$source` is recorded rather than inferred — `registration`, `account`,
     * `unsubscribe_link` — because "how did this person opt in" is the question a
     * consent record exists to answer.
     */
    public function set(int $customerId, bool $consented, string $source): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $before = self::has($customerId);

        if ($consented) {
            update_user_meta($customerId, self::META, '1');
        } else {
            /*
             * Deleted rather than set to '0'. A withdrawn consent is the absence of
             * consent, and keeping a row that says "no" invites a later query
             * written as `!= '0'`, which would read every customer who never
             * answered as a yes.
             */
            delete_user_meta($customerId, self::META);
        }

        /*
         * The when and the how are written for *both* directions and are kept when
         * the flag itself is deleted. A withdrawal is the half of the record a shop
         * is most likely to be asked to produce, and "no, since never" and "no,
         * withdrawn on 3 March" are different answers to the same question.
         *
         * **Only on an actual change.** A one-click unsubscribe link gets clicked
         * twice from two devices — that is the case `set()` is idempotent for — and
         * moving the date on the second click would restate the record as though the
         * customer had acted again. The `=== ''` arm is for a consent recorded before
         * this meta existed: there is a decision with no date, and stamping it now is
         * better than leaving it unanswerable forever.
         */
        if ($before !== $consented || self::rawChangedAt($customerId) === '') {
            update_user_meta($customerId, self::META_AT, gmdate('Y-m-d H:i:s'));
            update_user_meta($customerId, self::META_SOURCE, $source);
        }

        $this->audit->record(
            $consented ? 'marketing.consent_given' : 'marketing.consent_withdrawn',
            'customer',
            $customerId,
            ['source' => $source, 'changed' => $before !== $consented]
        );

        return $before !== $consented;
    }

    /**
     * Whether this user may be in an audience at all.
     *
     * Consent **and** the customer role. The second half is not redundant: an
     * `ac_admin` who ticked a box during a test is still not a customer, and §85's
     * audience is the customer list. `SegmentCriteria` refuses a `role` criterion
     * for the same reason from the other direction.
     */
    public static function isEligible(WP_User $user): bool
    {
        return in_array('customer', (array) $user->roles, true) && self::has($user->ID);
    }
}
