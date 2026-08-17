<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

/**
 * One click, no login — roadmap §85.
 *
 * Pure, and the same construction §84's `TrackingToken` uses: `{customer id}.{128
 * bits of HMAC-SHA256}`, keyed on the site's own auth salt, compared with
 * `hash_equals()`. See that class for why the identifier travels in the clear.
 *
 * **§85 says "a per-recipient signed token" and this keys on the *customer*, not
 * on the `ac_campaign_recipients` row.** That is a deliberate reading rather than a
 * shortcut, and the reason is the purge two paragraphs down in §85's own text:
 * recipient rows are deleted some fixed period after a campaign completes. A token
 * bound to a row would stop working exactly when somebody finally got round to
 * clicking it — and "unsubscribe is broken" is how a shop's domain ends up on a
 * blocklist, which is the outcome the one-click rule exists to prevent. The
 * customer id outlives every campaign.
 *
 * **Nothing about the campaign is in the token either.** Unsubscribing is from
 * marketing, not from one newsletter; a per-campaign token would invite a
 * per-campaign preference this section does not have and would leak which campaign
 * a link came from to anyone who saw the URL.
 *
 * The risk this accepts, stated plainly: somebody who could forge the MAC could
 * unsubscribe a stranger from marketing. They would need the site salt, the MAC is
 * 128 bits, and the worst outcome is that a customer stops receiving newsletters
 * they can re-enable from their account. Weighed against a dead unsubscribe link,
 * that is the right way round.
 */
final class UnsubscribeToken
{
    public const SEPARATOR = '.';

    /** Hex characters of HMAC kept — 128 bits. */
    public const LENGTH = 32;

    /** Namespaced, so a tracking token can never validate here and vice versa. */
    private const CONTEXT = 'ac-marketing-unsubscribe-v1';

    public static function mint(int $customerId, string $secret): string
    {
        if ($customerId <= 0 || trim($secret) === '') {
            return '';
        }

        return $customerId . self::SEPARATOR . self::mac($customerId, $secret);
    }

    /**
     * Which customer a token claims to be — 0 when it is not even shaped like
     * one. Shape only; `verify()` decides whether it is genuine.
     */
    public static function customerIdFrom(string $token): int
    {
        $parts = explode(self::SEPARATOR, trim($token));

        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return 0;
        }

        return strlen($parts[1]) === self::LENGTH && ctype_xdigit($parts[1]) ? (int) $parts[0] : 0;
    }

    public static function verify(string $token, string $secret): int
    {
        $customerId = self::customerIdFrom($token);

        if ($customerId <= 0 || trim($secret) === '') {
            return 0;
        }

        $presented = explode(self::SEPARATOR, trim($token))[1] ?? '';

        return hash_equals(self::mac($customerId, $secret), $presented) ? $customerId : 0;
    }

    private static function mac(int $customerId, string $secret): string
    {
        return substr(
            hash_hmac('sha256', self::CONTEXT . '|' . $customerId, $secret),
            0,
            self::LENGTH
        );
    }
}
