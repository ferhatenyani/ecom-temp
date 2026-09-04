<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\BundleStock;
use AlgerianCommerce\Products\OptionSelection;
use AlgerianCommerce\Products\OptionSetRepository;
use WC_Cart;
use WC_Product;
use WP_Error;
use WP_REST_Request;

/**
 * Cart operations — roadmap §59b, docs/PLAN.md §53.
 *
 * **The rule this class exists to enforce:** a cart arrives from a browser, so
 * every number in it is a request rather than a fact. A caller may say *which*
 * product and *how many*; it may not say what either costs. Price comes from
 * the catalogue, stock from inventory, discount from the coupon — read on the
 * server, on every mutation, and read again by `CartPresenter` on the way out.
 * This is SECURITY.md → "Payments" ("never trust the frontend to tell the
 * backend that a payment succeeded") one step earlier in the flow, and it is
 * why `LineInput` accepts exactly four fields, and why §83's `options` carries
 * choice ids rather than what a choice costs.
 *
 * **The maths is WooCommerce's and stays WooCommerce's.** `add_to_cart()`
 * validates purchasability, stock and quantity limits; `apply_coupon()` applies
 * §21's usage limits, expiry and restrictions; `calculate_totals()` does tax
 * and rounding. Reimplementing any of it would fork the data model CLAUDE.md
 * forbids forking, and would mean re-deriving security-critical arithmetic that
 * is already written and already audited. What this class owns is the boundary:
 * validation in, our envelope out, and errors that name the field.
 *
 * There is no authorization here, and that is not an omission — see
 * `CartController`. A cart belongs to whoever holds its signed token, which is
 * the only thing that could identify it before a shopper has an account.
 */
final class CartService
{
    /**
     * The most of one line a single request may put in a cart.
     *
     * WooCommerce enforces stock, but a product with stock management off has
     * no ceiling at all, and `quantity: 100000000` is then a totals calculation
     * over a number nobody meant. A cap that a real order never reaches costs
     * nothing and bounds the arithmetic.
     */
    public const MAX_QUANTITY = 999;

    public function __construct(
        private readonly CartSession $session,
        private readonly OptionSetRepository $optionSets,
        private readonly BundleStock $bundles,
        private readonly OptionPriceSubscriber $optionPrices
    ) {
    }

