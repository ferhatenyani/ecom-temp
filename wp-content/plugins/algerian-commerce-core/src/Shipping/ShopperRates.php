<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;
use Throwable;

/**
 * What a shopper is quoted for delivery — one number per courier.
 *
 * Pure: no WordPress, no database, no cart. It is handed the couriers, the
 * tariff rows and a destination, and it answers with rows in exactly the shape
 * `ShippingService::rates()` already publishes. That is deliberate — a client
 * reading `GET /checkout/shipping-rates` and `GET /shipping/rates` parses one
 * format, not two.
 *
 * ## Why this is not `ShippingService::rates()`
 *
 * Two reasons, and only the first is the obvious one.
 *
 * **Authorization.** `ShippingService::rates()` asserts `ac_manage_shipping`, a
 * staff capability a shopper will never hold, and §44 forbids handing the
 * storefront an admin credential. `CheckoutService` already made this argument
 * about `RateResolver`; it is unchanged by adding couriers to the mix.
 *
 * **They answer different questions, and the difference is cardinality.**
 * `rates()` returns the tariff quote *and* every courier quote as separate
 * rows, because the manager reading it is comparing them — that is the whole
 * point of the screen. A checkout cannot do that. `CheckoutService::place()`
 * takes the cheapest row and charges it, so two rows for one courier — 400 from
 * the shop's tariff, 550 from the courier's API — is not a comparison, it is a
 * coin toss that always lands on the smaller number and then puts it on the
 * customer's bill. Worse, it would show the shopper the same courier twice at
 * two prices.
 *
 * So: **one row per registered courier**, and this class decides which number
 * that row carries. That is the deliberate divergence from `rates()`'s shape —
 * same row format, different row count — and it is the only one.
 *
 * ## The order of precedence, and why free delivery is at the top
 *
 * ```
 * 1. the shop's rule, when it makes delivery free      ← a promise, not a price
 * 2. the courier's own live quote for this journey     ← source: provider
 * 3. the shop's rule                                   ← source: rules
 * 4. nothing — this courier does not reach there
 * ```
 *
 * Steps 2 and 3 are the step's instruction: quote the courier, fall back to the
 * tariff for any courier that returns nothing.
 *
 * **Step 1 is not, and it is here because without it this change silently
 * breaks free delivery.** A `free_over` threshold is the shop telling the
 * customer "spend 5000 and delivery is free" — `RateResolver` argues at length
 * about the centime that decides it. It is a statement about the *customer's
 * bill*, not about what a van costs: the shop pays the courier either way and
 * has decided to absorb it. Let a live courier quote outrank it and the banner
 * keeps saying "free over 5000" while the checkout charges 800, and the day
 * that starts happening is the day somebody switches a courier on — an
 * `ENABLE_YALIDINE` flag would have quietly repriced a promotion. Nothing else
 * in the tariff outranks a courier: a shop that has priced Algiers at 400 while
 * Yalidine charges 550 is being told what the journey actually costs, which is
 * the entire reason the step asks for this.
 *
 * ## Nothing here throws
 *
 * A courier that is switched off, unreachable, slow, rate limited, holding a
 * dead credential or simply broken must not cost the shop a sale. Every call
 * into an adapter is wrapped, and a courier that fails contributes nothing and
 * lets the tariff answer for it. `ShipmentPoller::poll()` already draws this
 * line the same way and for the same reason — one provider being down says
 * nothing about the next one — with the same two catches: `ApiException` for
 * the failures adapters are contracted to raise, `Throwable` for the ones no
 * contract covers. An adapter with a `TypeError` in it is a bug in one courier;
 * without the second catch it is a storefront that cannot sell anything.
 *
 * The deliberate asymmetry: **a checkout swallows what an admin screen would
 * report.** `ShippingService::rates()` lets an adapter's exception surface,
 * because a manager who cannot get a Yalidine price needs to know Yalidine is
 * refusing rather than see a tariff row and assume all is well. A shopper needs
 * a delivery option. Both are logged.
 *
 * Slow is the one failure this class cannot do anything about: a timeout is the
 * adapter's to enforce and it does — `YalidineSettings::$timeout` defaults to 15
 * seconds and is clamped, `ZRExpressSettings` carries its own. Quoting N
 * couriers costs N sequential calls in the worst case, which is the same
 * exposure `ShippingService::rates()` already has.
 */
