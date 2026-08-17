<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\API\ApiException;

/**
 * A saved audience definition, validated — roadmap §85.
 *
 * Pure — no WordPress, no database — so every rule about what a shop may ask for
 * is a unit test, and so `AudienceResolver` can assume every value it receives is
 * already the right type and inside its bounds. That is what lets the resolver's
 * SQL be short.
 *
 * **A segment is a stored query, not a stored membership list.** "Customers in
 * Alger who ordered in the last 90 days" is a definition; materialising it means
 * it is wrong the next day. This class is that definition's shape.
 *
 * ## The criteria, and where each one comes from
 *
 * ```
 * min_spent / max_spent      SUM of an order's totals, completed orders only
 * min_orders / max_orders    COUNT of orders in a revenue status
 * ordered_after / _before    MAX(date_created_gmt), Y-m-d
 * registered_after / _before the WordPress user's registration date, Y-m-d
 * wilaya_id                  from the SHIPMENT, never the address
 * bought_product_id          an order line item's product id
 * not_bought_product_id      …and its negation, which is a different question
 * ```
 *
 * **`wilaya_id` comes off the shipment and never off the address.**
 * `Shipping\ShipmentInput` refuses to fuzzy-match a commune name and §63 refused
 * to guess a wilaya out of an order's free-text `state`; a segment that made that
 * guess would mail the wrong province with a "free delivery in Oran" offer. An
 * order nobody has shipped therefore has no wilaya and cannot match — stated in
 * `AudienceResolver`, not hidden.
 *
 * **Money arrives as a decimal string**, as everywhere else in this API, and is
 * compared in the same currency the shop is now trading in. §63 found 890 orders
 * on this install recorded in `USD` from before anyone set `DZD`, so a sum that
 * ignored currency would put a customer in a "spent over 50,000" segment on the
 * strength of dollars.
 *
 * ## Refusals, each by name with the reason — the `CustomerInput` device
 *
 * The refused list is the interesting half. `email` and `email_contains` are
 * refused because a segment is not a search box: an admin who can name an address
 * can mail it, and a criterion that filters on it turns the segment resolver into
 * "does this address shop here", which is the enumeration oracle
 * `PasswordResetService` exists to avoid. `consent` is refused because consent is
 * **not a criterion** — it is applied by the resolver to every audience, and a
 * criterion that could set it would be a criterion that could switch it off.
 */
final class SegmentCriteria
{
    /**
     * Every criterion this version understands.
     *
     * @var array<string, string> field => type
     */
    public const FIELDS = [
        'min_spent' => 'money',
        'max_spent' => 'money',
        'min_orders' => 'int',
        'max_orders' => 'int',
        'ordered_after' => 'date',
        'ordered_before' => 'date',
        'registered_after' => 'date',
        'registered_before' => 'date',
        'wilaya_id' => 'int',
        'bought_product_id' => 'int',
        'not_bought_product_id' => 'int',
    ];

    /**
     * Refused by name, with the reason.
     *
     * @var array<string, string>
     */
    public const REFUSED = [
        'consent' => 'Consent is applied to every audience by the resolver and is never a criterion — a criterion that could set it could switch it off.',
        'marketing_consent' => 'Consent is applied to every audience by the resolver and is never a criterion.',
        'email' => 'A segment is not a search box. Mail one customer from their own record, not from an audience definition.',
        'email_contains' => 'A criterion on an address makes the resolver answer "does this address shop here", which is an enumeration oracle.',
        'role' => 'Only customers are ever in an audience; a role filter would let a campaign reach staff accounts.',
        'sql' => 'No.',
        'limit' => 'A segment is a definition, not a page of results. A campaign sends to everyone the definition matches.',
        'commune_id' => 'A shipment records a commune, but a commune-level audience is a handful of people and a definition that is wrong the moment one moves. Use wilaya_id.',
    ];

    /** Bounds. A segment asking for a million orders is a typo, not a query. */
    public const MAX_ORDERS = 100_000;

    public const MAX_SPENT = '99999999.99';