    /**
     * Whether this basket has to be delivered to somewhere.
     *
     * **Not `WC_Cart::needs_shipping()`, and the difference is not academic.**
     * That method begins:
     *
     *     if ( ! wc_shipping_enabled() || 0 === wc_get_shipping_method_count( true ) ) {
     *         return false;
     *     }
     *
     * `wc_get_shipping_method_count()` counts methods in **WooCommerce's
     * shipping zones**, and this project has none — §14 replaced zones with
     * `ac_shipping_rates` precisely because zones key on postcodes and the
     * Algerian commune dataset has none. So `WC_Cart::needs_shipping()` is
     * permanently `false` on a correctly configured install of this plugin,
     * whatever is in the basket.
     *
     * Measured 2026-08-16: a cart holding a physical product reported
     * `needs_shipping: false`, `CheckoutService` skipped the §14 quote on the
     * strength of it, and an order for a rug that has to be driven to Algiers
     * was created with no delivery charge and a total short by the whole
     * shipping cost. Nothing errored.
     *
     * The question this asks instead is a fact about the goods — does any line
     * describe something that must physically move — which is what §14 needs
     * and the only half WooCommerce's zone configuration cannot invalidate.
     */
    public static function needsShipping(WC_Cart $cart): bool
    {
        foreach ($cart->get_cart() as $line) {
            $product = $line['data'] ?? null;

            if ($product instanceof WC_Product && $product->needs_shipping()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function get(WP_REST_Request $request): array
    {
        $this->session->load($request);

        return $this->present();
    }

    /**
     * Add a line, or increase one that is already there.
     *
     * @param array{product_id: int, variation_id: int, quantity: int, options?: array<string, mixed>} $line
     * @return array<string, mixed>
     */
    public function addItem(WP_REST_Request $request, array $line): array
    {
        $this->session->load($request);

        $product = $this->requirePurchasable($line['product_id'], $line['variation_id']);

        /*
         * Options are priced **before** the line exists — roadmap §83.
         *
         * `OptionSelection::price()` is what refuses an unknown option, a
         * required group left out and a combination that would price below
         * zero, and it does it against the product's stored definition rather
         * than anything in the payload. Doing it here means a bad configuration
         * is a 400 naming the group, not a line silently added at the wrong
         * price. The result is discarded: what goes into the cart is the
         * shopper's *choice*, and `OptionPriceSubscriber` reads the money back
         * out of the catalogue on every totals pass.
         */
        $options = $line['options'] ?? [];
        OptionSelection::price($this->optionSets->forPurchase($product), $options, (string) $product->get_price());

        $this->assertBundleAvailable($product, $line['quantity']);

        // WooCommerce answers false *or* raises a wc_add_notice rather than
        // throwing, so the notices are drained and turned into a real error.
        // Without this a refused add is a 200 with an unchanged cart, which is
        // the shape a storefront cannot diagnose.
        $this->clearNotices();

        $key = WC()->cart->add_to_cart(
            $line['variation_id'] > 0 ? $line['product_id'] : $product->get_id(),
            $line['quantity'],
            $line['variation_id'],
            [],
            /*
             * WooCommerce hashes cart item data into the line's key, so two
             * configurations of one product are two lines rather than a
             * quantity of two — which is the only correct answer when one is
             * engraved "AB" and the other "CD".
             */
            $options === [] ? [] : [OptionPriceSubscriber::DATA_KEY => $options]
        );

        if (!is_string($key) || $key === '') {
            throw ApiException::invalidRequest(
                'That product could not be added to the cart.',
                ['fields' => ['product_id' => $this->noticeReason('It is not available in that quantity.')]]
            );
        }

        return $this->present();
    }

    /**
     * Set a line's quantity. Zero removes it, which is what a stepper control
     * does when it reaches the bottom.
     *
     * @return array<string, mixed>
     */
    public function setQuantity(WP_REST_Request $request, string $key, int $quantity): array
    {
        $this->session->load($request);

        $this->requireLine($key);
        $this->clearNotices();

        if ($quantity === 0) {
            WC()->cart->remove_cart_item($key);

            return $this->present();
        }

        /*
         * A bundle is re-checked on every quantity change — roadmap §83. Its
         * ceiling is the minimum of its components' stock, which WooCommerce
         * knows nothing about: `set_quantity()` would happily take ten of a
         * bundle whose scarcest component has three left.
         */
        $line = $this->cart()->get_cart()[$key] ?? null;
        $product = is_array($line) ? ($line['data'] ?? null) : null;

        if ($product instanceof WC_Product) {
            $this->assertBundleAvailable($product, $quantity);
        }

        if (!WC()->cart->set_quantity($key, $quantity, true)) {
            throw ApiException::invalidRequest(
                'That quantity is not available.',
                ['fields' => ['quantity' => $this->noticeReason('Not available in that quantity.')]]
            );
        }

        return $this->present();
    }

    /** @return array<string, mixed> */
    public function removeItem(WP_REST_Request $request, string $key): array
    {
        $this->session->load($request);

        $this->requireLine($key);

        WC()->cart->remove_cart_item($key);

        return $this->present();
    }

    /** @return array<string, mixed> */
    public function clear(WP_REST_Request $request): array
    {
        $this->session->load($request);

        WC()->cart->empty_cart();

        return $this->present();
    }

    /**
     * Apply a coupon — docs/PLAN.md §21.
     *
     * Every rule that decides whether a code is usable (expiry, usage limit,
     * per-customer limit, minimum spend, product and category restrictions) is
     * WooCommerce's, and the reason for the failure is whatever WooCommerce
     * said. Restating those rules here would produce a second set that can
     * disagree with the one that actually applies the discount.
     *
     * **A refusal carries `details.reason` as well as the sentence.** The
     * sentence is WooCommerce's, in the language the backend runs in, and a
     * storefront that shows it to a shopper is showing them English. The slug
     * beside it is stable, is what a French cart translates, and is what tells
     * an expired code apart from one the shop has never issued. See
     * `CouponRejection` — which also exists because one of those sentences is
     * wrong, and a slug derived from re-validating is how it gets corrected.
     *
     * @return array<string, mixed>
     */
    public function applyCoupon(WP_REST_Request $request, string $code): array
    {
        $this->session->load($request);

        $cart = $this->cart();

        if ($cart->is_empty()) {
            throw ApiException::invalidRequest('A coupon needs a cart to apply to.', [
                'reason' => 'cart_empty',
                'fields' => ['code' => 'The cart is empty.'],
            ]);
        }

        if ($cart->has_discount($code)) {
            throw ApiException::conflict('That coupon is already applied.', [
                'reason' => 'already_applied',
                'code' => $code,
            ]);
        }

        $this->clearNotices();

        $rejection = CouponRejection::of($cart, $code, static fn (): bool => $cart->apply_coupon($code));

        if ($rejection !== null) {
            $this->clearNotices();

            $message = self::plainText($rejection->message());

            throw ApiException::invalidRequest('That coupon could not be applied.', [
                'reason' => $rejection->reason(),
                'fields' => ['code' => $message === '' ? 'It is not valid for this cart.' : $message],
            ]);
        }

        return $this->present();
    }

    /** @return array<string, mixed> */
    public function removeCoupon(WP_REST_Request $request, string $code): array
    {
        $this->session->load($request);

        $cart = $this->cart();

        if (!$cart->has_discount($code)) {
            throw ApiException::notFound('That coupon is not applied to this cart.');
        }

        $cart->remove_coupon($code);

        return $this->present();
    }

    /**
     * Recalculate, persist, and shape the answer.
     *
     * `calculate_totals()` runs on **every** response, not only after a
     * mutation, because a cart read an hour after it was filled must reflect
     * today's prices and today's stock. A cached total is a promise the shop
     * did not make.
     *
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $cart = $this->cart();

        $cart->calculate_totals();
        $this->session->save();

        $result = [
            'cart' => CartPresenter::toArray($cart, $this->optionPrices),
            'token' => $this->session->token(),
        ];

        /*
         * Reported, never swallowed — roadmap §83, §61's rule.
         *
         * A shop can delete an option group while it sits in a basket. The
         * surcharge is then uncomputable, the line falls back to its catalogue
         * price, and saying nothing would be a shop giving gift wrap away to
         * somebody who cannot tell that anything changed. `CheckoutService`
         * refuses to place an order while this list is non-empty.
         */
        $problems = $this->optionPrices->problems();

        if ($problems !== []) {
            $result['problems'] = array_values($problems);
        }

        return $result;
    }

    /** Lines whose stored options no longer price — see `present()`. */
    public function optionProblems(): array
    {
        return $this->optionPrices->problems();
    }

    /**
     * A bundle can only be sold as often as its scarcest component allows.
     *
     * §83: "A bundle's purchasability is the minimum of its components',
     * recomputed on the server, not a stock field of its own. A bundle showing
     * 'in stock' because nobody refreshed it is an oversell."
     *
     * @throws ApiException
     */
    private function assertBundleAvailable(WC_Product $product, int $quantity): void
    {
        if ($this->bundles->canSell($product, $quantity)) {
            return;
        }

        throw ApiException::invalidRequest('That bundle is not available in that quantity.', [
            'fields' => ['quantity' => $this->bundles->shortfallReason($product, $quantity)],
        ]);
    }

    /**
     * @throws ApiException 404 when the product cannot be bought
     */
    private function requirePurchasable(int $productId, int $variationId): WC_Product
    {
        $product = wc_get_product($variationId > 0 ? $variationId : $productId);

        if (!$product instanceof WC_Product) {
            throw ApiException::notFound('That product does not exist.');
        }

        // A draft, a private product or one with no price is not a 404 — it
        // exists — but it is not something a shopper may put in a basket, and
        // saying so is more useful than "could not be added".
        if (!$product->is_purchasable()) {
            throw ApiException::invalidRequest('That product cannot be purchased.', [
                'fields' => ['product_id' => 'It is not published, or has no price.'],
            ]);
        }

        if (!$product->is_in_stock()) {
            throw ApiException::invalidRequest('That product is out of stock.', [
                'fields' => ['product_id' => 'Out of stock.'],
            ]);
        }

        return $product;
    }

    /** @throws ApiException 404 when the cart holds no such line */
    private function requireLine(string $key): void
    {
        if (!isset($this->cart()->get_cart()[$key])) {
            throw ApiException::notFound('That item is not in the cart.');
        }
    }

    private function cart(): WC_Cart
    {
        $cart = WC()->cart;

        if (!$cart instanceof WC_Cart) {
            // Only reachable if WooCommerce is deactivated mid-request; the
            // plugin declares it as a hard dependency.
            throw ApiException::internal('The cart is unavailable.');
        }

        return $cart;
    }

    /**
     * Why WooCommerce refused, in the words it used.
     *
     * Its cart methods report failure through `wc_add_notice()` and return
     * false, so the reason is in the notice queue rather than in the return
     * value. Dropping it would leave every refusal reading "could not be
     * added", which is true of a sold-out product, a per-order limit and a
     * coupon that expired yesterday alike.
     */
    private function noticeReason(string $fallback): string
    {
        if (!function_exists('wc_get_notices')) {
            return $fallback;
        }

        $notices = wc_get_notices('error');
        $this->clearNotices();

        foreach ($notices as $notice) {
            $text = self::plainText((string) ($notice['notice'] ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return $fallback;
    }

    /**
     * WooCommerce's markup as a sentence a JSON client can print.
     *
     * Its cart messages are written for a web page: `wc_price()` returns
     * `<span class="…"><bdi>1&nbsp;500,00&nbsp;<span…>DZD</span></bdi></span>`,
     * and every code and product name in them has been through `esc_html()`.
     * Stripping tags alone — which is what this used to do — leaves the
     * entities, so a shopper who typed `yani12` was shown
     * `coupon &quot;yani12&quot; is not applicable…`.
     *
     * Decoding **before** stripping rather than after is the order that matters:
     * a product name containing `&lt;script&gt;` decodes into a tag and is then
     * removed, where the other order would publish it. Non-breaking spaces from
     * `wc_price()` collapse with the rest of the whitespace, so a price does not
     * arrive with characters no client asked for.
     */
    private static function plainText(string $html): string
    {
        $text = wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * Notices are request-global in WooCommerce and this process serves many
     * requests in the in-process suites, so a stale notice from an earlier
     * assertion would otherwise be reported as this one's reason.
     */
    private function clearNotices(): void
    {
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
    }
}
