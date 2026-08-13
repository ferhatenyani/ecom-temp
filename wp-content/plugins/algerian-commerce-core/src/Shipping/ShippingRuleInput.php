<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a write to a shipping rate rule.
 *
 * Pure — no WordPress. Unknown fields are rejected (docs/SECURITY.md), and the
 * fields the API emits but does not accept are dropped, so a client can GET a
 * rule, change the price and PATCH the whole object back.
 *
 * A create needs an amount; a patch changes only what it names. That is the one
 * real difference between the two, and it is why `forUpdate()` keeps track of
 * which fields were actually present rather than filling the rest with
 * defaults — a PATCH that omitted `free_over` must not silently delete a
 * threshold a shop is relying on.
 *
 * Money arrives as a string and stays one. Accepting a float here would take a
 * price that a shop typed correctly and store it as something 0.000001 away.
 */
final class ShippingRuleInput
{
    /** Emitted by ShippingRule::toArray(), never accepted. */
    private const READ_ONLY = ['id', 'specificity', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const FIELDS = [
        'provider', 'wilaya_id', 'commune_id', 'delivery_type',
        'amount', 'free_over', 'estimated_days', 'is_active',
    ];

    /** Nine million dinars of delivery is a typo, not a tariff. */
    private const MAX_AMOUNT = 9999999.99;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        return new self(self::normalize($payload, true));
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        return new self(self::normalize($payload, false));
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
     * Apply this input to a rule, keeping whatever it did not mention.
     *
     * The merge lives here rather than in the service because it is the other
     * half of validation: which fields a PATCH leaves alone is part of what the
     * payload means.
     */
    public function applyTo(ShippingRule $rule, string $now): ShippingRule
    {
        return new ShippingRule(
            $this->has('wilaya_id') ? (int) $this->get('wilaya_id') : $rule->wilayaId,
            $this->has('commune_id') ? (int) $this->get('commune_id') : $rule->communeId,
            $this->has('amount') ? (string) $this->get('amount') : $rule->amount,
            $this->has('provider') ? (string) $this->get('provider') : $rule->provider,
            $this->has('delivery_type') ? (string) $this->get('delivery_type') : $rule->deliveryType,
            $this->has('free_over') ? $this->nullableString('free_over') : $rule->freeOver,
            $this->has('estimated_days') ? $this->nullableInt('estimated_days') : $rule->estimatedDays,
            $this->has('is_active') ? (bool) $this->get('is_active') : $rule->isActive,
            $rule->createdAt,
            $now,
            $rule->id
        );
    }

    public function toRule(string $now): ShippingRule
    {
        return new ShippingRule(
            (int) ($this->get('wilaya_id') ?? 0),
            (int) ($this->get('commune_id') ?? 0),
            (string) $this->get('amount'),
            (string) ($this->get('provider') ?? ''),
            (string) ($this->get('delivery_type') ?? ''),
            $this->nullableString('free_over'),
            $this->nullableInt('estimated_days'),
            $this->has('is_active') ? (bool) $this->get('is_active') : true,
            $now,
            $now
        );
    }

    private function nullableString(string $field): ?string
    {
        $value = $this->get($field);

        return $value === null ? null : (string) $value;
    }

    private function nullableInt(string $field): ?int
    {
        $value = $this->get($field);

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ApiException with a per-field breakdown in error.details.fields
     */
    private static function normalize(array $payload, bool $isCreate): array
    {
        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        $errors = [];
        $clean = [];

        foreach (array_diff(array_keys($payload), self::FIELDS) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        foreach (['wilaya_id', 'commune_id'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            // 0 is a value, not a gap: it is how a rule says "anywhere".
            if (!is_numeric($payload[$field]) || (int) $payload[$field] < 0) {
                $errors[$field] = 'Must be an id, or 0 for any.';
                continue;
            }

            $clean[$field] = (int) $payload[$field];
        }

        if (array_key_exists('provider', $payload)) {
            if ($payload['provider'] !== null && !is_scalar($payload['provider'])) {
                $errors['provider'] = 'Must be a string, or empty for any provider.';
            } else {
                $clean['provider'] = strtolower(trim((string) ($payload['provider'] ?? '')));
            }
        }

        if (array_key_exists('delivery_type', $payload)) {
            $type = is_scalar($payload['delivery_type'] ?? null)
                ? strtolower(trim((string) $payload['delivery_type']))
                : '';

            if ($type !== '' && !Destination::isKnownDeliveryType($type)) {
                $errors['delivery_type'] = 'Must be one of: ' . implode(', ', Destination::DELIVERY_TYPES)
                    . ', or empty for any.';
            } else {
                $clean['delivery_type'] = $type;
            }
        }

        if (array_key_exists('amount', $payload)) {
            $amount = self::money($payload['amount'], 'amount', $errors);

            if ($amount !== null) {
                $clean['amount'] = $amount;
            }
        } elseif ($isCreate) {
            $errors['amount'] = 'Required.';
        }

        if (array_key_exists('free_over', $payload)) {
            // Explicit null clears the threshold, which is the only way to say
            // "this rule no longer offers free delivery".
            $clean['free_over'] = $payload['free_over'] === null
                ? null
                : self::money($payload['free_over'], 'free_over', $errors);
        }

        if (array_key_exists('estimated_days', $payload)) {
            if ($payload['estimated_days'] === null) {
                $clean['estimated_days'] = null;
            } elseif (!is_numeric($payload['estimated_days'])
                || (int) $payload['estimated_days'] < 0
                || (int) $payload['estimated_days'] > 365) {
                $errors['estimated_days'] = 'Must be a number of days, or null.';
            } else {
                $clean['estimated_days'] = (int) $payload['estimated_days'];
            }
        }

        if (array_key_exists('is_active', $payload)) {
            if (!is_bool($payload['is_active'])) {
                $errors['is_active'] = 'Must be true or false.';
            } else {
                $clean['is_active'] = $payload['is_active'];
            }
        }

        self::guardCommuneHasItsWilaya($clean, $isCreate, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The shipping rule is invalid.', ['fields' => $errors]);
        }

        return $clean;
    }

    /**
     * A commune belongs to exactly one wilaya, so a rule naming a commune and
     * no wilaya describes a place nothing can match — and it would sit in the
     * table looking as though it worked.
     *
     * Only checked on a create: a PATCH that names a commune alone is applied
     * on top of a rule that already has a wilaya, and ShippingRule's own
     * constructor is the backstop if the result is still incoherent.
     *
     * @param array<string, mixed> $clean
     * @param array<string, string> $errors
     */
    private static function guardCommuneHasItsWilaya(array $clean, bool $isCreate, array &$errors): void
    {
        if (!$isCreate) {
            return;
        }

        if (($clean['commune_id'] ?? 0) > 0 && ($clean['wilaya_id'] ?? 0) === 0) {
            $errors['wilaya_id'] = 'Required when the rule names a commune.';
        }
    }

    /**
     * A money value as a decimal string.
     *
     * Accepted as a string or a number and stored as a string: a shop's price
     * list is typed by a person, and the string they typed is the one that
     * should end up in the table.
     *
     * @param array<string, string> $errors
     */
    private static function money(mixed $value, string $field, array &$errors): ?string
    {
        if (!is_scalar($value) || is_bool($value) || !is_numeric($value)) {
            $errors[$field] = 'Must be an amount.';

            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            $errors[$field] = 'Cannot be negative.';

            return null;
        }

        if ($amount > self::MAX_AMOUNT) {
            $errors[$field] = 'Is implausibly large.';

            return null;
        }

        return number_format($amount, 2, '.', '');
    }
}
