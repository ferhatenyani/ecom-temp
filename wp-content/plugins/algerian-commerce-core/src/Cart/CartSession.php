<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use Automattic\WooCommerce\StoreApi\SessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use WC_Cart;
use WC_Cart_Session;
use WP_REST_Request;

/**
 * Puts a WooCommerce cart in front of a request that has no cookies — roadmap
 * §59b.
 *
 * **This class is the whole reason §59b could reuse `WC_Cart` instead of
 * inventing a cart table.** Everything above it works with WooCommerce's real
 * cart: its coupon rules, its stock checks, its tax and its totals. What
 * WooCommerce assumes and a headless backend cannot provide is a *browser* —
 * `WC_Session_Handler` identifies a shopper by the `wp_woocommerce_session_`
 * cookie, and there is no cookie here because the caller is a Next.js server or
 * a fetch() from another origin.
 *
 * WooCommerce already solved that for its own Store API and the solution is
 * reusable: `StoreApi\SessionHandler` is the same session, keyed by a signed
 * token in a header instead of a cookie, with no cookie, cron or cache layer.
 * Swapping it in is one filter. We use WooCommerce's token utilities rather
 * than minting our own so that the signature, the expiry and the secret stay
 * WooCommerce's problem — a hand-rolled cart token is a credential, and this
 * project does not write its own credentials (see `Auth/AuthService`, which
 * declines to reimplement Application Passwords for the same reason).
 *
 * ## Three things measured on 2026-08-16, all load-bearing
 *
 * **`wc_load_cart()` does not populate the cart.** It creates the session and
 * the cart object, but `WC_Cart_Session::get_cart_from_session()` is hooked to
 * `wp_loaded` — which fired long before any REST route runs. So a request that
 * calls only `wc_load_cart()` gets a valid session containing the shopper's
 * items and an **empty cart object**, which is the worst shape available: the
 * data is there, nothing errors, and the shopper is told their basket is empty.
 * `hydrate()` is what closes that, and it is not defensive politeness.
 *
 * **Neither `WC_Cart::get_cart()` nor a fresh `new WC_Cart()` fixes it**, which
 * is why the fix looks indirect. `get_cart()` is guarded by
 * `did_action('woocommerce_cart_loaded_from_session')`, and that action has
 * already fired by then — against the session as it stood before the token was
 * read. Constructing `WC_Cart_Session` directly is the public path that is not
 * guarded, and running it twice does not duplicate lines.
 *
 * **A forged or expired token yields an empty cart, never someone else's.**
 * `CartTokenUtils::get_cart_token_payload()` returns an empty user id unless
 * the signature validates, so a bad token starts a new anonymous cart. That is
 * the property §59b asks for — "a cart id must be unguessable, not sequential"
 * — and it is stronger than unguessable, because the token is signed rather
 * than merely random.
 *
 * The header is `Cart-Token`, deliberately the same name Store API uses. A
 * storefront that already speaks Store API keeps its plumbing, and a shop that
 * later moves either way does not have to rename anything.
 */
final class CartSession
{
    /**
     * Where the token arrives.
     *
     * Read off the `WP_REST_Request` rather than `$_SERVER` so the whole class
     * is exercisable through `rest_do_request()` — `tests/Api/` has no real
     * HTTP request and would otherwise be unable to carry a cart between two
     * calls. `load()` copies it into `$_SERVER` because that is where
     * WooCommerce's own handler looks.
     */
    public const HEADER = 'Cart-Token';

    /** A fallback for clients that cannot set headers; same token, same checks. */
    public const PARAM = 'cart_token';

    private bool $loaded = false;

    /** The token the currently-loaded cart was opened with. */
    private ?string $loadedToken = null;

    /**
     * Make `WC()->cart` real for this request.
     *
     * Idempotent **per token**, not per instance. Within one HTTP request the
     * token cannot change, so this behaves exactly as a plain "load once" guard
     * would; keying on the token matters in the two places where one PHP
     * process handles more than one cart, and both are real:
     *
     *  - `tests/Api/cart.php`, which makes forty `rest_do_request()` calls in
     *    one process. A load-once guard made "a forged token opens an empty
     *    cart" **pass against the previous caller's cart**, which is to say the
     *    security property of the whole module was untestable and untested.
     *  - a WP-CLI command or any future batch that touches several carts.
     *
     * `$_SERVER['HTTP_CART_TOKEN']` is written on every call, including when
     * the token is empty, because leaving a previous one in place is how an
     * anonymous request inherits somebody else's basket.
     */
    public function load(?WP_REST_Request $request = null): void
    {
        $token = $request instanceof WP_REST_Request ? self::tokenFrom($request) : '';

        if ($this->loaded && $this->loadedToken === $token) {
            return;
        }

        $reloading = $this->loaded;

        $this->loaded = true;
        $this->loadedToken = $token;

        // WooCommerce's handler reads $_SERVER, so this is the hand-off point
        // between our transport and theirs. Unset rather than left stale.
        if ($token === '') {
            unset($_SERVER['HTTP_CART_TOKEN']);
        } else {
            $_SERVER['HTTP_CART_TOKEN'] = $token;
        }

        add_filter('woocommerce_session_handler', static fn (): string => SessionHandler::class, 0);

        // A second cart in the same process needs WooCommerce's singletons
        // rebuilt, or `wc_load_cart()` finds the first one already there and
        // returns it — which is the bug this whole branch exists to prevent.
        if ($reloading) {
            WC()->session = null;
            WC()->cart = null;
            WC()->customer = null;
        }

        wc_load_cart();
        $this->hydrate();
    }

    /**
     * Fill the cart object from the session the token just opened.
     *
     * See the class docblock: without this the cart is empty while the session
     * is not, and nothing anywhere reports a problem.
     */
    private function hydrate(): void
    {
        $cart = WC()->cart;

        if (!$cart instanceof WC_Cart) {
            return;
        }

        (new WC_Cart_Session($cart))->get_cart_from_session();
    }

    /**
     * The token for the cart as it now stands, or '' when there is nothing
     * worth carrying.
     *
     * An empty cart deliberately gets no token. A shopper who has added nothing
     * needs no identity, and handing one out means a row in
     * `wp_woocommerce_sessions` for every crawler that reads `GET /cart`.
     */
    public function token(): string
    {
        $session = WC()->session ?? null;

        if ($session === null) {
            return '';
        }

        $customerId = (string) $session->get_customer_id();

        if ($customerId === '') {
            return '';
        }

        $cart = WC()->cart;

        if (!$cart instanceof WC_Cart || $cart->is_empty()) {
            return '';
        }

        return CartTokenUtils::get_cart_token($customerId);
    }

    /**
     * Persist the cart, because nothing else will.
     *
     * `SessionHandler::init()` registers `save_data()` on `shutdown`, which is
     * reached in a real request and **not** in `rest_do_request()` — the
     * in-process suites finish inside one PHP process that keeps running. So
     * the write is explicit here rather than left to a hook that fires in one
     * of the two contexts this code has to work in.
     */
    public function save(): void
    {
        $session = WC()->session ?? null;

        if ($session !== null && method_exists($session, 'save_data')) {
            $session->save_data();
        }
    }

    /** Header first, query parameter second; both are the same signed token. */
    private static function tokenFrom(WP_REST_Request $request): string
    {
        $header = (string) ($request->get_header(self::HEADER) ?? '');

        if (trim($header) !== '') {
            return trim($header);
        }

        return trim((string) ($request->get_param(self::PARAM) ?? ''));
    }
}
