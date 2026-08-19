<?php

declare(strict_types=1);

namespace AlgerianCommerce\Coupons;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a coupon write — docs/PLAN.md §21, roadmap step 33.
 *
 * Pure, like every other `*Input`, so each rule is a unit test rather than
 * something discovered against a live discount.
 *
 * **PLAN §21 asks for ten things and WooCommerce supplies nine.** The tenth is
 * "maximum discount where supported", and the honest answer is that WooCommerce
 * does not support it: `WC_Coupon` has `maximum_amount`, which is a ceiling on
 * the *cart total the coupon may be used against*, not a cap on the discount it
 * produces. A 50% coupon on a 100,000 DZD cart discounts 50,000 and there is no
 * field that would stop it. The two are named apart here — `maximum_amount` is
 * exposed under its real meaning and `maximum_discount` is refused by name — so
 * that a shop owner cannot set one believing they set the other. §21's own
 * "where supported" is what licenses this; capping a percentage discount in our
 * own code would mean recomputing WooCommerce's discount after it applied one,
 * which is the fork this project does not do.
 */
final class CouponInput
{
    /** WooCommerce's own three; there is no fourth. */
    public const TYPES = ['percent', 'fixed_cart', 'fixed_product'];

    public const STATUSES = ['publish', 'draft'];

    /** A code is a thing people read down a phone line. */
    public const MAX_CODE = 64;

    /**
     * Fields the API emits on read but never accepts on write.
     *
     * Dropped silently rather than rejected so the GET → edit → PATCH round
     * trip works, exactly as `Products\ProductInput` does it.
     */
    private const READ_ONLY = [
        'id', 'usage_count', 'used_by', 'date_created', 'date_modified',
        /*
         * The resolved names beside the four id arrays. Dropped, not refused —
         * `tests/Api/coupons.php` asserts that the whole GET body PATCHes back, and
         * it failed the moment `restrictions` started being emitted. The ids remain
         * the writable form; this is their rendering.
         */
        'restrictions',
    ];

    /** @var array<string, string> */
    private const REJECTED = [
        'maximum_discount' => 'WooCommerce caps the cart a coupon may be used on (maximum_amount), not the discount itself.',
        'max_discount' => 'WooCommerce caps the cart a coupon may be used on (maximum_amount), not the discount itself.',
        'virtual' => 'Not a coupon field this API sets.',
    ];

    private const BOOL_FIELDS = ['individual_use', 'free_shipping', 'exclude_sale_items'];
    private const MONEY_FIELDS = ['amount', 'minimum_amount', 'maximum_amount'];
    private const INT_LIST_FIELDS = [
        'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories',
    ];
    private const LIMIT_FIELDS = ['usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items'];

    /** @param array<string, mixed> $fields */
    private function __construct(private readonly array $fields)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException 400 naming every bad field, not just the first
     */
    public static function fromPayload(array $payload, bool $creating): self
    {
        $errors = [];

        foreach (self::REJECTED as $field => $why) {
            if (array_key_exists($field, $payload)) {
                $errors[$field] = $why;
            }
        }

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        $known = array_merge(
            ['code', 'discount_type', 'status', 'description', 'date_expires', 'email_restrictions'],
            self::BOOL_FIELDS,
            self::MONEY_FIELDS,
            self::INT_LIST_FIELDS,
            self::LIMIT_FIELDS
        );

        foreach (array_keys($payload) as $field) {
            if (!in_array($field, $known, true) && !isset(self::REJECTED[$field])) {
                $errors[$field] = 'Unknown field.';
            }
        }

        $clean = [];

        self::code($payload, $clean, $errors, $creating);
        self::type($payload, $clean, $errors, $creating);
        self::money($payload, $clean, $errors, $creating);
        self::limits($payload, $clean, $errors);
        self::lists($payload, $clean, $errors);
        self::flags($payload, $clean);
        self::expiry($payload, $clean, $errors);
        self::emails($payload, $clean, $errors);

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? (string) $payload['status'] : '';

            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', self::STATUSES) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        if (array_key_exists('description', $payload)) {
            $clean['description'] = is_scalar($payload['description'])
                ? trim((string) $payload['description'])
                : '';
        }

