<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\ProductFilters;
use PHPUnit\Framework\TestCase;

/**
 * Catalogue filters — roadmap §82.
 *
 * Every value here arrives from a browser, and §82's security section names
 * the assertion that matters: **a filter payload must not widen a result set**,
 * because "200, no crash" is what a working injection returns. The shapes below
 * are what a payload can be before it reaches a query — the taxonomy-name half
 * of that rule lives in `AttributeCatalogue`, which needs the database, and is
 * asserted over the wire in `tests/Api/products.php`.
 *
 * The truncation cap is here and nowhere else: reaching it through the API
 * would take fifty-one attribute terms that each have a product, so a unit test
 * is the only place §82's "no unbounded facet list" is actually exercised
 * rather than assumed.
 */
final class ProductFiltersTest extends TestCase
{
    public function testAnEmptyRequestFiltersNothing(): void
    {
        $filters = ProductFilters::fromParams([]);

        self::assertTrue($filters->isEmpty());
        self::assertFalse($filters->wantsFacets());
        self::assertSame([], $filters->attributes);
        self::assertNull($filters->minPrice);
    }

    public function testNoneIsTheSameAsAnEmptyRequest(): void
    {
        self::assertTrue(ProductFilters::none()->isEmpty());
        self::assertFalse(ProductFilters::none()->wantsFacets());
    }

    /**
     * Prices stay strings. A float is the wrong type for money everywhere else
     * in this API and a filter is not the place to make an exception.
     */
    public function testAPriceBandIsKeptAsGivenAndAsAString(): void
    {
        $filters = ProductFilters::fromParams(['min_price' => '150.50', 'max_price' => 450]);

        self::assertSame('150.50', $filters->minPrice);
        self::assertSame('450', $filters->maxPrice);
        self::assertFalse($filters->isEmpty());
    }

