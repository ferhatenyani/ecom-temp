<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

use AlgerianCommerce\API\ApiException;

/**
 * Validates the body of a recorded confirmation call.
 *
 * Pure — no WordPress — so every rule is unit-testable.
 *
 * Unknown fields are rejected rather than ignored (docs/SECURITY.md). Nothing
 * is dropped silently here: unlike an order, this payload is not a round trip
 * of something the API emitted, so a key we do not recognise is a client
 * mistake worth reporting rather than a read-only field being echoed back.
 *
 * `outcome` is required and deliberately narrow. `pending` is not an outcome —
 * it is where an order starts — and `cancelled` is not one either: an order is
 * called off through the order itself, and CodSubscriber carries that across.
 */
final class CodAttemptInput
{
    private function __construct(
        public readonly string $outcome,
        public readonly string $reason
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), ['outcome', 'reason']) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $outcome = '';

        if (!array_key_exists('outcome', $payload)) {
            $errors['outcome'] = 'Required. One of: ' . implode(', ', CodStatus::OUTCOMES) . '.';
        } elseif (!is_scalar($payload['outcome']) || !CodStatus::isOutcome((string) $payload['outcome'])) {
            $errors['outcome'] = 'Must be one of: ' . implode(', ', CodStatus::OUTCOMES) . '.';
        } else {
            $outcome = CodStatus::normalize((string) $payload['outcome']);
        }

        $reason = '';

        if (array_key_exists('reason', $payload) && $payload['reason'] !== null) {
            if (!is_scalar($payload['reason'])) {
                $errors['reason'] = 'Must be a string.';
            } else {
                $reason = trim((string) $payload['reason']);

                if (mb_strlen($reason) > CodState::MAX_REASON) {
                    $errors['reason'] = 'Must be at most ' . CodState::MAX_REASON . ' characters.';
                    $reason = '';
                }
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The confirmation attempt is invalid.', ['fields' => $errors]);
        }

        return new self($outcome, $reason);
    }
}