final class ShopperRates
{
    /**
     * One quote per registered courier, cheapest-service-first within each.
     *
     * **Registered couriers, not the providers named on rules.** This is what
     * `ShippingService::rates()` iterates, and the reason to match it is that
     * every other consumer of this answer needs a name that means something: a
     * courier's API can only be called for a courier that exists, the
     * `shipping_provider` a shopper will be allowed to choose has to be one the
     * shop can actually ship with, and the name is written onto the order's
     * shipping line as `method_id` for `POST /orders/{id}/shipments` to route
     * by later.
     *
     * What that replaced was a list derived from the *rules* — `['', ...the
     * providers rules mention]` — whose first entry, the empty-string national
     * fallback, is not a courier at all. It priced correctly and then wrote
     * `method_id: ''` onto the order, so the one field recording who was
     * carrying the parcel said nobody. The registry is never empty:
     * `Plugin::shippingProviders()` always appends `ManualProvider`, precisely
     * so a shop with no courier credentials can still ship.
     *
     * @param list<ShippingRule> $rules the shop's active tariff, read once by
     *                                  the caller — a tariff does not depend on
     *                                  which van carries the parcel, and
     *                                  re-reading it per courier would make the
     *                                  answer depend on how many are configured
     * @return list<array<string, mixed>>
     */
    public static function forDestination(
        ProviderRegistry $providers,
        array $rules,
        Destination $destination,
        string $subtotal = '',
        int $decimals = 2,
        string $currency = 'DZD',
        ?Logger $logger = null
    ): array {
        $quotes = [];

        foreach ($providers->names() as $name) {
            $quote = self::forProvider(
                $providers,
                $name,
                $rules,
                $destination,
                $subtotal,
                $decimals,
                $currency,
                $logger
            );

            if ($quote !== null) {
                $quotes[] = ['provider' => $name] + $quote->toArray();
            }
        }

        return $quotes;
    }

    /**
     * Which of those rows to charge — the shopper's courier, or the cheapest.
     *
     * ## Why choosing is a lookup and not a second quote
     *
     * The rows this returns from are the rows `forDestination()` just built,
     * which are the rows `GET /checkout/shipping-rates` publishes. Re-asking
     * the chosen courier for its own price would be a second network call whose
     * answer may differ from the one the shopper was shown a moment earlier —
     * a courier's rate can move between two requests, and the number on the
     * bill has to be the number on the screen. One quote, one selection.
     *
     * That is only possible because the rows are **one per courier**: the whole
     * argument in this class's docblock for collapsing four Yalidine services
     * into a single row is what makes `provider` a key rather than a label. Had
     * `rates()`'s shape been kept, "the shopper chose Yalidine" would still
     * have needed a sort to break, and the sort is what this replaces.
     *
     * ## Null rather than an exception, and the distinction this cannot draw
     *
     * A checkout has two different things to say when a choice does not land —
     * *this shop has no such courier* and *that courier does not reach there* —
     * and telling them apart needs the registry, which this method is not
     * given and deliberately does not take. It is handed rows. Rows cannot
     * distinguish a courier that was never registered from one that was
     * registered and quoted nothing, because in both cases there is simply no
     * row, and a pure function that guessed which had happened would be
     * guessing.
     *
     * So null means "no row for that name" and `Cart\CheckoutService::requireShippingQuote()`
     * asks the registry which of the two it was. Keeping the HTTP vocabulary
     * out of here is the same line `RateResolver` draws: it returns `null` for
     * a destination the shop does not price and lets the caller decide that
     * this is a 400.
     *
     * ## The empty choice is the old behaviour, unchanged on purpose
     *
     * `$provider === ''` is "the caller said nothing", and it takes the sort
     * that was here before a shopper could choose — same comparison, same
     * tie-break on the provider name, same `[0]`. It is written as one branch
     * rather than folded into the lookup so that the thing an existing caller
     * gets is visibly the thing it used to get: `POST /checkout` without
     * `shipping_provider` must place exactly the order it placed yesterday, and
     * a reader should be able to confirm that by looking rather than by
     * reasoning about a merged code path.
     *
     * The sort is on a copy. `usort()` sorts in place and the caller's array is
     * the answer to a *different* question — every row, for the response — so
     * reordering it here would have this method quietly rearrange a list it was
     * only asked to read.
     *
     * @param list<array<string, mixed>> $quotes as built by forDestination()
     * @param string $provider the courier the shopper chose, '' for no choice
     * @return array<string, mixed>|null the row to charge, null when the chosen
     *                                   courier has none
     */
    public static function choose(array $quotes, string $provider = ''): ?array
    {
        if ($quotes === []) {
            return null;
        }

        $provider = strtolower(trim($provider));

        if ($provider !== '') {
            foreach ($quotes as $quote) {
                if (strtolower((string) ($quote['provider'] ?? '')) === $provider) {
                    return $quote;
                }
            }

            return null;
        }

        /*
         * The cheapest, deterministically. A checkout that has to pick for the
         * customer picks the one they would have.
         *
         * The tie-break on the provider name is not decoration: two couriers at
         * the same price would otherwise resolve by whichever order
         * `ProviderRegistry::names()` happened to return, so switching a third
         * courier on could change which of the other two an unrelated order was
         * placed with, and the shop's own history would stop being reproducible.
         * `cheapestFor()` makes the same argument one level down about two
         * services of one courier.
         */
        usort($quotes, static fn (array $a, array $b): int => ($a['amount'] <=> $b['amount'])
            ?: strcmp((string) $a['provider'], (string) $b['provider']));

        return $quotes[0];
    }

