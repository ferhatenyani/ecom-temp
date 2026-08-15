<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;

/**
 * What a caller may say when starting a payment, validated.
 *
 * Pure — no WordPress. The amount is deliberately **not** here: it comes from
 * the order, server-side, and a client that could name its own would be a client
 * that could pay 45 DZD for a 45,000 DZD basket. The same reasoning as
 * docs/SECURITY.md's re-check on the way back, applied on the way out.
 *
 * Unknown fields are rejected rather than ignored, as every other input object
 * in this codebase does (docs/SECURITY.md, "Input and output"). On a payment
 * endpoint that matters more than most: a misspelled field that is silently
 * dropped is how a caller believes it set something it did not.
 */
final class PaymentInput
{
    /** @var list<string> */
    public const ALLOWED = ['provider', 'reference', 'description', 'return_url'];

    public const MAX_REFERENCE = 64;
    public const MAX_DESCRIPTION = 255;

    private function __construct(
        public readonly string $provider,
        public readonly string $reference,
        public readonly string $description,
        public readonly string $returnUrl
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ApiException
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), self::ALLOWED) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        $string = static function (string $key, int $max) use ($payload, &$errors): string {
            if (!array_key_exists($key, $payload)) {
                return '';
            }

            if (!is_scalar($payload[$key])) {
                $errors[$key] = 'Must be a string.';

                return '';
            }

            $value = trim((string) $payload[$key]);

            if (mb_strlen($value) > $max) {
                $errors[$key] = "Must be at most {$max} characters.";
            }

            return $value;
        };

        $provider = $string('provider', 32);
        $reference = $string('reference', self::MAX_REFERENCE);
        $description = $string('description', self::MAX_DESCRIPTION);
        $returnUrl = $string('return_url', 2048);

        /*
         * https only, and validated as a URL at all.
         *
         * This value is handed to a provider, which redirects a customer's
         * browser to it after they have paid. An unchecked one makes this
         * endpoint an open redirect with a payment provider's credibility behind
         * it, and `http://` would send the shopper back over plaintext carrying
         * whatever the provider appended.
         */
        if ($returnUrl !== ''
            && (filter_var($returnUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($returnUrl, 'https://'))
        ) {
            $errors['return_url'] = 'Must be an https URL.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The payment data is invalid.', ['fields' => $errors]);
        }

        return new self(strtolower($provider), $reference, $description, $returnUrl);
    }
}
