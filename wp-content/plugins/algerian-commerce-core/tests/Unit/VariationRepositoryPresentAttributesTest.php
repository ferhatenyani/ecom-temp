<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Products\VariationRepository;
use PHPUnit\Framework\TestCase;

/**
 * The wire form of a variation's attributes — always a list of objects,
 * never the WooCommerce internal `{attribute_pa_color: "rouge"}` map.
 *
 * The storefront's Zod schema expects `[{name, option, slug?, id?}]`; before
 * this, `ProductPresenter::variation()` published the map form and every
 * variable product's PDP failed with `expected array, received object`.
 *
 * No WordPress here — `taxonomy_exists()` and `wc_attribute_label()` are
 * absent under the unit bootstrap, so `presentAttributes()` falls back to
 * `name = key`, which is the shape a variable product without a global
 * taxonomy also emits in production.
 */
final class VariationRepositoryPresentAttributesTest extends TestCase
{
    public function testReturnsAnArrayEvenForAnEmptyMap(): void
    {
        self::assertSame([], VariationRepository::presentAttributes([]));
    }

    public function testEachEntryHasNameAndOption(): void
    {
        $result = VariationRepository::presentAttributes(['pa_color' => 'rouge']);

        self::assertCount(1, $result);
        self::assertArrayHasKey('name', $result[0]);
        self::assertArrayHasKey('option', $result[0]);
        self::assertSame('rouge', $result[0]['option']);
    }

    public function testStripsWoocommerceAttributePrefix(): void
    {
        $result = VariationRepository::presentAttributes(['attribute_pa_size' => 'M']);

        self::assertSame('pa_size', $result[0]['name']);
        self::assertSame('m', $result[0]['option']);
    }

    public function testKeysAreLowercasedAndValuesTrimmed(): void
    {
        $result = VariationRepository::presentAttributes(['PA_Color' => '  Rouge  ']);

        self::assertSame('pa_color', $result[0]['name']);
        self::assertSame('rouge', $result[0]['option']);
    }

    public function testMultipleAttributesAreOrderedAlphabetically(): void
    {
        $result = VariationRepository::presentAttributes([
            'pa_size' => 'M',
            'pa_color' => 'rouge',
        ]);

        self::assertSame(['pa_color', 'pa_size'], [$result[0]['name'], $result[1]['name']]);
    }

    public function testResultIsAJsonArray(): void
    {
        $result = VariationRepository::presentAttributes([
            'pa_color' => 'rouge',
            'pa_size' => 'M',
        ]);

        // json_encode on a list yields [ … ]; on an object it yields { … }.
        // Regression against the pre-fix bug where the object form reached
        // the storefront's Zod validator.
        self::assertStringStartsWith('[', (string) json_encode($result));
    }

    public function testEmptyValuesArePreserved(): void
    {
        // A variation whose value is "" means "any" in WooCommerce's parlance;
        // publishing it as an empty string is what lets the storefront tell
        // apart "any color" from a specific choice.
        $result = VariationRepository::presentAttributes(['pa_color' => '']);

        self::assertSame('', $result[0]['option']);
    }
}
