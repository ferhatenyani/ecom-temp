<?php

declare(strict_types=1);

namespace AlgerianCommerce\Security;

/**
 * Who is asking? — one answer, for the rate limiter, the audit trail, the
 * webhook log, the tracking route and both account routes.
 *
 * **Until §86 there were six copies of this decision and all six said
 * `REMOTE_ADDR`**, each with a comment giving the same correct reason: an
 * `X-Forwarded-For` header is written by whoever sends the request, so trusting
 * it means letting a client choose its own rate-limit bucket and its own line
 * in an append-only audit trail. That reasoning has one condition in it —
 * *"only meaningful behind a proxy you control"* — and putting Caddy in front
 * of the container is the moment the condition changes.
 *
 * **Behind a proxy, `REMOTE_ADDR` is wrong in a way that fails quietly.** Every
 * request arrives from the proxy, so the rate limiter sees one client making
 * all the traffic — one shopper can throttle the whole shop, and the 10-failure
 * login lockout locks out everybody at once. The audit trail records the
 * proxy's address on every row, forever, in the one table this project refuses
 * to update or delete.
 *
 * So the rule is neither "trust the header" nor "ignore it":
 *
 * ```
 * REMOTE_ADDR is not a trusted proxy   -> REMOTE_ADDR, header ignored entirely
 * REMOTE_ADDR is a trusted proxy       -> rightmost X-Forwarded-For entry that
 *                                         is not itself a trusted proxy
 * nothing configured                   -> REMOTE_ADDR (the pre-§86 behaviour)
 * ```
 *
 * **Right to left, because the list is append-only in the wrong direction.**
 * Each proxy appends the address it received the request from, so the rightmost
 * entry is the one our own proxy observed and the leftmost is whatever the
 * original client claimed. A resolver reading left to right hands an attacker
 * the answer by letting them prepend anything they like.
 *
 * **The trusted list is one hop, not a CDN's IP ranges.** Cloudflare's ranges
 * live in Caddy's `trusted_proxies`, because Caddy is what talks to Cloudflare;
 * this class only needs to know that the request came from Caddy. Each layer
 * knows its own neighbour and no more — the alternative is a list of a hundred
 * CIDRs in `.env` that goes stale silently.
 *
 * Pure and static: `$server` is passed in, so every rule above is a unit test
 * rather than something discovered from a production log.
 */
final class ClientIp
{
    /**
     * Resolve the address to attribute this request to.
     *
     * @param array<string, mixed> $server        usually `$_SERVER`
     * @param string|null          $trustedProxies `AC_TRUSTED_PROXIES` — comma
     *                                            or space separated IPs and
     *                                            CIDR blocks
     *
     * @return string a validated address, or '' when there is nothing usable
     */
    public static function resolve(array $server, ?string $trustedProxies = null): string
    {
        $remote = self::validIp($server['REMOTE_ADDR'] ?? null);

        if ($remote === '') {
            return '';
        }

        $trusted = self::parseList($trustedProxies);

        /*
         * Nothing configured, or the request did not come from a proxy we
         * named. Either way the header is unsigned input from a stranger and
         * is not read at all — deliberately not "read it and validate it",
         * because a validated lie is still a lie.
         */
        if ($trusted === [] || !self::matchesAny($remote, $trusted)) {
            return $remote;
        }

        $forwarded = self::forwardedList($server);

        for ($i = count($forwarded) - 1; $i >= 0; $i--) {
            if (!self::matchesAny($forwarded[$i], $trusted)) {
                return $forwarded[$i];
            }
        }

        /*
         * Every entry was a trusted proxy, or the header was absent. The
         * request genuinely came from the proxy itself — a health check, or a
         * chain longer than the one configured. Its own address is the honest
         * answer; inventing one from the leftmost entry would mean trusting
         * the part of the header a client controls.
         */
        return $remote;
    }

    /**
     * Did this request reach us from an address we named as a proxy?
     *
     * The one question worth asking before believing any `X-Forwarded-*`
     * header, so `resolve()` and the bootstrap's scheme fix share an answer
     * rather than each deciding for themselves.
     *
     * @param array<string, mixed> $server
     */
    public static function trustsPeer(array $server, ?string $trustedProxies = null): bool
    {
        $remote = self::validIp($server['REMOTE_ADDR'] ?? null);
        $trusted = self::parseList($trustedProxies);

        return $remote !== '' && $trusted !== [] && self::matchesAny($remote, $trusted);
    }

