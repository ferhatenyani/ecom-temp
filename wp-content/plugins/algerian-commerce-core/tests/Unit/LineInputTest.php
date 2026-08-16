<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Cart\CartService;
use AlgerianCommerce\Cart\LineInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Roadmap §59b's rule, as a unit test: a cart line carries three writable
 * facts and no money.
 */
final class LineInputTest extends TestCase
{
    public function testAcceptsAProductAndAQuantity(): void
    {
        $line = LineInput::fromArray(['product_id' => 42, 'quantity' => 3]);

        self::assertSame(42, $line->productId);
        self::assertSame(3, $line->quantity);
        self::assertSame(0, $line->variationId);
    }

    public function testQuantityDefaultsToOne(): void
    {
        self::assertSame(1, LineInput::fromArray(['product_id' => 42])->quantity);
    }

    public function testAVariationIsCarried(): void
    {
        $line = LineInput::fromArray(['product_id' => 42, 'variation_id' => 43, 'quantity' => 2]);

        self::assertSame(['product_id' => 42, 'variation_id' => 43, 'quantity' => 2], $line->toArray());
    }

    /** @return array<string, array{0: string}> */
    public static function moneyFieldProvider(): array
    {
        return [
            'price' => ['price'],
            'line_total' => ['line_total'],
            'line_subtotal' => ['line_subtotal'],
            'subtotal' => ['subtotal'],
            'total' => ['total'],
            'discount' => ['discount'],
            'currency' => ['currency'],
        ];
    }

    /**
     * The security property of the whole module.
     *
     * Refused **by name**, with a reason, rather than silently dropped: a
     * client that sends a price is a client whose author believes it decides
     * one, and a 400 saying so is the only thing that corrects that belief
     * before it reaches production.
     */
    #[DataProvider('moneyFieldProvider')]
    public function testMoneyFieldsAreRefusedByName(string $field): void
    {
        try {
            LineInput::fromArray(['product_id' => 42, 'quantity' => 1, $field => '0.01']);
            self::fail("{$field} was accepted");
        } catch (ApiException $e) {
            $fields = $e->details()['fields'] ?? [];
            self::assertArrayHasKey($field, $fields);
            self::assertNotSame('Unknown field.', $fields[$field], 'it should say why, not just "unknown"');
        }
    }

    public function testUnknownFieldsAreRejected(): void
    {
        $this->expectException(ApiException::class);

        LineInput::fromArray(['product_id' => 42, 'nonsense' => 1]);
    }

    public function testAProductIdIsRequired(): void
    {
        $this->expectException(ApiException::class);

        LineInput::fromArray(['quantity' => 1]);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badQuantityProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'over the cap' => [CartService::MAX_QUANTITY + 1],
            'a float' => [1.5],
            'a word' => ['many'],
            'an array' => [[1]],
            'true' => [true],
            'null' => [null],
        ];
    }

    #[DataProvider('badQuantityProvider')]
    public function testQuantityMustBeAWholeNumberInRange(mixed $quantity): void
    {
        $this->expectException(ApiException::class);

        LineInput::fromArray(['product_id' => 42, 'quantity' => $quantity]);
    }

    public function testQuantityAtTheCapIsAllowed(): void
    {
        self::assertSame(
            CartService::MAX_QUANTITY,
            LineInput::fromArray(['product_id' => 42, 'quantity' => CartService::MAX_QUANTITY])->quantity
        );
    }

    /** A JSON client sending numbers as strings is normal and must work. */
    public function testNumericStringsAreAccepted(): void
    {
        $line = LineInput::fromArray(['product_id' => '42', 'quantity' => '3']);

        self::assertSame(42, $line->productId);
        self::assertSame(3, $line->quantity);
    }

    public function testEveryBadFieldIsReportedAtOnce(): void
    {
        try {
            LineInput::fromArray(['product_id' => 0, 'quantity' => -1, 'price' => '1', 'nope' => 1]);
            self::fail('accepted');
        } catch (ApiException $e) {
            self::assertSame(
                ['price', 'nope', 'product_id', 'quantity'],
                array_keys($e->details()['fields'] ?? []),
                'a form should be able to mark every bad field in one pass'
            );
        }
    }
}
