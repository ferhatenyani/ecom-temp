<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Security\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    private const PROXY = '172.20.0.5';
    private const CLIENT = '41.100.20.30';

    /** @param array<string, mixed> $extra */
    private static function server(string $remote, array $extra = []): array
    {
        return array_merge(['REMOTE_ADDR' => $remote], $extra);
    }

    // ---------------------------------------------------------------- no proxy

    /**
     * The pre-§86 behaviour, unchanged when nothing is configured. A shop that
     * never sets AC_TRUSTED_PROXIES must behave exactly as it did before.
     */
    public function testWithNoTrustedProxiesTheRemoteAddressIsUsed(): void
    {
        self::assertSame(self::CLIENT, ClientIp::resolve(self::server(self::CLIENT), null));
        self::assertSame(self::CLIENT, ClientIp::resolve(self::server(self::CLIENT), ''));
    }

    /**
     * **The security property this class exists for.** A request straight from
     * the internet carrying a forged header must not choose its own identity —
     * otherwise every rate limit is opt-out and every audit row is a suggestion.
     */
    public function testAForgedHeaderFromAnUntrustedSourceIsIgnored(): void
    {
        $resolved = ClientIp::resolve(
            self::server(self::CLIENT, ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']),
            self::PROXY
        );

        self::assertSame(self::CLIENT, $resolved);
    }

    /** Configured proxies exist, but this request did not come through one. */
    public function testAHeaderFromANonProxyIsIgnoredEvenWhenProxiesAreConfigured(): void
    {
        $resolved = ClientIp::resolve(
            self::server('203.0.113.9', ['HTTP_X_FORWARDED_FOR' => '10.0.0.1, 1.2.3.4']),
            '172.20.0.0/16'
        );

        self::assertSame('203.0.113.9', $resolved);
    }

    // ------------------------------------------------------------ behind proxy

    public function testBehindATrustedProxyTheForwardedClientIsUsed(): void
    {
        $resolved = ClientIp::resolve(
            self::server(self::PROXY, ['HTTP_X_FORWARDED_FOR' => self::CLIENT]),
            self::PROXY
        );

        self::assertSame(self::CLIENT, $resolved);
    }

    public function testATrustedProxyMayBeGivenAsACidrBlock(): void
    {
        $resolved = ClientIp::resolve(
            self::server(self::PROXY, ['HTTP_X_FORWARDED_FOR' => self::CLIENT]),
            '172.20.0.0/16'
        );

        self::assertSame(self::CLIENT, $resolved);
    }

    /**
     * The walk is right to left. Each proxy *appends* the address it saw, so
     * the rightmost non-proxy entry is the one our own infrastructure observed
     * — everything to its left is whatever the client chose to send.
     */
    public function testTheRightmostUntrustedEntryWinsSoAPrependedLieIsIgnored(): void
    {
        $resolved = ClientIp::resolve(
            self::server(self::PROXY, [
                // "1.1.1.1" is what the attacker prepended; the real client is
                // the entry our proxy actually recorded.
                'HTTP_X_FORWARDED_FOR' => '1.1.1.1, ' . self::CLIENT,
            ]),
            self::PROXY
        );

        self::assertSame(self::CLIENT, $resolved);
    }

    /** Cloudflare in front of Caddy: two hops, both trusted, client at the left. */
    public function testAChainOfTrustedProxiesResolvesPastAllOfThem(): void
    {
        $resolved = ClientIp::resolve(
            self::server('172.20.0.5', [
                'HTTP_X_FORWARDED_FOR' => self::CLIENT . ', 103.21.244.1, 172.20.0.9',
            ]),
            '172.20.0.0/16, 103.21.244.0/22'
        );

        self::assertSame(self::CLIENT, $resolved);
    }

    /**
     * Every entry is a proxy — a health check from the proxy itself, or a chain
     * longer than the one configured. Its own address is the honest answer;
     * falling back to the leftmost entry would trust the half a client writes.
     */
    public function testWhenEveryEntryIsAProxyTheProxysOwnAddressIsUsed(): void
    {
        $resolved = ClientIp::resolve(
            self::server(self::PROXY, ['HTTP_X_FORWARDED_FOR' => '172.20.0.9']),
            '172.20.0.0/16'
        );

        self::assertSame(self::PROXY, $resolved);
    }

    public function testATrustedProxyWithNoHeaderResolvesToItself(): void
    {
        self::assertSame(self::PROXY, ClientIp::resolve(self::server(self::PROXY), self::PROXY));
    }

    // --------------------------------------------------------------- malformed

    /**
     * Apache writes `unknown` when it has no upstream address. Dropping the
     * entry rather than stopping the walk keeps the entries to its left from
     * being promoted into the client position.
     */
    public function testAMalformedEntryIsDroppedRatherThanEndingTheWalk(): void
    {
        $resolved = ClientIp::resolve(
            self::server(self::PROXY, ['HTTP_X_FORWARDED_FOR' => self::CLIENT . ', unknown']),
            self::PROXY
        );

        self::assertSame(self::CLIENT, $resolved);
    }

    public function testAnInvalidRemoteAddressResolvesToNothing(): void
    {
        self::assertSame('', ClientIp::resolve(['REMOTE_ADDR' => 'not-an-ip'], null));
        self::assertSame('', ClientIp::resolve([], null));
    }

    public function testAPortIsNotPartOfTheAddress(): void
    {
        self::assertSame(
            self::CLIENT,
            ClientIp::resolve(self::server(self::PROXY, [
                'HTTP_X_FORWARDED_FOR' => self::CLIENT . ':51234',
            ]), self::PROXY)
        );
    }

    public function testAnIpv6AddressSurvivesItsBracketsAndPort(): void
    {
        self::assertSame(
            '2001:db8::1',
            ClientIp::resolve(self::server(self::PROXY, [
                'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443',
            ]), self::PROXY)
        );
    }

    public function testAnIpv6ProxyMatchesItsCidrBlock(): void
    {
        self::assertSame(
            self::CLIENT,
            ClientIp::resolve(self::server('2001:db8::5', [
                'HTTP_X_FORWARDED_FOR' => self::CLIENT,
            ]), '2001:db8::/32')
        );
    }

    /** A v4 address is not inside a v6 block, whatever the prefix arithmetic says. */
    public function testAnAddressIsNeverInsideABlockOfTheOtherFamily(): void
    {
        self::assertSame(
            self::CLIENT,
            ClientIp::resolve(self::server(self::CLIENT, [
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ]), '::/0')
        );
    }

    // ------------------------------------------------------------- list parsing

    public function testTheListAcceptsCommasAndWhitespace(): void
    {
        self::assertCount(3, ClientIp::parseList('10.0.0.1, 10.0.0.2   10.0.0.3'));
    }

    /**
     * A typo must never widen trust. Each of these is dropped, so the worst it
     * can do is leave a real proxy untrusted — which shows up as rate limiting
     * keyed on the proxy, not as a client choosing its own identity.
     */
    public function testMalformedEntriesAreDroppedRatherThanWidened(): void
    {
        self::assertSame([], ClientIp::parseList('nonsense'));
        self::assertSame([], ClientIp::parseList('10.0.0.1/'));
        self::assertSame([], ClientIp::parseList('10.0.0.1/abc'));
        self::assertSame([], ClientIp::parseList('10.0.0.0/33'));
        self::assertSame([], ClientIp::parseList('2001:db8::/129'));
        self::assertSame([], ClientIp::parseList(''));
        self::assertSame([], ClientIp::parseList(null));
    }

    public function testAValidEntryBesideAMalformedOneStillCounts(): void
    {
        self::assertCount(1, ClientIp::parseList('10.0.0.0/33, 10.0.0.1'));
    }

    /**
     * /0 is a legal prefix and trusts everything, so it must parse — this test
     * exists to record that it is accepted deliberately, and that
     * docs/DEPLOYMENT.md warns against writing it.
     */
    public function testAZeroPrefixParsesBecauseItIsLegalAndIsDocumentedAsDangerous(): void
    {
        self::assertCount(1, ClientIp::parseList('0.0.0.0/0'));
    }
}
