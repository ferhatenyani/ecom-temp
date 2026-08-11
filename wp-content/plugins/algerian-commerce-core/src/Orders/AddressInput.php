<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

/**
 * Validates and normalizes a billing or shipping address.
 *
 * Pure — no WordPress — and shared by both addresses, because they differ in
 * exactly one field: `email` is billing-only, matching WooCommerce, which has
 * `set_billing_email()` and no shipping equivalent. Phone exists on both.
 *
 * Errors are returned rather than thrown so the caller can merge them into one
 * per-field breakdown; they are keyed `billing.city`, `shipping.country`, so a
 * client can point at the offending input rather than at the object.
 *
 * No wilaya or commune validation here. Algeria's geographic data and the
 * mapping onto `state`/`city` arrive with roadmap §51; until that dataset
 * exists, rejecting a place name would mean rejecting it against a list we
 * have not built.
 */
final class AddressInput
{
    /** Present on both addresses. */
    public const FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
    ];

    /** Billing only — WooCommerce stores no shipping email. */
    public const BILLING_ONLY = ['email'];

    /** Matches WooCommerce's own column widths for address fields. */
    private const MAX_LENGTH = 200;

    /** @param array<string, string> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /**
     * @param array<string, string> $errors keyed with the prefix, e.g. `billing.city`
     */
    public static function forBilling(mixed $payload, array &$errors): self
    {
        return self::parse($payload, 'billing', true, $errors);
    }

    /** @param array<string, string> $errors */
    public static function forShipping(mixed $payload, array &$errors): self
    {
        return self::parse($payload, 'shipping', false, $errors);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @param array<string, string> $errors */
    private static function parse(mixed $payload, string $prefix, bool $allowEmail, array &$errors): self
    {
        if (!is_array($payload)) {
            $errors[$prefix] = 'Must be an object of address fields.';

            return new self([]);
        }

        $allowed = $allowEmail ? [...self::FIELDS, ...self::BILLING_ONLY] : self::FIELDS;
        $clean = [];

        foreach (array_diff(array_keys($payload), $allowed) as $field) {
            // `email` on a shipping address is named specifically: it is a
            // plausible mistake, not a typo, and "Unknown field" would send
            // someone looking for a spelling error that is not there.
            $errors["{$prefix}." . (string) $field] = $field === 'email'
                ? 'Only a billing address carries an email.'
                : 'Unknown field.';
        }

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            // '' clears a stored address field, so it is a valid value.
            if ($payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors["{$prefix}.{$field}"] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $payload[$field]);

            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors["{$prefix}.{$field}"] = 'Must be at most ' . self::MAX_LENGTH . ' characters.';
                continue;
            }

            $clean[$field] = $value;
        }

        self::validateEmail($clean, $prefix, $errors);
        self::validateCountry($clean, $prefix, $errors);

        return new self($clean);
    }

    /**
     * @param array<string, string> $clean
     * @param array<string, string> $errors
     */
    private static function validateEmail(array &$clean, string $prefix, array &$errors): void
    {
        if (($clean['email'] ?? '') === '') {
            return;
        }

        // filter_var rather than is_email(): this class must stay loadable
        // without WordPress. The two disagree only on addresses neither a
        // customer nor a courier will ever use.
        if (filter_var($clean['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors["{$prefix}.email"] = 'Must be a valid email address.';
            unset($clean['email']);
        }
    }

    /**
     * @param array<string, string> $clean
     * @param array<string, string> $errors
     */
    private static function validateCountry(array &$clean, string $prefix, array &$errors): void
    {
        if (!array_key_exists('country', $clean) || $clean['country'] === '') {
            return;
        }

        $country = strtoupper($clean['country']);

        // ISO 3166-1 alpha-2 shape only. Checking membership of the real list
        // means WC()->countries, which is WordPress; the shape check catches
        // the mistake that actually happens, a country *name* where a code
        // belongs ("Algeria" instead of "DZ"), which WooCommerce would store
        // and then fail to resolve for shipping.
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $errors["{$prefix}.country"] = 'Must be a two-letter ISO country code, such as DZ.';

            return;
        }

        $clean['country'] = $country;
    }
}
