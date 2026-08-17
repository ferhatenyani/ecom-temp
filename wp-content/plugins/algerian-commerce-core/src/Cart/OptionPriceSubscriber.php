<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\OptionSelection;
use AlgerianCommerce\Products\OptionSetRepository;
use WC_Cart;
use WC_Product;

/**
 * Applies a configured line's surcharge — roadmap §83.
 *
 * ## Why a hook and not a stored number
 *
 * The cart stores **what the shopper chose** and nothing about what it costs.
 * The surcharge is recomputed from the product's own option set on every
 * `calculate_totals()` — which `CartService::present()` runs on every response,
 * not only after a mutation, for exactly the reason its docblock gives: a cart
 * read an hour after it was filled must reflect today's prices.
 *
 * Storing the surcharge in the cart item would make it a number that outlives
 * the request in a session, and a number that outlives the request is a number
 * that can be stale — or, if anybody ever loosens the session's signing, a
 * number that can be edited. Recomputing costs one meta read per line and
 * removes the question.
 *
 * `woocommerce_before_calculate_totals` is where WooCommerce itself expects
 * a line price to be adjusted, so tax, coupons and rounding all happen
 * afterwards on the corrected figure rather than being re-derived here.
 *
 * ## When the definition changed underneath a cart
 *
 * A shop can delete an option group while it sits in somebody's basket. Pricing
 * that selection now throws, and there are only two directions: charge nothing
 * for it, or refuse. **It refuses** — silently dropping the surcharge is a shop
 * giving away gift wrap, and the shopper cannot see that anything happened.
 * The line keeps its catalogue price, the problem is recorded, `CartPresenter`
 * reports it, and `CheckoutService` will not place an order that contains one.
 *
 * Problems live here for the length of the request rather than being written
 * back into the cart item: a totals pass is a read, and a read that mutates the
 * session is how two tabs start disagreeing about a basket.
 */
final class OptionPriceSubscriber
{
    /** Cart item data key. Holds the chosen ids only — never money. */
    public const DATA_KEY = 'ac_options';

    /** @var array<string, string> cart item key => why this line could not be priced */
    private array $problems = [];

    /** @var array<string, array<string, mixed>> cart item key => priced selection */
    private array $priced = [];

    public function __construct(private readonly OptionSetRepository $optionSets)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'apply'], 20);
    }

    /** @param mixed $cart WC_Cart */
    public function apply(mixed $cart): void
    {
        if (!$cart instanceof WC_Cart) {
            return;
        }

        $this->problems = [];
        $this->priced = [];

        foreach ($cart->get_cart() as $key => $line) {
            $chosen = $line[self::DATA_KEY] ?? null;
            $product = $line['data'] ?? null;

            if (!is_array($chosen) || $chosen === [] || !$product instanceof WC_Product) {
                continue;
            }

            $base = self::cataloguePrice($product);

            try {
                $selection = OptionSelection::price(
                    $this->optionSets->forPurchase($product),
                    $chosen,
                    $base
                );
            } catch (ApiException $exception) {
                $this->problems[(string) $key] = $exception->getMessage();

                continue;
            }

            $this->priced[(string) $key] = [
                'options' => $selection->toArray(),
                'surcharge' => $selection->surcharge,
            ];

            $product->set_price($selection->unitPrice($base));
        }
    }

    /**
     * The catalogue's price for this line, read fresh — **not the line's own**.
     *
     * ## The bug this method exists to prevent, which was real
     *
     * `apply()` mutates the cart line's product object with `set_price()`, and
     * that object **lives in the session**. `calculate_totals()` runs more than
     * once — `add_to_cart()` triggers one, `CartService::present()` runs another
     * on every single response — so reading the base from `$product->get_price()`
     * reads back the surcharge that the *previous* pass just applied, and adds
     * it again.
     *
     * Measured 2026-08-17 on a 1,000 DZD mug with a 250 wrap and a 500
     * engraving: the correct unit price is 1,750. The response carried
     * **3,250**, and a second line added later carried 2,500 for a 500
     * surcharge. Nothing errored. The totals were arithmetically consistent
     * with themselves, the line items looked like money, and the only way to
     * see it was to know what the answer should have been — which is precisely
     * §83's warning about a configurator whose failure "still looks plausible".
     *
     * A fresh `wc_get_product()` is a new object built from the data store, so
     * it carries what the catalogue says today and nothing this class did to a
     * different instance. It also keeps the sale price: an option rides on top
     * of a discount rather than resetting the product to full price, which is
     * what a shopper who put a discounted item in a basket expects.
     */
    private static function cataloguePrice(WC_Product $product): string
    {
        $fresh = wc_get_product($product->get_id());

        return (string) ($fresh instanceof WC_Product ? $fresh->get_price() : $product->get_price());
    }

    /** @return array<string, string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, mixed>|null */
    public function pricedLine(string $key): ?array
    {
        return $this->priced[$key] ?? null;
    }
}
