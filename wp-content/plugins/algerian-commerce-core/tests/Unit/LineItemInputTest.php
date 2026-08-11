<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Orders\LineItemInput;
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
     * `price` is dropped from a *read* shape by name, but a caller who sends
     * one is trying to set it and must be told no rather than ignored.
     */
    public function testPriceIsRefusedWithAReasonRatherThanCalledUnknown(): void
    {
        [, $errors] = $this->parse([['product_id' => 7, 'quantity' => 1, 'price' => '0.01']]);

        self::assertArrayHasKey('line_items.0.price', $errors);
        self::assertStringContainsString('catalogue', $errors['line_items.0.price']);
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

    /** @dataProvider badQuantityProvider */
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
