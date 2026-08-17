<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\OptionSet;
use PHPUnit\Framework\TestCase;

/**
 * The option-set document — roadmap §83.
 *
 * Two entry points with deliberately opposite manners, and the tests come in
 * pairs because of it. `fromPayload()` is a **write**: somebody is typing now,
 * so every problem is a named field error and nothing is dropped.
 * `fromStored()` is a **read** of product meta, which `wp post meta update` and
 * any other plugin can reach, so a bad group degrades rather than breaking
 * every read of the product — dropped **and reported**, which is §61's
 * malformed-homepage-section rule.
 *
 * The pairing is the point: a document that is refused on write must still be
 * survivable on read, or one bad meta row takes a product page down.
 */
final class OptionSetTest extends TestCase
{
    public function testAnEmptySetIsEmpty(): void
    {
        self::assertTrue(OptionSet::empty()->isEmpty());
        self::assertFalse(OptionSet::empty()->isBundle());
        self::assertSame([], OptionSet::empty()->bundleItems());
    }

    public function testNullClearsRatherThanFailing(): void
    {
        self::assertTrue(OptionSet::fromPayload(null)->isEmpty());
        self::assertTrue(OptionSet::fromPayload([])->isEmpty());
    }

    public function testAChoiceGroupRoundTrips(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'wrap', 'type' => 'choice', 'label' => 'Gift wrap', 'min' => 0, 'max' => 1,
            'choices' => [['id' => 'gold', 'label' => 'Gold', 'price_delta' => '250']],
        ]]]);

        $group = $set->group('wrap');

        self::assertSame('choice', $group['type']);
        self::assertSame('Gift wrap', $group['label']);
        self::assertFalse($group['required']);
        self::assertSame('250', $group['choices'][0]['price_delta']);
        self::assertSame(0, $group['choices'][0]['image_id']);
    }

    public function testATextGroupTakesADefaultLength(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'note', 'type' => 'text', 'label' => 'Message',
        ]]]);

        self::assertSame(OptionSet::DEFAULT_TEXT_LENGTH, $set->group('note')['max_length']);
        self::assertSame('0', $set->group('note')['price_delta']);
    }

    public function testABundleGroupIsRecognised(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'contents', 'type' => 'bundle', 'label' => 'Contents',
            'items' => [['product_id' => 12, 'quantity' => 2], ['product_id' => 13, 'quantity' => 1]],
        ]]]);

        self::assertTrue($set->isBundle());
        self::assertSame(
            [['product_id' => 12, 'quantity' => 2], ['product_id' => 13, 'quantity' => 1]],
            $set->bundleItems()
        );
    }

    /** A bare list is what somebody types when they skip the wrapper. */
    public function testTheGroupsWrapperIsOptional(): void
    {
        $set = OptionSet::fromPayload([[
            'id' => 'note', 'type' => 'text', 'label' => 'Message',
        ]]);

        self::assertNotNull($set->group('note'));
    }

    // ── refusals on write ──

    /**
     * @param array<string, mixed> $group
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badGroups')]
    public function testABadGroupIsRefusedByField(array $group, string $expectedField): void
    {
        try {
            OptionSet::fromPayload(['groups' => [$group]]);
            self::fail('expected a refusal');
        } catch (ApiException $exception) {
            $fields = array_keys($exception->toPayload()['error']['details']['fields'] ?? []);

            self::assertContains($expectedField, $fields, 'got ' . implode(', ', $fields));
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badGroups(): array
    {
        $choice = ['id' => 'a', 'label' => 'A'];

        return [
            'unknown type' => [
                ['id' => 'g', 'type' => 'slider', 'label' => 'G'],
                'options.groups[0].type',
            ],
            'missing id' => [
                ['type' => 'text', 'label' => 'G'],
                'options.groups[0].id',
            ],
            'id with spaces' => [
                ['id' => 'my group', 'type' => 'text', 'label' => 'G'],
                'options.groups[0].id',
            ],
            'missing label' => [
                ['id' => 'g', 'type' => 'text'],
                'options.groups[0].label',
            ],
            'choice group with no choices' => [
                ['id' => 'g', 'type' => 'choice', 'label' => 'G', 'choices' => []],
                'options.groups[0].choices',
            ],
            'non-numeric delta' => [
                ['id' => 'g', 'type' => 'choice', 'label' => 'G',
                 'choices' => [['id' => 'a', 'label' => 'A', 'price_delta' => 'free']]],
                'options.groups[0].choices[0].price_delta',
            ],
            'duplicate choice id' => [
                ['id' => 'g', 'type' => 'choice', 'label' => 'G',
                 'choices' => [$choice, ['id' => 'a', 'label' => 'B']]],
                'options.groups[0].choices[1].id',
            ],
            'max above the number of choices' => [
                ['id' => 'g', 'type' => 'choice', 'label' => 'G', 'max' => 5, 'choices' => [$choice]],
                'options.groups[0].max',
            ],
            'min above max' => [
                ['id' => 'g', 'type' => 'choice', 'label' => 'G', 'min' => 1, 'max' => 0, 'choices' => [$choice]],
                'options.groups[0].max',
            ],
            'text length above the ceiling' => [
                ['id' => 'g', 'type' => 'text', 'label' => 'G', 'max_length' => 5000],
                'options.groups[0].max_length',
            ],
            'text length of zero' => [
                ['id' => 'g', 'type' => 'text', 'label' => 'G', 'max_length' => 0],
                'options.groups[0].max_length',
            ],
            'bundle with no items' => [
                ['id' => 'g', 'type' => 'bundle', 'label' => 'G', 'items' => []],
                'options.groups[0].items',
            ],
            'bundle with a zero quantity' => [
                ['id' => 'g', 'type' => 'bundle', 'label' => 'G',
                 'items' => [['product_id' => 4, 'quantity' => 0]]],
                'options.groups[0].items[0].quantity',
            ],
            'bundle with the same component twice' => [
                ['id' => 'g', 'type' => 'bundle', 'label' => 'G',
                 'items' => [['product_id' => 4, 'quantity' => 1], ['product_id' => 4, 'quantity' => 2]]],
                'options.groups[0].items[1].product_id',
            ],
            'a key from the wrong group type' => [
                ['id' => 'g', 'type' => 'text', 'label' => 'G', 'choices' => [$choice]],
                'options.groups[0]',
            ],
            'a negative image id' => [
                ['id' => 'g', 'type' => 'choice', 'label' => 'G',
                 'choices' => [['id' => 'a', 'label' => 'A', 'image_id' => -1]]],
                'options.groups[0].choices[0].image_id',
            ],
        ];
    }

    /**
     * Refused rather than normalised. "Required, choose at least none" is two
     * settings contradicting each other, and quietly raising `min` to 1 would
     * store something other than what was written.
     */
    public function testARequiredGroupWithAMinOfZeroIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSet::fromPayload(['groups' => [[
            'id' => 'g', 'type' => 'choice', 'label' => 'G', 'required' => true, 'min' => 0, 'max' => 1,
            'choices' => [['id' => 'a', 'label' => 'A']],
        ]]]);
    }

    public function testDuplicateGroupIdsAreRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSet::fromPayload(['groups' => [
            ['id' => 'g', 'type' => 'text', 'label' => 'One'],
            ['id' => 'g', 'type' => 'text', 'label' => 'Two'],
        ]]);
    }

    public function testTooManyGroupsAreRefused(): void
    {
        $groups = [];

        for ($i = 0; $i <= OptionSet::MAX_GROUPS; $i++) {
            $groups[] = ['id' => 'g' . $i, 'type' => 'text', 'label' => 'G'];
        }

        $this->expectException(ApiException::class);

        OptionSet::fromPayload(['groups' => $groups]);
    }

    public function testTooManyChoicesAreRefused(): void
    {
        $choices = [];

        for ($i = 0; $i <= OptionSet::MAX_CHOICES; $i++) {
            $choices[] = ['id' => 'c' . $i, 'label' => 'C'];
        }

        $this->expectException(ApiException::class);

        OptionSet::fromPayload(['groups' => [
            ['id' => 'g', 'type' => 'choice', 'label' => 'G', 'choices' => $choices],
        ]]);
    }

    /** Every bad group is reported at once, not one resubmission at a time. */
    public function testAllErrorsComeBackTogether(): void
    {
        try {
            OptionSet::fromPayload(['groups' => [
                ['id' => 'g1', 'type' => 'slider', 'label' => 'One'],
                ['id' => 'g2', 'type' => 'text', 'label' => 'Two', 'max_length' => 9999],
            ]]);
            self::fail('expected a refusal');
        } catch (ApiException $exception) {
            $fields = array_keys($exception->toPayload()['error']['details']['fields'] ?? []);

            self::assertContains('options.groups[0].type', $fields);
            self::assertContains('options.groups[1].max_length', $fields);
        }
    }

    // ── reading what is stored, which is a different manner ──

    public function testStoredJsonIsDecoded(): void
    {
        $json = (string) json_encode(['groups' => [['id' => 'note', 'type' => 'text', 'label' => 'Message']]]);

        self::assertNotNull(OptionSet::fromStored($json)->group('note'));
    }

    public function testAbsentMetaIsAnEmptySetAndNotAProblem(): void
    {
        foreach ([null, '', false, []] as $stored) {
            $set = OptionSet::fromStored($stored);

            self::assertTrue($set->isEmpty());
            self::assertSame([], $set->problems, 'absent meta reported a problem');
        }
    }

    /**
     * The rule this whole entry point exists for: a bad group is dropped **and
     * reported**. Silently vanishing is the one failure a shop cannot diagnose.
     */
    public function testABadStoredGroupIsDroppedAndReported(): void
    {
        $set = OptionSet::fromStored(['groups' => [
            ['id' => 'good', 'type' => 'text', 'label' => 'Message'],
            ['id' => 'bad', 'type' => 'slider', 'label' => 'Broken'],
        ]]);

        self::assertNotNull($set->group('good'));
        self::assertNull($set->group('bad'));
        self::assertCount(1, $set->problems);
        self::assertStringContainsString('2', $set->problems[0]);
    }

    public function testGarbageMetaDoesNotThrow(): void
    {
        foreach (['not json at all', '{"groups": "nope"}', '42'] as $stored) {
            $set = OptionSet::fromStored($stored);

            self::assertTrue($set->isEmpty());
            self::assertNotSame([], $set->problems, 'garbage was accepted silently: ' . $stored);
        }
    }

    public function testStoredGroupsBeyondTheCapAreDroppedAndReported(): void
    {
        $groups = [];

        for ($i = 0; $i < OptionSet::MAX_GROUPS + 5; $i++) {
            $groups[] = ['id' => 'g' . $i, 'type' => 'text', 'label' => 'G'];
        }

        $set = OptionSet::fromStored(['groups' => $groups]);

        self::assertCount(OptionSet::MAX_GROUPS, $set->groups);
        self::assertNotSame([], $set->problems);
    }

    /**
     * Anything `fromPayload()` accepts, `fromStored()` must read back
     * identically — they are one document with two manners, not two formats.
     */
    public function testWhatIsWrittenIsWhatIsRead(): void
    {
        $written = OptionSet::fromPayload(['groups' => [
            ['id' => 'wrap', 'type' => 'choice', 'label' => 'Gift wrap', 'min' => 0, 'max' => 1,
             'choices' => [['id' => 'gold', 'label' => 'Gold', 'price_delta' => '250']]],
            ['id' => 'note', 'type' => 'text', 'label' => 'Message', 'max_length' => 20, 'price_delta' => '100'],
            ['id' => 'contents', 'type' => 'bundle', 'label' => 'Contents',
             'items' => [['product_id' => 7, 'quantity' => 3]]],
        ]]);

        $read = OptionSet::fromStored(json_encode($written->toArray()));

        self::assertSame([], $read->problems);
        self::assertEquals($written->toArray(), $read->toArray());
    }

    public function testSelectableGroupsExcludeBundleContents(): void
    {
        $set = OptionSet::fromPayload(['groups' => [
            ['id' => 'note', 'type' => 'text', 'label' => 'Message'],
            ['id' => 'contents', 'type' => 'bundle', 'label' => 'Contents',
             'items' => [['product_id' => 7, 'quantity' => 1]]],
        ]]);

        self::assertSame(['note'], array_column($set->selectableGroups(), 'id'));
    }
}
