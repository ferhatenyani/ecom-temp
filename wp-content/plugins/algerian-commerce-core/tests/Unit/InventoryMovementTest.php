<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Inventory\InventoryMovement;
use AlgerianCommerce\Inventory\MovementReason;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InventoryMovementTest extends TestCase
{
    public function testBuildsABalancedRow(): void
    {
        $movement = new InventoryMovement(12, -3, 40, 37, MovementReason::DAMAGE, 'water damage');

        self::assertSame(12, $movement->productId);
        self::assertSame(-3, $movement->delta);
        self::assertSame(40, $movement->quantityBefore);
        self::assertSame(37, $movement->quantityAfter);
        self::assertSame('water damage', $movement->note);
    }

    /**
     * The invariant the whole ledger rests on. A row that does not balance
     * cannot be reconciled against its neighbours.
     */
    public function testRejectsARowThatDoesNotBalance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not balance');

        new InventoryMovement(12, -3, 40, 30, MovementReason::DAMAGE);
    }

    public function testRejectsAnUnknownReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown movement reason');

        new InventoryMovement(12, 1, 0, 1, 'shrinkage');
    }

    public function testRejectsAMissingProductId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InventoryMovement(0, 1, 0, 1, MovementReason::RESTOCK);
    }

    public function testAllowsNegativeStockForBackorders(): void
    {
        $movement = new InventoryMovement(12, -5, 2, -3, MovementReason::ORDER_REDUCED);

        self::assertSame(-3, $movement->quantityAfter);
    }

    public function testFromDeltaDerivesTheBeforeQuantityFromTheAuthoritativeResult(): void
    {
        // WooCommerce reported 52 after a +5; before must be 47, whatever the
        // caller happened to read earlier.
        $movement = InventoryMovement::fromDelta(12, 5, 52, MovementReason::RESTOCK);

        self::assertSame(47, $movement->quantityBefore);
        self::assertSame(52, $movement->quantityAfter);
        self::assertSame(5, $movement->delta);
    }

    public function testClipsAnOverlongNoteRatherThanFailingTheWrite(): void
    {
        // The stock has already moved by the time a row is built; a rejected
        // insert would lose the record of a change that did happen.
        $movement = new InventoryMovement(
            12,
            1,
            0,
            1,
            MovementReason::RESTOCK,
            str_repeat('x', InventoryMovement::MAX_NOTE + 50)
        );

        self::assertSame(InventoryMovement::MAX_NOTE, mb_strlen($movement->note));
    }

    public function testRowAndFormatsLineUp(): void
    {
        $movement = new InventoryMovement(12, 1, 0, 1, MovementReason::RESTOCK, 'n', 7, 3);

        self::assertCount(count($movement->toRow()), $movement->rowFormats());
        self::assertSame(
            ['product_id', 'delta', 'quantity_before', 'quantity_after', 'reason', 'note', 'order_id', 'actor_id', 'created_at'],
            array_keys($movement->toRow())
        );
    }

    public function testStampsCreatedAtInUtc(): void
    {
        $movement = new InventoryMovement(12, 1, 0, 1, MovementReason::RESTOCK);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $movement->createdAt);
    }

    public function testAcceptsAnExplicitTimestamp(): void
    {
        $movement = new InventoryMovement(12, 1, 0, 1, MovementReason::RESTOCK, '', 0, 0, '2026-08-11 09:30:00');

        self::assertSame('2026-08-11 09:30:00', $movement->createdAt);
    }
}
