<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Chargily;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Payments\PaymentStatus;

/**
 * Chargily's checkout states, in our vocabulary — roadmap §59.
 *
 * Pure — no WordPress, no HTTP — so the one translation that decides whether an
 * order counts as paid is unit-testable on its own. The counterpart of
 * `ZRExpressStateMap`, and strict for the same reason: **an unmapped state
 * raises rather than defaulting to anything.** A gateway that invents a sixth
 * word must not have it quietly read as `pending`, which would leave a settled
 * order looking unpaid, nor as `paid`, which would ship goods against nothing.
 *
 * Their reference lists five: `pending`, `processing`, `paid`, `failed`,
 * `canceled`.
 *
 * **`processing` is ours to call `pending`, and that is a decision about money
 * rather than a translation.** It means Chargily has the customer at the bank
 * and does not yet know the answer. The shop's question is only ever "has the
 * money arrived", and until that is yes it is no.
 *
 * `expired` is a sixth, and **the reference does not list it** — it was mapped
 * on the strength of Chargily's own WooCommerce plugin having a branch for the
 * string, then **verified against the live test API on 2026-08-15**:
 * `POST checkouts/{id}/expire` followed by a read-back answers `"status":
 * "expired"`. The documented enum is simply incomplete. Leaving it out would
 * have meant a real shop raising `provider_status_unknown` on an ordinary
 * abandoned basket.
 */
final class ChargilyStatusMap
{
    /** @var array<string, string> */
    private const MAP = [
        'pending' => PaymentStatus::PENDING,
        'processing' => PaymentStatus::PENDING,
        'paid' => PaymentStatus::PAID,
        'failed' => PaymentStatus::FAILED,
        // Chargily spells it with one L. Both are accepted because this is one
        // state written two ways, not two states — a gateway tidying its own
        // spelling must not stop a shop taking money.
        'canceled' => PaymentStatus::CANCELLED,
        'cancelled' => PaymentStatus::CANCELLED,
        // Undocumented, and real — verified live 2026-08-15. See the docblock.
        'expired' => PaymentStatus::EXPIRED,
    ];

    private function __construct()
    {
    }

    /**
     * @throws ApiException when Chargily reports a state this adapter has never
     *                      been told about
     */
    public static function toPaymentStatus(string $status): string
    {
        $key = strtolower(trim($status));

        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }

        throw new ApiException(
            'provider_status_unknown',
            'Chargily reported a payment state this store does not recognise.',
            502,
            ['provider' => ChargilyProvider::NAME, 'provider_status' => $key]
        );
    }

    public static function isKnown(string $status): bool
    {
        return isset(self::MAP[strtolower(trim($status))]);
    }

    /** @return list<string> */
    public static function known(): array
    {
        return array_keys(self::MAP);
    }
}