    /**
     * Teach WordPress it is behind TLS, when the proxy says so and the proxy is
     * one we trust.
     *
     * **Without this, WordPress builds `http://` URLs behind an `https://`
     * proxy.** TLS ends at Caddy, so Apache sees plain HTTP, `is_ssl()` is
     * false, and `home_url()` returns http — the browser is then bounced
     * between the proxy's https and WordPress's http until it gives up. It is
     * the redirect loop every first deployment behind a proxy hits, and it also
     * silently un-does §44: `wp_is_application_passwords_supported()` checks
     * `is_ssl()`, so the Next.js server's credential stops being accepted.
     *
     * **This lives in the plugin rather than in `wp-config.php`, and that was
     * measured.** The `wordpress` image only writes `WORDPRESS_CONFIG_EXTRA`
     * into `wp-config.php` when it *creates* that file, and it creates it only
     * when the volume has none — so on every install that already exists, the
     * setting is accepted, documented, and silently does nothing. That is the
     * same trap CLAUDE.md records for the image tag and §61 for
     * `AC_RATE_LIMIT_*`. The plugin is what gets cloned per client and it runs
     * on every request, so it is the honest home for this.
     *
     * Gated on `trustsPeer()`, which is why there is no separate "behind a
     * proxy" flag: a deployment that has named its proxy has said everything
     * needed, and one that has not cannot be spoofed into believing a header.
     *
     * @param array<string, mixed> $server usually `$_SERVER`, by reference
     */
    public static function applyForwardedScheme(array &$server, ?string $trustedProxies = null): void
    {
        if (!self::trustsPeer($server, $trustedProxies)) {
            return;
        }

        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';

        // A comma-separated list is legal when there are several hops; the
        // first entry is what the client spoke to.
        if (is_string($proto) && strtolower(trim(explode(',', $proto)[0])) === 'https') {
            $server['HTTPS'] = 'on';
        }
    }

    /**
     * `X-Forwarded-For`, left to right, keeping only what parses as an address.
     *
     * A malformed entry is dropped rather than ending the walk: a proxy that
     * writes `unknown` (Apache does, for a request with no upstream) must not
     * make the entries to its left look like the client.
     *
     * @param array<string, mixed> $server
     *
     * @return list<string>
     */
    private static function forwardedList(array $server): array
    {
        $raw = $server['HTTP_X_FORWARDED_FOR'] ?? '';

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $found = [];

        foreach (explode(',', $raw) as $candidate) {
            $ip = self::validIp($candidate);

            if ($ip !== '') {
                $found[] = $ip;
            }
        }

        return $found;
    }

    /**
     * @param list<array{0: string, 1: int|null}> $trusted
     */
    private static function matchesAny(string $ip, array $trusted): bool
    {
        foreach ($trusted as [$network, $bits]) {
            if ($bits === null) {
                if (hash_equals($network, $ip)) {
                    return true;
                }

                continue;
            }

            if (self::inCidr($ip, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Binary prefix comparison, which works for v4 and v6 without two
     * implementations: `inet_pton` gives 4 or 16 bytes and a mismatch in
     * length is a mismatch in family.
     */
    private static function inCidr(string $ip, string $network, int $bits): bool
    {
        $address = @inet_pton($ip);
        $subnet = @inet_pton($network);

        if ($address === false || $subnet === false || strlen($address) !== strlen($subnet)) {
            return false;
        }

        if ($bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole > 0 && strncmp($address, $subnet, $whole) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return ($address[$whole] & $mask) === ($subnet[$whole] & $mask);
    }

    /**
     * Parse `AC_TRUSTED_PROXIES` into networks.
     *
     * Public so the parsing is a unit test: a malformed entry is **dropped**,
     * never widened. The failure that matters is a typo turning into a broader
     * trust than anyone intended, and dropping is the direction that fails
     * closed — the worst a bad entry can do is leave a real proxy untrusted,
     * which shows up as rate limiting keyed on the proxy rather than as a
     * client choosing its own identity.
     *
     * @return list<array{0: string, 1: int|null}> network, prefix bits or null
     */
    public static function parseList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parsed = [];

        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }

            if (!str_contains($entry, '/')) {
                $ip = self::validIp($entry);

                if ($ip !== '') {
                    $parsed[] = [$ip, null];
                }

                continue;
            }

            [$network, $bits] = explode('/', $entry, 2);

            $network = self::validIp($network);

            if ($network === '' || !preg_match('/^\d{1,3}$/', $bits)) {
                continue;
            }

            $length = strlen((string) @inet_pton($network)) * 8;

            if ((int) $bits > $length) {
                continue;
            }

            $parsed[] = [$network, (int) $bits];
        }

        return $parsed;
    }

    private static function validIp(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // A proxy may write `[2001:db8::1]:443`; the port is not part of the
        // identity and the brackets are not part of the address.
        $value = trim($value);

        if (preg_match('/^\[(.+)\](?::\d+)?$/', $value, $m) === 1) {
            $value = $m[1];
        } elseif (substr_count($value, ':') === 1 && str_contains($value, '.')) {
            $value = substr($value, 0, (int) strpos($value, ':'));
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? '' : $value;
    }
}