    /**
     * The one number this courier is quoting, or null when it has none.
     *
     * @param list<ShippingRule> $rules
     */
    private static function forProvider(
        ProviderRegistry $providers,
        string $name,
        array $rules,
        Destination $destination,
        string $subtotal,
        int $decimals,
        string $currency,
        ?Logger $logger
    ): ?RateQuote {
        $rule = RateResolver::resolve($rules, $destination, $name);

        $tariff = $rule === null
            ? null
            : RateResolver::quote($rule, $subtotal, $decimals, $currency, $destination->deliveryType);

        // Free delivery is the shop's promise to this customer and outranks
        // anything a courier says — see the class docblock.
        if ($tariff !== null && $tariff->isFree) {
            return $tariff;
        }

        return self::live($providers, $name, $destination, $logger) ?? $tariff;
    }

    /**
     * Ask the courier, and never let the answer be an exception.
     *
     * An empty list is the contracted way for an adapter to say "I publish no
     * rate API" (`ManualProvider`) or "I have no mapping for that destination"
     * (`YalidineProvider`, `ZRExpressProvider`, both of which return `[]` and
     * log rather than throwing). With `sync-destinations` unrun — which is the
     * state of every install until a human supplies courier credentials — that
     * second case is *every* destination, so the tariff fallback is not an edge
     * case here, it is the path.
     */
    private static function live(
        ProviderRegistry $providers,
        string $name,
        Destination $destination,
        ?Logger $logger
    ): ?RateQuote {
        try {
            $quotes = $providers->get($name)->getShippingRates($destination);
        } catch (ApiException $exception) {
            // The contracted failures: unreachable, refused, rate limited, a
            // credential that has stopped working.
            $logger?->warning('A courier would not quote a checkout', [
                'provider' => $name,
                'error' => $exception->errorCode(),
                'wilaya_id' => $destination->wilayaId,
                'commune_id' => $destination->communeId,
            ]);

            return null;
        } catch (Throwable $throwable) {
            // The uncontracted ones. A bug inside one adapter must cost that
            // courier its row and nothing else.
            $logger?->error('A courier threw while quoting a checkout', [
                'provider' => $name,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        return self::cheapestFor($quotes, $destination);
    }

    /**
     * The cheapest of a courier's services that suits this journey.
     *
     * Yalidine answers with up to four — express and economic, each to the door
     * and to a desk — and deliberately returns all of them whatever was asked
     * for, because the admin screen compares them. Taking `[0]` would charge
     * express to everyone and taking the cheapest of the four would charge a
     * stop-desk price to a customer waiting at home, so the journey is filtered
     * on first. `RateQuote::coversDeliveryType()` is that test, and it reads a
     * field the *adapter* set rather than matching `_desk` against a service
     * name the core is contracted to treat as opaque.
     *
     * The tie-break on `service` is there for the same reason
     * `CheckoutService::requireShippingQuote()` has one: two services at the
     * same price must not resolve by whichever order the courier's JSON
     * happened to arrive in, or the shop's own history stops being reproducible.
     *
     * @param list<RateQuote> $quotes
     */
    private static function cheapestFor(array $quotes, Destination $destination): ?RateQuote
    {
        $best = null;

        foreach ($quotes as $quote) {
            if (!$quote instanceof RateQuote || !$quote->coversDeliveryType($destination->deliveryType)) {
                continue;
            }

            if ($best === null || self::beats($quote, $best)) {
                $best = $quote;
            }
        }

        return $best;
    }

    /**
     * Cheaper, or the same price under an earlier service name.
     *
     * The amounts are decimal strings and `<=>` compares two numeric strings
     * numerically, which is what `requireShippingQuote()` already relies on.
     */
    private static function beats(RateQuote $candidate, RateQuote $best): bool
    {
        $order = ($candidate->amount <=> $best->amount)
            ?: strcmp($candidate->service, $best->service);

        return $order < 0;
    }
}
