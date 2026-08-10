<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\ProductInput;
use PHPUnit\Framework\TestCase;

final class ProductInputTest extends TestCase
{
    /** @param array<string, mixed> $payload */
    private function fieldErrors(array $payload, bool $create = true): array
    {
        try {
            $create ? ProductInput::forCreate($payload) : ProductInput::forUpdate($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testAcceptsAMinimalCreatePayload(): void
    {
        $input = ProductInput::forCreate(['name' => 'Tapis Berbère']);

        self::assertSame('Tapis Berbère', $input->get('name'));
        self::assertFalse($input->has('sku'));
    }

    public function testNameIsRequiredOnCreate(): void
    {
        self::assertArrayHasKey('name', $this->fieldErrors([]));
        self::assertArrayHasKey('name', $this->fieldErrors(['name' => '   ']));
    }

    public function testNameIsOptionalOnUpdateButCannotBeEmptied(): void
    {
        self::assertTrue(ProductInput::forUpdate(['sku' => 'X'])->isEmpty() === false);
        self::assertArrayHasKey('name', $this->fieldErrors(['name' => ''], false));
    }

    public function testUnknownFieldsAreRejectedNotIgnored(): void
    {
        // Silently dropping a typo leaves the caller believing it applied.
        $errors = $this->fieldErrors(['name' => 'X', 'stock_quantiy' => 5]);

        self::assertArrayHasKey('stock_quantiy', $errors);
    }

    public function testTrimsStrings(): void
    {
        $input = ProductInput::forCreate(['name' => '  Burnous  ', 'sku' => " DZ-1 \n"]);

        self::assertSame('Burnous', $input->get('name'));
        self::assertSame('DZ-1', $input->get('sku'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function badPriceProvider(): array
    {
        return [
            'text' => ['free'],
            'negative' => [-1],
            'negative string' => ['-0.01'],
            'array' => [[1]],
        ];
    }

    /** @dataProvider badPriceProvider */
    public function testRejectsInvalidPrices(mixed $price): void
    {
        self::assertArrayHasKey('regular_price', $this->fieldErrors(['name' => 'X', 'regular_price' => $price]));
    }

    public function testAcceptsZeroAndDecimalPrices(): void
    {
        $input = ProductInput::forCreate(['name' => 'X', 'regular_price' => '0', 'sale_price' => 0]);

        self::assertSame('0', $input->get('regular_price'));
    }

    public function testPricesStayStringsSoDecimalsAreNotRounded(): void
    {
        $input = ProductInput::forCreate(['name' => 'X', 'regular_price' => '1999.99']);

        self::assertIsString($input->get('regular_price'));
        self::assertSame('1999.99', $input->get('regular_price'));
    }

    public function testEmptyPriceIsAllowedBecauseItClearsThePrice(): void
    {
        $input = ProductInput::forUpdate(['sale_price' => '']);

        self::assertTrue($input->has('sale_price'));
        self::assertSame('', $input->get('sale_price'));
    }

    public function testSalePriceCannotExceedRegularPrice(): void
    {
        $errors = $this->fieldErrors(['name' => 'X', 'regular_price' => '100', 'sale_price' => '150']);

        self::assertArrayHasKey('sale_price', $errors);
    }

    public function testSalePriceEqualToRegularIsAllowed(): void
    {
        $input = ProductInput::forCreate(['name' => 'X', 'regular_price' => '100', 'sale_price' => '100']);

        self::assertSame('100', $input->get('sale_price'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function enumProvider(): array
    {
        return [
            'status' => ['status', 'published'],
            'visibility' => ['catalog_visibility', 'everywhere'],
            'stock status' => ['stock_status', 'maybe'],
        ];
    }

    /** @dataProvider enumProvider */
    public function testRejectsValuesOutsideTheEnum(string $field, string $bad): void
    {
        self::assertArrayHasKey($field, $this->fieldErrors(['name' => 'X', $field => $bad]));
    }

    public function testAcceptsEveryDeclaredEnumValue(): void
    {
        foreach (ProductInput::STATUSES as $status) {
            self::assertSame($status, ProductInput::forCreate(['name' => 'X', 'status' => $status])->get('status'));
        }

        foreach (ProductInput::STOCK_STATUSES as $stockStatus) {
            self::assertSame(
                $stockStatus,
                ProductInput::forCreate(['name' => 'X', 'stock_status' => $stockStatus])->get('stock_status')
            );
        }
    }

    public function testRejectsNegativeOrNonNumericStock(): void
    {
        self::assertArrayHasKey('stock_quantity', $this->fieldErrors(['name' => 'X', 'stock_quantity' => -3]));
        self::assertArrayHasKey('stock_quantity', $this->fieldErrors(['name' => 'X', 'stock_quantity' => 'lots']));
    }

    public function testNullStockIsAllowedBecauseItMeansUntracked(): void
    {
        $input = ProductInput::forUpdate(['stock_quantity' => null]);

        self::assertTrue($input->has('stock_quantity'));
        self::assertNull($input->get('stock_quantity'));
    }

    public function testCoercesBooleans(): void
    {
        self::assertTrue(ProductInput::forCreate(['name' => 'X', 'featured' => 'true'])->get('featured'));
        self::assertTrue(ProductInput::forCreate(['name' => 'X', 'featured' => 1])->get('featured'));
        self::assertFalse(ProductInput::forCreate(['name' => 'X', 'featured' => '0'])->get('featured'));
        self::assertFalse(ProductInput::forCreate(['name' => 'X', 'featured' => 'nope'])->get('featured'));
    }

    public function testCategoryIdsMustBePositiveIntegers(): void
    {
        self::assertArrayHasKey('category_ids', $this->fieldErrors(['name' => 'X', 'category_ids' => '12']));
        self::assertArrayHasKey('category_ids', $this->fieldErrors(['name' => 'X', 'category_ids' => [0]]));
        self::assertArrayHasKey('category_ids', $this->fieldErrors(['name' => 'X', 'category_ids' => ['a']]));
    }

    public function testCategoryIdsAreCastAndDeduplicated(): void
    {
        $input = ProductInput::forCreate(['name' => 'X', 'category_ids' => ['4', 4, 9]]);

        self::assertSame([4, 9], $input->get('category_ids'));
    }

    public function testReportsEveryProblemAtOnce(): void
    {
        // One round trip should tell the client everything that is wrong.
        $errors = $this->fieldErrors([
            'regular_price' => 'free',
            'status' => 'published',
            'stock_quantity' => -1,
            'nonsense' => true,
        ]);

        self::assertSame(
            ['nonsense', 'name', 'regular_price', 'status', 'stock_quantity'],
            array_keys($errors)
        );
    }

    public function testRejectionIsA400WithFieldDetails(): void
    {
        try {
            ProductInput::forCreate([]);
        } catch (ApiException $exception) {
            self::assertSame('invalid_request', $exception->errorCode());
            self::assertSame(400, $exception->statusCode());
            self::assertArrayHasKey('fields', $exception->details());

            return;
        }

        self::fail('Expected an ApiException.');
    }

    public function testAnUpdateWithNoRecognisedFieldsIsEmpty(): void
    {
        self::assertTrue(ProductInput::forUpdate([])->isEmpty());
    }

    /** @return array<string, array{0: string}> */
    public static function readOnlyFieldProvider(): array
    {
        return [
            'id' => ['id'],
            'computed price' => ['price'],
            'on_sale' => ['on_sale'],
            'permalink' => ['permalink'],
            'date_created' => ['date_created'],
            'date_modified' => ['date_modified'],
            'variations' => ['variations'],
        ];
    }

    /**
     * A client that does GET → edit → PATCH sends back the fields we emit.
     * Rejecting our own output would make the obvious usage pattern fail.
     *
     * @dataProvider readOnlyFieldProvider
     */
    public function testFieldsWeEmitButDoNotAcceptAreIgnoredNotRejected(string $field): void
    {
        $input = ProductInput::forUpdate(['name' => 'X', $field => 'anything']);

        self::assertFalse($input->has($field), 'read-only fields must not reach the setters');
        self::assertSame('X', $input->get('name'));
    }

    public function testAFullReadPayloadCanBePatchedBackUnchanged(): void
    {
        // Exactly what ProductPresenter emits.
        $roundTrip = [
            'id' => 7, 'name' => 'Tapis', 'slug' => 'tapis', 'type' => 'simple', 'status' => 'publish',
            'featured' => false, 'catalog_visibility' => 'visible', 'sku' => 'DZ-1',
            'description' => 'd', 'short_description' => 's', 'price' => '100',
            'regular_price' => '100', 'sale_price' => '', 'on_sale' => false,
            'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock',
            'weight' => '', 'category_ids' => [3], 'tag_ids' => [], 'attributes' => [],
            'variations' => [], 'permalink' => 'http://x/p', 'date_created' => '2026-01-01T00:00:00+00:00',
            'date_modified' => null,
        ];

        $input = ProductInput::forUpdate($roundTrip);

        self::assertSame('Tapis', $input->get('name'));
        self::assertSame('simple', $input->get('type'));
        self::assertFalse($input->has('id'));
        self::assertFalse($input->has('price'));
    }

    public function testGenuinelyUnknownFieldsAreStillRejected(): void
    {
        // The read-only exemption must not become a blanket amnesty.
        self::assertArrayHasKey('stock_quantiy', $this->fieldErrors(['name' => 'X', 'stock_quantiy' => 5]));
        self::assertArrayHasKey('post_author', $this->fieldErrors(['name' => 'X', 'post_author' => 1]));
    }

    public function testTypeIsLimitedToKnownValues(): void
    {
        self::assertSame('variable', ProductInput::forCreate(['name' => 'X', 'type' => 'variable'])->get('type'));
        self::assertArrayHasKey('type', $this->fieldErrors(['name' => 'X', 'type' => 'grouped']));
    }

    public function testAttributesAreParsedIntoValueObjects(): void
    {
        $input = ProductInput::forCreate([
            'name' => 'X',
            'attributes' => [['name' => 'Size', 'options' => ['S', 'M'], 'variation' => true]],
        ]);

        self::assertCount(1, $input->attributes());
        self::assertSame('Size', $input->attributes()[0]->name);
        self::assertTrue($input->attributes()[0]->variation);
    }

    public function testSimpleFieldErrorsAreReportedEvenWhenAttributesAreAlsoBroken(): void
    {
        $errors = $this->fieldErrors([
            'name' => 'X',
            'regular_price' => 'free',
            'attributes' => 'not-an-array',
        ]);

        self::assertArrayHasKey('regular_price', $errors);
        self::assertArrayNotHasKey('attributes', $errors, 'attributes are parsed after the simpler fields');
    }
}
