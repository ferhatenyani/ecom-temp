<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Payments\PaymentProviderRegistry;
use AlgerianCommerce\Products\OptionSelection;
use AlgerianCommerce\Products\OptionSetRepository;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\RateResolver;
use AlgerianCommerce\Shipping\ShippingRuleRepository;
use WC_Cart;
use WC_Order;
use WP_REST_Request;

/**
 * Turns a cart into an order — roadmap §59b, docs/PLAN.md §53.
 *
 * **This is where the three abstractions that were built separately finally
 * meet**, and the reason it is one class rather than three: §14's shipping
 * rules decide what delivery costs, §58's payment registry decides how the
 * order can be paid for, and `Orders/` owns the order once it exists. Checkout
 * is the only place in this codebase that needs all three in one request.
 *
 * ## What it deliberately does not use
 *
 * **Not `ShippingService::rates()`**, which asserts `ac_manage_shipping` — a
 * staff capability that a shopper will never hold. The tariff itself is
 * `RateResolver`, which is pure and takes rules plus a destination; going
 * through the staff service would mean either widening that capability or
 * handing the storefront an admin credential, and §44 forbids the second.
 * A shopper being quoted a delivery price is not reading the shop's shipping
 * configuration.
 *
 * **Not a courier's live quote.** `ShippingProviderInterface::getShippingRates()`
 * asks Yalidine or ZR Express what *they* would charge, which is a fact about
 * the shop's costs and not about the customer's bill. What a customer pays is
 * `ac_shipping_rates` (§14) and nothing else — CLAUDE.md: "What the shop
 * *charges* is separate from what a courier quotes."
 *
 * **Not WooCommerce's `WC_Checkout`.** It is built around form posts, nonces
 * and `wp_safe_redirect()`, which is the half that never runs headless — the
 * §61/§62 argument again. The parts worth reusing are `WC_Cart`'s arithmetic,
 * which `CartService` already uses, and order creation, which is
 * `wc_create_order()` plus WooCommerce's own CRUD here.
 *
 * ## The rule
 *
 * Everything a caller sends is a request. The address is theirs to state; the
 * **prices are not**. Line prices come from the cart, which re-reads the
 * catalogue; the shipping cost comes from `RateResolver` against the
 * destination the caller named, never from a `shipping_total` in the payload;
 * and the payment method must be one `Plugin::paymentProviders()` actually
 * registered, so a disabled gateway cannot be selected by naming it.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartSession $session,
        private readonly ShippingRuleRepository $rules,
        private readonly PaymentProviderRegistry $payments,
        private readonly Logger $logger,
        private readonly CartService $cartService,
        private readonly OptionSetRepository $optionSets
    ) {
    }

    /**
     * What delivery costs for this cart to this destination — §14.
     *
     * The subtotal comes from the cart rather than the request, which is what
     * makes a free-shipping threshold a threshold: a caller that could state
     * its own subtotal could claim to have crossed one.
     *
     * @param array{wilaya_id: int, commune_id: int, delivery_type: string} $criteria
     * @return array<string, mixed>
     */
    public function shippingRates(WP_REST_Request $request, array $criteria): array
    {
        $this->session->load($request);

        $cart = $this->requireCart();
        $cart->calculate_totals();

        $destination = $this->destination($criteria);
        $subtotal = (string) wc_format_decimal((string) $cart->get_subtotal(), wc_get_price_decimals());

        return [
            'destination' => [
                'wilaya_id' => $destination->wilayaId,
                'commune_id' => $destination->communeId,
                'delivery_type' => $destination->deliveryType,
            ],
            'subtotal' => $subtotal,
            'rates' => $this->quotes($destination, $subtotal),
        ];
    }

    /**
     * Place the order.
     *
     * @param array{
     *     billing: array<string, string>, shipping: array<string, string>,
     *     wilaya_id: int, commune_id: int, delivery_type: string,
     *     payment_method: string, customer_note: string
     * } $input
     * @return array<string, mixed>
     */
    public function place(WP_REST_Request $request, array $input): array
    {
        $this->session->load($request);

        $cart = $this->requireCart();

        if ($cart->is_empty()) {
            throw ApiException::invalidRequest('The cart is empty.', [
                'fields' => ['cart' => 'Add something before checking out.'],
            ]);
        }

        $cart->calculate_totals();

        /*
         * A line whose options no longer price cannot be sold — roadmap §83.
         *
         * `OptionPriceSubscriber` falls back to the catalogue price and records
         * why, which is the safe direction for a *cart* (nothing is charged
         * yet). It is the wrong direction for an order: placing one would sell
         * gift wrap at nothing and there would be no record that it happened.
         * The shopper is told to re-choose, which is a nuisance; the
         * alternative is a shop that quietly undercharges.
         */
        $problems = $this->cartService->optionProblems();

        if ($problems !== []) {
            throw ApiException::invalidRequest('Some items need their options chosen again.', [
                'fields' => ['cart' => implode(' ', $problems)],
            ]);
        }

        $provider = $this->requireProvider($input['payment_method']);
        $destination = $this->destination($input);
        $subtotal = (string) wc_format_decimal((string) $cart->get_subtotal(), wc_get_price_decimals());

        $shipping = null;

        // CartService::needsShipping(), not WC_Cart::needs_shipping() — see that
        // method for why the WooCommerce one is permanently false here and what
        // it cost to find out.
        if (CartService::needsShipping($cart)) {
            $shipping = $this->requireShippingQuote($destination, $subtotal);
        }

        $order = $this->createOrder($cart, $input, $shipping);

        // The cart is emptied only once the order exists. A failure above this
        // line leaves the shopper's basket intact, which is the direction that
        // does not lose a sale — an order that was never created is a retry,
        // an emptied cart is a customer starting again.
        $cart->empty_cart();
        $this->session->save();

        $this->logger->info('Checkout placed an order', [
            'order_id' => $order->get_id(),
            'payment_method' => $provider,
            'items' => count($order->get_items()),
        ]);

        return [
            'order' => [
                'id' => $order->get_id(),
                'number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'currency' => $order->get_currency(),
                'total' => (string) wc_format_decimal($order->get_total(), wc_get_price_decimals()),
                'payment_method' => $provider,
            ],
            // §58's hand-off is a separate, explicit call: POST
            // /orders/{id}/payments. Checkout does not start it, because a
            // payment that fails must not orphan an order that succeeded, and
            // because `PaymentService::createPayment()` already owns the
            // transaction row, the audit entry and the provider call.
            'next' => [
                'action' => 'create_payment',
                'endpoint' => '/orders/' . $order->get_id() . '/payments',
                'payment_method' => $provider,
            ],
        ];
    }

    /**
     * Build the order from the cart — WooCommerce's own CRUD throughout.
     *
     * `wc_create_order()` then `add_product()` per line rather than
     * `WC_Checkout::create_order()`: the latter reads `$_POST` and fires the
     * form-oriented half of the checkout flow. Adding the lines explicitly also
     * means the order records **the cart's** prices, which are the ones the
     * shopper was shown a moment earlier.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $shipping
     */
    /**
     * Chosen options land on the order line item — roadmap §83.
     *
     * Two shapes, on purpose. **Visible meta** — "Gravure: AB" — is what
     * WooCommerce already renders on a packing slip, in the admin order screen
     * and in its own emails, so fulfilment sees what to engrave without anybody
     * teaching those surfaces about this plugin. **Hidden `_ac_options`** keeps
     * the structured selection with the ids and the deltas that applied *at the
     * time of sale*, frozen — the same argument migrations 009 and 010 make for
     * freezing a payload at queue time. A shop that later renames "gold" to
     * "brass" must not change what an order says it sold.
     *
     * Nothing here is read back from the request. The line came out of the
     * cart, which was priced from the catalogue on the pass immediately above.
     *
     * @param array<string, mixed> $line
     */
    private function attachOptions(WC_Order $order, int $itemId, array $line): void
    {
        $chosen = $line[OptionPriceSubscriber::DATA_KEY] ?? null;

        if (!is_array($chosen) || $chosen === [] || $itemId <= 0) {
            return;
        }

        $item = $order->get_item($itemId);

        if (!$item instanceof \WC_Order_Item_Product) {
            return;
        }

        $product = $line['data'] ?? null;

        /*
         * The catalogue's price, read fresh — never the cart line's own. That
         * object has had `set_price()` called on it by `OptionPriceSubscriber`
         * and re-pricing against it double-counts the surcharge; see that
         * class's `cataloguePrice()` for the measurement. Here it only feeds
         * the below-zero check, but a wrong base there would refuse a
         * legitimate order rather than mispricing one, which is no better.
         */
        $catalogue = $product instanceof \WC_Product ? wc_get_product($product->get_id()) : null;

        $priced = $product instanceof \WC_Product
            ? OptionSelection::price(
                $this->optionSets->forPurchase($product),
                $chosen,
                (string) ($catalogue instanceof \WC_Product ? $catalogue->get_price() : $product->get_price())
            )
            : null;

        if ($priced === null) {
            return;
        }

        foreach ($priced->toItemMeta() as $label => $value) {
            $item->add_meta_data($label, $value);
        }

        $item->add_meta_data('_ac_options', $priced->toArray());
        $item->save();
    }

    private function createOrder(WC_Cart $cart, array $input, ?array $shipping): WC_Order
    {
        $order = wc_create_order(['status' => 'pending']);

        if (!$order instanceof WC_Order) {
            throw ApiException::internal('The order could not be created.');
        }

        foreach ($cart->get_cart() as $line) {
            $product = $line['data'] ?? null;

            if ($product === null) {
                continue;
            }

            $itemId = $order->add_product(
                $product,
                (int) ($line['quantity'] ?? 1),
                [
                    'subtotal' => (float) ($line['line_subtotal'] ?? 0),
                    'total' => (float) ($line['line_total'] ?? 0),
                ]
            );

            $this->attachOptions($order, (int) $itemId, $line);
        }

        foreach ($cart->get_coupons() as $code => $coupon) {
            $order->apply_coupon((string) $code);
        }

        if ($shipping !== null) {
            $item = new \WC_Order_Item_Shipping();
            $item->set_method_title((string) $shipping['label']);
            $item->set_method_id((string) $shipping['provider']);
            $item->set_total((float) $shipping['amount']);
            $order->add_item($item);
        }

        $order->set_address($input['billing'], 'billing');
        $order->set_address($input['shipping'] !== [] ? $input['shipping'] : $input['billing'], 'shipping');
        $order->set_payment_method($input['payment_method']);

        if ($input['customer_note'] !== '') {
            $order->set_customer_note($input['customer_note']);
        }

        // The destination the tariff was quoted against, kept with the order so
        // a later shipment does not have to guess it back out of a free-text
        // address — which is precisely the guess `Shipping\ShipmentInput`
        // refuses to make.
        $order->update_meta_data('_ac_wilaya_id', (int) $input['wilaya_id']);
        $order->update_meta_data('_ac_commune_id', (int) $input['commune_id']);
        $order->update_meta_data('_ac_delivery_type', (string) $input['delivery_type']);

        if (is_user_logged_in()) {
            $order->set_customer_id(get_current_user_id());
        }

        $order->calculate_totals(false);
        $order->save();

        return $order;
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException 400 when this shop does not deliver there
     */
    private function requireShippingQuote(Destination $destination, string $subtotal): array
    {
        $quotes = $this->quotes($destination, $subtotal);

        if ($quotes === []) {
            throw ApiException::invalidRequest('This shop does not deliver to that destination.', [
                'fields' => ['commune_id' => 'No shipping rule matches it.'],
            ]);
        }

        // The cheapest, deterministically. A checkout that has to pick for the
        // customer picks the one they would have.
        usort($quotes, static fn (array $a, array $b): int => ($a['amount'] <=> $b['amount'])
            ?: strcmp((string) $a['provider'], (string) $b['provider']));

        return $quotes[0];
    }

    /**
     * The shop's own tariff for a destination, per registered courier.
     *
     * @return list<array<string, mixed>>
     */
    private function quotes(Destination $destination, string $subtotal): array
    {
        $rules = $this->rules->active();
        $decimals = wc_get_price_decimals();
        $currency = get_woocommerce_currency();
        $quotes = [];

        foreach ($this->shippingProviderNames() as $provider) {
            $rule = RateResolver::resolve($rules, $destination, $provider);

            if ($rule !== null) {
                $quotes[] = ['provider' => $provider]
                    + RateResolver::quote($rule, $subtotal, $decimals, $currency)->toArray();
            }
        }

        return $quotes;
    }

    /**
     * Which couriers a rule may name.
     *
     * A rule with an empty provider is the national fallback and applies to
     * every courier, so the list has to include the empty string or a shop that
     * has only written fallback rules quotes nothing.
     *
     * @return list<string>
     */
    private function shippingProviderNames(): array
    {
        $names = [''];

        foreach ($this->rules->active() as $rule) {
            $provider = trim((string) $rule->provider);

            if ($provider !== '' && !in_array($provider, $names, true)) {
                $names[] = $provider;
            }
        }

        return $names;
    }

    /** @throws ApiException 400 when the method is not one this shop offers */
    private function requireProvider(string $method): string
    {
        if ($this->payments->isEmpty()) {
            throw ApiException::invalidRequest('This shop cannot take payments yet.', [
                'fields' => ['payment_method' => 'No payment method is configured.'],
            ]);
        }

        if ($method === '') {
            return $this->payments->defaultName();
        }

        if (!$this->payments->has($method)) {
            throw ApiException::invalidRequest('That payment method is not available.', [
                'fields' => ['payment_method' => 'Available: ' . implode(', ', $this->payments->names()) . '.'],
            ]);
        }

        return $method;
    }

    /** @param array{wilaya_id: int, commune_id: int, delivery_type: string} $criteria */
    private function destination(array $criteria): Destination
    {
        $type = (string) $criteria['delivery_type'];

        if (!Destination::isKnownDeliveryType($type)) {
            throw ApiException::invalidRequest('That delivery type is not supported.', [
                'fields' => ['delivery_type' => 'Unknown delivery type.'],
            ]);
        }

        return new Destination((int) $criteria['wilaya_id'], (int) $criteria['commune_id'], $type);
    }

    private function requireCart(): WC_Cart
    {
        $cart = WC()->cart;

        if (!$cart instanceof WC_Cart) {
            throw ApiException::internal('The cart is unavailable.');
        }

        return $cart;
    }
}
