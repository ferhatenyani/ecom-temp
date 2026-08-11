<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\API\ApiException;

/**
 * Validates and normalizes a manual stock adjustment.
 *
 * Pure — no WordPress, no WooCommerce — so the arithmetic and the rules around
 * it are unit-testable on their own.
 *
 * `set` states the quantity the shelf should end up at; `increase` and
 * `decrease` state how far it moves. The distinction matters beyond
 * convenience: WooCommerce applies increase and decrease as a relative SQL
 * update, so two concurrent decrements compose correctly, whereas two
 * concurrent `set`s are last-writer-wins. A stock count uses `set`; receiving
 * or writing off goods should use `increase` / `decrease`.
 */
final class StockAdjustment
{
    public const MODES = ['set', 'increase', 'decrease'];

    public const MAX_NOTE = 255;

    private const FIELDS = ['mode', 'quantity', 'reason', 'note'];

    private function __construct(
        public readonly string $mode,
        public readonly int $quantity,
        public readonly string $reason,
        public readonly string $note
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ApiException with a per-field breakdown in error.details.fields
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), self::FIELDS) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $mode = is_scalar($payload['mode'] ?? null) ? (string) $payload['mode'] : '';

        if (!in_array($mode, self::MODES, true)) {
            $errors['mode'] = 'Must be one of: ' . implode(', ', self::MODES) . '.';
        }

        $quantity = self::quantity($payload, $mode, $errors);
        $reason = self::reason($payload, $errors);
        $note = self::note($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The adjustment is invalid.', ['fields' => $errors]);
        }

        return new self($mode, $quantity, $reason, $note);
    }

    /**
     * The quantity this adjustment would produce from $current.
     *
     * Used to check the result *before* touching WooCommerce — the recorded
     * before/after come from what WooCommerce actually wrote, not from here,
     * because a concurrent adjustment can land in between.
     */
    public function project(int $current): int
    {
        return match ($this->mode) {
            'increase' => $current + $this->quantity,
            'decrease' => $current - $this->quantity,
            default => $this->quantity,
        };
    }

    /**
     * How many units this adjustment moved, given the quantity read before the
     * write and the one WooCommerce reported after it.
     *
     * `increase` and `decrease` know their own delta: WooCommerce applies them
     * as relative SQL, so the movement is exactly the requested amount even if
     * a concurrent adjustment landed in between — and `$after - $before` would
     * then wrongly attribute the other party's change to this one.
     *
     * `set` has no such luxury. It is last-writer-wins, and the only delta it
     * can claim is the difference across its own read and write.
     */
    public function deltaFor(int $before, int $after): int
    {
        return match ($this->mode) {
            'increase' => $this->quantity,
            'decrease' => -$this->quantity,
            default => $after - $before,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function quantity(array $payload, string $mode, array &$errors): int
    {
        if (!array_key_exists('quantity', $payload)) {
            $errors['quantity'] = 'A quantity is required.';

            return 0;
        }

        $raw = $payload['quantity'];

        // A float would silently truncate: 2.5 units off the shelf is not a
        // thing WooCommerce's default integer stock can represent.
        if (!is_numeric($raw) || (float) $raw !== floor((float) $raw)) {
            $errors['quantity'] = 'Must be a whole number.';

            return 0;
        }

        $quantity = (int) $raw;

        if ($quantity < 0) {
            // Negative stock is a consequence of backorders, never of a manual
            // entry. Getting there deliberately means decreasing from a
            // positive figure, which is checked against the backorder policy.
            $errors['quantity'] = 'Cannot be negative.';

            return 0;
        }

        // A zero-magnitude move is a no-op that would still write a ledger row.
        if ($quantity === 0 && in_array($mode, ['increase', 'decrease'], true)) {
            $errors['quantity'] = 'Must be greater than zero for ' . $mode . '.';
        }

        return $quantity;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function reason(array $payload, array &$errors): string
    {
        $reason = is_scalar($payload['reason'] ?? null) ? (string) $payload['reason'] : '';

        if (MovementReason::isManual($reason)) {
            return $reason;
        }

        // A system reason is rejected with the same message as an unknown one.
        // Confirming "that reason exists but you may not use it" tells a
        // caller how to shape a forgery attempt.
        $errors['reason'] = 'Must be one of: ' . implode(', ', MovementReason::MANUAL) . '.';

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function note(array $payload, array &$errors): string
    {
        if (!array_key_exists('note', $payload) || $payload['note'] === null) {
            return '';
        }

        if (!is_scalar($payload['note'])) {
            $errors['note'] = 'Must be a string.';

            return '';
        }

        $note = trim((string) $payload['note']);

        // Rejected rather than clipped: a note is the operator's own
        // explanation, and silently storing half of it is worse than saying
        // it was too long.
        if (mb_strlen($note) > self::MAX_NOTE) {
            $errors['note'] = 'Must be at most ' . self::MAX_NOTE . ' characters.';

            return '';
        }

        return $note;
    }
}
