<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Shipping\Destination;
use AlgerianCommerce\Shipping\ManualProvider;
use AlgerianCommerce\Shipping\ProviderRegistry;
use AlgerianCommerce\Shipping\RateQuote;
use AlgerianCommerce\Shipping\ShipmentRequest;
use AlgerianCommerce\Shipping\ShipmentResult;
use AlgerianCommerce\Shipping\ShipmentStatus;
use AlgerianCommerce\Shipping\ShipmentWebhookResult;
use AlgerianCommerce\Shipping\ShippingProviderInterface;
use AlgerianCommerce\Shipping\ShippingRule;
use AlgerianCommerce\Shipping\ShopperRates;
use AlgerianCommerce\Shipping\StatusReport;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * A courier that answers however this test needs it to.
 *
 * **This double is the only way half of `ShopperRates` is reachable at all**,
 * and that is a fact about the environment rather than a preference. Both real
 * couriers are switched off — `ENABLE_YALIDINE` and `ENABLE_ZR_EXPRESS` are
 * present and empty, and their credentials are issued by the couriers to an
 * account holder, so they cannot be produced locally. The only provider a live
 * install can register is `ManualProvider`, which publishes no rates by design.
 * Every branch below in which a courier *answers* — a quote that wins, a quote
 * filtered out for the wrong journey, an adapter that throws — would otherwise
 * be dead code that nothing in the suite ever entered.
 *
 * `ProviderRegistryTest` writes a near-identical one, and the duplication is
 * deliberate: a double that grew shared configuration knobs for two unrelated
 * tests would be a second implementation of the interface to keep correct.
 */
final class ScriptedCourier implements ShippingProviderInterface
{
    /** @param list<RateQuote> $quotes */
    public function __construct(
        private readonly string $name = 'scripted',
        private readonly array $quotes = [],
        private readonly ?\Throwable $failure = null
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'Scripted courier';
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        return new ShipmentResult('SCRIPTED-1', 'TRACK-1', ShipmentStatus::CREATED);
    }

    public function cancelShipment(string $providerShipmentId): bool
    {
        return true;
    }

    public function getShipmentStatus(string $providerShipmentId): StatusReport
    {
        return new StatusReport(ShipmentStatus::IN_TRANSIT, 'ON_THE_ROAD');
    }

    /** @return list<RateQuote> */
    public function getShippingRates(Destination $destination): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->quotes;
    }

    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): ShipmentWebhookResult
    {
        throw new ApiException('webhook_unsupported', 'Not a real courier.', 400);
    }
}

/**
 * `ShopperRates` — what a shopper is quoted, per courier.
 *
 * The two branches the step names are `testTheCourierQuoteWins` and
 * `testAProviderThatQuotesNothingFallsBackToTheTariff`. The second is the only
 * one a running install can reach today, because no courier can be switched on
 * here; the first exists so that turning one on is not the moment the code is
 * first executed.
 */
final class ShopperRatesTest extends TestCase
{
    private const HOME = 'home';

