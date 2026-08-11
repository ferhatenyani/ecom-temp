<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Inventory\MovementReason;
use AlgerianCommerce\Inventory\StockAdjustment;
use PHPUnit\Framework\TestCase;

final class StockAdjustmentTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function reject(array $payload): ApiException
    {
        try {
            StockAdjustment::fromPayload($payload);
        } catch (ApiException $exception) {
            return $exception;
        }

        self::fail('Expected the adjustment to be rejected.');
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        return [...['mode' => 'set', 'quantity' => 10, 'reason' => 'correction'], ...$overrides];
    }

    public function testAcceptsAMinimalAdjustment(): void
    {
        $adjustment = StockAdjustment::fromPayload($this->valid());

        self::assertSame('set', $adjustment->mode);
        self::assertSame(10, $adjustment->quantity);
        self::assertSame('correction', $adjustment->reason);
        self::assertSame('', $adjustment->note);
    }

    public function testTrimsTheNote(): void
    {
        $adjustment = StockAdjustment::fromPayload($this->valid(['note' => '  PO 4471  ']));

        self::assertSame('PO 4471', $adjustment->note);
    }

    public function testRejectsUnknownFields(): void
    {
        $exception = $this->reject($this->valid(['quantiy' => 3]));

        self::assertSame(400, $exception->statusCode());
        self::assertSame('Unknown field.', $exception->details()['fields']['quantiy']);
    }

    public function testRejectsAnUnknownMode(): void
    {
        self::assertArrayHasKey('mode', $this->reject($this->valid(['mode' => 'add']))->details()['fields']);
    }

    public function testRejectsAMissingQuantity(): void
    {
        $payload = $this->valid();
        unset($payload['quantity']);

        self::assertArrayHasKey('quantity', $this->reject($payload)->details()['fields']);
    }

    public function testRejectsANegativeQuantity(): void
    {
        $fields = $this->reject($this->valid(['quantity' => -1]))->details()['fields'];

        self::assertSame('Cannot be negative.', $fields['quantity']);
    }

    public function testRejectsAFractionalQuantity(): void
    {
        // WooCommerce's default stock is integral; 2.5 would truncate silently.
        $fields = $this->reject($this->valid(['quantity' => 2.5]))->details()['fields'];

        self::assertSame('Must be a whole number.', $fields['quantity']);
    }

    public function testAllowsSettingStockToZero(): void
    {
        self::assertSame(0, StockAdjustment::fromPayload($this->valid(['quantity' => 0]))->quantity);
    }

    /** @return array<string, array{0: string}> */
    public static function relativeModeProvider(): array
    {
        return ['increase' => ['increase'], 'decrease' => ['decrease']];
    }

    /** @dataProvider relativeModeProvider */
    public function testRejectsAZeroMagnitudeMove(string $mode): void
    {
        // A no-op that would still write a ledger row.
        $fields = $this->reject($this->valid(['mode' => $mode, 'quantity' => 0]))->details()['fields'];

        self::assertSame("Must be greater than zero for {$mode}.", $fields['quantity']);
    }

    public function testRejectsAMissingReason(): void
    {
        $payload = $this->valid();
        unset($payload['reason']);

        self::assertArrayHasKey('reason', $this->reject($payload)->details()['fields']);
    }

    /** @return array<string, array{0: string}> */
    public static function systemReasonProvider(): array
    {
        return [
            'product edit' => [MovementReason::PRODUCT_EDIT],
            'order reduced' => [MovementReason::ORDER_REDUCED],
            'order restored' => [MovementReason::ORDER_RESTORED],
        ];
    }

    /**
     * A human must not be able to write a row that reads as though an order
     * caused it — that is what keeps the ledger's provenance meaningful.
     *
     * @dataProvider systemReasonProvider
     */
    public function testRejectsSystemReasons(string $reason): void
    {
        $fields = $this->reject($this->valid(['reason' => $reason]))->details()['fields'];

        self::assertArrayHasKey('reason', $fields);
        // Same message as an unknown reason: confirming the reason exists but
        // is off limits would tell a caller how to shape a forgery.
        self::assertStringNotContainsString($reason, $fields['reason']);
    }

    public function testAcceptsEveryManualReason(): void
    {
        foreach (MovementReason::MANUAL as $reason) {
            self::assertSame(
                $reason,
                StockAdjustment::fromPayload($this->valid(['reason' => $reason]))->reason
            );
        }
    }

    public function testRejectsAnOverlongNote(): void
    {
        $fields = $this->reject($this->valid([
            'note' => str_repeat('x', StockAdjustment::MAX_NOTE + 1),
        ]))->details()['fields'];

        self::assertArrayHasKey('note', $fields);
    }

    public function testAcceptsANoteAtExactlyTheLimit(): void
    {
        $note = str_repeat('x', StockAdjustment::MAX_NOTE);

        self::assertSame($note, StockAdjustment::fromPayload($this->valid(['note' => $note]))->note);
    }

    public function testReportsEveryProblemAtOnce(): void
    {
        $fields = $this->reject(['mode' => 'nope', 'quantity' => -5, 'reason' => 'nope'])->details()['fields'];

        self::assertArrayHasKey('mode', $fields);
        self::assertArrayHasKey('quantity', $fields);
        self::assertArrayHasKey('reason', $fields);
    }

    /** @return array<string, array{0: string, 1: int, 2: int, 3: int}> */
    public static function projectionProvider(): array
    {
        return [
            'set replaces' => ['set', 10, 40, 10],
            'increase adds' => ['increase', 10, 40, 50],
            'decrease subtracts' => ['decrease', 10, 40, 30],
            'decrease past zero' => ['decrease', 10, 4, -6],
            'increase from negative' => ['increase', 10, -4, 6],
        ];
    }

    /** @dataProvider projectionProvider */
    public function testProjectsTheResultingQuantity(string $mode, int $quantity, int $current, int $expected): void
    {
        $adjustment = StockAdjustment::fromPayload($this->valid(['mode' => $mode, 'quantity' => $quantity]));

        self::assertSame($expected, $adjustment->project($current));
    }

    /**
     * A relative move knows its own delta regardless of what else landed in
     * between — WooCommerce applies it as relative SQL. Using after - before
     * would credit this adjustment with somebody else's change.
     */
    public function testRelativeDeltaIgnoresAConcurrentChange(): void
    {
        $increase = StockAdjustment::fromPayload($this->valid(['mode' => 'increase', 'quantity' => 5]));
        $decrease = StockAdjustment::fromPayload($this->valid(['mode' => 'decrease', 'quantity' => 5]));

        // Read 40, but another request added 7 before our write landed.
        self::assertSame(5, $increase->deltaFor(40, 52));
        self::assertSame(-5, $decrease->deltaFor(40, 42));
    }

    public function testSetDeltaIsTheDifferenceAcrossItsOwnReadAndWrite(): void
    {
        $set = StockAdjustment::fromPayload($this->valid(['mode' => 'set', 'quantity' => 10]));

        self::assertSame(-30, $set->deltaFor(40, 10));
        self::assertSame(10, $set->deltaFor(0, 10));
    }
}