    /** @param array<string, int|string> $fields validated, typed values only */
    private function __construct(public readonly array $fields)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException 400 listing every bad field at once
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];
        $fields = [];

        foreach (self::REFUSED as $field => $why) {
            if (array_key_exists($field, $payload)) {
                $errors[$field] = $why;
            }
        }

        foreach ($payload as $field => $value) {
            $field = (string) $field;

            if (isset(self::REFUSED[$field])) {
                continue;
            }

            if (!isset(self::FIELDS[$field])) {
                $errors[$field] = 'Unknown criterion. Supported: ' . implode(', ', array_keys(self::FIELDS)) . '.';

                continue;
            }

            // An explicitly null criterion is how a client clears one, and is
            // not an error — it simply does not join the document.
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $parsed = self::parse($field, self::FIELDS[$field], $value, $errors);

            if ($parsed !== null) {
                $fields[$field] = $parsed;
            }
        }

        self::checkRanges($fields, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The segment criteria are invalid.', ['fields' => $errors]);
        }

        return new self($fields);
    }

    /**
     * The stored document, read back leniently.
     *
     * Lenient where `fromPayload()` is strict, which is the pair `OptionSet` (§83)
     * and `HomepageSections` (§61) both establish: a criterion that stopped being
     * valid — a product that was deleted, a field removed in a later version —
     * must not make an existing segment unreadable, because a segment that cannot
     * be read cannot be *fixed*. What it must not do is silently become "everyone":
     * `problems` reports what was dropped and `AudienceResolver` refuses to resolve
     * a segment whose document lost every criterion it had.
     *
     * @param array<string, mixed> $stored
     * @return array{criteria: self, problems: list<string>}
     */
    public static function fromStored(array $stored): array
    {
        $problems = [];
        $fields = [];

        foreach ($stored as $field => $value) {
            $field = (string) $field;

            if (!isset(self::FIELDS[$field])) {
                $problems[] = "Dropped unknown criterion \"{$field}\".";

                continue;
            }

            $errors = [];
            $parsed = self::parse($field, self::FIELDS[$field], $value, $errors);

            if ($parsed === null || $errors !== []) {
                $problems[] = "Dropped unusable criterion \"{$field}\".";

                continue;
            }

            $fields[$field] = $parsed;
        }

        return ['criteria' => new self($fields), 'problems' => $problems];
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): int|string|null
    {
        return $this->fields[$field] ?? null;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return $this->fields;
    }

    /** @param array<string, string> $errors */
    private static function parse(string $field, string $type, mixed $value, array &$errors): int|string|null
    {
        if (!is_scalar($value)) {
            $errors[$field] = 'Must be a number, a date or a decimal string.';

            return null;
        }

        $raw = trim((string) $value);

        switch ($type) {
            case 'int':
                if (!ctype_digit($raw)) {
                    $errors[$field] = 'Must be a whole number.';

                    return null;
                }

                $number = (int) $raw;

                if ($number < 0 || ($field === 'min_orders' || $field === 'max_orders') && $number > self::MAX_ORDERS) {
                    $errors[$field] = 'Out of range.';

                    return null;
                }

                return $number;

            case 'money':
                if (preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
                    $errors[$field] = 'Must be a decimal amount, e.g. "5000.00".';

                    return null;
                }

                if ((float) $raw > (float) self::MAX_SPENT) {
                    $errors[$field] = 'Out of range.';

                    return null;
                }

                return $raw;

            case 'date':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
                    $errors[$field] = 'Must be Y-m-d.';

                    return null;
                }

                // Real calendar dates only: 2026-02-31 parses as a pattern and
                // would silently become March in MySQL's comparison.
                [$y, $m, $d] = array_map('intval', explode('-', $raw));

                if (!checkdate($m, $d, $y)) {
                    $errors[$field] = 'Not a real date.';

                    return null;
                }

                return $raw;
        }

        return null;
    }

    /**
     * Ranges that cannot both be true.
     *
     * Refused rather than resolved to an empty audience, because an inverted
     * range is a typo in an admin form and an audience of nobody looks exactly
     * like a segment whose customers have not shopped yet.
     *
     * @param array<string, int|string> $fields
     * @param array<string, string> $errors
     */
    private static function checkRanges(array $fields, array &$errors): void
    {
        if (isset($fields['min_spent'], $fields['max_spent'])
            && (float) $fields['min_spent'] > (float) $fields['max_spent']
        ) {
            $errors['max_spent'] = 'Must be at least min_spent.';
        }

        if (isset($fields['min_orders'], $fields['max_orders'])
            && (int) $fields['min_orders'] > (int) $fields['max_orders']
        ) {
            $errors['max_orders'] = 'Must be at least min_orders.';
        }

        foreach ([['ordered_after', 'ordered_before'], ['registered_after', 'registered_before']] as [$after, $before]) {
            if (isset($fields[$after], $fields[$before]) && $fields[$after] > $fields[$before]) {
                $errors[$before] = "Must not be earlier than {$after}.";
            }
        }

        if (isset($fields['bought_product_id'], $fields['not_bought_product_id'])
            && $fields['bought_product_id'] === $fields['not_bought_product_id']
        ) {
            $errors['not_bought_product_id'] = 'Cannot be the same product as bought_product_id.';
        }
    }
}
