<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Campaigns\AudienceResolver;
use AlgerianCommerce\Campaigns\SegmentCriteria;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A segment's stored query — §85.
 *
 * Two halves. `fromPayload()` is strict and names the field, `fromStored()` is lenient
 * and reports what it dropped: the pair `OptionSet` (§83) and `HomepageSections` (§61)
 * both establish, and it matters more here than in either, because **a criteria
 * document that lost every criterion would mean "everyone"** — the one mistake in this
 * module that cannot be undone once the mail has gone out.
 *
 * `AudienceResolver::matchesOrderStats()` is tested here rather than in a suite of its
 * own: it is a pure static on an otherwise database-bound class, and its boundary
 * cases — "spent exactly the minimum", "a customer with no orders at all" — are where
 * an off-by-one would put the wrong people in an audience.
 */
final class SegmentCriteriaTest extends TestCase
{
    public function testAcceptsEveryDocumentedCriterion(): void
    {
        $criteria = SegmentCriteria::fromPayload([
            'min_spent' => '5000.00',
            'max_spent' => '90000',
            'min_orders' => 2,
            'max_orders' => 20,
            'ordered_after' => '2026-05-01',
            'ordered_before' => '2026-08-01',
            'registered_after' => '2025-01-01',
            'registered_before' => '2026-08-01',
            'wilaya_id' => 16,
            'bought_product_id' => 42,
            'not_bought_product_id' => 43,
        ]);

        self::assertCount(count(SegmentCriteria::FIELDS), $criteria->toArray());
        self::assertSame('5000.00', $criteria->get('min_spent'));
        self::assertSame(16, $criteria->get('wilaya_id'));
    }

    #[DataProvider('refusedFields')]
    public function testRefusesByNameWithAReason(string $field): void
    {
        try {
            SegmentCriteria::fromPayload([$field => 'anything']);
            self::fail("{$field} was accepted");
        } catch (ApiException $exception) {
            $fields = $exception->details()['fields'] ?? [];

            self::assertArrayHasKey($field, $fields);
            self::assertNotSame('', trim((string) $fields[$field]), 'the refusal must carry a reason');
        }
    }

    /** @return list<array{string}> */
    public static function refusedFields(): array
    {
        return array_map(static fn (string $f): array => [$f], array_keys(SegmentCriteria::REFUSED));
    }

    /**
     * **Consent is not a criterion**, and this is the assertion that keeps it that
     * way. A criterion that could set it could switch it off, and §85 puts the filter
     * in the resolver precisely so no caller can.
     */
    public function testConsentCanNeverBeACriterion(): void
    {
        foreach (['consent', 'marketing_consent'] as $field) {
            self::assertArrayHasKey($field, SegmentCriteria::REFUSED);
            self::assertArrayNotHasKey($field, SegmentCriteria::FIELDS);
        }
    }

    public function testAnUnknownCriterionIsNamedAndListsWhatIsSupported(): void
    {
        try {
            SegmentCriteria::fromPayload(['favourite_colour' => 'blue']);
            self::fail('accepted');
        } catch (ApiException $exception) {
            self::assertStringContainsString('min_spent', (string) $exception->details()['fields']['favourite_colour']);
        }
    }

    public function testAnEmptyOrNullCriterionIsHowAClientClearsOne(): void
    {
        $criteria = SegmentCriteria::fromPayload(['min_orders' => 2, 'max_orders' => null, 'wilaya_id' => '']);

        self::assertTrue($criteria->has('min_orders'));
        self::assertFalse($criteria->has('max_orders'));
        self::assertFalse($criteria->has('wilaya_id'));
    }

    public function testAnEmptyDocumentIsEmptyRatherThanAnError(): void
    {
        // Refusing an empty document is `SegmentService`'s job, because "empty means
        // everyone" is a decision about audiences rather than about parsing.
        self::assertTrue(SegmentCriteria::fromPayload([])->isEmpty());
    }

    // ------------------------------------------------------------ validation --

    #[DataProvider('badValues')]
    public function testRefusesAValueOfTheWrongShape(string $field, mixed $value): void
    {
        $this->expectException(ApiException::class);

        SegmentCriteria::fromPayload([$field => $value]);
    }

    /** @return array<string, array{string, mixed}> */
    public static function badValues(): array
    {
        return [
            'money with three decimals' => ['min_spent', '10.123'],
            'money with a currency' => ['min_spent', '5000 DZD'],
            'negative money' => ['min_spent', '-5'],
            'money over the ceiling' => ['min_spent', '999999999.00'],
            'a count that is not a number' => ['min_orders', 'two'],
            'a count over the ceiling' => ['min_orders', 200000],
            'a date in the wrong format' => ['ordered_after', '01/05/2026'],
            'an impossible date' => ['ordered_after', '2026-02-31'],
            'a month that does not exist' => ['ordered_after', '2026-13-01'],
            'an id that is not a number' => ['wilaya_id', '16a'],
            'an array where a scalar belongs' => ['min_orders', ['2']],
        ];
    }

