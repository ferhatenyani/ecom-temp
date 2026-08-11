<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

/**
 * The cash-on-delivery state of one order — roadmap §52's field list.
 *
 * Pure — no WordPress, no WooCommerce — so the state machine's effects are
 * unit-testable: what an outcome does to the attempt count, which timestamp it
 * stamps, and what a cancellation leaves behind.
 *
 * **Stored as order meta, not in a table of its own.** These fields are the
 * *current* state of one order, which is what order meta is for, and reaching
 * them through `WC_Order`'s CRUD keeps the plugin's HPOS compatibility true.
 * The *history* — who called, when, what the customer said — is already
 * append-only in `ac_audit_logs` and already merged into the order timeline, so
 * a `ac_cod_attempts` table would be a third copy of something two stores hold
 * between them (CLAUDE.md: custom tables only for genuinely custom, high-volume
 * domains).
 *
 * Timestamps are held as `'Y-m-d H:i:s'` in UTC, which is how the audit trail,
 * the stock ledger and the normalized order notes all store a time — so a COD
 * timestamp can be compared against them without a conversion nobody remembers
 * to make. They are presented as ISO-8601, which is what the wire format uses
 * everywhere else.
 *
 * Absent values are `''` rather than null throughout, because that is what
 * WordPress hands back for meta that was never written, and carrying one empty
 * value instead of two removes a class of null checks. The wire format turns
 * them back into `null`.
 */
final class CodState
{
    /**
     * Meta keys, underscore-prefixed so WordPress treats them as protected —
     * they are this module's bookkeeping, not custom fields to be edited by
     * hand in wp-admin.
     */
    public const META_ENABLED = '_ac_cod_enabled';
    public const META_STATUS = '_ac_cod_status';
    public const META_ATTEMPTS = '_ac_cod_attempts';
    public const META_CONFIRMED_AT = '_ac_cod_confirmed_at';
    public const META_CANCELLED_AT = '_ac_cod_cancelled_at';
    public const META_LAST_ATTEMPT_AT = '_ac_cod_last_attempt_at';
    public const META_REASON = '_ac_cod_reason';

    /** Long enough for an explanation, short enough not to bloat an audit row. */
    public const MAX_REASON = 500;

    public function __construct(
        public readonly bool $enabled,
        public readonly string $status = CodStatus::PENDING,
        public readonly int $attempts = 0,
        public readonly string $confirmedAt = '',
        public readonly string $cancelledAt = '',
        public readonly string $lastAttemptAt = '',
        /** The reason recorded with the most recent outcome, if one was given. */
        public readonly string $reason = ''
    ) {
    }

    /**
     * Read the state off an order's meta.
     *
     * `$defaultEnabled` is what the order's payment method says — an order paid
     * cash on delivery is a COD order whether or not anyone has written our
     * meta yet, which matters for every order placed before this module
     * existed. Once the flag has been set explicitly it wins, so an operator
     * can turn COD off for one order without changing how it was paid.
     *
     * An unrecognised stored status falls back to `pending` rather than being
     * carried through. The alternative is a state outside the matrix, which
     * reaches nothing and silently freezes the order's confirmation.
     *
     * @param array<string, mixed> $meta raw meta values, as WordPress returns them
     */
    public static function fromMeta(array $meta, bool $defaultEnabled): self
    {
        $enabled = $meta[self::META_ENABLED] ?? null;
        $status = CodStatus::normalize((string) ($meta[self::META_STATUS] ?? ''));

        return new self(
            $enabled === null || $enabled === '' ? $defaultEnabled : self::toBool($enabled),
            CodStatus::isKnown($status) ? $status : CodStatus::PENDING,
            max(0, (int) ($meta[self::META_ATTEMPTS] ?? 0)),
            (string) ($meta[self::META_CONFIRMED_AT] ?? ''),
            (string) ($meta[self::META_CANCELLED_AT] ?? ''),
            (string) ($meta[self::META_LAST_ATTEMPT_AT] ?? ''),
            (string) ($meta[self::META_REASON] ?? '')
        );
    }

    /**
     * What the repository writes back.
     *
     * Every key every time, including the empty ones: a partial write would
     * leave a stale `confirmed_at` next to a status that says the order was
     * never confirmed.
     *
     * @return array<string, string>
     */
    public function toMeta(): array
    {
        return [
            self::META_ENABLED => $this->enabled ? '1' : '0',
            self::META_STATUS => $this->status,
            self::META_ATTEMPTS => (string) $this->attempts,
            self::META_CONFIRMED_AT => $this->confirmedAt,
            self::META_CANCELLED_AT => $this->cancelledAt,
            self::META_LAST_ATTEMPT_AT => $this->lastAttemptAt,
            self::META_REASON => $this->reason,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'confirmed_at' => self::iso($this->confirmedAt),
            'cancelled_at' => self::iso($this->cancelledAt),
            'last_attempt_at' => self::iso($this->lastAttemptAt),
            'reason' => $this->reason,
            // What a confirmation call may conclude with from here, so a client
            // can render the buttons that will work instead of guessing and
            // collecting a 409.
            'allowed_outcomes' => $this->allowedOutcomes(),
        ];
    }

    /** @return list<string> */
    public function allowedOutcomes(): array
    {
        return array_values(array_intersect(CodStatus::allowedFrom($this->status), CodStatus::OUTCOMES));
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            $enabled,
            $this->status,
            $this->attempts,
            $this->confirmedAt,
            $this->cancelledAt,
            $this->lastAttemptAt,
            $this->reason
        );
    }

    /**
     * Record the outcome of a confirmation call.
     *
     * The caller has already checked that the move is legal — that is policy,
     * and policy lives in the service.
     *
     * The attempt count rises on every outcome, including a repeat: two calls
     * that both failed to connect are two attempts, and that count is half of
     * what "unreachable customers" means operationally.
     *
     * `confirmed_at` is stamped once. A re-confirmation is the same customer
     * saying yes to the same order, so overwriting it would move the moment
     * they agreed forward in time for no reason.
     *
     * `$now` is passed in rather than read from the clock, so this class stays
     * pure and the timestamps are testable.
     */
    public function record(string $outcome, string $reason, string $now): self
    {
        $outcome = CodStatus::normalize($outcome);

        return new self(
            $this->enabled,
            $outcome,
            $this->attempts + 1,
            $outcome === CodStatus::CONFIRMED && $this->confirmedAt === '' ? $now : $this->confirmedAt,
            $this->cancelledAt,
            $now,
            $reason
        );
    }

    /**
     * Called off.
     *
     * Not an attempt: nobody phoned anyone, so the counter stays where it is.
     * Whatever the order had reached — pending, unreachable, or confirmed — is
     * preserved in `confirmed_at`, which is what lets the confirmation rate
     * still count an order that was confirmed and later cancelled.
     */
    public function cancel(string $reason, string $now): self
    {
        return new self(
            $this->enabled,
            CodStatus::CANCELLED,
            $this->attempts,
            $this->confirmedAt,
            $now,
            $this->lastAttemptAt,
            $reason === '' ? $this->reason : $reason
        );
    }

    /**
     * WordPress hands meta back as a string, so `'0'` has to mean false. Casting
     * straight to bool would make the string `'0'` true in a language where the
     * scalar 0 is false, and COD would silently re-enable itself on every order
     * an operator had turned it off for.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    /** Stored UTC 'Y-m-d H:i:s' to ISO-8601, or null when never stamped. */
    private static function iso(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        $timestamp = strtotime($stored . ' UTC');

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }
}
