<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Tracking\TrackingToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The key to §84's public route.
 *
 * The property that matters most is the one §84 states first — **the token is
 * not derived from the order id, and not sequential** — and it is asserted
 * directly rather than inferred from "it looks random": consecutive order ids
 * with the same nonce and the same secret must produce MACs with nothing in
 * common, or a shop's order book is walkable from one leaked link.
 */
final class TrackingTokenTest extends TestCase
{
    private const SECRET = 'a-site-auth-salt-that-is-long-enough';
    private const NONCE = 'd6f1c0a29b3e4f5081726354a9b8c7d0';

    public function testRoundTrips(): void
    {
        $token = TrackingToken::mint(4211, self::NONCE, self::SECRET);

        self::assertNotSame('', $token);
        self::assertSame(4211, TrackingToken::orderIdFrom($token));
        self::assertTrue(TrackingToken::verify($token, 4211, self::NONCE, self::SECRET));
    }

    /** Same inputs, same token — the checkout response and the email must agree. */
    public function testIsDeterministic(): void
    {
        self::assertSame(
            TrackingToken::mint(4211, self::NONCE, self::SECRET),
            TrackingToken::mint(4211, self::NONCE, self::SECRET)
        );
    }

    public function testShape(): void
    {
        [$id, $mac] = explode(TrackingToken::SEPARATOR, TrackingToken::mint(7, self::NONCE, self::SECRET));

        self::assertSame('7', $id);
        self::assertSame(TrackingToken::LENGTH, strlen($mac));
        self::assertTrue(ctype_xdigit($mac));
    }

    /**
     * §84's first requirement, asserted as arithmetic.
     *
     * Order numbers are sequential; the MAC must not be. Twenty consecutive ids
     * produce twenty distinct MACs, and no two of them share so much as their
     * first four characters — which is what a "close ids give close tokens"
     * construction would fail.
     */
    public function testIsNotDerivableFromTheOrderId(): void
    {
        $macs = [];

        for ($id = 1000; $id < 1020; $id++) {
            $macs[] = explode(TrackingToken::SEPARATOR, TrackingToken::mint($id, self::NONCE, self::SECRET))[1];
        }

        self::assertCount(20, array_unique($macs), 'two order ids produced the same MAC');

        $prefixes = array_map(static fn (string $mac): string => substr($mac, 0, 4), $macs);

        self::assertCount(20, array_unique($prefixes), 'consecutive ids produced adjacent MACs');
    }

    /** A leaked link must not open the next order along. */
    public function testATokenIsBoundToItsOrder(): void
    {
        $token = TrackingToken::mint(4211, self::NONCE, self::SECRET);

        self::assertFalse(TrackingToken::verify($token, 4212, self::NONCE, self::SECRET));
    }

    /**
     * Rotating the nonce is §84's revocation, so the old token must stop
     * working. If this passes trivially the revoke path is decorative.
     */
    public function testRotatingTheNonceInvalidatesTheToken(): void
    {
        $token = TrackingToken::mint(4211, self::NONCE, self::SECRET);

        self::assertFalse(TrackingToken::verify($token, 4211, TrackingToken::newNonce(), self::SECRET));
    }

    public function testRotatingTheSecretInvalidatesTheToken(): void
    {
        $token = TrackingToken::mint(4211, self::NONCE, self::SECRET);

        self::assertFalse(TrackingToken::verify($token, 4211, self::NONCE, 'a-different-salt-entirely'));
    }

    /**
     * One flipped character. The MAC is truncated to 32 hex characters and a
     * truncation bug that compared only a prefix would pass every other test
     * here.
     */
    public function testATamperedMacIsRefused(): void
    {
        $token = TrackingToken::mint(4211, self::NONCE, self::SECRET);

        for ($position = 0; $position < TrackingToken::LENGTH; $position++) {
            [$id, $mac] = explode(TrackingToken::SEPARATOR, $token);
            $mac[$position] = $mac[$position] === 'a' ? 'b' : 'a';

            self::assertFalse(
                TrackingToken::verify($id . TrackingToken::SEPARATOR . $mac, 4211, self::NONCE, self::SECRET),
                "a MAC altered at position {$position} was accepted"
            );
        }
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokensCarryNoOrderId(string $token): void
    {
        self::assertSame(0, TrackingToken::orderIdFrom($token));
        self::assertFalse(TrackingToken::verify($token, 4211, self::NONCE, self::SECRET));
    }

    /** @return array<string, array{string}> */
    public static function malformedTokens(): array
    {
        $mac = str_repeat('a', TrackingToken::LENGTH);

        return [
            'empty' => [''],
            'no separator' => ['4211' . $mac],
            'no mac' => ['4211.'],
            'no id' => ['.' . $mac],
            'three parts' => ['4211.' . $mac . '.extra'],
            'non-numeric id' => ['abc.' . $mac],
            'negative id' => ['-1.' . $mac],
            'short mac' => ['4211.' . substr($mac, 0, 8)],
            'long mac' => ['4211.' . $mac . 'aa'],
            'non-hex mac' => ['4211.' . str_repeat('z', TrackingToken::LENGTH)],
            'sql-ish' => ["4211' OR '1'='1"],
            'path traversal' => ['../../4211.' . $mac],
        ];
    }

    /**
     * An order that was never issued a link has no nonce, and an empty stored
     * value must not become a MAC anybody can compute. Without this, every
     * order in the shop would be trackable the moment somebody guessed the
     * construction.
     */
    public function testAnOrderWithNoNonceCannotBeTracked(): void
    {
        self::assertSame('', TrackingToken::mint(4211, '', self::SECRET));
        self::assertFalse(TrackingToken::verify('4211.' . str_repeat('a', 32), 4211, '', self::SECRET));
        self::assertFalse(TrackingToken::verify('4211.' . str_repeat('a', 32), 4211, '   ', self::SECRET));
    }

    /** An install with no salt mints nothing rather than minting with ''. */
    public function testNoSecretMintsNothing(): void
    {
        self::assertSame('', TrackingToken::mint(4211, self::NONCE, ''));
        self::assertFalse(TrackingToken::verify('4211.' . str_repeat('a', 32), 4211, self::NONCE, ''));
    }

    public function testAnUnusableOrderIdMintsNothing(): void
    {
        self::assertSame('', TrackingToken::mint(0, self::NONCE, self::SECRET));
        self::assertSame('', TrackingToken::mint(-3, self::NONCE, self::SECRET));
    }

    public function testNoncesAreLongAndDistinct(): void
    {
        $nonces = [];

        for ($i = 0; $i < 50; $i++) {
            $nonce = TrackingToken::newNonce();

            self::assertSame(TrackingToken::NONCE_BYTES * 2, strlen($nonce));
            self::assertTrue(ctype_xdigit($nonce));

            $nonces[] = $nonce;
        }

        self::assertCount(50, array_unique($nonces));
    }

    /** Surrounding whitespace is a copy-paste artefact, not a different token. */
    public function testWhitespaceIsTolerated(): void
    {
        $token = TrackingToken::mint(4211, self::NONCE, self::SECRET);

        self::assertTrue(TrackingToken::verify("  {$token}\n", 4211, self::NONCE, self::SECRET));
    }
}
