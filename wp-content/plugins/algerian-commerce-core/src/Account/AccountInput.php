<?php

declare(strict_types=1);

namespace AlgerianCommerce\Account;

use AlgerianCommerce\API\ApiException;

/**
 * Registration and password rules — roadmap §59c.
 *
 * Pure, so every rule is a unit test rather than something discovered against
 * a live signup form.
 *
 * **`role`, `roles` and `capabilities` are refused by name**, the way
 * `Customers\CustomerInput` refuses them. `AccountService` also forces
 * `role => customer` when it calls `wp_insert_user()`, so this is the second of
 * two locks — but it is the one that produces a 400 saying what happened, and a
 * registration endpoint that silently dropped an attempted `role: administrator`
 * would leave nothing in the log for anyone to notice.
 */
final class AccountInput
{
    /**
     * WordPress's own floor is nothing at all; a shop needs one.
     *
     * Twelve characters and no composition rules, which is NIST SP 800-63B's
     * advice and the opposite of the "one capital, one symbol" habit: length is
     * what resists guessing, and the symbol rule mostly produces `Password1!`.
     */
    public const MIN_PASSWORD = 12;

    /** `wp_check_password()` truncates nothing, but a megabyte is a DoS. */
    public const MAX_PASSWORD = 200;

    /** @var array<string, string> */
    private const REJECTED = [
        'role' => 'A registration cannot choose a role.',
        'roles' => 'A registration cannot choose a role.',
        'capabilities' => 'Capabilities are not set through this endpoint.',
        'user_pass' => 'Use `password`.',
        'id' => 'The account id is assigned by the shop.',
        'user_login' => 'The email address is the login.',
    ];

    private function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $firstName,
        public readonly string $lastName,
        /**
         * Marketing consent — roadmap §85, **default false**.
         *
         * Absent means no. Never inferred from having registered, and never from
         * having placed an order: a customer who bought something consented to an
         * order confirmation, not to a newsletter. `Campaigns\Consent` carries the
         * rest of the rule, including why no staff route can set it.
         */
        public readonly bool $marketingConsent = false
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException 400 listing every bad field at once
     */
    public static function forRegistration(array $payload): self
    {
        $errors = [];

        foreach (self::REJECTED as $field => $why) {
            if (array_key_exists($field, $payload)) {
                $errors[$field] = $why;
            }
        }

        $known = ['email', 'password', 'first_name', 'last_name', 'marketing_consent'];

        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $known, true) && !isset(self::REJECTED[$field])) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $email = is_scalar($payload['email'] ?? null) ? trim((string) $payload['email']) : '';
        $password = is_scalar($payload['password'] ?? null) ? (string) $payload['password'] : '';
        $firstName = is_scalar($payload['first_name'] ?? null) ? trim((string) $payload['first_name']) : '';
        $lastName = is_scalar($payload['last_name'] ?? null) ? trim((string) $payload['last_name']) : '';

        // filter_var(), not WordPress's is_email(): this class is pure so its
        // rules are unit tests, and `Customers\CustomerInput` validates the
        // same field the same way — one shape of "valid email" across the API.
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address is required.';
        }

        self::checkPassword($password, 'password', $errors);

        if (mb_strlen($firstName) > 100) {
            $errors['first_name'] = 'At most 100 characters.';
        }

        if (mb_strlen($lastName) > 100) {
            $errors['last_name'] = 'At most 100 characters.';
        }

        /*
         * §85's consent flag, and the parsing is the rule. Only a real boolean true
         * or the strings a JSON client sends for one count as a yes — **`"0"`,
         * `"false"`, `""` and an absent field are all no**, which is what makes the
         * default false rather than "whatever PHP thinks is truthy". A checkbox that
         * arrived as the string `"false"` must not opt somebody in.
         */
        $consent = $payload['marketing_consent'] ?? null;
        $consented = $consent === true
            || (is_string($consent) && in_array(strtolower(trim($consent)), ['1', 'true', 'yes', 'on'], true))
            || (is_int($consent) && $consent === 1);

        if ($consent !== null && !is_bool($consent) && !is_scalar($consent)) {
            $errors['marketing_consent'] = 'Must be a boolean.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The registration is invalid.', ['fields' => $errors]);
        }

        return new self($email, $password, $firstName, $lastName, $consented);
    }

    /**
     * @throws ApiException 400
     * @return array{current: string, new: string}
     */
    public static function forPasswordChange(array $payload): array
    {
        $errors = [];

        $current = is_scalar($payload['current_password'] ?? null) ? (string) $payload['current_password'] : '';
        $new = is_scalar($payload['new_password'] ?? null) ? (string) $payload['new_password'] : '';

        if ($current === '') {
            $errors['current_password'] = 'Required.';
        }

        self::checkPassword($new, 'new_password', $errors);

        // Not a formality: a "change" that changes nothing leaves every other
        // session valid while the shopper believes they have just revoked them.
        if ($errors === [] && $current === $new) {
            $errors['new_password'] = 'Must differ from the current password.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The password change is invalid.', ['fields' => $errors]);
        }

        return ['current' => $current, 'new' => $new];
    }

    /**
     * The password rules, shared with the reset path.
     *
     * Public because `PasswordResetService` sets a password without a session
     * and without a current password, so it reaches none of the entry points
     * above — and a reset that accepted a four-character password would undo
     * the rule every other door enforces.
     *
     * @param array<string, string> $errors
     */
    public static function checkNewPassword(string $password, string $field, array &$errors): void
    {
        self::checkPassword($password, $field, $errors);
    }

    /** @param array<string, string> $errors */
    private static function checkPassword(string $password, string $field, array &$errors): void
    {
        if ($password === '') {
            $errors[$field] = 'Required.';

            return;
        }

        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $errors[$field] = 'At least ' . self::MIN_PASSWORD . ' characters.';

            return;
        }

        if (mb_strlen($password) > self::MAX_PASSWORD) {
            $errors[$field] = 'At most ' . self::MAX_PASSWORD . ' characters.';
        }
    }
}
