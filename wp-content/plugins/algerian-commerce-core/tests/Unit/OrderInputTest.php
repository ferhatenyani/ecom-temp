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

    /**
     * A manual line price reaches the order input on both verbs.
     *
     * This used to assert the opposite — that a price was refused because
     * prices come from the catalogue. The rule is reversed deliberately; see
     * the LineItemInput class docblock for what replaced it.
     */
    public function testALinePriceIsAcceptedOnCreateAndOnUpdate(): void
    {
        foreach (['forCreate', 'forUpdate'] as $verb) {
            $input = OrderInput::{$verb}([
                'line_items' => [
                    ['product_id' => 12, 'quantity' => 1, 'price' => '0.01'],
                    ['product_id' => 30, 'quantity' => 2],
                ],
            ]);

            $items = $input->lineItems();

            self::assertSame('0.01', $items[0]->price, $verb . ' must carry a stated price');
            self::assertNull($items[1]->price, $verb . ' must leave an unpriced line to the catalogue');
        }
    }

    /**
     * The refusals still arrive as a per-field breakdown under the order's own
     * error envelope, keyed by line index — the shape a form binds to.
     */
    public function testLinePriceRefusalsAreKeyedLikeEveryOtherLineField(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forCreate'], [
            'line_items' => [
                ['product_id' => 12, 'quantity' => 1],
                ['product_id' => 12, 'quantity' => 1, 'price' => '-5'],
                ['id' => 91, 'price' => '500'],
            ],
        ]);

        self::assertSame('Cannot be negative.', $errors['line_items.1.price'] ?? null);
        self::assertArrayHasKey('line_items.2.price', $errors);
        self::assertArrayNotHasKey('line_items.0.price', $errors);
    }

    /**
     * A delivery fee reaches the order input on both verbs — backend step 4.
     *
     * The amount is carried as the string the caller typed rather than a float
     * or a two-decimal normalization, for the reason every money value in this
     * API is a string: this class is pure and cannot ask WooCommerce what the
     * store's price precision is, so rounding belongs where the amount lands.
     */
    public function testAShippingAmountIsAcceptedOnCreateAndOnUpdate(): void
    {
        foreach (['forCreate', 'forUpdate'] as $verb) {
            $input = OrderInput::{$verb}([
                'line_items' => [['product_id' => 12, 'quantity' => 1]],
                'shipping_amount' => '450.50',
            ]);

            self::assertTrue($input->has('shipping_amount'), $verb . ' must accept a delivery fee');
            self::assertSame('450.50', $input->get('shipping_amount'), $verb . ' must carry it verbatim');
        }
    }

    /**
     * The pair this field exists to keep apart. `shipping_total` is derived by
     * `calculate_totals()` from the order's shipping lines, so a caller who
     * states it is stating something the next recompute discards — it stays
     * read-only and is dropped rather than refused, so a whole-body PATCH
     * works. `shipping_amount` is the statement.
     */
    public function testShippingTotalStaysReadOnlyBesideTheSettableAmount(): void
    {
        $input = OrderInput::forUpdate(['shipping_total' => '999.00', 'shipping_amount' => '450']);

        self::assertFalse($input->has('shipping_total'));
        self::assertSame('450', $input->get('shipping_amount'));
    }

    /**
     * Empty means "this request says nothing about delivery", which is
     * `LineItemInput`'s treatment of an absent price and has to be, because the
     * two fields are one concept. It is *not* "no delivery charge": the
     * presenter emits null for a fee the checkout quoted, and PATCHing a
     * fetched order back must leave that fee alone rather than delete it.
     */
    public function testAnEmptyShippingAmountStatesNothing(): void
    {
        foreach ([null, ''] as $empty) {
            $input = OrderInput::forUpdate(['shipping_amount' => $empty, 'customer_note' => 'x']);

            self::assertFalse($input->has('shipping_amount'), var_export($empty, true) . ' must state nothing');
        }

        // And a body of nothing but an empty fee is a body that states nothing
        // at all, which is the 400 the service turns into "No supported fields
        // were provided."
        self::assertTrue(OrderInput::forUpdate(['shipping_amount' => null])->isEmpty());
    }

    /**
     * Zero is the only way to cancel a fee, so it cannot be folded in with the
     * empties — an order charged for delivery once would be charged for it
     * forever. Same call as `LineItemInput` makes about a free line.
     */
    public function testAZeroShippingAmountIsAnAmount(): void
    {
        self::assertSame('0', OrderInput::forUpdate(['shipping_amount' => '0'])->get('shipping_amount'));
        self::assertSame('0', OrderInput::forUpdate(['shipping_amount' => 0])->get('shipping_amount'));
    }

    /**
     * The three sentences, asserted by name.
     *
     * They are word for word `LineItemInput::amount()`'s, and this test is what
     * stops the two copies drifting: a panel binds one wording to a line price
     * and the same wording to a delivery fee, and a form that reddens two boxes
     * with two different explanations of the same mistake is a form read twice.
     * See `OrderInput::amount()` for why there are two copies at all.
     */
    public function testShippingAmountRefusalsMatchALinePriceWordForWord(): void
    {
        foreach ([
            ['-1', 'Cannot be negative.'],
            ['10000000.00', 'Is implausibly large.'],
            ['free', 'Must be an amount.'],
            [['450'], 'Must be an amount.'],
        ] as [$value, $message]) {
            $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], ['shipping_amount' => $value]);

            self::assertSame($message, $errors['shipping_amount'] ?? null, var_export($value, true));
        }
    }

    /**
     * The ceiling is `Shipping\ShippingRuleInput::MAX_AMOUNT` — a back-office
     * order must not be able to charge a delivery fee that no §14 rule could
     * have quoted — and the value exactly on it is a tariff, not a typo.
     */
    public function testTheShippingCeilingIsInclusive(): void
    {
        self::assertSame('9999999.99', OrderInput::forUpdate(['shipping_amount' => '9999999.99'])->get('shipping_amount'));
    }

    public function testADestinationIsAcceptedOnCreateAndOnUpdate(): void
    {
        foreach (['forCreate', 'forUpdate'] as $verb) {
            $input = OrderInput::{$verb}([
                'line_items' => [['product_id' => 12, 'quantity' => 1]],
                'wilaya_id' => 16,
                'commune_id' => 1234,
                'delivery_type' => 'desk',
            ]);

            // Ints, not the strings a JSON form may have sent, because
            // `OrderRepository::applyProps()` casts to (int) exactly as
            // `Cart\CheckoutService::createOrder()` does and the two writers
            // have to leave identical meta behind.
            self::assertSame(16, $input->get('wilaya_id'), $verb);
            self::assertSame(1234, $input->get('commune_id'), $verb);
            self::assertSame('desk', $input->get('delivery_type'), $verb);
        }

        self::assertSame(16, OrderInput::forUpdate(['wilaya_id' => '16'])->get('wilaya_id'));
    }

    /**
     * The round trip is the whole reason for this rule. `OrderPresenter` emits
     * all three keys and emits `null` for an order nobody has addressed, so a
     * client that GETs an order, changes a note and PATCHes the body back sends
     * three nulls — which must not be a 400 on keys it never touched, and must
     * not delete a destination either.
     */
    public function testAnEmptyDestinationStatesNothing(): void
    {
        foreach ([null, ''] as $empty) {
            $input = OrderInput::forUpdate([
                'wilaya_id' => $empty,
                'commune_id' => $empty,
                'delivery_type' => $empty,
                'customer_note' => 'x',
            ]);

            foreach (['wilaya_id', 'commune_id', 'delivery_type'] as $field) {
                self::assertFalse($input->has($field), $field . ' must state nothing for ' . var_export($empty, true));
            }
        }

        self::assertTrue(OrderInput::forUpdate(['wilaya_id' => null, 'commune_id' => null])->isEmpty());
    }

    /**
     * Where an id parts company with a fee, and the one asymmetry in this
     * class's emptiness rule.
     *
     * `shipping_amount` has a meaningful zero — *no delivery charge* — so `0`
     * is a statement there. An id has none: there is no commune 0, and
     * `Shipping\ShipmentSubscriber::destinationOf()` reads anything below 1 as
     * no destination at all. Dropping it would turn a broken picker into
     * silence and the order would confirm with `order_destination_missing` and
     * nothing to explain it.
     */
    public function testAZeroIdIsRefusedWhereAZeroFeeIsAStatement(): void
    {
        self::assertSame('0', OrderInput::forUpdate(['shipping_amount' => '0'])->get('shipping_amount'));

        foreach (['wilaya_id', 'commune_id'] as $field) {
            $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], [$field => 0]);

            self::assertSame('Must be a positive id.', $errors[$field] ?? null, $field);
        }
    }

    /**
     * One sentence for every way an id can be wrong, and it is
     * `Shipping\ShipmentInput::requiredId()`'s word for word.
     *
     * That identity is a contract: the panel offers the same destination picker
     * on the order drawer and on the parcel drawer, and one mistake must not
     * produce two wordings. A float is in the list because `16.5` is numeric
     * and casts to a real commune id — a picker that produced it is not
     * pointing at that commune.
     */
    public function testDestinationIdRefusalsMatchShipmentInputWordForWord(): void
    {
        foreach ([-4, 0, '16.5', 'sixteen', [16], true] as $value) {
            $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], ['commune_id' => $value]);

            self::assertSame('Must be a positive id.', $errors['commune_id'] ?? null, var_export($value, true));
        }
    }

    /**
     * Both halves report independently, because a picker bound to two selects
     * has two boxes to redden. This is the one place in this class where two
     * fields are so nearly one field that collapsing them would have looked
     * reasonable.
     */
    public function testBothDestinationIdsReportTheirOwnError(): void
    {
        $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], ['wilaya_id' => 0, 'commune_id' => -1]);

        self::assertArrayHasKey('wilaya_id', $errors);
        self::assertArrayHasKey('commune_id', $errors);
    }

    /**
     * The enum is `Shipping\Destination::DELIVERY_TYPES`, imported rather than
     * restated, and the message is `ShipmentInput`'s and `ShippingRuleInput`'s
     * built from that same constant — so a third journey cannot arrive on two
     * of the three routes and not the last.
     */
    public function testDeliveryTypeTakesTheSharedEnum(): void
    {
        foreach (['home', 'desk'] as $type) {
            self::assertSame($type, OrderInput::forUpdate(['delivery_type' => $type])->get('delivery_type'));
        }

        // Normalized the way `Destination::isKnownDeliveryType()` normalizes,
        // so what reaches the meta is what `destinationOf()` matches on.
        self::assertSame('desk', OrderInput::forUpdate(['delivery_type' => '  DESK '])->get('delivery_type'));

        foreach (['pickup', 'locker', ['desk'], 7] as $value) {
            $errors = $this->fieldErrors([OrderInput::class, 'forUpdate'], ['delivery_type' => $value]);

            self::assertSame(
                'Must be one of: home, desk.',
                $errors['delivery_type'] ?? null,
                var_export($value, true)
            );
        }
    }

    /**
     * No default is invented here, and that is the decision rather than an
     * omission.
     *
     * `ShipmentSubscriber::destinationOf()` already falls back to `home` for a
     * missing or unrecognised value and argues why it is the one field safe to
     * default. A second default in this class would give one fact two owners
     * that can drift, and would make a back-office order *claim* a journey
     * nobody chose.
     */
    public function testTheDeliveryTypeIsNotDefaulted(): void
    {
        $input = OrderInput::forCreate([
            'line_items' => [['product_id' => 12, 'quantity' => 1]],
            'wilaya_id' => 16,
            'commune_id' => 1234,
        ]);

        self::assertFalse($input->has('delivery_type'));
    }

    /**
     * Half a destination passes *this* class, and has to.
     *
     * Whether the pair holds together depends on what the order already stores
     * — an operator correcting only the commune of an order that already names
     * a wilaya is the single most useful write this route makes — and a pure
     * validator cannot see an order. `OrderService::guardDestinationResolves()`
     * owns it, on `shipping_provider`'s stated rule: shape here, runtime state
     * in the service.
     */
    public function testAHalfDestinationIsTheServicesQuestionNotThisOne(): void
    {
        self::assertSame(1234, OrderInput::forUpdate(['commune_id' => 1234])->get('commune_id'));
        self::assertFalse(OrderInput::forUpdate(['commune_id' => 1234])->has('wilaya_id'));
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
