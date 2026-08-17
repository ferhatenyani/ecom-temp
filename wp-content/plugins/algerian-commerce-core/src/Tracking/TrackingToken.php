<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tracking;

/**
 * The key to the public tracking route — roadmap §84.
 *
 * Pure: strings and integers in, strings and booleans out, no WordPress and no
 * database, so every property below is a unit test rather than a claim.
 *
 * ## Why a token at all, and why not the obvious alternative
 *
 * §84 does the arithmetic and it is worth keeping where the code is. The obvious
 * key for a guest order is **order number plus phone number**, and it is
 * enumerable: order numbers are sequential, an Algerian mobile is ten digits
 * behind a known operator prefix, so one order is about ten million guesses and
 * a shop's whole order book — with every customer's name, address and phone —
 * sits a few million requests behind a form. Order number plus email is worse:
 * anyone who knows one customer's address walks the order numbers.
 *
 * ## The shape
 *
 * ```
 *   4211.9f3c1a…            ← order id, a dot, 32 hex characters of HMAC
 *   └─ subject              └─ HMAC-SHA256(subject | nonce, site salt), truncated
 * ```
 *
 * **The order id travels in the clear, and that is deliberate.** Verifying an
 * HMAC needs the message, so the route has to know which order is being claimed
 * before it can check anything — either the id is in the token or the token has
 * to be *searched for*, which means a meta query on every public request. This
 * is exactly the construction `CartSession` already relies on: WooCommerce's
 * cart token is a JWT carrying the customer id in readable base64 with a
 * signature over it. Nothing is disclosed by it either, because the public
 * route publishes the order number anyway (§84's allowed list) and only the
 * person holding the order was ever given the token.
 *
 * **The 128 bits are in the HMAC, not in the id.** `LENGTH` is 32 hex
 * characters — half of SHA-256 — which is the same truncation WordPress's own
 * nonces and this project's rate-limit keys use, and it is far beyond guessing
 * at the 20-requests-a-minute the route allows.
 *
 * **The nonce is what makes a link revocable.** It is a per-order random value
 * (`TrackingLink` keeps it in order meta), so rotating it kills a leaked link
 * without touching the salt, without touching any other order, and without a
 * revocation list. It also means an order that was never *issued* a link has no
 * valid token at all: without the nonce, every order in the shop would have a
 * working token derivable from the salt alone, so `/orders/track` would answer
 * about orders nobody was ever given a link for.
 *
 * `hash_equals()` throughout: a byte-by-byte `===` on a MAC leaks its prefix
 * through timing, and this is an unauthenticated route an attacker may call as
 * fast as the limiter allows.
 */
final class TrackingToken
{
    /** Order id and MAC, so neither can be mistaken for the other. */
    public const SEPARATOR = '.';

    /** Hex characters of HMAC kept — 128 bits. */
    public const LENGTH = 32;

    /** Bytes of randomness in a nonce, before hex encoding. */
    public const NONCE_BYTES = 16;

    /**
     * Namespaced so a token minted here can never validate somewhere else that
     * happens to HMAC an integer with the same salt.
     */
    private const CONTEXT = 'ac-order-tracking-v1';

    public static function mint(int $orderId, string $nonce, string $secret): string
    {
        if ($orderId <= 0 || trim($nonce) === '' || trim($secret) === '') {
            return '';
        }

        return $orderId . self::SEPARATOR . self::mac($orderId, $nonce, $secret);
    }

    /**
     * Which order a token claims to be about — 0 when it is not even shaped
     * like one.
     *
     * Shape only. This says nothing about whether the token is genuine, which
     * is `verify()`'s job, and a caller that treats a non-zero return as
     * authorisation has the bug this split exists to make visible.
     */
    public static function orderIdFrom(string $token): int
    {
        $parts = explode(self::SEPARATOR, trim($token));

        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return 0;
        }

        $id = (int) $parts[0];

        // A MAC of the wrong length is not a token, and checking it here keeps
        // the verifier from ever being handed one.
        return strlen($parts[1]) === self::LENGTH && ctype_xdigit($parts[1]) ? $id : 0;
    }

    public static function verify(string $token, int $orderId, string $nonce, string $secret): bool
    {
        if (self::orderIdFrom($token) !== $orderId || $orderId <= 0) {
            return false;
        }

        if (trim($nonce) === '' || trim($secret) === '') {
            // No nonce means no link was ever issued for this order. Refusing
            // here rather than comparing against '' is what stops an empty
            // stored value from turning into a MAC anyone can compute.
            return false;
        }

        $presented = explode(self::SEPARATOR, trim($token))[1] ?? '';

        return hash_equals(self::mac($orderId, $nonce, $secret), $presented);
    }

    /** A fresh per-order nonce. Hex, so it survives an option, a URL and a log. */
    public static function newNonce(): string
    {
        return bin2hex(random_bytes(self::NONCE_BYTES));
    }

    private static function mac(int $orderId, string $nonce, string $secret): string
    {
        return substr(
            hash_hmac('sha256', self::CONTEXT . '|' . $orderId . '|' . trim($nonce), $secret),
            0,
            self::LENGTH
        );
    }
}