    public function testTheCourierQuoteWins(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [self::courierQuote('550.00')])]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertCount(1, $quotes, 'one row per registered courier, never two');
        self::assertSame('scripted', $quotes[0]['provider']);
        self::assertSame('550.00', $quotes[0]['amount'], 'the courier priced the journey, not the tariff');
        self::assertSame(RateQuote::SOURCE_PROVIDER, $quotes[0]['source']);
    }

    /**
     * The path every install is on right now.
     *
     * `getShippingRates()` returns `[]` for a destination the courier has no
     * mapping for, and with `sync-destinations` unrun that is every destination
     * in the country. A shop whose tariff is written must still be able to
     * sell.
     */
    public function testAProviderThatQuotesNothingFallsBackToTheTariff(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [])]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertCount(1, $quotes);
        self::assertSame('400.00', $quotes[0]['amount']);
        self::assertSame(RateQuote::SOURCE_RULES, $quotes[0]['source']);
    }

    /** The real registry of a live install: one courier, no rate API at all. */
    public function testInHouseDeliveryIsPricedByTheTariffAlone(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ManualProvider()]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertSame('manual', $quotes[0]['provider'], 'never the empty-string fallback');
        self::assertSame(RateQuote::SOURCE_RULES, $quotes[0]['source']);
    }

    /**
     * A courier that is down must cost the shop a courier, not a sale.
     *
     * `ApiException` is the contracted failure — unreachable, refused, rate
     * limited, a credential that stopped working.
     */
    public function testACourierThatFailsFallsBackToTheTariff(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([
                new ScriptedCourier('scripted', [], new ApiException('provider_unavailable', 'Down.', 502)),
            ]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertCount(1, $quotes);
        self::assertSame('400.00', $quotes[0]['amount']);
        self::assertSame(RateQuote::SOURCE_RULES, $quotes[0]['source']);
    }

    /**
     * And a courier that is simply broken, which no contract covers.
     *
     * Without the second catch a `TypeError` inside one adapter is a storefront
     * that cannot quote delivery to anybody.
     */
    public function testACourierThatThrowsSomethingUncontractedFallsBackToo(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([
                new ScriptedCourier('scripted', [], new LogicException('a bug in an adapter')),
            ]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertSame('400.00', $quotes[0]['amount']);
        self::assertSame(RateQuote::SOURCE_RULES, $quotes[0]['source']);
    }

    /** A failing courier with no tariff behind it quotes nothing, and does not throw. */
    public function testAFailingCourierWithNoTariffIsSimplyAbsent(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([
                new ScriptedCourier('scripted', [], new ApiException('provider_unavailable', 'Down.', 502)),
            ]),
            [],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertSame([], $quotes);
    }

    /**
     * One courier failing says nothing about the next one.
     *
     * The shape `ShipmentPoller` already uses, for the same reason.
     */
    public function testOneBrokenCourierDoesNotTakeTheOthersDown(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([
                new ScriptedCourier('broken', [], new ApiException('provider_unavailable', 'Down.', 502)),
                new ScriptedCourier('working', [self::courierQuote('550.00')]),
            ]),
            [],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertCount(1, $quotes);
        self::assertSame('working', $quotes[0]['provider']);
        self::assertSame('550.00', $quotes[0]['amount']);
    }

    /**
     * Free delivery is a promise to the customer and outranks a live quote.
     *
     * The regression this prevents is silent and arrives on the day somebody
     * switches a courier on: the banner still says "free over 5000" and the
     * checkout starts charging 550.
     */
    public function testAFreeShippingThresholdOutranksTheCourier(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [self::courierQuote('550.00')])]),
            [self::rule('400.00', '5000.00')],
            new Destination(16, 1601),
            '5000.00'
        );

        self::assertSame('0.00', $quotes[0]['amount']);
        self::assertTrue($quotes[0]['free_shipping']);
        self::assertSame(RateQuote::SOURCE_RULES, $quotes[0]['source']);
    }

    /** Below the threshold the courier prices it again. */
    public function testUnderTheThresholdTheCourierStillWins(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [self::courierQuote('550.00')])]),
            [self::rule('400.00', '5000.00')],
            new Destination(16, 1601),
            '4999.99'
        );

        self::assertSame('550.00', $quotes[0]['amount']);
        self::assertSame(RateQuote::SOURCE_PROVIDER, $quotes[0]['source']);
    }

    /**
     * A courier's stop-desk price is not charged to somebody waiting at home.
     *
     * Yalidine returns all four of its services whatever was asked for — the
     * admin screen compares them — so the cheapest of the four is routinely the
     * wrong journey. Read from source: `YalidineProvider::getShippingRates()`.
     */
    public function testAServiceForTheWrongJourneyIsNotCharged(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [
                new RateQuote('express_home', 'Home', '600.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'home'),
                new RateQuote('express_desk', 'Desk', '400.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'desk'),
                new RateQuote('economic_desk', 'Desk', '350.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'desk'),
            ])]),
            [],
            new Destination(16, 1601, self::HOME),
            '2000.00'
        );

        self::assertSame('600.00', $quotes[0]['amount'], 'the desk prices are cheaper and are not this journey');
        self::assertSame('home', $quotes[0]['delivery_type']);
    }

    /** And the cheapest of the ones that *are* the right journey. */
    public function testTheCheapestSuitableServiceWins(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [
                new RateQuote('express_home', 'Express', '600.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'home'),
                new RateQuote('economic_home', 'Economic', '500.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'home'),
                new RateQuote('economic_desk', 'Desk', '350.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'desk'),
            ])]),
            [],
            new Destination(16, 1601, self::HOME),
            '2000.00'
        );

        self::assertSame('500.00', $quotes[0]['amount']);
        self::assertSame('economic_home', $quotes[0]['service']);
    }

    /**
     * An adapter that states no journey is taken at its word.
     *
     * `getShippingRates()` is handed a `Destination`; a courier answering about
     * a different one has misunderstood the question, and refusing every quote
     * that omits the field would make the field mandatory in all but name.
     */
    public function testAQuoteWithNoStatedJourneyIsUsed(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ScriptedCourier('scripted', [self::courierQuote('550.00')])]),
            [],
            new Destination(16, 1601, 'desk'),
            '2000.00'
        );

        self::assertSame('550.00', $quotes[0]['amount']);
        self::assertNull($quotes[0]['delivery_type']);
    }

    /**
     * Two services at one price resolve the same way every time.
     *
     * Whichever order the courier's JSON arrived in, the shop's own history has
     * to be reproducible — the argument `requireShippingQuote()` makes about
     * its own tie-break.
     */
    public function testAPriceTieIsBrokenDeterministically(): void
    {
        $forward = [
            new RateQuote('bravo', 'B', '500.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'home'),
            new RateQuote('alpha', 'A', '500.00', 'DZD', null, RateQuote::SOURCE_PROVIDER, false, 'home'),
        ];

        foreach ([$forward, array_reverse($forward)] as $order) {
            $quotes = ShopperRates::forDestination(
                new ProviderRegistry([new ScriptedCourier('scripted', $order)]),
                [],
                new Destination(16, 1601),
                '2000.00'
            );

            self::assertSame('alpha', $quotes[0]['service']);
        }
    }

    /** No courier and no rule is an empty list, not an exception. */
    public function testNothingToQuoteIsAnEmptyList(): void
    {
        self::assertSame([], ShopperRates::forDestination(
            new ProviderRegistry([new ManualProvider()]),
            [],
            new Destination(16, 1601),
            '2000.00'
        ));
    }

    /** Every registered courier gets its own row, tariff or live. */
    public function testEachRegisteredCourierGetsOneRow(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([
                new ScriptedCourier('scripted', [self::courierQuote('550.00')]),
                new ManualProvider(),
            ]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertSame(['scripted', 'manual'], array_column($quotes, 'provider'));
        self::assertSame(['550.00', '400.00'], array_column($quotes, 'amount'));
        self::assertSame(
            [RateQuote::SOURCE_PROVIDER, RateQuote::SOURCE_RULES],
            array_column($quotes, 'source'),
            'the two sources coexist across couriers and each row says which it is'
        );
    }

    /** A rule naming one courier does not price another's row. */
    public function testARuleForOneCourierDoesNotPriceAnother(): void
    {
        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([new ManualProvider(), new ScriptedCourier('scripted', [])]),
            [self::rule('400.00', null, 'manual')],
            new Destination(16, 1601),
            '2000.00'
        );

        self::assertSame(['manual'], array_column($quotes, 'provider'));
    }

    /** A logger is optional, and a failing courier is written down when there is one. */
    public function testAFailingCourierIsLogged(): void
    {
        $logger = new Logger('test', Logger::DEBUG);

        $quotes = ShopperRates::forDestination(
            new ProviderRegistry([
                new ScriptedCourier('scripted', [], new ApiException('provider_unavailable', 'Down.', 502)),
            ]),
            [self::rule('400.00')],
            new Destination(16, 1601),
            '2000.00',
            2,
            'DZD',
            $logger
        );

        self::assertSame('400.00', $quotes[0]['amount']);
    }

    /**
     * The shopper said nothing, so the cheapest row wins — today's behaviour,
     * and the assertion that says so.
     *
     * This is the test backend step 2's third item asks for by name: adding a
     * choice must not change what an existing caller gets. It is pinned here
     * rather than only at the REST layer because here the *dearer* row can be
     * made to exist — a live install registers one courier, so a checkout test
     * cannot tell "took the cheapest" apart from "took the only one".
     */
    public function testNoChoiceTakesTheCheapest(): void
    {
        $chosen = ShopperRates::choose([
            ['provider' => 'scripted', 'amount' => '550.00'],
            ['provider' => 'manual', 'amount' => '400.00'],
        ]);

        self::assertSame('manual', $chosen['provider'] ?? null);
    }

    /**
     * And breaks a tie by name, so the answer does not depend on registry order.
     *
     * Two couriers at one price would otherwise resolve by whatever
     * `ProviderRegistry::names()` happened to return, which means switching a
     * third courier on could change which of the other two an unrelated order
     * was placed with.
     */
    public function testATieIsBrokenByProviderName(): void
    {
        $ascending = ShopperRates::choose([
            ['provider' => 'manual', 'amount' => '400.00'],
            ['provider' => 'aardvark', 'amount' => '400.00'],
        ]);

        $descending = ShopperRates::choose([
            ['provider' => 'aardvark', 'amount' => '400.00'],
            ['provider' => 'manual', 'amount' => '400.00'],
        ]);

        self::assertSame('aardvark', $ascending['provider'] ?? null);
        self::assertSame(
            $ascending,
            $descending,
            'the same rows in a different order must produce the same choice'
        );
    }

    /**
     * The point of the whole item: the shopper's courier wins even when it costs
     * more.
     *
     * If this returned the cheapest row the field would be decoration, and the
     * shape of the bug would be a customer who picked the fast courier, was
     * charged for the slow one, and got the slow one.
     */
    public function testAChosenCourierBeatsACheaperOne(): void
    {
        $chosen = ShopperRates::choose([
            ['provider' => 'manual', 'amount' => '400.00'],
            ['provider' => 'scripted', 'amount' => '550.00'],
        ], 'scripted');

        self::assertSame('scripted', $chosen['provider'] ?? null);
        self::assertSame('550.00', $chosen['amount'] ?? null);
    }

    /**
     * A courier that quoted nothing is not choosable — the branch no running
     * install can reach.
     *
     * `Cart\CheckoutService::requireShippingQuote()` turns this null into the
     * *"does not deliver to that destination"* refusal, which is a different
     * 400 from the one an unregistered name gets. That split needs two
     * registered couriers with only one quoting, and a live install registers
     * exactly one — `ManualProvider` — so the REST suite cannot construct it.
     * Here it is a two-row array with a name that is in neither.
     */
    public function testACourierWithNoRowCannotBeChosen(): void
    {
        self::assertNull(ShopperRates::choose([
            ['provider' => 'manual', 'amount' => '400.00'],
        ], 'scripted'));
    }

    /** No rows at all is null whether or not a courier was named. */
    public function testNoRowsIsNull(): void
    {
        self::assertNull(ShopperRates::choose([]));
        self::assertNull(ShopperRates::choose([], 'manual'));
    }

    /**
     * The name is matched the way `ProviderRegistry` matches one.
     *
     * `has()` and `get()` both look up `strtolower(trim($name))`. A choice
     * normalized differently here would be a name that passes the registry
     * check in `requireShippingQuote()` and then fails to find its own row,
     * producing *"that courier does not deliver there"* for a courier that
     * quoted perfectly well.
     */
    public function testTheChoiceIsTrimmedAndLowerCased(): void
    {
        $rows = [['provider' => 'zr_express', 'amount' => '600.00']];

        self::assertSame($rows[0], ShopperRates::choose($rows, '  ZR_Express '));
    }

    /**
     * Choosing must not rearrange the caller's rows.
     *
     * `usort()` sorts in place, and the array handed in is also the answer to
     * *"what were all the quotes"* — the list `GET /checkout/shipping-rates`
     * publishes. A selection that reordered it would be a read with a side
     * effect on somebody else's response.
     */
    public function testChoosingDoesNotReorderTheRowsItWasGiven(): void
    {
        $rows = [
            ['provider' => 'scripted', 'amount' => '550.00'],
            ['provider' => 'manual', 'amount' => '400.00'],
        ];

        ShopperRates::choose($rows);

        self::assertSame(['scripted', 'manual'], array_column($rows, 'provider'));
    }

    private static function rule(string $amount, ?string $freeOver = null, string $provider = ''): ShippingRule
    {
        return new ShippingRule(0, 0, $amount, $provider, '', $freeOver);
    }

    private static function courierQuote(string $amount): RateQuote
    {
        return new RateQuote('express', 'Courier', $amount, 'DZD', null, RateQuote::SOURCE_PROVIDER);
    }
}
