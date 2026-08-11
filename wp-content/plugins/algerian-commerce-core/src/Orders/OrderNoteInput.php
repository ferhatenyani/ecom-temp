<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a note written against an order.
 *
 * Pure — no WordPress — so the one rule that matters here is testable on its
 * own: whether the note is visible to the customer.
 *
 * `customer_note` defaults to false, and the default is the security-relevant
 * part. A customer note is emailed to the buyer by WooCommerce the moment it is
 * saved, so treating an absent flag as "yes" would turn an internal remark —
 * "caller sounds like a repeat non-payer" — into a message the customer
 * receives. Silence must mean internal.
 */
final class OrderNoteInput
{
    public const MAX_LENGTH = 5000;

    private const ALLOWED = ['note', 'customer_note'];

    /** Emitted by the presenter, ignored on write, so a note round-trips. */
    private const READ_ONLY = ['id', 'added_by', 'date_created'];

    public function __construct(
        public readonly string $note,
        public readonly bool $customerNote
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::ALLOWED) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $note = '';

        if (!array_key_exists('note', $payload)) {
            $errors['note'] = 'A note is required.';
        } elseif (!is_scalar($payload['note'])) {
            $errors['note'] = 'Must be a string.';
        } else {
            $note = trim((string) $payload['note']);

            if ($note === '') {
                $errors['note'] = 'A note cannot be empty.';
            } elseif (mb_strlen($note) > self::MAX_LENGTH) {
                $errors['note'] = 'Must be at most ' . self::MAX_LENGTH . ' characters.';
            }
        }

        $customerNote = false;

        if (array_key_exists('customer_note', $payload)) {
            if (!is_bool($payload['customer_note'])) {
                // Not coerced. "false", 0 and "no" all read as intent to keep
                // the note internal, and a loose cast that turns the string
                // "false" into true would email it to the customer.
                $errors['customer_note'] = 'Must be true or false.';
            } else {
                $customerNote = $payload['customer_note'];
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The note is invalid.', ['fields' => $errors]);
        }

        return new self($note, $customerNote);
    }
}
