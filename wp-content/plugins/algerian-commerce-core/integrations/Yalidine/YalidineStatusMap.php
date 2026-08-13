<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

use AlgerianCommerce\Geography\GeoSlug;
use AlgerianCommerce\Shipping\ShipmentStatus;

/**
 * Yalidine's 36 parcel states, mapped onto ours — roadmap §56.
 *
 * Pure — no WordPress, no HTTP — because this is the part of a courier
 * integration most likely to be quietly wrong, and the only part that can be
 * proven correct without an account. The list is the complete `last_status`
 * vocabulary as it appears in the merchant dashboard's filter.
 *
 * Three decisions worth keeping:
 *
 *  - **Whole labels, never substrings.** The reference implementation matches
 *    on `contains("échouée")` and `contains("retour")`, and walks straight into
 *    the two traps this table exists to avoid: *Tentative échouée* is a
 *    delivery attempt that failed and will be retried — the parcel is still
 *    with a driver, not lost — and *Bloqué* is a hold, not an ending. Neither
 *    is terminal, and both read as failure to a keyword match.
 *  - **Matched accent- and case-insensitively.** These labels are what the
 *    dashboard shows; the API may differ in accent or capitalisation, and
 *    nobody can check which without an account. Folding through `GeoSlug` —
 *    the codebase's one accent-folding table, already the geography importer's
 *    natural key — makes *Echèc livraison*, *Échec livraison* and *ECHEC
 *    LIVRAISON* the same word. The raw value is stored either way, in
 *    `StatusReport::$providerStatus`.
 *  - **No default.** An unrecognised label returns null and the adapter throws,
 *    because a `match` that falls through to `in_transit` is how every parcel in
 *    a newly added state reads as normal for a month (roadmap §53).
 *
 * ASSUMPTION (unverified — no merchant account, no sandbox): *Retour vers
 * vendeur* is the 36th label. Roadmap §56 lists 35 and calls them 36; the
 * reference implementation's own mapping comment names this one as well, and it
 * is the natural "on its way back to the seller" counterpart to the terminal
 * *Retourné au vendeur*. If the live API never emits it, the entry is inert.
 */
final class YalidineStatusMap
{
    /**
     * Their word on the left, ours on the right.
     *
     * Kept in the dashboard's own spelling so this table can be read against
     * the source it came from; the folding happens once, below.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        // Accepted, not yet moving.
        'Pas encore expédié' => ShipmentStatus::CREATED,
        'A vérifier' => ShipmentStatus::CREATED,
        'En préparation' => ShipmentStatus::CREATED,
        'Pas encore ramassé' => ShipmentStatus::CREATED,
        'Prêt à expédier' => ShipmentStatus::CREATED,
        'En passation' => ShipmentStatus::CREATED,

        'Ramassé' => ShipmentStatus::PICKED_UP,

        // Inside the network. `En attente`, `Bloqué` and `En alerte` are holds
        // — the parcel has stopped moving but has not stopped existing, and an
        // operator seeing "in transit" against them is being told the truth.
        'Transfert' => ShipmentStatus::IN_TRANSIT,
        'Expédié' => ShipmentStatus::IN_TRANSIT,
        'Centre' => ShipmentStatus::IN_TRANSIT,
        'En localisation' => ShipmentStatus::IN_TRANSIT,
        'Vers Wilaya' => ShipmentStatus::IN_TRANSIT,
        'En transit' => ShipmentStatus::IN_TRANSIT,
        'Reçu à Wilaya' => ShipmentStatus::IN_TRANSIT,
        'Prêt pour livreur' => ShipmentStatus::IN_TRANSIT,
        'En attente' => ShipmentStatus::IN_TRANSIT,
        'En attente du client' => ShipmentStatus::IN_TRANSIT,
        'Bloqué' => ShipmentStatus::IN_TRANSIT,
        'Débloqué' => ShipmentStatus::IN_TRANSIT,
        'En alerte' => ShipmentStatus::IN_TRANSIT,
        'Alerte résolue' => ShipmentStatus::IN_TRANSIT,

        // With a driver. A failed attempt is still with the driver: Yalidine
        // retries, and calling it a failure would end tracking on a parcel that
        // goes out again tomorrow.
        'Sorti en livraison' => ShipmentStatus::OUT_FOR_DELIVERY,
        'Tentative échouée' => ShipmentStatus::OUT_FOR_DELIVERY,

        // Coming back, not back yet — the state ShipmentStatus gained for this.
        'Retour vers centre' => ShipmentStatus::RETURNING,
        'Retourné au centre' => ShipmentStatus::RETURNING,
        'Retour transfert' => ShipmentStatus::RETURNING,
        'Retour groupé' => ShipmentStatus::RETURNING,
        'Retour à retirer' => ShipmentStatus::RETURNING,
        'Retour non retiré' => ShipmentStatus::RETURNING,
        'Retour vers vendeur' => ShipmentStatus::RETURNING,
        // Reads like an ending and is not: the delivery failed, so the parcel
        // starts its trip back. `Retourné au vendeur` is where that ends.
        'Echèc livraison' => ShipmentStatus::RETURNING,

        'Livré' => ShipmentStatus::DELIVERED,
        'Retourné au vendeur' => ShipmentStatus::RETURNED,
        'Annulé' => ShipmentStatus::CANCELLED,

        // Gone. Neither comes back and neither was delivered.
        'Colis abandonné' => ShipmentStatus::FAILED,
        'Echange échoué' => ShipmentStatus::FAILED,
    ];

    /** @var array<string, string>|null label folded to a key → our status */
    private static ?array $folded = null;

    /**
     * Our status for one of their labels, or null when we do not know it.
     *
     * Null rather than a fallback, on purpose — see the class docblock.
     */
    public static function toShipmentStatus(string $lastStatus): ?string
    {
        $key = GeoSlug::make($lastStatus);

        if ($key === '') {
            return null;
        }

        return self::folded()[$key] ?? null;
    }

    public static function isKnown(string $lastStatus): bool
    {
        return self::toShipmentStatus($lastStatus) !== null;
    }

    /** @return array<string, string> */
    private static function folded(): array
    {
        if (self::$folded !== null) {
            return self::$folded;
        }

        $folded = [];

        foreach (self::LABELS as $label => $status) {
            $folded[GeoSlug::make($label)] = $status;
        }

        return self::$folded = $folded;
    }
}
