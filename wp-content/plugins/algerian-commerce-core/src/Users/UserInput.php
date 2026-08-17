<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a staff write payload.
 *
 * Pure — no WordPress — so the field list is testable on its own, as
 * `CustomerInput` is.
 *
 * **This is the mirror image of `CustomerInput` and the pair is the design.**
 * That class refuses `roles`, `capabilities` and `user_pass` because
 * `ac_manage_customers` is Support Agent's, the thinnest role there is, and a
 * support account that could set a role would be an escalation path to
 * administrator. This class *accepts* a role, because it sits behind
 * `ac_manage_users`, which is Super Admin's alone. Account and credential
 * management lives on exactly one side of that line, and the two classes are
 * where the line is drawn.
 *
 * It still refuses credentials, and for a reason that has nothing to do with
 * privilege: a password chosen by somebody else is a password its owner cannot
 * trust. Staff are onboarded with an application password, which is minted once
 * and shown once — see `UserService::createApplicationPassword()`.
 */
final class UserInput
{
    /**
     * Emitted on read, ignored on write, so a client can GET a staff account,
     * change one field and PATCH the whole object back.
     *
     * Everything the presenter emits and does not accept has to be in here
     * rather than in REFUSED. A field that is both published and refused turns
     * an ordinary round trip into a 400, which is the failure `docs/API.md`
     * promises will not happen.
     */
    private const READ_ONLY = [
        'id', 'username', 'role_name', 'is_administrator',
        'date_created', 'application_passwords',
    ];

    /**
     * Refused by name rather than as "Unknown field.".
     *
     * None of these is ever emitted, so nobody arrives at one by round-tripping
     * a response — they are only typed on purpose, and a caller who typed one
     * is asking a question the message should answer.
     *
     * @var array<string, string>
     */
    private const REFUSED = [
        'password' => 'A password set by somebody else is one its owner cannot trust. Onboard with POST /users/{id}/application-passwords.',
        'user_pass' => 'A password set by somebody else is one its owner cannot trust. Onboard with POST /users/{id}/application-passwords.',
        'capabilities' => 'Capabilities come from the role. Assign a role and GET /roles to see what it holds.',
        'roles' => 'An account holds exactly one role here. Use "role".',
        'user_login' => 'A login is an identity, not a field. Create the account with the username you want.',
    ];

    private const STRING_FIELDS = ['first_name', 'last_name', 'display_name'];

    private const MAX_LENGTH = 200;
    private const USERNAME_MIN = 3;
    private const USERNAME_MAX = 60;

    /**
     * WordPress's `sanitize_user()` vocabulary, minus the characters that only
     * survive it in "strict off" mode.
     *
     * Deliberately a first gate rather than the only one: `validate_username()`
     * runs in the repository and is the platform's own answer, which also
     * catches a name the install has blocked. Two checks, the narrower one
     * first — the ordering `Media\UploadPolicy` uses.
     */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_.\-@ ]+$/';

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function createFields(): array
    {
        return ['username', 'email', 'role', ...self::STRING_FIELDS];
    }

    /** @return list<string> */
    public static function updateFields(): array
    {
        return ['email', 'role', 'status', ...self::STRING_FIELDS];
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

    /**
     * A new staff account.
     *
     * **A role is required**, and that is the boundary between this endpoint and
     * `/customers`. An account created with no role is a customer created
     * through the wrong door — it would be invisible to `GET /users` the moment
     * it existed, and `/customers` never expected to receive one.
     *
     * @param array<string, mixed> $payload
     */
    public static function forCreate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, self::createFields(), $errors);

        if (!array_key_exists('username', $payload)) {
            $errors['username'] = 'Required.';
        } else {
            $username = is_scalar($payload['username']) ? trim((string) $payload['username']) : '';
            $length = mb_strlen($username);

            if ($length < self::USERNAME_MIN || $length > self::USERNAME_MAX) {
                $errors['username'] = sprintf(
                    'Must be between %d and %d characters.',
                    self::USERNAME_MIN,
                    self::USERNAME_MAX
                );
            } elseif (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
                $errors['username'] = 'May contain letters, digits, spaces and _ . - @ only.';
            } else {
                $clean['username'] = $username;
            }
        }

        if (!array_key_exists('email', $payload)) {
            $errors['email'] = 'Required.';
        }

        if (!array_key_exists('role', $payload)) {
            $errors['role'] = 'Required. An account with no role is a customer, and customers are managed at /customers.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The user data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, self::updateFields(), $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The user data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * Everything both payloads share.
     *
     * @param array<string, mixed>  $payload
     * @param list<string>          $allowed
     * @param array<string, string> $errors  collected by reference so one
     *                                       response names every bad field
     * @return array<string, mixed>
     */
    private static function common(array $payload, array $allowed, array &$errors): array
    {
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), $allowed) as $field) {
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

            // Not emptiable, for the reason CustomerInput gives: a WordPress
            // user with no address cannot be sent a reset and the account
            // becomes unrecoverable.
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Must be a valid email address.';
            } else {
                $clean['email'] = $email;
            }
        }

        if (array_key_exists('role', $payload)) {
            $role = is_scalar($payload['role']) ? trim((string) $payload['role']) : '';
            $problem = UserRoles::assignmentError($role);

            /*
             * Vocabulary only. Whether *this caller* may grant this role is
             * `UserService::guardAssignable()`, because that question needs to
             * know who is asking and this class deliberately does not.
             */
            if ($problem !== null) {
                $errors['role'] = $problem;
            } else {
                $clean['role'] = $role;
            }
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? trim((string) $payload['status']) : '';

            if (!in_array($status, UserStatus::ALL, true)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', UserStatus::ALL) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        return $clean;
    }
}
