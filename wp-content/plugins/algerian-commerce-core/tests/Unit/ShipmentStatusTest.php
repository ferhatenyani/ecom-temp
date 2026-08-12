<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Shipping\ShipmentStatus;
use PHPUnit\Framework\TestCase;

final class ShipmentStatusTest extends TestCase
{
    /**
     * Couriers spell their states however they like. Normalizing hyphens is
     * what lets an adapter map "out-of-delivery" style values without each one
     * inventing its own cleanup.
     */
    public function testNormalizeAcceptsTheSpellingsProvidersActuallySend(): void
    {
        self::assertSame('in_transit', ShipmentStatus::normalize('IN-TRANSIT'));
        self::assertSame('out_for_delivery', ShipmentStatus::normalize('  Out-For-Delivery '));
        self::assertSame('delivered', ShipmentStatus::normalize('delivered'));
    }

    public function testIsKnownRejectsAProvidersOwnVocabulary(): void
    {
        self::assertTrue(ShipmentStatus::isKnown('picked_up'));
        self::assertFalse(ShipmentStatus::isKnown('en_preparation'));
        self::assertFalse(ShipmentStatus::isKnown('ACCEPTED'));
        self::assertFalse(ShipmentStatus::isKnown(''));
    }

    public function testEveryTerminalStatusIsAKnownStatus(): void
    {
        foreach (ShipmentStatus::TERMINAL as $status) {
            self::assertContains($status, ShipmentStatus::ALL);
            self::assertTrue(ShipmentStatus::isTerminal($status));
            self::assertFalse(ShipmentStatus::isLive($status));
        }
    }

    public function testALiveStatusIsOneWeAreStillWaitingOn(): void
    {
        self::assertTrue(ShipmentStatus::isLive('pending'));
        self::assertTrue(ShipmentStatus::isLive('in_transit'));
        self::assertFalse(ShipmentStatus::isLive('delivered'));
        // Unknown is not live: nothing sensible can be done with it.
        self::assertFalse(ShipmentStatus::isLive('en_preparation'));
    }

    public function testAParcelMovesForwardThroughTheLiveStatuses(): void
    {
        self::assertTrue(ShipmentStatus::accepts('pending', 'created'));
        self::assertTrue(ShipmentStatus::accepts('created', 'picked_up'));
        self::assertTrue(ShipmentStatus::accepts('picked_up', 'in_transit'));
        self::assertTrue(ShipmentStatus::accepts('in_transit', 'delivered'));
    }

    /**
     * A courier owns reality. Out-of-order reports are normal, and refusing one
     * because it did not follow the expected sequence would mean our record
     * disagreeing with the parcel to defend a diagram.
     */
    public function testAnUnexpectedButLiveSequenceIsStillAccepted(): void
    {
        self::assertTrue(ShipmentStatus::accepts('in_transit', 'created'));
        self::assertTrue(ShipmentStatus::accepts('out_for_delivery', 'picked_up'));
    }

    /**
     * The one thing that is enforced: a replayed webhook or a poll that crossed
     * a delivery in flight must not reopen a finished shipment.
     */
    public function testAFinishedShipmentIsNeverReopened(): void
    {
        foreach (ShipmentStatus::TERMINAL as $terminal) {
            foreach (ShipmentStatus::ALL as $reported) {
                self::assertFalse(
                    ShipmentStatus::accepts($terminal, $reported),
                    "{$terminal} must not accept {$reported}"
                );
            }
        }
    }

    /** Most polls find a parcel exactly where it was — that is not a write. */
    public function testTheSameStatusIsNotAChange(): void
    {
        foreach (ShipmentStatus::ALL as $status) {
            self::assertFalse(ShipmentStatus::accepts($status, $status));
        }
    }

    /**
     * A status the adapter failed to map is refused rather than stored: the raw
     * value is kept in the metadata either way, and a word nothing can reason
     * about is worse than the last one that could.
     */
    public function testAnUnmappedProviderStatusIsRefused(): void
    {
        self::assertFalse(ShipmentStatus::accepts('in_transit', 'chez_le_livreur'));
        self::assertFalse(ShipmentStatus::accepts('in_transit', ''));
    }

    /** A shipment starting from an unknown status can still be corrected. */
    public function testAnUnknownCurrentStatusCanBeMovedToAKnownOne(): void
    {
        self::assertTrue(ShipmentStatus::accepts('something_odd', 'in_transit'));
    }
}
