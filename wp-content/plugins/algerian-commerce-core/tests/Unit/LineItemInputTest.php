<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Orders\LineItemInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineItemInputTest extends TestCase
{
    /**
     * @param mixed $payload
     * @return array{0: list<LineItemInput>, 1: array<string, string>}
     */
    private function parse(mixed $payload): array
    {
        $errors = [];
        $items = LineItemInput::listFromPayload($payload, $errors);

        return [$items, $errors];
    }

    public function testParsesASimpleLine(): void
    {
        [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 3]]);

        self::assertSame([], $errors);
        self::assertCount(1, $items);
        self::assertSame(7, $items[0]->productId);
        self::assertSame(0, $items[0]->variationId);
        self::assertSame(3, $items[0]->quantity);
    }

    /**
     * The presenter emits variation_id 0 for a simple product. A round-tripped
     * order must not fail on a field the caller never touched.
     */
    public function testVariationIdZeroMeansNone(): void
    {
        foreach ([0, '0', null, ''] as $empty) {
            [$items, $errors] = $this->parse([
                ['product_id' => 7, 'variation_id' => $empty, 'quantity' => 1],
            ]);

            self::assertSame([], $errors, var_export($empty, true) . ' should mean "no variation"');
            self::assertSame(0, $items[0]->variationId);
        }
    }

    /** The rest of the round trip: everything the presenter adds is dropped. */
    public function testEmittedLineFieldsAreDropped(): void
    {
        [$items, $errors] = $this->parse([[
            'id' => 91,
            'name' => 'Blue kettle',
            'sku' => 'KET-BLU',
            'subtotal' => '3000.00',
            'total' => '3000.00',
            'product_id' => 7,
            'variation_id' => 0,
            'quantity' => 2,
        ]]);

        self::assertSame([], $errors);
        self::assertSame(2, $items[0]->quantity);
    }

    /**
     * The reversal, asserted directly: this exact payload used to be refused
     * with "Line prices come from the catalogue and cannot be set."
     */
    public function testAManualPriceIsAcceptedAndKeptAsTheStringItArrivedAs(): void
    {
        [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => '0.01']]);

        self::assertSame([], $errors);
        self::assertSame('0.01', $items[0]->price);
    }

    /** A price is optional; a line without one is priced by the catalogue. */
    public function testALineWithNoPriceCarriesNone(): void
    {
        [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1]]);

        self::assertSame([], $errors);
        self::assertNull($items[0]->price);
    }

    /**
     * Zero is a price, not an absence. It is the case the old refusal existed
     * to prevent — a free line — and it is now allowed on purpose, guarded by
     * `ac_manage_orders` and the audit rather than by a 400.
     */
    public function testZeroIsAPriceAndNotAnEmptyValue(): void
    {
        foreach ([0, '0', 0.0, '0.00'] as $free) {
            [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => $free]]);

            self::assertSame([], $errors, var_export($free, true) . ' is a free line, not a missing price');
            self::assertNotNull($items[0]->price, var_export($free, true) . ' must survive as a stated price');
            self::assertSame(0.0, (float) $items[0]->price);
        }
    }

    /**
     * Clearing a manual price has to be expressible, or a line priced by hand
     * once is priced by hand forever.
     */
    public function testAnEmptyPriceMeansNoManualPrice(): void
    {
        foreach ([null, ''] as $empty) {
            [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => $empty]]);

            self::assertSame([], $errors, var_export($empty, true) . ' should mean "no manual price"');
            self::assertNull($items[0]->price);
        }
    }

    public function testANegativePriceIsRefusedByName(): void
    {
        [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => '-1']]);

        self::assertSame(['line_items.0.price' => 'Cannot be negative.'], $errors);
        self::assertSame([], $items, 'a refused price must not survive as a catalogue-priced line');
    }

    public function testANonNumericPriceIsRefusedByName(): void
    {
        [, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => 'free']]);

        self::assertSame(['line_items.0.price' => 'Must be an amount.'], $errors);
    }

    /**
     * An unbounded amount would reach the step-3 total recompute as INF, and
     * `1e400` is how a JSON body produces one.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function implausiblePriceProvider(): array
    {
        return [
            'over the ceiling' => ['10000000.00'],
            'json infinity' => [1e400],
        ];
    }

    #[DataProvider('implausiblePriceProvider')]
    public function testAnImplausiblyLargePriceIsRefusedByName(mixed $price): void
    {
        [, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => $price]]);

        self::assertSame(['line_items.0.price' => 'Is implausibly large.'], $errors);
    }

    /** The ceiling itself is a legal price — the refusal is above it, not at it. */
    public function testTheCeilingItselfIsAccepted(): void
    {
        [$items, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => '9999999.99']]);

        self::assertSame([], $errors);
        self::assertSame('9999999.99', $items[0]->price);
    }

    /**
     * "A price on a line the caller did not otherwise change."
     *
     * `line_items` is a wholesale replacement — OrderRepository::replaceLineItems()
     * deletes every line and re-adds the payload's — and the line `id` is
     * dropped on write, so there is no way to reprice one existing line in
     * place. A caller who tries gets told that about `price`, the field they
     * came for, rather than only about the two fields they thought they could
     * leave out.
     */
    public function testAPriceOnALineThatDoesNotRestateItselfIsRefusedByName(): void
    {
        [$items, $errors] = $this->parse([['id' => 91, 'price' => '500']]);

        self::assertArrayHasKey('line_items.0.price', $errors);
        self::assertStringContainsString('replaces the whole set', $errors['line_items.0.price']);
        self::assertSame([], $items);
    }

    /** Half a restatement is still not a restatement. */
    public function testAPriceWithAProductButNoQuantityIsRefusedByName(): void
    {
        [, $errors] = $this->parse([['product_id' => 7, 'price' => '500']]);

        self::assertArrayHasKey('line_items.0.price', $errors);
        self::assertStringContainsString('product and quantity', $errors['line_items.0.price']);
    }

    /**
     * A stated-but-invalid quantity is a quantity problem and gets one message.
     * The price refusal is about a field the caller *omitted*, and firing both
     * would blame the price for the quantity's mistake.
     */
    public function testAStatedButInvalidQuantityDoesNotAlsoBlameThePrice(): void
    {
        [, $errors] = $this->parse([['product_id' => 7, 'quantity' => 0, 'price' => '500']]);

        self::assertArrayHasKey('line_items.0.quantity', $errors);
        self::assertArrayNotHasKey('line_items.0.price', $errors);
    }

    /** The read shape still round trips, price or no price. */
    public function testAPriceRidesAlongsideTheDroppedReadOnlyKeys(): void
    {
        [$items, $errors] = $this->parse([[
            'id' => 91,
            'name' => 'Blue kettle',
            'sku' => 'KET-BLU',
            'subtotal' => '3000.00',
            'total' => '3000.00',
            'product_id' => 7,
            'variation_id' => 0,
            'quantity' => 2,
            'price' => '1200.50',
        ]]);

        self::assertSame([], $errors);
        self::assertSame('1200.50', $items[0]->price);
    }

    public function testUnknownLineFieldsAreRejected(): void
    {
        [, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'discount' => 5]]);

        self::assertSame(['line_items.0.discount' => 'Unknown field.'], $errors);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badQuantityProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-2],
            'fractional' => [1.5],
            'text' => ['two'],
            'null' => [null],
            'missing' => ['__absent__'],
        ];
    }

    #[DataProvider('badQuantityProvider')]
    public function testQuantityMustBeAWholeNumberOfOneOrMore(mixed $quantity): void
    {
        $line = ['product_id' => 7];

        if ($quantity !== '__absent__') {
            $line['quantity'] = $quantity;
        }

        [$items, $errors] = $this->parse([$line]);

        self::assertArrayHasKey('line_items.0.quantity', $errors);
        self::assertSame([], $items, 'a rejected line must not survive as a half-built item');
    }

    /** A numeric string is how JSON from a form arrives, and is fine. */
    public function testNumericStringsAreAccepted(): void
    {
        [$items, $errors] = $this->parse([['product_id' => '7', 'quantity' => '2']]);

        self::assertSame([], $errors);
        self::assertSame(7, $items[0]->productId);
        self::assertSame(2, $items[0]->quantity);
    }

    public function testProductIdIsRequired(): void
    {
        [, $errors] = $this->parse([['quantity' => 1]]);

        self::assertArrayHasKey('line_items.0.product_id', $errors);
    }

    public function testTheListItselfMustBeAList(): void
    {
        [, $errors] = $this->parse(['product_id' => 7, 'quantity' => 1]);

        self::assertSame(['line_items' => 'Must be an array of line items.'], $errors);
    }

    public function testAnEmptyListIsRejected(): void
    {
        [, $errors] = $this->parse([]);

        self::assertArrayHasKey('line_items', $errors);
    }

    public function testEveryBadLineIsReportedNotJustTheFirst(): void
    {
        [$items, $errors] = $this->parse([
            ['product_id' => 7, 'quantity' => 1],
            ['product_id' => 0, 'quantity' => 1],
            ['product_id' => 9, 'quantity' => 0],
        ]);

        self::assertArrayHasKey('line_items.1.product_id', $errors);
        self::assertArrayHasKey('line_items.2.quantity', $errors);
        self::assertCount(1, $items);
    }
}
