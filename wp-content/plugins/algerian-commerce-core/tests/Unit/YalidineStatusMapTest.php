<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Integrations\Yalidine\YalidineStatusMap;
use AlgerianCommerce\Shipping\ShipmentStatus;
use PHPUnit\Framework\TestCase;

/**
 * The status mapping is the part of a courier integration most likely to be
 * quietly wrong, and — with no sandbox account behind roadmap §56 — the part
 * that can be proven right without one.
 */
final class YalidineStatusMapTest extends TestCase
{
    public function testEveryPublishedStatusIsMapped(): void
    {
        // The complete vocabulary of the merchant dashboard's filter.
        self::assertCount(36, YalidineStatusMap::LABELS);

        foreach (YalidineStatusMap::LABELS as $label => $expected) {
            self::assertSame(
                $expected,
                YalidineStatusMap::toShipmentStatus((string) $label),
                "{$label} must map to {$expected}"
            );
        }
    }

    public function testEveryMappedStatusIsOneOfOurs(): void
    {
        foreach (YalidineStatusMap::LABELS as $label => $status) {
            self::assertTrue(
                ShipmentStatus::isKnown($status),
                "{$label} maps to \"{$status}\", which is not a shipment status"
            );
        }
    }

    /**
     * The first of the two traps in the table. *Tentative échouée* is a
     * delivery attempt that failed and will be retried — the parcel is with a
     * driver, not lost — and a keyword match on "échouée" reads it as failure,
     * which is what the reference implementation does.
     */
    public function testAFailedAttemptIsStillOutForDelivery(): void
    {
        self::assertSame(
            ShipmentStatus::OUT_FOR_DELIVERY,
            YalidineStatusMap::toShipmentStatus('Tentative échouée')
        );
    }

    /** The second trap: a hold is not an ending. */
    public function testAHeldParcelIsStillInTransit(): void
    {
        self::assertSame(ShipmentStatus::IN_TRANSIT, YalidineStatusMap::toShipmentStatus('Bloqué'));
        self::assertSame(ShipmentStatus::IN_TRANSIT, YalidineStatusMap::toShipmentStatus('En alerte'));
        self::assertSame(ShipmentStatus::IN_TRANSIT, YalidineStatusMap::toShipmentStatus('En attente du client'));
    }

    /**
     * Seven states say the parcel is coming back and has not arrived. That is
     * why `returning` was added to the vocabulary at all — none of these may be
     * terminal, or tracking stops on a parcel still in the network.
     */
    public function testTheJourneyBackIsNotTheEndOfIt(): void
    {
        foreach ([
            'Retour vers centre',
            'Retourné au centre',
            'Retour transfert',
            'Retour groupé',
            'Retour à retirer',
            'Retour non retiré',
            'Echèc livraison',
        ] as $label) {
            self::assertSame(
                ShipmentStatus::RETURNING,
                YalidineStatusMap::toShipmentStatus($label),
                "{$label} must be returning"
            );
            self::assertFalse(ShipmentStatus::isTerminal(ShipmentStatus::RETURNING));
        }

        // And where it ends, which is terminal.
        self::assertSame(ShipmentStatus::RETURNED, YalidineStatusMap::toShipmentStatus('Retourné au vendeur'));
        self::assertTrue(ShipmentStatus::isTerminal(ShipmentStatus::RETURNED));
    }

    /**
     * These labels come from the dashboard, and nobody can check what the API
     * capitalises or accents without an account. Folding is what makes that
     * uncertainty harmless.
     */
    public function testAccentsAndCaseDoNotChangeTheAnswer(): void
    {
        foreach (['Livré', 'livre', 'LIVRÉ', ' Livré '] as $spelling) {
            self::assertSame(
                ShipmentStatus::DELIVERED,
                YalidineStatusMap::toShipmentStatus($spelling),
                "{$spelling} must still be delivered"
            );
        }

        // As the dashboard spells it, and as French would.
        self::assertSame(ShipmentStatus::RETURNING, YalidineStatusMap::toShipmentStatus('Echèc livraison'));
        self::assertSame(ShipmentStatus::RETURNING, YalidineStatusMap::toShipmentStatus('Échec livraison'));
    }

    /**
     * No default. A courier that adds a state has to be visible on the first
     * parcel that reaches it — not after a month of parcels reading as normal.
     */
    public function testAnUnknownStatusIsNotGuessedAt(): void
    {
        self::assertNull(YalidineStatusMap::toShipmentStatus('En route vers Mars'));
        self::assertNull(YalidineStatusMap::toShipmentStatus(''));
        self::assertNull(YalidineStatusMap::toShipmentStatus('   '));
        self::assertFalse(YalidineStatusMap::isKnown('Nouveau statut'));
    }

    /** A substring of a real label is not that label. */
    public function testPartialLabelsDoNotMatch(): void
    {
        self::assertNull(YalidineStatusMap::toShipmentStatus('Retour'));
        self::assertNull(YalidineStatusMap::toShipmentStatus('livraison'));
    }
}
