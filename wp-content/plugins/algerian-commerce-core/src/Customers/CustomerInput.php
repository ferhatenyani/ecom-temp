<?php

declare(strict_types=1);

namespace AlgerianCommerce\Customers;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\AddressInput;

/**
 * Validates a customer write payload.
 *
 * Pure — no WordPress — so the field list is testable on its own.
 *
 * **The field list is a security boundary, not a convenience.** A customer is a
 * WordPress user, and a user object carries the password hash, the role and the
 * capability map. `ac_manage_customers` is held by Support Agent — the thinnest
 * role there is — precisely so a compromised support account cannot do more
 * than correct an address. Letting `role` or `password` through this class
 * would turn that role into an escalation path to administrator, so account and
 * credential management stays behind `ac_manage_users` and is not reachable
 * from here at all.
 */
final class CustomerInput
{
    /**
     * Emitted on read, ignored on write, so a client can GET a customer, change
     * a field and PATCH the object back.
     *
     * `role` and `username` are in here because the presenter emits them, and
     * dropping is safe: a dropped field is never applied. The danger would be
     * *accepting* one.
     */
    private const READ_ONLY = [
        'id', 'username', 'role', 'is_paying_customer',
        'date_created', 'date_modified', 'orders_count', 'statistics',
        'marketing_consent_at', 'marketing_consent_source',
    ];

    /**
     * Refused by name rather than as "Unknown field.".
     *
     * Most of these appear in no response, so nobody arrives at them by
     * round-tripping one — they are only ever typed on purpose, and the caller
     * deserves to be told where the capability boundary is instead of being
     * left to hunt for a spelling mistake.
     *
     * `marketing_consent` is the exception and the reason this list is not simply
     * "fields we do not emit": it *is* emitted, so it is the one field a client
     * reaches by GET → edit → PATCH rather than by typing. It was answering
     * "Unknown field." — the same message a misspelling gets — which told a panel
     * developer the field did not exist when in fact it exists and is somebody
     * else's to set. It is refused rather than silently dropped for the same
     * reason: a shop that thinks it ticked a consent box has no consent record.
     *
     * @var array<string, string>
     */
    private const REFUSED = [
        'password' => 'Credentials are not managed through this endpoint.',
        'user_pass' => 'Credentials are not managed through this endpoint.',
        'roles' => 'Roles are managed under ac_manage_users, not here.',
        'capabilities' => 'Capabilities are managed under ac_manage_users, not here.',
        'marketing_consent' => 'Marketing consent is the customer\'s to give. '
            . 'They set it at registration or at POST /account/marketing-consent, '
            . 'and withdraw it with the unsubscribe link in any campaign.',
    ];

    private const STRING_FIELDS = ['first_name', 'last_name'];

    private const MAX_LENGTH = 200;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return [...self::STRING_FIELDS, 'email', 'billing', 'shipping'];
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $field = (string) $field;
            $errors[$field] = self::REFUSED[$field] ?? 'Unknown field.';
        }

        foreach (self::STRING_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $payload[$field]);

            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[$field] = 'Must be at most ' . self::MAX_LENGTH . ' characters.';
                continue;
            }

            $clean[$field] = $value;
        }

        if (array_key_exists('email', $payload)) {
            $email = is_scalar($payload['email']) ? trim((string) $payload['email']) : '';

            // Not emptiable: a WordPress user without an email cannot be sent a
            // password reset or an order notification, and the account becomes
            // unrecoverable.
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Must be a valid email address.';
            } else {
                $clean['email'] = $email;
            }
        }

        if (array_key_exists('billing', $payload)) {
            $clean['billing'] = AddressInput::forBilling($payload['billing'], $errors);
        }

        if (array_key_exists('shipping', $payload)) {
            $clean['shipping'] = AddressInput::forShipping($payload['shipping'], $errors);
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The customer data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }
}
