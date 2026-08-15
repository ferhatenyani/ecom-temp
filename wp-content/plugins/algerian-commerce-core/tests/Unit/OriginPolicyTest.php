<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\OriginPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OriginPolicyTest extends TestCase
{
    public function testParsesACommaSeparatedList(): void
    {
        $policy = OriginPolicy::fromList('https://store.example.dz, https://admin.example.dz');

        self::assertSame(
            ['https://store.example.dz', 'https://admin.example.dz'],
            $policy->origins()
        );
    }

    public function testFallsBackToDevelopmentOriginsWhenUnset(): void
    {
        self::assertSame(OriginPolicy::DEVELOPMENT_DEFAULTS, OriginPolicy::fromList(null)->origins());
        self::assertSame(OriginPolicy::DEVELOPMENT_DEFAULTS, OriginPolicy::fromList('   ')->origins());
    }

    public function testNormalizesCaseAndTrailingSlash(): void
    {
        $policy = new OriginPolicy(['HTTPS://Store.Example.DZ/']);

        self::assertTrue($policy->allows('https://store.example.dz'));
        self::assertSame(['https://store.example.dz'], $policy->origins());
    }

    public function testKeepsThePort(): void
    {
        $policy = new OriginPolicy(['http://localhost:3000']);

        self::assertTrue($policy->allows('http://localhost:3000'));
        self::assertFalse($policy->allows('http://localhost:3001'), 'a different port is a different origin');
        self::assertFalse($policy->allows('http://localhost'), 'no port is a different origin');
    }

    public function testSchemeMustMatch(): void
    {
        $policy = new OriginPolicy(['https://store.example.dz']);

        self::assertFalse($policy->allows('http://store.example.dz'));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedProvider(): array
    {
        return [
            'wildcard' => ['*'],
            'null origin' => ['null'],
            'empty' => [''],
            'whitespace' => ['   '],
            'no scheme' => ['store.example.dz'],
            'ftp' => ['ftp://store.example.dz'],
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,x'],
            'wildcard host' => ['https://*.example.dz'],
            'with path' => ['https://store.example.dz/api'],
            'with query' => ['https://store.example.dz?a=1'],
            'with credentials' => ['https://user:pass@store.example.dz'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectsAnythingThatIsNotABareHttpOrigin(string $origin): void
    {
        self::assertNull(OriginPolicy::normalize($origin));
        self::assertFalse((new OriginPolicy([$origin]))->allows($origin));
    }

    public function testAWildcardEntryProducesAnEmptyPolicyRatherThanAnOpenOne(): void
    {
        // Fail closed: a misconfigured "*" must block everything, never
        // allow everything.
        $policy = OriginPolicy::fromList('*');

        self::assertTrue($policy->isEmpty());
        self::assertFalse($policy->allows('https://anything.example.com'));
    }

    /** @return array<string, array{0: string}> */
    public static function lookalikeProvider(): array
    {
        return [
            'suffix attack' => ['https://store.example.dz.attacker.com'],
            'prefix attack' => ['https://attacker-store.example.dz'],
            'subdomain' => ['https://api.store.example.dz'],
            'parent domain' => ['https://example.dz'],
            'homoglyph-ish' => ['https://store.example.dz@evil.com'],
        ];
    }

    /**
     * Matching is exact. A prefix or suffix match here would hand an
     * attacker-controlled domain a credentialed request against the API.
     */
    #[DataProvider('lookalikeProvider')]
    public function testLookalikeOriginsAreNotAllowed(string $origin): void
    {
        $policy = new OriginPolicy(['https://store.example.dz']);

        self::assertFalse($policy->allows($origin), "{$origin} must not match");
    }

    public function testDeduplicates(): void
    {
        $policy = OriginPolicy::fromList('http://localhost:3000,http://localhost:3000/,HTTP://LOCALHOST:3000');

        self::assertSame(['http://localhost:3000'], $policy->origins());
    }

    public function testInvalidEntriesAreDroppedWithoutLosingValidOnes(): void
    {
        $policy = OriginPolicy::fromList('https://store.example.dz, not-a-url, *, https://admin.example.dz');

        self::assertSame(['https://store.example.dz', 'https://admin.example.dz'], $policy->origins());
    }

    public function testAnEmptyPolicyAllowsNothing(): void
    {
        $policy = new OriginPolicy([]);

        self::assertTrue($policy->isEmpty());
        self::assertFalse($policy->allows('http://localhost:3000'));
    }
}