    /**
     * An inverted range is refused rather than resolved to an empty audience, because
     * an audience of nobody looks exactly like a segment whose customers have not
     * shopped yet.
     */
    #[DataProvider('invertedRanges')]
    public function testRefusesAnInvertedRange(array $payload, string $field): void
    {
        try {
            SegmentCriteria::fromPayload($payload);
            self::fail('accepted');
        } catch (ApiException $exception) {
            self::assertArrayHasKey($field, $exception->details()['fields'] ?? []);
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invertedRanges(): array
    {
        return [
            'spent' => [['min_spent' => '100', 'max_spent' => '10'], 'max_spent'],
            'orders' => [['min_orders' => 10, 'max_orders' => 2], 'max_orders'],
            'ordered' => [['ordered_after' => '2026-08-01', 'ordered_before' => '2026-05-01'], 'ordered_before'],
            'registered' => [['registered_after' => '2026-08-01', 'registered_before' => '2026-05-01'], 'registered_before'],
        ];
    }

    public function testEqualBoundsAreLegal(): void
    {
        $criteria = SegmentCriteria::fromPayload([
            'min_orders' => 3, 'max_orders' => 3,
            'ordered_after' => '2026-08-01', 'ordered_before' => '2026-08-01',
        ]);

        self::assertSame(3, $criteria->get('min_orders'));
    }

    /** "Bought X and did not buy X" is a contradiction, not a query. */
    public function testTheSameProductCannotBeBothBoughtAndNotBought(): void
    {
        $this->expectException(ApiException::class);

        SegmentCriteria::fromPayload(['bought_product_id' => 7, 'not_bought_product_id' => 7]);
    }

    // ------------------------------------------------------------ fromStored --

    public function testStoredCriteriaAreReadLeniently(): void
    {
        $read = SegmentCriteria::fromStored([
            'min_orders' => 2,
            'removed_in_a_later_version' => 'x',
            'ordered_after' => 'not a date',
        ]);

        self::assertSame(['min_orders' => 2], $read['criteria']->toArray());
        self::assertCount(2, $read['problems']);
        self::assertStringContainsString('removed_in_a_later_version', implode(' ', $read['problems']));
    }

    /**
     * The one that matters: a document whose every criterion was dropped comes back
     * **empty and reported**, so `Segment::isResolvable()` is false and the resolver
     * refuses. Silently resolving it would mail the whole customer list.
     */
    public function testADocumentThatLostEverythingIsEmptyAndReported(): void
    {
        $read = SegmentCriteria::fromStored(['nonsense' => 1, 'more_nonsense' => 2]);

        self::assertTrue($read['criteria']->isEmpty());
        self::assertCount(2, $read['problems']);
    }

    public function testStoredValuesStillHaveToBeTheRightShape(): void
    {
        $read = SegmentCriteria::fromStored(['min_spent' => '10.999']);

        self::assertTrue($read['criteria']->isEmpty());
        self::assertSame(['Dropped unusable criterion "min_spent".'], $read['problems']);
    }

    // --------------------------------------------- matchesOrderStats, pure --

    /** @param array{orders: int, spent: string, last_order_at: string, last_order_number: string} $stat */
    #[DataProvider('statCases')]
    public function testOrderStatsMatching(array $stat, array $payload, bool $expected): void
    {
        self::assertSame(
            $expected,
            AudienceResolver::matchesOrderStats($stat, SegmentCriteria::fromPayload($payload))
        );
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function statCases(): array
    {
        $none = ['orders' => 0, 'spent' => '0.00', 'last_order_at' => '', 'last_order_number' => ''];
        $some = ['orders' => 3, 'spent' => '5000.00', 'last_order_at' => '2026-06-15 10:00:00', 'last_order_number' => '99'];

        return [
            'exactly the minimum spend qualifies' => [$some, ['min_spent' => '5000.00'], true],
            'a dinar over the minimum fails' => [$some, ['min_spent' => '5000.01'], false],
            'exactly the maximum spend qualifies' => [$some, ['max_spent' => '5000.00'], true],
            'exactly the minimum count qualifies' => [$some, ['min_orders' => 3], true],
            'one more than the count fails' => [$some, ['min_orders' => 4], false],
            'a band that brackets it' => [$some, ['min_orders' => 1, 'max_orders' => 5], true],

            // The case a HAVING clause gets wrong.
            'nobody with no orders passes a minimum' => [$none, ['min_orders' => 1], false],
            'somebody with no orders passes max_orders 0' => [$none, ['max_orders' => 0], true],
            'nobody with no orders passes an ordered_after' => [$none, ['ordered_after' => '2020-01-01'], false],
            'nor an ordered_before' => [$none, ['ordered_before' => '2030-01-01'], false],

            'the last order inside the window' => [$some, ['ordered_after' => '2026-06-01'], true],
            'the last order before the window' => [$some, ['ordered_after' => '2026-07-01'], false],
            'the last order on the boundary' => [$some, ['ordered_after' => '2026-06-15'], true],
            'the last order after a before-bound' => [$some, ['ordered_before' => '2026-06-01'], false],

            'no criteria matches anybody' => [$none, [], true],
        ];
    }
}
