<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;

/**
 * Validates the body of a create-shipment request.
 *
 * Pure — no WordPress — so every rule is unit-testable. Unknown fields are
 * rejected rather than ignored (docs/SECURITY.md).
 *
 * **The destination is required, by id.** A shipment could in principle be
 * addressed from the order's shipping address, and it deliberately is not: that
 * address holds `city` and `state` as free text, and turning "Ouled Fayet" into
 * the commune a courier will route on means fuzzy-matching a name that is
 * spelled several ways in two languages. Getting that wrong sends a parcel to
 * the wrong daira. The §51 dataset exists so an admin UI can offer the two
 * dropdowns that make this exact, and `/locations/wilayas/{id}/communes` is
 * already serving them.
 *
 * The recipient, phone and street line *are* optional: those come off the
 * order's shipping address when omitted, because they are what that address is
 * good at. The service fills them in — that is not validation's job.
 */
final class ShipmentInput
{
    public const MAX_TEXT = 200;
    public const MAX_NOTE = 500;

    /** @var list<string> */
    private const OPTIONAL_TEXT = ['recipient', 'phone', 'address'];

    private function __construct(
        public readonly string $provider,
        public readonly Destination $destination,
        public readonly string $recipient,
        public readonly string $phone,
        public readonly string $address,
        public readonly string $note
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        $allowed = ['provider', 'wilaya_id', 'commune_id', 'delivery_type', 'note', ...self::OPTIONAL_TEXT];

        foreach (array_diff(array_keys($payload), $allowed) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $wilayaId = self::requiredId($payload, 'wilaya_id', $errors);
        $communeId = self::requiredId($payload, 'commune_id', $errors);

        $deliveryType = Destination::HOME;

        if (array_key_exists('delivery_type', $payload)) {
            $candidate = is_scalar($payload['delivery_type'])
                ? strtolower(trim((string) $payload['delivery_type']))
                : '';

            if (!Destination::isKnownDeliveryType($candidate)) {
                $errors['delivery_type'] = 'Must be one of: ' . implode(', ', Destination::DELIVERY_TYPES) . '.';
            } else {
                $deliveryType = $candidate;
            }
        }

        $provider = '';

        if (array_key_exists('provider', $payload)) {
            if (!is_scalar($payload['provider'])) {
                $errors['provider'] = 'Must be a string.';
            } else {
                // Not checked against the registry here: which providers a shop
                // has configured is runtime state, and a pure validator that
                // knew it could not be tested without one.
                $provider = strtolower(trim((string) $payload['provider']));
            }
        }

        $text = [];

        foreach (self::OPTIONAL_TEXT as $field) {
            $text[$field] = self::text($payload, $field, self::MAX_TEXT, $errors);
        }

        $note = self::text($payload, 'note', self::MAX_NOTE, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The shipment data is invalid.', ['fields' => $errors]);
        }

        return new self(
            $provider,
            new Destination($wilayaId, $communeId, $deliveryType),
            $text['recipient'],
            $text['phone'],
            $text['address'],
            $note
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function requiredId(array $payload, string $field, array &$errors): int
    {
        if (!array_key_exists($field, $payload)) {
            $errors[$field] = 'Required.';

            return 0;
        }

        if (!is_numeric($payload[$field]) || (int) $payload[$field] < 1) {
            $errors[$field] = 'Must be a positive id.';

            return 0;
        }

        return (int) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors
     */
    private static function text(array $payload, string $field, int $limit, array &$errors): string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return '';
        }

        if (!is_scalar($payload[$field])) {
            $errors[$field] = 'Must be a string.';

            return '';
        }

        $value = trim((string) $payload[$field]);

        if (mb_strlen($value) > $limit) {
            $errors[$field] = "Must be at most {$limit} characters.";

            return '';
        }

        return $value;
    }
}
