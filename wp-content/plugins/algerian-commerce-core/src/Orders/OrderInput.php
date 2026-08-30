<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\AddressInput;

/**
 * Validates and normalizes an order write payload.
 *
 * Pure — no WordPress, no WooCommerce — so every rule is unit-testable: which
 * fields exist, which are required on a create, and which values are nonsense.
 *
 * Unknown fields are rejected rather than ignored (docs/SECURITY.md). The
 * fields this API emits but does not accept are dropped silently instead, so a
 * client can GET an order, change one thing and PATCH the whole object back.
 * That distinction matters more here than on a product: an order body is large,
 * mostly computed, and no client wants to strip fourteen keys by hand.
 *
 * Every *total* is read-only. Totals are what WooCommerce computes from the
 * lines, never what a caller sends. One amount is settable — see below — and it
 * is not a total.
 *
 * ## `shipping_amount` is writable and `shipping_total` is not, and that is the
 * whole distinction this class draws about money
 *
 * A back-office order needs to carry a delivery fee. Until backend step 4 the
 * only shipping line this shop could produce came from the checkout quote
 * (`Cart\CheckoutService::createOrder()`), so an order placed on the phone had
 * no way to charge for delivery at all.
 *
 * The obvious move — lift `shipping_total` out of READ_ONLY — is the wrong one,
 * and not for a squeamish reason. `shipping_total` is *derived*:
 * `WC_Abstract_Order::calculate_totals()` sums the order's shipping **line
 * items** into `set_shipping_total()` (`abstract-wc-order.php:2163`, verified
 * against the 11.0.1 that compose.yaml pins). Writing the prop would set a
 * number that the very next `calculate_totals()` overwrites, so a "settable
 * `shipping_total`" would be a field that appears to work and silently stops
 * working the next time anybody edits a line. It is read-only here because it
 * is read-only *there*.
 *
 * So the settable thing needs its own name, and `shipping_amount` is it. **How
 * a reader tells them apart:** `shipping_amount` is the one number this order
 * states about delivery; `shipping_total` is what the order's shipping lines
 * add up to. On every order this API writes they are the same money, because
 * `OrderRepository::replaceShippingLine()` collapses the statement to exactly
 * one line — which is the tell worth remembering: **send `shipping_amount`,
 * read `shipping_total`.** They can differ only on an order some other surface
 * gave two shipping lines, and then `shipping_total` is right and
 * `shipping_amount` is what *this* API last said.
 *
 * ### Why not `shipping_cost`
 *
 * Because this API already has a `shipping_cost` and it means the opposite.
 * `Analytics\RevenueReport::unavailable()` publishes the key with the sentence
 * *"What a courier charges the shop is not recorded. ac_shipments deliberately
 * has no cost column, and shipping_revenue above is the separate figure of what
 * the customer was charged."* Cost is the shop's side, revenue is the
 * customer's side, and the number here is unambiguously the customer's — it
 * feeds `shipping_revenue`, which `Analytics\AnalyticsRepository::revenueExtras()`
 * sums out of `shipping_total_amount`, the column this field ends up writing.
 * One API cannot hold both meanings of the word. The reference shop does call
 * it `shippingCost` (`EL/api/.../domain/Order.java:47`) and its own dashboard
 * then has to subtract it from revenue to get sales
 * (`DashboardRepositoryImpl.java:107`), which is the collision arriving on
 * schedule.
 *
 * `amount` is instead what this codebase has always called the customer's
 * delivery charge in the one place it was already settable: a shipping rule's
 * `amount` (`Shipping\ShippingRuleInput`), carried through
 * `Shipping\RateResolver::quote()` as a quote's `amount`, and handed to
 * `WC_Order_Item_Shipping::set_total()` as `$shipping['amount']` by
 * `Cart\CheckoutService::createOrder()`. Same quantity, same name, and now the
 * back office states directly what the tariff table would have stated for it.
 *
 * ### The name has to survive item 2
 *
 * Item 2 adds a `shipping_provider` — the courier a back-office order is going
 * out with — which belongs on the same shipping line, in `method_id`. The pair
 * `shipping_amount` + `shipping_provider` reads as two facts about one line,
 * and neither name has to move when the second arrives. `POST /checkout` can
 * take `shipping_amount` under the same name if it is ever allowed to override
 * its quote, but it is not allowed to today and this class is not the place
 * that would change: a shopper stating their own delivery price is exactly the
 * threat `Cart\LineInput` refuses by name, and §14's tariff is the answer.
 */
