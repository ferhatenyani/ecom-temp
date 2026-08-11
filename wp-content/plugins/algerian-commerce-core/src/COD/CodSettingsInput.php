<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a write to an order's COD settings.
 *
 * Pure — no WordPress. One writable field, `enabled`, because everything else
 * in the state is the consequence of an event rather than a setting: attempts
 * are counted, timestamps are stamped, and the status is reached by recording
 * an outcome. A PATCH that could set `status` directly would be a way to
 * confirm an order without anyone having called the customer.
 *
 * The fields the API emits but does not accept are dropped silently, so a
 * client can GET the state, flip `enabled` and PATCH the whole object back —
 * the same contract OrderInput offers, and for the same reason.
 */
final class CodSettingsInput
{
    /** Emitted by CodState::toArray(), never accepted. */
    private const READ_ONLY = [
        'status', 'attempts', 'confirmed_at', 'cancelled_at',
        'last_attempt_at', 'reason', 'allowed_outcomes',
    ];

    private function __construct(public readonly bool $enabled)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        $errors = [];

        foreach (array_diff(array_keys($payload), ['enabled']) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        if (!array_key_exists('enabled', $payload)) {
            $errors['enabled'] = 'Required.';
        } elseif (!is_bool($payload['enabled'])) {
            // Strictly boolean: this is a JSON body, where true and "true" are
            // different values, and a shop that reads "false" as true stops
            // calling customers it should be calling.
            $errors['enabled'] = 'Must be true or false.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The COD settings are invalid.', ['fields' => $errors]);
        }

        return new self((bool) $payload['enabled']);
    }
}
