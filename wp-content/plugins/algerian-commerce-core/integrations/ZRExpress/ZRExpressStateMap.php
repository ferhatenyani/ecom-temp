<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

use AlgerianCommerce\Shipping\ShipmentStatus;

/**
 * ZR Express parcel states, mapped onto ours — roadmap §57.
 *
 * Pure — no WordPress, no HTTP.
 *
 * **These are machine names, not labels.** `state.name` is a stable snake_case
 * identifier (`sortie_en_livraison`) with a separate human `description`
 * ("Sortie en livraison"). That makes this mapping far safer than Yalidine's,
 * where the only thing on offer was the wording a dashboard happened to use:
 * an identifier does not change because somebody fixed an accent.
 *
 * **The list is not known to be complete, and that is handled by refusing to
 * guess.** §57 records that the enumeration is undocumented and that the
 * reference implementation invents it with substring matching —
 * `contains("livre")`, `contains("retour")`, `contains("echec")` — which is the
 * same trap §56 documents: *échoué* appears in states that are not failures.
 * Every entry below was **observed on the live API on 2026-08-15**, across the
 * state histories of real parcels; anything else returns null and the adapter
 * raises it rather than filing it under something plausible.
 *
 * Two mappings worth their reasoning:
 *
 *  - `confirme_au_bureau` is `in_transit`, not `created`. It reads like an
 *    office confirmation, but in real histories it alternates with
 *    `vers_wilaya` for days — it is the parcel sitting in a hub between hops.
 *  - `recouvert` and `encaisse` are `delivered`. They are money states — the
 *    cash collected, then paid out to the merchant — and the parcel arrived in
 *    all three. COD reconciliation is a separate question (§52), and inventing
 *    shipment statuses for it would be exactly what PLAN §8 forbids.
 */
final class ZRExpressStateMap
{
    /**
     * Their machine name on the left, ours on the right; the comment is their
     * own description, kept so this table can be read against the dashboard.
     *
     * @var array<string, string>
     */
    public const STATES = [
        'commande_recue' => ShipmentStatus::CREATED,                    // Commande reçue
        'pret_a_expedier' => ShipmentStatus::CREATED,                   // Prêt à expédier
        'confirme_au_bureau' => ShipmentStatus::IN_TRANSIT,             // Confirmé au bureau
        'dispatch' => ShipmentStatus::IN_TRANSIT,                       // Dispatch dans la même wilaya
        'vers_wilaya' => ShipmentStatus::IN_TRANSIT,                    // Dispatch dans une autre wilaya
        'sortie_en_livraison' => ShipmentStatus::OUT_FOR_DELIVERY,      // Sortie en livraison
        'en_livraison' => ShipmentStatus::OUT_FOR_DELIVERY,             // En livraison
        'livre' => ShipmentStatus::DELIVERED,                           // Livré
        'recouvert' => ShipmentStatus::DELIVERED,                       // Recouvert
        'encaisse' => ShipmentStatus::DELIVERED,                        // Encaissé
        // The journey back, and its end. Verified on parcels carrying
        // isReturn: true, whose histories finish exactly this way.
        'attente_recuperation_fournisseur' => ShipmentStatus::RETURNING, // En attente récupération fournisseur
        'recupere_par_fournisseur' => ShipmentStatus::RETURNED,          // Récupéré par fournisseur
    ];

    /** Our status for one of their states, or null when we do not know it. */
    public static function toShipmentStatus(string $state): ?string
    {
        // Lower-cased and trimmed only. No accent folding and no hyphen
        // rewriting, because these are identifiers rather than prose — if one
        // arrives spelled differently, that is a new state and it should be
        // seen, not absorbed.
        return self::STATES[strtolower(trim($state))] ?? null;
    }

    public static function isKnown(string $state): bool
    {
        return self::toShipmentStatus($state) !== null;
    }
}
