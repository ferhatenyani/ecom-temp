<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Products\OptionSelection;
use AlgerianCommerce\Products\OptionSet;
use PHPUnit\Framework\TestCase;

/**
 * The configurator's pricing calculator — roadmap §83.
 *
 * §83 puts this test file in the section itself: "The pricing calculator is
 * pure — strings and arrays in, a decimal out — so it is `tests/Unit/`, and it
 * is where the boundary cases live: an unknown option id, a required group
 * omitted, a min/max violation, a negative delta, a delta that would take a
 * line below zero." All five are here, each named.
 *
 * The property every one of them protects is the same: **the client sends the
 * choice and the server reads the price.** A payload never states what an
 * option costs, so every figure below is derived from the definition — and the
 * only way that can go wrong quietly is arithmetic, which is what a unit test
 * is for.
 */
final class OptionSelectionTest extends TestCase
{
    private static function set(): OptionSet
    {
        return OptionSet::fromPayload(['groups' => [
            [
                'id' => 'wrap',
                'type' => 'choice',
                'label' => 'Gift wrap',
                'required' => false,
                'min' => 0,
                'max' => 1,
                'choices' => [
                    ['id' => 'gold', 'label' => 'Gold', 'price_delta' => '250'],
                    ['id' => 'plain', 'label' => 'Plain', 'price_delta' => '0'],
                    ['id' => 'none', 'label' => 'No box', 'price_delta' => '-100'],
                ],
            ],
            [
                'id' => 'engraving',
                'type' => 'text',
                'label' => 'Gravure',
                'max_length' => 20,
                'price_delta' => '500',
            ],
        ]]);
    }

    public function testNothingChosenCostsNothing(): void
    {
        $priced = OptionSelection::price(self::set(), [], '1000');

        self::assertSame('0.00', $priced->surcharge);
        self::assertTrue($priced->isEmpty());
        self::assertSame('1000.00', $priced->unitPrice('1000'));
    }

    public function testAChoiceAddsItsDelta(): void
    {
        $priced = OptionSelection::price(self::set(), ['wrap' => 'gold'], '1000');

        self::assertSame('250.00', $priced->surcharge);
        self::assertSame('1250.00', $priced->unitPrice('1000'));
    }

    public function testDeltasFromSeveralGroupsAddUp(): void
    {
        $priced = OptionSelection::price(self::set(), ['wrap' => 'gold', 'engraving' => 'AB'], '1000');

        self::assertSame('750.00', $priced->surcharge);
        self::assertSame('1750.00', $priced->unitPrice('1000'));
        self::assertCount(2, $priced->toArray());
    }

    /**
     * §83's fourth boundary case. "Without the presentation box, −100" is a
     * real option, and refusing negatives would push shops into modelling a
     * discount as a coupon anybody can guess.
     */
    public function testANegativeDeltaIsAllowed(): void
    {
        $priced = OptionSelection::price(self::set(), ['wrap' => 'none'], '1000');

        self::assertSame('-100.00', $priced->surcharge);
        self::assertSame('900.00', $priced->unitPrice('1000'));
    }

