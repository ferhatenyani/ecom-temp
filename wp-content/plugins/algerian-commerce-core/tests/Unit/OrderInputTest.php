<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Orders\LineItemInput;
use AlgerianCommerce\Orders\OrderInput;
use PHPUnit\Framework\TestCase;

final class OrderInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function fieldErrors(callable $build, array $payload): array
    {
        try {
            $build($payload);
        } catch (ApiException $exception) {
            return $exception->details()['fields'] ?? [];
        }

        self::fail('Expected the payload to be rejected.');
    }

    public function testCreateNeedsAtLeastOneLineItem(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forCreate'], []);

        self::assertArrayHasKey('line_items', $errors);
    }

    public function testCreateRejectsAnEmptyLineItemList(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forCreate'], ['line_items' => []]);

        self::assertArrayHasKey('line_items', $errors);
    }

    public function testUpdateDoesNotRequireLineItems(): void
    {
        $input = OrderInput::forUpdate(['status' => 'processing']);

        self::assertSame(['status' => 'processing'], $input->fields);
        self::assertFalse($input->has('line_items'));
    }

    public function testUpdateWithNothingUsefulIsEmpty(): void
    {
        self::assertTrue(OrderInput::forUpdate([])->isEmpty());
    }

    public function testUnknownFieldsAreRejected(): void
    {
        $errors = $this->fieldErrors(
            [OrderInput::class, 'forUpdate'],
            ['statsu' => 'processing', 'wilaya' => 16]
        );

        self::assertSame(['statsu' => 'Unknown field.', 'wilaya' => 'Unknown field.'], $errors);
    }

    /**
     * The round trip a client actually performs: GET an order, change one
     * field, PATCH the whole object back. Every computed field it emits has to
     * be dropped rather than rejected, or that pattern is impossible.
     */
    public function testEmittedReadOnlyFieldsAreDroppedNotRejected(): void
    {
        $input = OrderInput::forUpdate([
            'id' => 42,
            'number' => '42',
            'order_key' => 'wc_order_abc',
            'created_via' => 'rest-api',
            'currency' => 'DZD',
            'version' => '11.0.0',
            'prices_include_tax' => false,
            'discount_total' => '0.00',
            'shipping_total' => '400.00',
            'total_tax' => '0.00',
            'subtotal' => '3000.00',
            'total' => '3400.00',
            'payment_url' => 'https://example.test/pay',
            'is_editable' => true,
            'needs_payment' => true,
            'stock_reduced' => false,
            'customer' => ['id' => 3],
            'date_created' => '2026-08-11T09:00:00+00:00',
            'date_modified' => '2026-08-11T09:30:00+00:00',
            'date_paid' => null,
            'date_completed' => null,
            'customer_note' => 'Call before delivery',
        ]);

        self::assertSame(['customer_note' => 'Call before delivery'], $input->fields);
    }

    /** A total that a request can set is not a total. */
    public function testTotalCannotBeWritten(): void
    {
        $input = OrderInput::forUpdate(['total' => '1.00', 'customer_note' => 'x']);

        self::assertFalse($input->has('total'));
    }

    public function testStatusMustBeKnown(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], ['status' => 'shipped']);

        self::assertArrayHasKey('status', $errors);
    }

    public function testStatusIsNormalized(): void
    {
        self::assertSame('on-hold', OrderInput::forUpdate(['status' => 'wc-on-hold'])->get('status'));
    }

    public function testCustomerIdZeroIsAGuestOrderNotAnError(): void
    {
        self::assertSame(0, OrderInput::forUpdate(['customer_id' => 0])->get('customer_id'));
    }

    public function testCustomerIdRejectsNegativeAndNonNumeric(): void
    {
        self::assertArrayHasKey(
            'customer_id',
            $this->fieldErrors([OrderInput::class, 'forUpdate'], ['customer_id' => -1])
        );
        self::assertArrayHasKey(
            'customer_id',
            $this->fieldErrors([OrderInput::class, 'forUpdate'], ['customer_id' => 'me'])
        );
    }

    public function testLineItemsAreParsedIntoInputObjects(): void
    {
        $input = OrderInput::forCreate([
            'line_items' => [
                ['product_id' => 12, 'quantity' => 2],
                ['product_id' => 30, 'variation_id' => 31, 'quantity' => 1],
            ],
        ]);

        $items = $input->lineItems();

        self::assertCount(2, $items);
        self::assertContainsOnlyInstancesOf(LineItemInput::class, $items);
        self::assertSame([12, 0, 2], [$items[0]->productId, $items[0]->variationId, $items[0]->quantity]);
        self::assertSame([30, 31, 1], [$items[1]->productId, $items[1]->variationId, $items[1]->quantity]);
    }

    public function testLineItemErrorsAreKeyedByIndex(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forCreate'], [
            'line_items' => [
                ['product_id' => 12, 'quantity' => 1],
                ['product_id' => 0, 'quantity' => 0],
            ],
        ]);

        self::assertArrayHasKey('line_items.1.product_id', $errors);
        self::assertArrayHasKey('line_items.1.quantity', $errors);
        self::assertArrayNotHasKey('line_items.0.product_id', $errors);
    }

    /** The whole point of LineItemInput: catalogue prices, not caller prices. */
    public function testALinePriceCannotBeSet(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forCreate'], [
            'line_items' => [['product_id' => 12, 'quantity' => 1, 'price' => '0.01']],
        ]);

        self::assertArrayHasKey('line_items.0.price', $errors);
        self::assertStringContainsString('catalogue', $errors['line_items.0.price']);
    }

    public function testAddressErrorsArePrefixed(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], [
            'billing' => ['email' => 'not-an-email', 'country' => 'Algeria', 'wilaya' => '16'],
        ]);

        self::assertArrayHasKey('billing.email', $errors);
        self::assertArrayHasKey('billing.country', $errors);
        self::assertArrayHasKey('billing.wilaya', $errors);
    }

    public function testShippingHasNoEmail(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], [
            'shipping' => ['email' => 'someone@example.test'],
        ]);

        self::assertArrayHasKey('shipping.email', $errors);
        self::assertStringContainsString('billing', $errors['shipping.email']);
    }

    public function testEveryErrorIsReportedAtOnce(): void
    {
        // A caller fixing one field at a time across five round trips is a
        // worse API than one that says everything up front.
        $errors = $this->fieldErrors([OrderInput::class, 'forCreate'], [
            'status' => 'shipped',
            'customer_id' => -1,
            'nope' => 1,
            'billing' => ['country' => 'Algeria'],
            'line_items' => [['product_id' => 0, 'quantity' => 0]],
        ]);

        self::assertSame([
            'nope',
            'status',
            'customer_id',
            'billing.country',
            'line_items.0.product_id',
            'line_items.0.quantity',
        ], array_keys($errors));
    }
}
