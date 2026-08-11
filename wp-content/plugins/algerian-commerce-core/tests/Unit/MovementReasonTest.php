<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Inventory\InventoryMovement;
use AlgerianCommerce\Inventory\MovementReason;
use PHPUnit\Framework\TestCase;

final class MovementReasonTest extends TestCase
{
    public function testManualAndSystemReasonsDoNotOverlap(): void
    {
        // The split is a security boundary: if a reason were in both lists,
        // the adjustment endpoint would accept a value the ledger presents as
        // machine-generated.
        self::assertSame([], array_intersect(MovementReason::MANUAL, MovementReason::SYSTEM));
    }

    public function testAllIsTheUnionOfBoth(): void
    {
        self::assertSame(
            count(MovementReason::MANUAL) + count(MovementReason::SYSTEM),
            count(MovementReason::all())
        );
    }

    public function testReasonsAreUnique(): void
    {
        self::assertSame(MovementReason::all(), array_values(array_unique(MovementReason::all())));
    }

    public function testEveryManualReasonIsManualAndKnown(): void
    {
        foreach (MovementReason::MANUAL as $reason) {
            self::assertTrue(MovementReason::isManual($reason));
            self::assertTrue(MovementReason::isKnown($reason));
        }
    }

    public function testSystemReasonsAreKnownButNotManual(): void
    {
        foreach (MovementReason::SYSTEM as $reason) {
            self::assertFalse(MovementReason::isManual($reason), "{$reason} must not be settable by hand");
            self::assertTrue(MovementReason::isKnown($reason));
        }
    }

    public function testUnknownReasonsAreNeither(): void
    {
        self::assertFalse(MovementReason::isKnown('shrinkage'));
        self::assertFalse(MovementReason::isManual('shrinkage'));
        self::assertFalse(MovementReason::isKnown(''));
    }

    /**
     * Guards the mistake that adds a reason longer than the column, which
     * MySQL in strict mode would reject on a stock change that already
     * happened.
     */
    public function testEveryReasonFitsItsColumn(): void
    {
        foreach (MovementReason::all() as $reason) {
            self::assertLessThanOrEqual(InventoryMovement::MAX_REASON, strlen($reason), $reason);
        }
    }
}
