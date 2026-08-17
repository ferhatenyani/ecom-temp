<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tracking;

use AlgerianCommerce\Settings\SettingsRepository;
use WC_Order;

/**
 * Issues, resolves and revokes a tracking token — roadmap §84.
 *
 * The one place that knows where the nonce lives and where the storefront is,
 * so `TrackingToken` can stay pure and `TrackingService` never touches order
 * meta. Three callers, and they need different halves:
 *
 *   CheckoutService        the token, to return beside `next`
 *   NotificationSubscriber the URL, to put in "your parcel is on its way"
 *   TrackingService        the order behind a token somebody presented
 *
 * ## The nonce is order meta, and there is no table
 *
 * One value per order, read and written only through WooCommerce's own CRUD —
 * `get_meta()` / `update_meta_data()` — because HPOS is on and a direct
 * `get_post_meta()` against `wp_posts` works on a legacy install and silently
 * returns nothing here (CLAUDE.md). Nothing queries *by* the nonce: the order id
 * arrives inside the token, so this is always a keyed read on a known order.
 * That is the property that makes a table unnecessary and, more to the point,
 * that keeps an unauthenticated route off a `meta_query` — §82 measured what
 * WooCommerce does with query args it does not support, and the answer was
 * "ignores them and returns everything".
 *
 * ## A URL is never guessed
 *
 * `urlFor()` returns `''` when §71 has no `store.storefront_url`, and the
 * callers treat that as "send the message without a link". WordPress's own
 * permalink points at this backend, so deriving one would mail a customer a link
 * to an admin domain they have no account for — §62 refused the same guess for
 * canonical URLs and `PasswordResetService` refuses to mint a token without it.
 * The difference here is deliberate: a reset with no link is useless, so it is a
 * 503, while a shipment notification with no link is still worth sending.
 */
final class TrackingLink
{
    /** Where the per-order nonce lives. Underscored, so it is not a public meta. */
    public const NONCE_META = '_ac_tracking_nonce';

    /** The storefront path a token is appended to. Theirs to build, ours to name. */
    public const STOREFRONT_PATH = '/orders/track';

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /**
     * The token for this order, minting and storing a nonce the first time.
     *
     * Idempotent: the same order yields the same token on every call, which is
     * what stops the checkout response and the shipment email carrying two
     * different links to the same parcel.
     */
    public function tokenFor(WC_Order $order): string
    {
        $secret = self::secret();

        if ($secret === '') {
            return '';
        }

        return TrackingToken::mint($order->get_id(), $this->nonceFor($order), $secret);
    }

    /**
     * The full link, or `''` when this shop has not said where its storefront
     * is. Never a URL on the admin domain — see the class docblock.
     */
    public function urlFor(WC_Order $order): string
    {
        $storefront = $this->storefrontUrl();

        if ($storefront === '') {
            return '';
        }

        $token = $this->tokenFor($order);

        if ($token === '') {
            return '';
        }

        return rtrim($storefront, '/') . self::STOREFRONT_PATH . '?' . http_build_query(['token' => $token]);
    }

    /**
     * The order a token is genuinely about, or null.
     *
     * Null covers every failure with one answer on purpose — malformed, wrong
     * MAC, unknown order, an order that was never issued a link. The caller
     * turns all of them into the same 404, because telling them apart tells
     * somebody guessing which half of the guess was right.
     */
    public function resolve(string $token): ?WC_Order
    {
        $orderId = TrackingToken::orderIdFrom($token);

        if ($orderId <= 0) {
            return null;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return null;
        }

        $nonce = (string) $order->get_meta(self::NONCE_META);

        return TrackingToken::verify($token, $orderId, $nonce, self::secret()) ? $order : null;
    }

    /**
     * Kill every link to this order and issue nothing in its place.
     *
     * The revocation half of §84's "revocable, or at least expiring". Rotating
     * the nonce is one write and invalidates the old MAC — no revocation list,
     * no expiry to wait out, and no effect on any other order. A later
     * `tokenFor()` mints a fresh nonce and a fresh link.
     */
    public function revoke(WC_Order $order): void
    {
        $order->delete_meta_data(self::NONCE_META);
        $order->save();
    }

    /**
     * This order's nonce, created on first use.
     *
     * Saved immediately rather than left on the object: the callers are a
     * checkout response, a notification subscriber and a CLI drain, and only the
     * first of those has an order it is about to save anyway.
     */
    private function nonceFor(WC_Order $order): string
    {
        $existing = trim((string) $order->get_meta(self::NONCE_META));

        if ($existing !== '') {
            return $existing;
        }

        $nonce = TrackingToken::newNonce();

        $order->update_meta_data(self::NONCE_META, $nonce);
        $order->save();

        return $nonce;
    }

    public function storefrontUrl(): string
    {
        $stored = $this->settings->stored();

        return trim((string) ($stored['store']['storefront_url'] ?? ''));
    }

    /**
     * WordPress's own auth salt.
     *
     * Not a credential this project mints and stores: `AuthService` declines to
     * reimplement Application Passwords, `AccountSession` uses core's
     * auth-cookie format and `CartSession` uses WooCommerce's token utilities,
     * all for the same reason. The salt already exists, is already per-install,
     * and rotating it — which an operator may do for unrelated reasons —
     * invalidates every outstanding tracking link, which is the safe direction.
     */
    private static function secret(): string
    {
        return function_exists('wp_salt') ? (string) wp_salt('auth') : '';
    }
}
