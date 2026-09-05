<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\Account\AccountSession;
use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Payments\PaymentProviderRegistry;
use AlgerianCommerce\Products\OptionSelection;
use AlgerianCommerce\Products\OptionSetRepository;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ProviderRegistry;
use AlgerianCommerce\Shipping\RateResolver;
use AlgerianCommerce\Shipping\ShippingRuleRepository;
use AlgerianCommerce\Shipping\ShopperRates;
use AlgerianCommerce\Tracking\TrackingController;
use AlgerianCommerce\Tracking\TrackingLink;
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
 * staff capability that a shopper will never hold. Going through the staff
 * service would mean either widening that capability or handing the storefront
 * an admin credential, and §44 forbids the second. A shopper being quoted a
 * delivery price is not reading the shop's shipping configuration. The pricing
 * itself is `Shipping\ShopperRates`, which is pure and takes the couriers, the
 * rules and a destination.
 *
 * **Not WooCommerce's `WC_Checkout`.** It is built around form posts, nonces
 * and `wp_safe_redirect()`, which is the half that never runs headless — the
 * §61/§62 argument again. The parts worth reusing are `WC_Cart`'s arithmetic,
 * which `CartService` already uses, and order creation, which is
 * `wc_create_order()` plus WooCommerce's own CRUD here.
 *
 * ## A courier's live quote *is* now part of the customer's bill
 *
 * This class used to say the opposite, in a paragraph that has been removed
 * rather than quietly edited, because a reader who remembers it deserves to
 * know it was overturned on purpose. It said: `getShippingRates()` is a fact
 * about the shop's costs, what a customer pays is `ac_shipping_rates` and
 * nothing else, and it cited CLAUDE.md — *"What the shop charges is separate
 * from what a courier quotes."*
 *
 * That sentence is still true and still the rule. What was wrong was the
 * conclusion drawn from it: that the two numbers being different things means a
 * courier's number may never reach a bill. Under §14 alone a shop can only
 * charge what somebody typed into a tariff table, which is why
 * `GET /checkout/shipping-rates` could not change with the commune unless an
 * operator had priced that commune by hand — 1 541 of them. Asking the courier
 * is how a delivery charge becomes the price of the actual journey.
 *
 * The separation is kept where it does the work, in `ShopperRates`: the two
 * sources never merge, exactly one of them produces each row, and every row is
 * **labelled** with which — so the order can record whether a number came from
 * a courier or a rule, and nobody has to guess afterwards. CLAUDE.md's own next
 * sentence is that `GET /shipping/rates` returns both sources *each labelled*;
 * this is the same discipline applied on the checkout side, where a shopper can
 * only be shown one number per courier.
 *
 * The one thing a courier may not outrank is a free-delivery threshold, which
 * is a promise to the customer rather than a price — `ShopperRates` argues it.
 *
 * ## And the shopper may now say which courier
 *
 * `POST /checkout` takes an optional `shipping_provider`. It is a *choice among
 * the quotes this shop just published*, never a price and never a courier the
 * shop does not have — see `requireShippingQuote()`, which owns the whole
 * argument about what "a courier that serves this destination" resolves to and
 * why a missing row gets two different refusals.
 *
 * **Absent means exactly what it meant before the field existed**, which is the
 * one property this change could not be allowed to cost: the cheapest row wins,
 * with the same tie-break, so a storefront that has never heard of the field
 * places the order it placed yesterday. That is not a claim about intent — it
 * is the empty-string branch of `ShopperRates::choose()`, carrying the sort
 * that used to live in this class unmodified.
 *
 * The field is on `POST /checkout` and deliberately not on
 * `GET /checkout/shipping-rates`. That route's entire job is to return every
 * row so the storefront can render the choice; filtering it to one would be
 * asking the shopper to choose from a list of one.
 *
 * ## The rule
 *
 * Everything a caller sends is a request. The address is theirs to state; the
 * **prices are not**. Line prices come from the cart, which re-reads the
 * catalogue; the shipping cost comes from `ShopperRates` against the
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
        private readonly OptionSetRepository $optionSets,
        /*
         * Roadmap §84. Optional so every existing construction keeps working; a
         * checkout built without it simply returns no `tracking` block, which is
         * what §59b did.
         */
        private readonly ?TrackingLink $tracking = null,
        /*
         * The couriers, for their live rates. Optional for the same reason
         * `$tracking` is and with the same consequence spelled out: a checkout
         * built without a registry quotes the §14 tariff alone, which is what
         * this class did before couriers were asked. `Plugin::checkoutService()`
         * always passes one, so that path is a test seam rather than a
         * configuration a shop can end up in by accident.
         */
        private readonly ?ProviderRegistry $providers = null,
        /*
         * The shopper's session, so an order placed by a signed-in customer is
         * owned by that customer rather than by whichever WordPress user
         * happened to authenticate the transport. The storefront proxy sends an
         * Application Password over HTTP Basic, which makes `is_user_logged_in()`
         * true for the service account on every checkout — the wrong owner. The
         * customer is identified by the `X-Customer-Token` header, which is
         * what `AccountSession::current()` resolves. Optional so tests and any
         * older construction still work; a checkout built without it falls back
         * to guest orders, which is the safe direction.
         */
        private readonly ?AccountSession $accountSession = null
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
     *     payment_method: string, customer_note: string,
     *     shipping_provider?: string
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

        /*
         * The last line of defence against oversell — every cart line is
         * checked against live stock before the order is written. The cart
         * update path enforces the same rule, and the storefront clamps the
         * stepper, but this is the door orders come through and a stale
         * client that skipped the earlier gates would still land here.
         * `OrderStockSubscriber` reduces stock *after* the order is created,
         * so a check *before* creation is what prevents inventory going
         * negative on a race that opened between add-to-cart and checkout.
         */
        $this->assertStockAvailable($cart);

        $provider = $this->requireProvider($input['payment_method']);
        $destination = $this->destination($input);
        $subtotal = (string) wc_format_decimal((string) $cart->get_subtotal(), wc_get_price_decimals());

        $shipping = null;

        // CartService::needsShipping(), not WC_Cart::needs_shipping() — see that
        // method for why the WooCommerce one is permanently false here and what
        // it cost to find out.
        if (CartService::needsShipping($cart)) {
            /*
             * The shopper's courier, or '' for "you pick". Read with `??` and
             * cast rather than assumed present, because `place()` is called
             * directly by tests and by `Plugin`'s wiring as well as by the
             * route that defaults it, and an array key that only exists when
             * one particular caller supplies it is a notice waiting for the
             * other one.
             */
            $shipping = $this->requireShippingQuote(
                $destination,
                $subtotal,
                (string) ($input['shipping_provider'] ?? '')
            );
        }

        $order = $this->createOrder($cart, $input, $shipping, $this->resolveCustomerId($request));

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
            // Roadmap §84. Minted here because this is the one moment the caller
            // is provably the person who placed the order — after this the only
            // way back to it is the token, which is the whole design.
            'tracking' => $this->trackingBlock($order),
        ];
    }

    /**
     * The tracking token, and the link when this shop knows its storefront.
     *
     * `endpoint` is always present and is this API's own route, so a storefront
     * that renders its own tracking page has what it needs. `url` appears only
     * when §71's `store.storefront_url` is set: this backend cannot derive it —
     * WordPress's permalink is the admin domain — and §62 refused the same guess
     * for canonical URLs. A guessed URL here would be printed in a confirmation
     * email and send a customer to a login screen they have no account for.
     *
     * @return array<string, string>
     */
    private function trackingBlock(WC_Order $order): array
    {
        if ($this->tracking === null) {
            return [];
        }

        $token = $this->tracking->tokenFor($order);

        if ($token === '') {
            return [];
        }

        $block = [
            'token' => $token,
            'endpoint' => '/orders/track?' . TrackingController::PARAM . '=' . rawurlencode($token),
        ];

        $url = $this->tracking->urlFor($order);

        if ($url !== '') {
            $block['url'] = $url;
        }

        return $block;
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

    private function createOrder(WC_Cart $cart, array $input, ?array $shipping, int $customerId = 0): WC_Order
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
            // The courier this line is for. Now always a registered provider's
            // name rather than the empty string the rules-derived fallback used
            // to produce — this is the second half of the labelling pair, and
            // "which courier" is unanswerable while it says nothing.
            $item->set_method_id((string) $shipping['provider']);
            $item->set_total((float) $shipping['amount']);

            /*
             * Which of the two sources priced this line — the first half of the
             * pair, and the reason it is written at all: months later an
             * operator looking at this order has to be able to tell whether 550
             * was a courier's answer or a row somebody typed into the tariff
             * table, and the amount cannot tell them.
             *
             * Frozen onto the line rather than recomputed on read, for the
             * reason `OrderRepository::CATALOGUE_PRICE_META` gives about a
             * catalogue price: re-resolving it later answers "where would this
             * number come from today", which is a different question and drifts
             * the moment a courier is switched on, a tariff row is edited or a
             * destination mapping is synced.
             */
            $item->add_meta_data(OrderRepository::RATE_SOURCE_META, (string) $shipping['source']);

            $order->add_item($item);
        }

        $order->set_address($input['billing'], 'billing');
        $order->set_address($input['shipping'] !== [] ? $input['shipping'] : $input['billing'], 'shipping');
        $order->set_payment_method($input['payment_method']);

        if ($input['customer_note'] !== '') {
            $order->set_customer_note($input['customer_note']);
        }

        /*
         * The destination the tariff was quoted against, kept with the order so
         * a later shipment does not have to guess it back out of a free-text
         * address — which is precisely the guess `Shipping\ShipmentInput`
         * refuses to make.
         *
         * The three keys were literals here, and are now
         * `OrderRepository`'s constants — the values are unchanged and the
         * storage is byte for byte what it was, so every order this checkout
         * has ever placed still reads back. What changed is that
         * `POST /orders` learned to write a destination too, and a fact with
         * two writers cannot be spelled in two places: see `WILAYA_META`, which
         * argues why identical shape between this method and
         * `OrderRepository::applyProps()` is the whole reason
         * `Shipping\ShipmentSubscriber` need not know which door an order came
         * through.
         */
        $order->update_meta_data(OrderRepository::WILAYA_META, (int) $input['wilaya_id']);
        $order->update_meta_data(OrderRepository::COMMUNE_META, (int) $input['commune_id']);
        $order->update_meta_data(OrderRepository::DELIVERY_TYPE_META, (string) $input['delivery_type']);

        /*
         * The buyer, not the transport's authenticator. Resolved from the
         * customer session in `place()` — see `resolveCustomerId()` for why
         * `is_user_logged_in()` is the wrong question here. Zero for guests,
         * which is what WooCommerce expects on an anonymous order.
         */
        if ($customerId > 0) {
            $order->set_customer_id($customerId);
        }

        $order->calculate_totals(false);
        $order->save();

        return $order;
    }

    /**
     * The quote the shopper chose, or the cheapest — backend step 2.
     *
     * The seam this replaces was documented here in full: the rows are already
     * one per courier and already carry the `provider` they belong to, so
     * honouring a choice is selecting the matching row rather than sorting.
     * That prediction held exactly, and the selection itself is
     * `Shipping\ShopperRates::choose()` — pure, so the branch where a shopper
     * picks a courier that is *not* the cheapest can be tested on an install
     * where no courier can be switched on. What is left here is the part that
     * needs the registry: turning "no row" into the right refusal.
     *
     * Nothing else moved. The label pair written in `createOrder()` is read off
     * whichever row wins, so an order records where its number came from
     * whether the shopper picked it or the sort did.
     *
     * ## Three refusals, not one, because they have three different fixes
     *
     * All three are 400s, and a storefront that collapsed them would be logging
     * "checkout failed" for a shop that is misconfigured, a build that is
     * stale, and a customer who changed their mind — three problems with
     * nothing in common.
     *
     *  - **Nobody delivers there.** Keyed `commune_id`, because the destination
     *    is what is wrong. Unchanged from before this method took a choice.
     *  - **This shop has no such courier.** Keyed `shipping_provider`. The name
     *    is not one `Plugin::shippingProviders()` registered, so it is wrong for
     *    every destination and every basket — a stale storefront build, or a
     *    hand-made request. A shopper cannot fix this; a developer can.
     *  - **That courier does not reach there.** Also keyed `shipping_provider`.
     *    The courier is real and simply has no price for this journey. The
     *    normal way to arrive here is a shopper who picked a courier and then
     *    changed wilaya, and the fix is for the storefront to re-quote.
     *
     * ## "The providers that actually serve the destination" is the row list
     *
     * The step asks for the choice to be validated against the providers that
     * serve the destination, and the definition that resolves to is: **a
     * courier serves this destination if and only if it produced a row.**
     *
     * The tempting alternative — ask the adapter whether it maps this commune —
     * is wrong in the direction that matters most on this install.
     * `ManualProvider::getShippingRates()` returns `[]` by design, because
     * in-house delivery publishes no rate API at all; under that definition the
     * one courier a credential-less shop actually has would be unchoosable
     * everywhere, while §14 prices it perfectly well. `ShopperRates` already
     * put the tariff behind every courier for exactly this reason, and a row is
     * the result of that whole precedence — courier, then tariff, free delivery
     * above both. A row is a price the shopper can be charged, and being
     * charged is what choosing a courier means here.
     *
     * It is also the only definition that cannot contradict the screen. The row
     * list is what `GET /checkout/shipping-rates` published a moment ago, so
     * the radio buttons and this validation are the same list by construction,
     * and a shopper can never be refused for choosing something the shop just
     * offered them. Any second source of truth would eventually disagree with
     * the first, and it would disagree at the checkout.
     *
     * ## Why `ProviderRegistry::get()`'s own 400 is not reused
     *
     * It exists and it refuses an unregistered name already, and it is wrong
     * here three times over: it keys the failure `provider` rather than
     * `shipping_provider`, so a panel binding on the field name gets nothing;
     * it says *"The shipment data is invalid."*, which is a sentence about a
     * different route; and it publishes `available` — **every registered
     * courier** — which on this public endpoint would hand an anonymous caller
     * the shop's courier configuration. `GET /shipping/providers` is gated on
     * `Capabilities::MANAGE_SHIPPING` precisely because that list is staff
     * knowledge. What is safe to name here is the serving set, because
     * `GET /checkout/shipping-rates` already publishes it to the same caller.
     *
     * @return array<string, mixed>
     * @throws ApiException 400 when this shop does not deliver there, or the
     *                      chosen courier is not one that can carry it
     */
    private function requireShippingQuote(Destination $destination, string $subtotal, string $chosen = ''): array
    {
        $quotes = $this->quotes($destination, $subtotal);

        if ($quotes === []) {
            throw ApiException::invalidRequest('This shop does not deliver to that destination.', [
                'fields' => ['commune_id' => 'No shipping rule matches it.'],
            ]);
        }

        $quote = ShopperRates::choose($quotes, $chosen);

        if ($quote !== null) {
            return $quote;
        }

        /*
         * Named back to the caller, capped, and only after the choice has
         * already been refused — echoing a caller's own string is how the
         * message says which value was rejected when a form posted several, and
         * `ProviderRegistry::get()` does the same. The cap is because this
         * route is public: `sanitize_text_field()` strips tags but does not
         * shorten, and an error message is not a place to reflect a kilobyte.
         */
        $named = mb_substr($chosen, 0, 40);

        // Registered but rowless, or never registered at all. With no registry
        // — the test seam in this class's constructor — only the second
        // sentence can be told truthfully, because there is nothing to ask.
        $known = $this->providers !== null && $this->providers->has($chosen);

        throw ApiException::invalidRequest(
            $known
                ? 'That courier does not deliver to that destination.'
                : 'This shop does not ship with that courier.',
            [
                'fields' => [
                    'shipping_provider' => sprintf(
                        $known
                            ? '"%s" has no price for that destination. Available here: %s.'
                            : '"%s" is not a courier this shop has. Available here: %s.',
                        $named,
                        implode(', ', array_map(
                            static fn (array $quote): string => (string) ($quote['provider'] ?? ''),
                            $quotes
                        ))
                    ),
                ],
            ]
        );
    }

    /**
     * What delivery costs here, per registered courier.
     *
     * The blending — courier first, tariff behind it, free delivery above both,
     * and no exception allowed out — is `Shipping\ShopperRates`, which is pure
     * and therefore testable against a courier that cannot be switched on. That
     * is not a stylistic preference: with `ENABLE_YALIDINE` and
     * `ENABLE_ZR_EXPRESS` off and their credentials unissued, the only courier
     * this install can register is `ManualProvider`, which publishes no rates at
     * all. Every branch that involves a courier *answering* is reachable only
     * through a test double, and a double cannot be injected into a REST request
     * — so the logic had to live somewhere a unit test can hold it directly.
     *
     * With no registry this is the §14 tariff alone, and the empty-string
     * fallback provider that used to head the list is gone with it — see
     * `ShopperRates::forDestination()` for why a quote has to name a courier
     * that exists.
     *
     * @return list<array<string, mixed>>
     */
    private function quotes(Destination $destination, string $subtotal): array
    {
        $rules = $this->rules->active();
        $decimals = wc_get_price_decimals();
        $currency = get_woocommerce_currency();

        if ($this->providers === null) {
            $rule = RateResolver::resolve($rules, $destination, '');

            return $rule === null ? [] : [
                ['provider' => '']
                    + RateResolver::quote($rule, $subtotal, $decimals, $currency, $destination->deliveryType)
                        ->toArray(),
            ];
        }

        return ShopperRates::forDestination(
            $this->providers,
            $rules,
            $destination,
            $subtotal,
            $decimals,
            $currency,
            $this->logger
        );
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

    /**
     * The buyer's WordPress user id, or 0 for a guest.
     *
     * **Never `get_current_user_id()`**, because the storefront proxy
     * authenticates the transport with an Application Password over HTTP Basic
     * and that credential belongs to the service account — a checkout that
     * trusted `is_user_logged_in()` would set the service account as every
     * order's owner, hiding orders from the customers who placed them and
     * making `/account/orders/{id}` 403 for the buyer. The customer session is
     * carried by `X-Customer-Token` and resolved by `AccountSession::current()`,
     * which is what identifies the buyer here.
     *
     * Guest checkouts have no such token and land at 0, which is what
     * WooCommerce records for an anonymous order.
     */
    private function resolveCustomerId(WP_REST_Request $request): int
    {
        $user = $this->accountSession?->current($request);

        return $user instanceof \WP_User ? (int) $user->ID : 0;
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

    /**
     * Every line's quantity fits its product's live stock.
     *
     * Aggregates failures so a shopper with three over-stock lines is told
     * about all three, not just the first — the storefront then displays each
     * against its stepper. Delegates the per-product rule to
     * `CartService::assertStockAvailable()`, which is the same code the cart
     * update path uses; sharing it means the two doors cannot disagree about
     * when a quantity is refused.
     *
     * @throws ApiException 400 if any line exceeds available stock
     */
    private function assertStockAvailable(WC_Cart $cart): void
    {
        $errors = [];

        foreach ($cart->get_cart() as $key => $line) {
            $product = $line['data'] ?? null;
            $quantity = (int) ($line['quantity'] ?? 0);

            if (!$product instanceof \WC_Product || $quantity <= 0) {
                continue;
            }

            try {
                CartService::assertStockAvailable($product, $quantity);
            } catch (ApiException $e) {
                $errors['items.' . $key] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest(
                'Some items are no longer available in that quantity.',
                ['fields' => $errors]
            );
        }
    }
}