    /**
     * §83's fifth boundary case, and the only one that needs the product's own
     * price to decide — which is why it lives here and not in `OptionSet`.
     */
    public function testADeltaThatWouldTakeALineBelowZeroIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['wrap' => 'none'], '50');
    }

    public function testExactlyZeroIsAllowed(): void
    {
        $priced = OptionSelection::price(self::set(), ['wrap' => 'none'], '100');

        self::assertSame('0.00', $priced->unitPrice('100'));
    }

    /** The refusal names the arithmetic, not just "invalid options". */
    public function testTheBelowZeroRefusalShowsTheSum(): void
    {
        try {
            OptionSelection::price(self::set(), ['wrap' => 'none'], '50');
            self::fail('expected a refusal');
        } catch (ApiException $exception) {
            $message = (string) ($exception->toPayload()['error']['details']['fields']['options'] ?? '');
            self::assertStringContainsString('50.00', $message);
            self::assertStringContainsString('100.00', $message);
        }
    }

    /** §83's first boundary case. */
    public function testAnUnknownChoiceIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['wrap' => 'platinum'], '1000');
    }

    /**
     * Naming a group the product does not offer is refused, not ignored.
     * Ignoring it is how a storefront built against last week's definition
     * quietly stops applying a surcharge the shop still charges for.
     */
    public function testAnUnknownGroupIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['monogram' => 'AB'], '1000');
    }

    public function testAProductWithNoOptionsRefusesAny(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(OptionSet::empty(), ['wrap' => 'gold'], '1000');
    }

    public function testAProductWithNoOptionsAcceptsNone(): void
    {
        self::assertSame('0.00', OptionSelection::price(OptionSet::empty(), [], '1000')->surcharge);
    }

    /** §83's second boundary case. */
    public function testARequiredGroupOmittedIsRefused(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'size', 'type' => 'choice', 'label' => 'Size', 'required' => true, 'min' => 1, 'max' => 1,
            'choices' => [['id' => 's', 'label' => 'S'], ['id' => 'm', 'label' => 'M']],
        ]]]);

        $this->expectException(ApiException::class);

        OptionSelection::price($set, [], '1000');
    }

    public function testARequiredTextGroupOmittedIsRefused(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'max_length' => 10,
        ]]]);

        $this->expectException(ApiException::class);

        OptionSelection::price($set, [], '1000');
    }

    /** §83's third boundary case, both ends. */
    public function testChoosingMoreThanMaxIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['wrap' => ['gold', 'plain']], '1000');
    }

    public function testChoosingFewerThanMinIsRefused(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'toppings', 'type' => 'choice', 'label' => 'Toppings', 'required' => true,
            'min' => 2, 'max' => 3,
            'choices' => [
                ['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B'], ['id' => 'c', 'label' => 'C'],
            ],
        ]]]);

        $this->expectException(ApiException::class);

        OptionSelection::price($set, ['toppings' => ['a']], '1000');
    }

    public function testAMultiChoiceGroupSumsEveryChosenDelta(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'toppings', 'type' => 'choice', 'label' => 'Toppings', 'min' => 0, 'max' => 3,
            'choices' => [
                ['id' => 'a', 'label' => 'A', 'price_delta' => '10'],
                ['id' => 'b', 'label' => 'B', 'price_delta' => '20'],
                ['id' => 'c', 'label' => 'C', 'price_delta' => '30'],
            ],
        ]]]);

        self::assertSame('60.00', OptionSelection::price($set, ['toppings' => ['a', 'b', 'c']], '1000')->surcharge);
    }

    /** The same choice twice is one choice, not two deltas. */
    public function testARepeatedChoiceIsCountedOnce(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'toppings', 'type' => 'choice', 'label' => 'Toppings', 'min' => 0, 'max' => 2,
            'choices' => [
                ['id' => 'a', 'label' => 'A', 'price_delta' => '10'],
                ['id' => 'b', 'label' => 'B', 'price_delta' => '20'],
            ],
        ]]]);

        self::assertSame('10.00', OptionSelection::price($set, ['toppings' => ['a', 'a']], '1000')->surcharge);
    }

    // ── free text ──

    public function testTextIsCappedByItsGroup(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['engraving' => str_repeat('A', 21)], '1000');
    }

    public function testTextAtTheCapIsAccepted(): void
    {
        $priced = OptionSelection::price(self::set(), ['engraving' => str_repeat('A', 20)], '1000');

        self::assertSame('500.00', $priced->surcharge);
    }

    public function testMarkupIsStrippedFromText(): void
    {
        $priced = OptionSelection::price(self::set(), ['engraving' => '<b>AB</b>'], '1000');

        self::assertSame('AB', $priced->values['engraving']);
    }

    public function testNewlinesAndTabsCollapseToSpaces(): void
    {
        $priced = OptionSelection::price(self::set(), ['engraving' => "A\n\tB"], '1000');

        self::assertSame('A B', $priced->values['engraving']);
    }

    public function testControlCharactersAreRemoved(): void
    {
        $priced = OptionSelection::price(self::set(), ['engraving' => "A\x00\x07B"], '1000');

        self::assertSame('AB', $priced->values['engraving']);
    }

    /**
     * **A leading `=` survives, deliberately.**
     *
     * §64's formula-injection rule is real and applies the moment this text
     * reaches a spreadsheet — but the escape belongs at the CSV boundary, where
     * `CsvWriter` already does exactly what `WC_CSV_Exporter::escape_data()`
     * does. Stripping it here would mangle "A=B" on a keepsake and would still
     * not protect any other export path. Asserted so that a later "harden the
     * sanitiser" change has to argue with this test rather than quietly break
     * engraving.
     */
    public function testAFormulaPrefixIsLeftForTheCsvBoundaryToEscape(): void
    {
        $priced = OptionSelection::price(self::set(), ['engraving' => '=A1+B2'], '1000');

        self::assertSame('=A1+B2', $priced->values['engraving']);
    }

    public function testTextThatIsOnlyWhitespaceIsTreatedAsAbsent(): void
    {
        $priced = OptionSelection::price(self::set(), ['engraving' => '   '], '1000');

        self::assertSame('0.00', $priced->surcharge);
        self::assertArrayNotHasKey('engraving', $priced->values);
    }

    public function testTextThatSanitisesToNothingIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['engraving' => '<b></b>'], '1000');
    }

    public function testANonStringTextValueIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), ['engraving' => ['A', 'B']], '1000');
    }

    // ── what fulfilment and the storefront are handed ──

    public function testItemMetaIsWhatAPackingSlipNeeds(): void
    {
        $priced = OptionSelection::price(self::set(), ['wrap' => 'gold', 'engraving' => 'AB'], '1000');

        self::assertSame(['Gift wrap' => 'Gold', 'Gravure' => 'AB'], $priced->toItemMeta());
    }

    public function testEachLineCarriesItsOwnDelta(): void
    {
        $lines = OptionSelection::price(self::set(), ['wrap' => 'gold', 'engraving' => 'AB'], '1000')->toArray();

        self::assertSame(['wrap', 'engraving'], array_column($lines, 'group_id'));
        self::assertSame(['250.00', '500.00'], array_column($lines, 'price_delta'));
    }

    /**
     * A bundle group is contents, not a question. It must never appear as
     * something a shopper is asked to choose, and naming it is as much an error
     * as naming a group that does not exist.
     */
    public function testABundleGroupIsNotSelectable(): void
    {
        $set = OptionSet::fromPayload(['groups' => [[
            'id' => 'contents', 'type' => 'bundle', 'label' => 'Contents',
            'items' => [['product_id' => 12, 'quantity' => 2]],
        ]]]);

        self::assertSame([], $set->selectableGroups());

        $this->expectException(ApiException::class);

        OptionSelection::price($set, ['contents' => 'anything'], '1000');
    }

    public function testEveryBadGroupIsReportedAtOnce(): void
    {
        try {
            OptionSelection::price(
                self::set(),
                ['wrap' => 'platinum', 'engraving' => str_repeat('A', 40), 'nope' => 1],
                '1000'
            );
            self::fail('expected a refusal');
        } catch (ApiException $exception) {
            $fields = $exception->toPayload()['error']['details']['fields'] ?? [];

            self::assertArrayHasKey('options.wrap', $fields);
            self::assertArrayHasKey('options.engraving', $fields);
            self::assertArrayHasKey('options.nope', $fields);
        }
    }

    public function testAScalarWhereAMapBelongsIsRefused(): void
    {
        $this->expectException(ApiException::class);

        OptionSelection::price(self::set(), 'gold', '1000');
    }

    /** Money never leaves as a float, here as everywhere else in this API. */
    public function testEveryFigureIsADecimalString(): void
    {
        $priced = OptionSelection::price(self::set(), ['wrap' => 'gold'], '1000');

        self::assertIsString($priced->surcharge);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $priced->surcharge);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $priced->unitPrice('1000'));
    }
}