        // A percentage over 100 is a shop paying its customers to shop. Checked
        // across two fields, so it also catches a PATCH that only sends the
        // amount against a coupon that is already a percentage.
        if (
            $errors === []
            && ($clean['discount_type'] ?? '') === 'percent'
            && isset($clean['amount'])
            && (float) $clean['amount'] > 100
        ) {
            $errors['amount'] = 'A percentage discount cannot exceed 100.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The coupon is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function code(array $payload, array &$clean, array &$errors, bool $creating): void
    {
        if (!array_key_exists('code', $payload)) {
            if ($creating) {
                $errors['code'] = 'A coupon needs a code.';
            }

            return;
        }

        $code = is_scalar($payload['code']) ? trim((string) $payload['code']) : '';

        if ($code === '') {
            $errors['code'] = 'A coupon needs a code.';

            return;
        }

        if (mb_strlen($code) > self::MAX_CODE) {
            $errors['code'] = 'At most ' . self::MAX_CODE . ' characters.';

            return;
        }

        // WooCommerce lower-cases every code it stores, so a shop that creates
        // `SUMMER10` and searches for `SUMMER10` would otherwise find nothing.
        // Normalising here means one spelling reaches the database and the
        // response says which one it was.
        $clean['code'] = strtolower($code);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function type(array $payload, array &$clean, array &$errors, bool $creating): void
    {
        if (!array_key_exists('discount_type', $payload)) {
            if ($creating) {
                $clean['discount_type'] = 'fixed_cart';
            }

            return;
        }

        $type = is_scalar($payload['discount_type']) ? (string) $payload['discount_type'] : '';

        if (!in_array($type, self::TYPES, true)) {
            $errors['discount_type'] = 'Must be one of: ' . implode(', ', self::TYPES) . '.';

            return;
        }

        $clean['discount_type'] = $type;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function money(array $payload, array &$clean, array &$errors, bool $creating): void
    {
        foreach (self::MONEY_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                if ($field === 'amount' && $creating) {
                    $errors['amount'] = 'A coupon needs an amount.';
                }

                continue;
            }

            $raw = $payload[$field];

            // An empty string or null clears a threshold, which is how a shop
            // removes a minimum spend — and how a body this API emitted round-
            // trips, since `CouponPresenter` reports an absent threshold as null.
            // `amount` is not clearable: a coupon that discounts nothing is a
            // coupon that should be unpublished.
            if ($raw === '' || $raw === null) {
                if ($field === 'amount') {
                    $errors['amount'] = 'A coupon needs an amount.';
                } else {
                    $clean[$field] = '';
                }

                continue;
            }

            if (!is_numeric($raw)) {
                $errors[$field] = 'Must be a number.';

                continue;
            }

            /*
             * **Negative is refused before zero clears.**
             *
             * This check used to be unreachable for a threshold: the clearing arm
             * above read `<= 0.0`, so `{"minimum_amount": "-1"}` answered 200 and
             * silently erased a minimum spend of 15 000 DA. Measured against the
             * live shop while building the admin panel — a threshold of 100.00 came
             * back null, and nothing anywhere said so. A negative amount was
             * refused by name the whole time, which made the two fields disagree
             * about what a minus sign means.
             *
             * A typo must not be a destructive write. Clearing stays expressible
             * three ways: `null`, `""` and `0`.
             */
            if ((float) $raw < 0) {
                $errors[$field] = 'Must not be negative.';

                continue;
            }

            if ($field !== 'amount' && (float) $raw === 0.0) {
                $clean[$field] = '';

                continue;
            }

            // Kept as a string end to end, as every other money value in this
            // API is, so nothing picks up binary-floating-point rounding.
            $clean[$field] = (string) $raw;
        }

        /*
         * Both must be *present and non-empty* to be comparable. An empty
         * maximum means "no ceiling", and treating it as 0 made every coupon
         * with a minimum spend and no maximum fail — including the exact body
         * this API had just emitted, which broke the GET → edit → PATCH round
         * trip the read shape exists to support. Found by tests/Api/coupons.php.
         */
        $min = $clean['minimum_amount'] ?? '';
        $max = $clean['maximum_amount'] ?? '';

        if (
            !isset($errors['minimum_amount'], $errors['maximum_amount'])
            && $min !== '' && $max !== ''
            && (float) $min > (float) $max
        ) {
            $errors['minimum_amount'] = 'Must not be greater than maximum_amount.';
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function limits(array $payload, array &$clean, array &$errors): void
    {
        foreach (self::LIMIT_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $raw = $payload[$field];

            // null clears the limit — "unlimited" has to be expressible, or a
            // shop can never undo a usage cap it set by mistake.
            if ($raw === null || $raw === '') {
                $clean[$field] = null;

                continue;
            }

            if (!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
                $errors[$field] = 'Must be a whole number, or null for unlimited.';

                continue;
            }

            $value = (int) $raw;

            if ($value < 1) {
                $errors[$field] = 'Must be at least 1, or null for unlimited.';

                continue;
            }

            $clean[$field] = $value;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function lists(array $payload, array &$clean, array &$errors): void
    {
        foreach (self::INT_LIST_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $raw = $payload[$field];

            if (!is_array($raw)) {
                $errors[$field] = 'Must be an array of ids.';

                continue;
            }

            $ids = [];

            foreach ($raw as $value) {
                if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
                    $errors[$field] = 'Every id must be a positive whole number.';

                    continue 2;
                }

                $id = (int) $value;

                if ($id < 1) {
                    $errors[$field] = 'Every id must be a positive whole number.';

                    continue 2;
                }

                $ids[] = $id;
            }

            $clean[$field] = array_values(array_unique($ids));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     */
    private static function flags(array $payload, array &$clean): void
    {
        foreach (self::BOOL_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $clean[$field] = filter_var($payload[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function expiry(array $payload, array &$clean, array &$errors): void
    {
        if (!array_key_exists('date_expires', $payload)) {
            return;
        }

        $raw = $payload['date_expires'];

        if ($raw === null || $raw === '') {
            $clean['date_expires'] = null;

            return;
        }

        if (!is_string($raw)) {
            $errors['date_expires'] = 'Must be Y-m-d, or null for no expiry.';

            return;
        }

        /*
         * **The read shape has to be writable.** `CouponPresenter` emits dates
         * as ISO 8601 (`2027-01-31T00:00:00+00:00`), which is right for a
         * client, and a `Y-m-d`-only rule refused the very body this API had
         * just produced — so GET → edit → PATCH failed on a field nobody had
         * touched. Accepting the datetime and keeping the day is what makes the
         * round trip work; the time is discarded because WooCommerce expires a
         * coupon at the end of its day, not at an instant.
         */
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T[\d:]{8}/', $raw, $matches) === 1) {
            $raw = $matches[1];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            $errors['date_expires'] = 'Must be Y-m-d, or null for no expiry.';

            return;
        }

        // createFromFormat accepts 2026-02-31 and rolls it into March, so the
        // round trip is the check — the same guard AnalyticsRange uses.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            $errors['date_expires'] = 'Must be a real date in Y-m-d form.';

            return;
        }

        $clean['date_expires'] = $raw;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function emails(array $payload, array &$clean, array &$errors): void
    {
        if (!array_key_exists('email_restrictions', $payload)) {
            return;
        }

        $raw = $payload['email_restrictions'];

        if ($raw === null || $raw === '') {
            $clean['email_restrictions'] = [];

            return;
        }

        if (!is_array($raw)) {
            $errors['email_restrictions'] = 'Must be an array of email addresses.';

            return;
        }

        $emails = [];

        foreach ($raw as $value) {
            $email = is_scalar($value) ? strtolower(trim((string) $value)) : '';

            // WooCommerce allows a wildcard such as *@example.test here, so the
            // check has to admit one; anything else must be a real address.
            $wildcard = $email !== '' && str_contains($email, '*');

            if (!$wildcard && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email_restrictions'] = 'Every entry must be an email address or a wildcard.';

                return;
            }

            $emails[] = $email;
        }

        $clean['email_restrictions'] = array_values(array_unique($emails));
    }
}