    /**
     * The one cross-field rule, and the reason it cannot be an arg schema: an
     * inverted band matches nothing, which is indistinguishable from a shop
     * with nothing in that band.
     */
    public function testAnInvertedPriceBandIsRefused(): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['min_price' => '500', 'max_price' => '100']);
    }

    public function testAnEqualPriceBandIsAllowed(): void
    {
        $filters = ProductFilters::fromParams(['min_price' => '100', 'max_price' => '100']);

        self::assertSame('100', $filters->minPrice);
    }

    public function testANegativePriceIsRefused(): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['min_price' => '-1']);
    }

    public function testANonNumericPriceIsRefused(): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['max_price' => 'free']);
    }

    public function testAttributesArriveAsAMapOfLists(): void
    {
        $filters = ProductFilters::fromParams(['attributes' => ['pa_size' => 'm,l', 'pa_colour' => ['rouge']]]);

        self::assertSame(['pa_size' => ['m', 'l'], 'pa_colour' => ['rouge']], $filters->attributes);
    }

    public function testDuplicateAttributeValuesCollapse(): void
    {
        $filters = ProductFilters::fromParams(['attributes' => ['pa_size' => 'm,m,l']]);

        self::assertSame(['pa_size' => ['m', 'l']], $filters->attributes);
    }

    public function testAnAttributeWithNoValuesIsRefused(): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['attributes' => ['pa_size' => '']]);
    }

    public function testAScalarAttributesValueIsRefused(): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['attributes' => 'pa_size']);
    }

    /**
     * §82's security rule, at the shape layer. An attribute *name* carrying
     * anything but a name never reaches `AttributeCatalogue`, let alone a
     * query — and the refusal is a 400 rather than a silently dropped clause,
     * because a dropped clause is how a filter widens a result set.
     *
     * @param mixed $key
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostileAttributeNames')]
    public function testAnAttributeNameThatIsNotANameIsRefused(string $key): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['attributes' => [$key => 'm']]);
    }

    /** @return array<string, array{string}> */
    public static function hostileAttributeNames(): array
    {
        return [
            'sql fragment' => ["pa_size' OR '1'='1"],
            'union' => ['pa_size UNION SELECT'],
            'backtick' => ['`wp_users`'],
            'semicolon' => ['pa_size; DROP TABLE wp_posts'],
            'space' => ['pa size'],
            'quote' => ['pa_size"'],
            'empty' => [''],
            'parenthesis' => ['pa_size()'],
        ];
    }

    public function testATermListAcceptsOneIdOrSeveral(): void
    {
        self::assertSame([12], ProductFilters::fromParams(['category' => '12'])->categories);
        self::assertSame([12, 15], ProductFilters::fromParams(['category' => '12,15'])->categories);
        self::assertSame([7], ProductFilters::fromParams(['tag' => '7'])->tags);
    }

    /**
     * A term list that is not a list of terms is refused rather than coerced.
     * `(int) "1 OR 1=1"` is 1, so coercing would quietly filter on the wrong
     * category and report success.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostileTermLists')]
    public function testAHostileTermListIsRefused(string $value): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['category' => $value]);
    }

    /** @return array<string, array{string}> */
    public static function hostileTermLists(): array
    {
        return [
            'or clause' => ['1 OR 1=1'],
            'union' => ['1 UNION SELECT 1'],
            'negative' => ['-1'],
            'zero' => ['0'],
            'word' => ['tapis'],
            'trailing comma' => ['1,'],
        ];
    }

    public function testStockStatusIsAnEnum(): void
    {
        self::assertSame('outofstock', ProductFilters::fromParams(['stock_status' => 'outofstock'])->stockStatus);

        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['stock_status' => "instock' OR '1'='1"]);
    }

    public function testRatingIsBoundedToTheFiveStarsThatExist(): void
    {
        self::assertSame(4.0, ProductFilters::fromParams(['rating_min' => '4'])->ratingMin);

        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['rating_min' => '6']);
    }

    public function testBooleansAcceptTheFormsAQueryStringProduces(): void
    {
        self::assertTrue(ProductFilters::fromParams(['on_sale' => 'true'])->onSale);
        self::assertTrue(ProductFilters::fromParams(['on_sale' => '1'])->onSale);
        self::assertTrue(ProductFilters::fromParams(['featured' => true])->featured);
        self::assertFalse(ProductFilters::fromParams(['on_sale' => 'false'])->onSale);
        self::assertFalse(ProductFilters::fromParams(['on_sale' => '0'])->onSale);
        self::assertNull(ProductFilters::fromParams([])->onSale);
    }

    /**
     * Absent and false are different questions: "do not filter on sales" and
     * "everything that is not on sale". Collapsing them would make the
     * unfiltered listing quietly exclude every discounted product.
     */
    public function testAnAbsentBooleanIsNotFalse(): void
    {
        self::assertNull(ProductFilters::fromParams([])->onSale);
        self::assertNull(ProductFilters::fromParams(['on_sale' => ''])->onSale);
        self::assertFalse(ProductFilters::fromParams(['on_sale' => 'no'])->onSale);
    }

    public function testFacetsAreOptInAndNamed(): void
    {
        $filters = ProductFilters::fromParams(['facets' => 'attributes,price']);

        self::assertTrue($filters->wantsFacets());
        self::assertTrue($filters->wants('attributes'));
        self::assertTrue($filters->wants('price'));
        self::assertFalse($filters->wants('category'));
    }

    public function testAnUnknownFacetGroupIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(ApiException::class);

        ProductFilters::fromParams(['facets' => 'attributes,everything']);
    }

    public function testFacetGroupsAreTheDocumentedSix(): void
    {
        self::assertSame(
            ['attributes', 'price', 'category', 'tag', 'stock_status', 'rating'],
            ProductFilters::FACET_GROUPS
        );
    }

    /** Resolving attribute keys replaces them without disturbing anything else. */
    public function testWithAttributesKeepsEveryOtherFilter(): void
    {
        $filters = ProductFilters::fromParams([
            'attributes' => ['size' => 'm'],
            'min_price' => '100',
            'category' => '4',
            'on_sale' => 'true',
            'facets' => 'price',
        ])->withAttributes(['pa_size' => ['m']]);

        self::assertSame(['pa_size' => ['m']], $filters->attributes);
        self::assertSame('100', $filters->minPrice);
        self::assertSame([4], $filters->categories);
        self::assertTrue($filters->onSale);
        self::assertTrue($filters->wants('price'));
    }

    // ── §82's third refusal: no unbounded facet list ──

    public function testAFacetGroupUnderTheCapIsNotReportedAsTruncated(): void
    {
        $capped = ProductFilters::capFacetValues(self::values(3));

        self::assertCount(3, $capped['values']);
        self::assertSame(3, $capped['total_values']);
        self::assertFalse($capped['truncated']);
    }

    public function testAFacetGroupExactlyAtTheCapIsNotTruncated(): void
    {
        $capped = ProductFilters::capFacetValues(self::values(ProductFilters::MAX_FACET_VALUES));

        self::assertCount(ProductFilters::MAX_FACET_VALUES, $capped['values']);
        self::assertFalse($capped['truncated']);
    }

    /**
     * The assertion §66's rule exists for: the list is cut **and says so**, so
     * a storefront showing fifty values knows there were more.
     */
    public function testAFacetGroupOverTheCapIsCutAndSaysSo(): void
    {
        $capped = ProductFilters::capFacetValues(self::values(ProductFilters::MAX_FACET_VALUES + 1));

        self::assertCount(ProductFilters::MAX_FACET_VALUES, $capped['values']);
        self::assertSame(ProductFilters::MAX_FACET_VALUES + 1, $capped['total_values']);
        self::assertTrue($capped['truncated']);
    }

    /** The commonest values survive the cut, not whichever came back first. */
    public function testTheCapKeepsTheLargestCounts(): void
    {
        $capped = ProductFilters::capFacetValues([
            ['term_id' => 1, 'slug' => 'a', 'name' => 'A', 'count' => 1],
            ['term_id' => 2, 'slug' => 'b', 'name' => 'B', 'count' => 9],
            ['term_id' => 3, 'slug' => 'c', 'name' => 'C', 'count' => 5],
        ]);

        self::assertSame([9, 5, 1], array_column($capped['values'], 'count'));
    }

    /** Ties break by name, so the same catalogue always answers the same way. */
    public function testEqualCountsAreOrderedByName(): void
    {
        $capped = ProductFilters::capFacetValues([
            ['term_id' => 1, 'slug' => 'zinc', 'name' => 'Zinc', 'count' => 2],
            ['term_id' => 2, 'slug' => 'argent', 'name' => 'Argent', 'count' => 2],
        ]);

        self::assertSame(['Argent', 'Zinc'], array_column($capped['values'], 'name'));
    }

    /** @return list<array{term_id: int, slug: string, name: string, count: int}> */
    private static function values(int $count): array
    {
        $values = [];

        for ($i = 1; $i <= $count; $i++) {
            $values[] = ['term_id' => $i, 'slug' => "t{$i}", 'name' => "T{$i}", 'count' => $i];
        }

        return $values;
    }
}