final class OrderInput
{
    /**
     * Computed, derived or identity fields the presenter emits.
     *
     * The money ones are here for a stronger reason than convenience: an order
     * total that a request can set is not a total, it is a suggestion.
     *
     * `shipping_total` stays in this list now that a delivery fee is settable,
     * and the class docblock argues why at length: it is derived from the
     * shipping *lines* by `calculate_totals()`, so a caller who states it is
     * stating something the next recompute will discard. The writable field
     * beside it is `shipping_amount`.
     */
    private const READ_ONLY = [
        'id', 'number', 'order_key', 'created_via', 'currency', 'version',
        'discount_total', 'shipping_total', 'total_tax', 'total', 'subtotal',
        'prices_include_tax', 'payment_url', 'is_editable', 'needs_payment',
        'stock_reduced', 'customer', 'date_created', 'date_modified',
        'date_paid', 'date_completed',
    ];

    private const STRING_FIELDS = ['payment_method', 'payment_method_title', 'customer_note'];

    private const MAX_NOTE = 5000;

    /**
     * A ceiling on a stated delivery fee.
     *
     * The same number as `Shipping\ShippingRuleInput::MAX_AMOUNT` and
     * `LineItemInput::MAX_PRICE`, and the first of those is the one that
     * actually justifies it: a shipping rule's amount is *this quantity* stated
     * in the tariff table, and its constant is commented "Nine million dinars
     * of delivery is a typo, not a tariff." A back-office order must not be
     * able to charge a delivery fee that no shipping rule could have quoted, so
     * the ceiling is inherited rather than invented.
     *
     * The numeric half of the argument is `LineItemInput::MAX_PRICE`'s: the fee
     * is summed into the order total, PHP turns an unbounded amount into `INF`
     * rather than an error, and `wp_wc_orders.total_amount` is
     * `decimal(26,8)`. A fee above eight figures is a typo or a probe, and both
     * deserve a 400 rather than a row nobody can read back.
     */
    private const MAX_SHIPPING_AMOUNT = 9999999.99;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        return new self(self::normalize($payload, true));
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        return new self(self::normalize($payload, false));
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return list<LineItemInput> */
    public function lineItems(): array
    {
        return $this->fields['line_items'] ?? [];
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return [
            ...self::STRING_FIELDS,
            'status',
            'customer_id',
            'billing',
            'shipping',
            'line_items',
            'shipping_amount',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ApiException with a per-field breakdown in error.details.fields
     */
    private static function normalize(array $payload, bool $isCreate): array
    {
        $errors = [];
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        foreach (self::STRING_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $payload[$field]);

            if (mb_strlen($value) > self::MAX_NOTE) {
                $errors[$field] = 'Must be at most ' . self::MAX_NOTE . ' characters.';
                continue;
            }

            $clean[$field] = $value;
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? OrderStatus::normalize((string) $payload['status']) : '';

            if (!OrderStatus::isKnown($status)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', OrderStatus::ALL) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        if (array_key_exists('customer_id', $payload)) {
            // 0 is a guest order, which is the normal case for a storefront
            // that does not force registration — so it is a value, not a gap.
            if (!is_numeric($payload['customer_id']) || (int) $payload['customer_id'] < 0) {
                $errors['customer_id'] = 'Must be a user id, or 0 for a guest.';
            } else {
                $clean['customer_id'] = (int) $payload['customer_id'];
            }
        }

        if (array_key_exists('billing', $payload)) {
            $clean['billing'] = AddressInput::forBilling($payload['billing'], $errors);
        }

        if (array_key_exists('shipping', $payload)) {
            $clean['shipping'] = AddressInput::forShipping($payload['shipping'], $errors);
        }

        if (array_key_exists('line_items', $payload)) {
            $clean['line_items'] = LineItemInput::listFromPayload($payload['line_items'], $errors);
        } elseif ($isCreate) {
            $errors['line_items'] = 'An order needs at least one line item.';
        }

        /*
         * Validated last, after the lines, because it is the second half of the
         * same sum: the order's total is the lines plus this. Its position is
         * also what keeps every existing error ordering intact — the breakdown
         * is built in field order and the REST suite asserts that order.
         *
         * `null` and `""` mean "this request says nothing about delivery", so
         * they are dropped rather than refused and rather than stored. That is
         * `LineItemInput`'s treatment of an absent `price`, deliberately
         * identical: the two fields are one concept — an amount a person typed
         * on an order — and a caller who learns the emptiness rule on a line
         * must not have to learn a second one here.
         *
         * **What the two empties do not share is what they mean afterwards.**
         * An empty line price hands that line back to the catalogue, because a
         * line has a catalogue price to fall back to. Delivery has no
         * catalogue, so an empty `shipping_amount` cannot mean "re-quote it" —
         * there is nothing to re-quote against without a destination, and §14's
         * tariff is reached from a cart, not from an order. It means *leave the
         * order's shipping line exactly as it is*, which is the only reading
         * that lets the round trip work: `OrderPresenter` emits `null` for a
         * line the checkout quoted, and PATCHing a fetched order back must not
         * delete the delivery charge the shopper already paid for.
         *
         * `0` is therefore the way to say "no delivery charge", and it is a
         * real statement rather than an absence — a zero shipping line, the
         * same shape `Shipping\RateResolver::quote()` writes when a basket
         * crosses a free-delivery threshold. It is also the only way to cancel
         * a fee, which is why conflating it with empty would strand every order
         * that was ever charged one.
         */
        if (array_key_exists('shipping_amount', $payload)
            && !in_array($payload['shipping_amount'], [null, ''], true)) {
            $amount = self::amount($payload['shipping_amount'], 'shipping_amount', $errors);

            if ($amount !== null) {
                $clean['shipping_amount'] = $amount;
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The order data is invalid.', ['fields' => $errors]);
        }

        return $clean;
    }

    /**
     * A stated delivery fee as the decimal string the caller typed.
     *
     * Null on refusal, with the reason already recorded under `$field`. Three
     * distinct ways to be wrong, three messages: a single "invalid amount"
     * tells a form which box to redden and nothing else.
     *
     * Zero is allowed and negative is not, for `LineItemInput::amount()`'s
     * reason and one of its own. The shared reason: a negative amount on an
     * order is a refund, and a refund is a different object with a different
     * route, not an order line with a minus sign. The one specific to shipping:
     * nothing downstream would catch it. `calculate_totals()` clamps a negative
     * **fee** so it cannot exceed what the order is worth
     * (`abstract-wc-order.php:2168-2172`, the `0 > $fee_total` branch) and
     * applies no such clamp to shipping — `:2158-2163` sums the shipping lines
     * exactly as they stand. A negative delivery fee would subtract from the
     * order total unchecked, which is a discount granted through a field named
     * for a charge.
     *
     * ## This is the third copy of this rule, and it is a copy on purpose
     *
     * The other two are `LineItemInput::amount()` and
     * `Shipping\ShippingRuleInput::money()`. All three refuse with the same
     * three sentences — `Must be an amount.`, `Cannot be negative.`,
     * `Is implausibly large.` — and that identity is a contract a panel binds
     * to, not a coincidence: a form that shows one wording for a line price and
     * another for a delivery fee is a form the operator has to read twice.
     *
     * Reusing step 2's helper was the alternative and was not taken.
     * `LineItemInput::amount()` is private, and the only way to call it from
     * here is to publish it — which exports a *line*'s validator as this
     * module's general money check, and the next caller reaches for it on
     * something that is neither a line nor a price. `ShippingRuleInput::money()`
     * is worse in the other direction: it lives in `Shipping\`, so `Orders\`
     * would take a cross-module dependency to check a string, and it
     * `number_format()`s to two decimals against a store precision it hard-codes
     * — right for a rule stored in our own `decimal(10,2)` column, wrong for an
     * amount whose rounding belongs to WooCommerce when it reaches the line
     * (the argument `LineItemInput`'s constructor property makes at length).
     *
     * The honest reading is that this API wants **one** money validator, shared
     * by all three, and that extraction is a refactor across two modules with
     * its own argument to make — not a side effect of adding a field. Until
     * somebody makes it, the three copies are kept identical by the unit tests
     * that assert each message by name.
     *
     * @param array<string, string> $errors
     */
    private static function amount(mixed $value, string $field, array &$errors): ?string
    {
        if (!is_numeric($value)) {
            $errors[$field] = 'Must be an amount.';

            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            $errors[$field] = 'Cannot be negative.';

            return null;
        }

        // Negated rather than `>`, which also catches the `INF` a JSON literal
        // like 1e400 decodes to — no comparison against a real number would.
        if (!($amount <= self::MAX_SHIPPING_AMOUNT)) {
            $errors[$field] = 'Is implausibly large.';

            return null;
        }

        // `is_numeric()` tolerates surrounding whitespace; the stored string
        // should not carry it onto the order's shipping line.
        return trim((string) $value);
    }
}
