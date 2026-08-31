<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\AddressInput;
use AlgerianCommerce\Shipping\Destination;

/**
 * Validates and normalizes an order write payload.
 *
 * Pure — no WordPress, no WooCommerce — so every rule is unit-testable: which
 * fields exist, which are required on a create, and which values are nonsense.
 *
 * Unknown fields are rejected rather than ignored (docs/SECURITY.md). The
 * fields this API emits but does not accept are dropped silently instead, so a
 * client can GET an order, change one thing and PATCH the whole object back.
 * That distinction matters more here than on a product: an order body is large,
 * mostly computed, and no client wants to strip fourteen keys by hand.
 *
 * Every *total* is read-only. Totals are what WooCommerce computes from the
 * lines, never what a caller sends. One amount is settable — see below — and it
 * is not a total.
 *
 * ## `shipping_amount` is writable and `shipping_total` is not, and that is the
 * whole distinction this class draws about money
 *
 * A back-office order needs to carry a delivery fee. Until backend step 4 the
 * only shipping line this shop could produce came from the checkout quote
 * (`Cart\CheckoutService::createOrder()`), so an order placed on the phone had
 * no way to charge for delivery at all.
 *
 * The obvious move — lift `shipping_total` out of READ_ONLY — is the wrong one,
 * and not for a squeamish reason. `shipping_total` is *derived*:
 * `WC_Abstract_Order::calculate_totals()` sums the order's shipping **line
 * items** into `set_shipping_total()` (`abstract-wc-order.php:2163`, verified
 * against the 11.0.1 that compose.yaml pins). Writing the prop would set a
 * number that the very next `calculate_totals()` overwrites, so a "settable
 * `shipping_total`" would be a field that appears to work and silently stops
 * working the next time anybody edits a line. It is read-only here because it
 * is read-only *there*.
 *
 * So the settable thing needs its own name, and `shipping_amount` is it. **How
 * a reader tells them apart:** `shipping_amount` is the one number this order
 * states about delivery; `shipping_total` is what the order's shipping lines
 * add up to. On every order this API writes they are the same money, because
 * `OrderRepository::replaceShippingLine()` collapses the statement to exactly
 * one line — which is the tell worth remembering: **send `shipping_amount`,
 * read `shipping_total`.** They can differ only on an order some other surface
 * gave two shipping lines, and then `shipping_total` is right and
 * `shipping_amount` is what *this* API last said.
 *
 * ### Why not `shipping_cost`
 *
 * Because this API already has a `shipping_cost` and it means the opposite.
 * `Analytics\RevenueReport::unavailable()` publishes the key with the sentence
 * *"What a courier charges the shop is not recorded. ac_shipments deliberately
 * has no cost column, and shipping_revenue above is the separate figure of what
 * the customer was charged."* Cost is the shop's side, revenue is the
 * customer's side, and the number here is unambiguously the customer's — it
 * feeds `shipping_revenue`, which `Analytics\AnalyticsRepository::revenueExtras()`
 * sums out of `shipping_total_amount`, the column this field ends up writing.
 * One API cannot hold both meanings of the word. The reference shop does call
 * it `shippingCost` (`EL/api/.../domain/Order.java:47`) and its own dashboard
 * then has to subtract it from revenue to get sales
 * (`DashboardRepositoryImpl.java:107`), which is the collision arriving on
 * schedule.
 *
 * `amount` is instead what this codebase has always called the customer's
 * delivery charge in the one place it was already settable: a shipping rule's
 * `amount` (`Shipping\ShippingRuleInput`), carried through
 * `Shipping\RateResolver::quote()` as a quote's `amount`, and handed to
 * `WC_Order_Item_Shipping::set_total()` as `$shipping['amount']` by
 * `Cart\CheckoutService::createOrder()`. Same quantity, same name, and now the
 * back office states directly what the tariff table would have stated for it.
 *
 * ### The name has to survive item 2
 *
 * Item 2 adds a `shipping_provider` — the courier a back-office order is going
 * out with — which belongs on the same shipping line, in `method_id`. The pair
 * `shipping_amount` + `shipping_provider` reads as two facts about one line,
 * and neither name has to move when the second arrives. `POST /checkout` can
 * take `shipping_amount` under the same name if it is ever allowed to override
 * its quote, but it is not allowed to today and this class is not the place
 * that would change: a shopper stating their own delivery price is exactly the
 * threat `Cart\LineInput` refuses by name, and §14's tariff is the answer.
 *
 * ## `shipping_provider` arrived, and the word it collides with is "provider"
 *
 * It is the courier that will carry the parcel: `manual`, `yalidine`,
 * `zrexpress` — one of the names `Plugin::shippingProviders()` registered. It
 * is written to the shipping line's `method_id`, the slot
 * `OrderRepository::SHIPPING_LINE_TITLE`'s docblock has been holding open for
 * it since step 1, and it is the field backend step 2's fifth item reads to
 * decide which courier to hand a confirmed order to.
 *
 * **The collision is real and a reader has to be warned about it in exactly one
 * place, so this is that place.** `shipping_source` — read-only, emitted by
 * `OrderPresenter::shippingSource()` — carries the two values `rules` and
 * **`provider`**. So this API now has a field whose *name* is provider and a
 * different field whose *value* can be provider, and they are not about the
 * same thing at all:
 *
 * ```
 * shipping_source    rules | provider | null   who said the number
 * shipping_provider  a courier name    | null  who carries the box
 * ```
 *
 * The one-line test: **`shipping_source` is about the price, `shipping_provider`
 * is about the parcel.** `shipping_source: "provider"` means a courier's own
 * API quoted this fee rather than §14's tariff; `shipping_provider: "yalidine"`
 * means Yalidine is carrying it, whoever priced it.
 *
 * The combination that proves they are independent is also the *common* one on
 * any install without courier credentials: `shipping_source: "rules"` with
 * `shipping_provider: "yalidine"` — the shop's tariff priced the journey
 * because Yalidine has no destination mapping to quote from, and Yalidine
 * carries it regardless. Anyone who reads the two as one fact will misread that
 * order, which is why they are two fields and not one.
 *
 * ### Writable, unlike `shipping_source`, and that asymmetry is the point
 *
 * `shipping_source` is in `READ_ONLY` because a caller who could state it could
 * claim a courier had answered when none was asked. Nothing like that is true
 * of `shipping_provider`: naming the courier *is* the operator's decision, not
 * a claim about what anyone said. A back-office order is a phone call being
 * written down, and "this one goes out with ZR Express" is exactly the kind of
 * thing the person on the phone knows and the system does not.
 *
 * ### Empty means "says nothing", the same as `shipping_amount`
 *
 * `null` and `""` are dropped rather than refused and rather than stored,
 * identically to `shipping_amount` above and for its stated reason: the two
 * fields are one concept — facts about one shipping line that a person typed —
 * and a caller who learns the emptiness rule on one must not have to learn a
 * second one here. It is also what makes the round trip work, since
 * `OrderPresenter` emits `null` for an order whose courier nobody has named.
 *
 * The cost is worth naming out loud because it is the one place this field is
 * less expressive than `shipping_amount`: a fee has `0` to mean "no charge", so
 * conflating empty with absent costs it nothing, while a courier has no such
 * third value and therefore **cannot be un-chosen** — only replaced. That is
 * tolerable because the slot only means anything when it names somebody: an
 * operator who picked the wrong courier names the right one, and an order whose
 * courier is genuinely undecided is one nobody has named yet. Spending the
 * empty string on "forget the courier" would have bought that rare correction
 * at the price of the round trip, which every client pays on every PATCH.
 *
 * ### Membership is not checked here, and that is `ShipmentInput`'s rule
 *
 * Which couriers a shop has configured is runtime state, and this class is pure
 * so that every rule in it is unit-testable without a container.
 * `Shipping\ShipmentInput::fromPayload()` faced this exact question for its own
 * `provider` field and answered it with a comment — *"Not checked against the
 * registry here: which providers a shop has configured is runtime state, and a
 * pure validator that knew it could not be tested without one."* The same
 * answer, for the same reason: shape here, membership in `OrderService`.
 *
 * There is deliberately no charset rule either, only a length cap. The registry
 * is the authority on which names exist, and a pattern here would be a second
 * and weaker authority that could only ever refuse names the registry also
 * refuses — while standing ready to refuse a future adapter whose name contains
 * a character nobody anticipated. The cap is not about validity: the value is
 * echoed back in `OrderService`'s refusal and written to `method_id`, and
 * neither wants a kilobyte.
 *
 * ## And now a destination: `wilaya_id`, `commune_id`, `delivery_type`
 *
 * The gap the field above could not close on its own. `shipping_provider` says
 * who carries the parcel; until these three arrived nothing on this route said
 * **where to**, so an order taken on the telephone named a courier, was
 * confirmed, and `Shipping\ShipmentSubscriber` recorded
 * `order_destination_missing` against it — every time, by construction. Only
 * checkout-placed orders auto-created a parcel, which made "no manual parcel
 * step" a storefront-only promise on the one screen this panel exists for.
 *
 * ### These are geography row ids, and `city`/`state` are not
 *
 * The collision this trio has to be told apart from is not another
 * `shipping_*` key — it is the address. `billing` and `shipping` already carry
 * a `city` and a `state`, and on an Algerian address those hold a commune name
 * and a wilaya name. **They are a different kind of thing, and the two tells
 * are the suffix and the nesting.**
 *
 * ```
 * shipping.city     "Bir Mourad Raïs"  free text, as typed, stored as given
 * shipping.state    "Alger"            free text, as typed, stored as given
 * commune_id        1234               a row of ac_geo_communes
 * wilaya_id         16                 a row of ac_geo_wilayas
 * ```
 *
 * The address half is what a driver reads and a label prints;
 * `Commerce\AddressInput` validates its *shape* and says so in terms — *"No
 * wilaya or commune validation here"* — so `state` accepts anything and means
 * whatever the operator typed. The id half is what a courier **routes** on, and
 * `Shipping\Destination`'s docblock is the argument for why one cannot be
 * derived from the other: *"'Ouled Fayet' is spelled six ways across three
 * couriers and two languages"*, and several communes of one name sit in
 * different wilayas. `Shipping\ShipmentInput` refuses that derivation by name
 * and this class refuses it by the same reasoning — which is precisely why
 * these are three new fields rather than a cleverer reading of two that already
 * exist.
 *
 * So: **`_id` means a row, and a row is what gets routed.** Anything without
 * the suffix, inside an address object, is text for a human.
 *
 * ### Flat and unprefixed, unlike `shipping_amount`
 *
 * `shipping_amount` earned its prefix by colliding with `shipping_total` inside
 * one object, and `shipping_provider` earned its by sitting beside
 * `shipping_source`. Neither argument applies here, and the opposite one does:
 * `wilaya_id`, `commune_id` and `delivery_type` are already the spelling of
 * this fact on **four** other surfaces — `Shipping\ShipmentInput`
 * (`POST /orders/{id}/shipments`), `Shipping\ShippingRuleInput`,
 * `GET /shipping/rates` and `POST /checkout` — and
 * `Shipping\Destination::toArray()` publishes exactly these three keys in
 * exactly this order. A fifth spelling would mean a panel renaming the same two
 * integers on the way from its destination picker to its rate lookup to the
 * order body, and a rename is where a mismatch hides.
 *
 * `shipping_wilaya_id` was the alternative and is wrong twice over: it invents
 * a word for a fact this codebase has always spelled without one, and in this
 * API `shipping_` prefixes facts about the **shipping line** — the amount, the
 * source, the provider, the total, every one of them line-level. A destination
 * is not a line fact. It is written to order meta, it deliberately outlives the
 * line rewrite `OrderRepository::replaceShippingLine()` performs on every
 * restated fee, and borrowing the line's prefix would say it was one of them.
 *
 * ### `delivery_type` takes the enum it already has, and invents no default
 *
 * `home` or `desk`, from `Shipping\Destination::DELIVERY_TYPES`, refused with
 * `Shipping\ShipmentInput`'s and `Shipping\ShippingRuleInput`'s sentence word
 * for word. The constant is imported rather than restated so that a third
 * journey — a locker, a relay point — cannot arrive on two of the three routes
 * and not the last.
 *
 * **Absent stays absent.** This class does not default it to `home`, and that
 * is deliberate: `ShipmentSubscriber::destinationOf()` already falls back to
 * `Destination::HOME` for a missing or unrecognised value, and states why it is
 * the one field safe to default — *"home delivery is what a courier does when
 * nobody asks for a desk, and getting it wrong costs a customer a trip rather
 * than a parcel a different wilaya"*. A second default here would give one fact
 * two owners that can drift, and would make a back-office order *claim* a
 * journey nobody chose. So an unstated type reads back `null`, means "nobody
 * said", and ships home — one default, left where it already lived.
 *
 * ### Empty says nothing, exactly as it does for the two fields above
 *
 * `null` and `''` are dropped rather than refused and rather than stored, on
 * `shipping_amount`'s stated rule and for its reason: the presenter emits
 * `null` for an order with no destination, and a client PATCHing back a body it
 * just read must not be 400ed on keys it never touched.
 *
 * **`0` is not empty, and is refused** — which is where these part company with
 * `shipping_amount`, on the same split `LineItemInput` draws. A fee has a
 * meaningful zero: *no delivery charge*. An id has none; there is no commune 0,
 * and `ShipmentSubscriber::destinationOf()` reads anything below 1 as no
 * destination at all. A `0` quietly dropped would be a picker bug arriving as
 * silence — the order would confirm with `order_destination_missing` and
 * nothing would say why. `Must be a positive id.` is
 * `ShipmentInput::requiredId()`'s sentence, on the identically named field, for
 * the identical mistake.
 *
 * ### The pair, and where it is checked
 *
 * A wilaya and a commune are one statement in two boxes, and a commune that
 * does not belong to the wilaya beside it is the mistake an address form
 * actually makes. None of that can be settled here: it needs the geography
 * tables, and this class is pure so that every rule in it is testable without a
 * container — `shipping_provider`'s membership check hit the same wall and gave
 * the same answer. **Shape here, geography in
 * `OrderService::guardDestinationResolves()`**, which owns the whole pair
 * argument, the half-stated case included.
 */
final class OrderInput
{
    /**
     * Computed, derived or identity fields the presenter emits.
     *
     * The money ones are here for a stronger reason than convenience: an order
     * total that a request can set is not a total, it is a suggestion.
     *
     * `shipping_total` stays in this list now that a delivery fee is settable,
     * and the class docblock argues why at length: it is derived from the
     * shipping *lines* by `calculate_totals()`, so a caller who states it is
     * stating something the next recompute will discard. The writable field
     * beside it is `shipping_amount`.
     *
     * `shipping_source` is here for a stronger reason than "derived": it is not
     * settable *by anyone*, ever. It records whether a delivery charge came
     * from a courier's live quote or from §14's tariff, and a caller who could
     * state it could claim a courier had answered when none was asked — see
     * `OrderPresenter::shippingSource()`. It is listed rather than left out
     * because unknown fields are rejected here (line 16) and the presenter now
     * emits it: without this line a client that reads an order and PATCHes the
     * whole object back gets a 400 on a key it never touched, which is exactly
     * the round trip the presenter's docblock promises works.
     */
    private const READ_ONLY = [
        'id', 'number', 'order_key', 'created_via', 'currency', 'version',
        'discount_total', 'shipping_total', 'shipping_source',
        // Why the last confirmation created no parcel, and *only* readable —
        // OrderRepository::SHIPPING_ERROR_META argues it: a caller who could
        // state this could claim a courier had refused an address that no
        // courier was ever asked about. It sits beside `shipping_source`
        // because the two are the same kind of field, a statement about
        // something that already happened.
        'shipping_provider_error', 'total_tax',
        'total', 'subtotal',
        'prices_include_tax', 'payment_url', 'is_editable', 'needs_payment',
        'stock_reduced', 'customer', 'date_created', 'date_modified',
        'date_paid', 'date_completed',
    ];

    private const STRING_FIELDS = ['payment_method', 'payment_method_title', 'customer_note'];

    private const MAX_NOTE = 5000;

    /**
     * A ceiling on a stated delivery fee.
     *
     * The same number as `Shipping\ShippingRuleInput::MAX_AMOUNT` and
     * `LineItemInput::MAX_PRICE`, and the first of those is the one that
     * actually justifies it: a shipping rule's amount is *this quantity* stated
     * in the tariff table, and its constant is commented "Nine million dinars
     * of delivery is a typo, not a tariff." A back-office order must not be
     * able to charge a delivery fee that no shipping rule could have quoted, so
     * the ceiling is inherited rather than invented.
     *
     * The numeric half of the argument is `LineItemInput::MAX_PRICE`'s: the fee
     * is summed into the order total, PHP turns an unbounded amount into `INF`
     * rather than an error, and `wp_wc_orders.total_amount` is
     * `decimal(26,8)`. A fee above eight figures is a typo or a probe, and both
     * deserve a 400 rather than a row nobody can read back.
     */
    private const MAX_SHIPPING_AMOUNT = 9999999.99;

    /**
     * A ceiling on a stated courier name.
     *
     * The same 40 as the `shipping_provider` pattern on `POST /checkout`
     * (`Cart\CheckoutController::registerRoutes()`), and inherited rather than
     * picked so that the one field cannot accept a string on one route and
     * refuse it on the other. A client that can name a courier at checkout can
     * name the same courier in the back office.
     *
     * Generous for what it holds — the longest name any adapter registers is
     * `zrexpress` at nine characters — because it is a bound, not a
     * description. Its job is to stop an error message and a `method_id` from
     * carrying an arbitrary payload, which the registry check alone would not
     * do: that check refuses the value *after* it has been read.
     *
     * **This read `zr_express` at ten characters and both halves were wrong.**
     * `ZRExpressProvider::NAME` is `'zrexpress'`, which is nine, and that
     * constant is the authority because it is the string `ProviderRegistry::has()`
     * matches on. The correction changes no behaviour and nothing was sized
     * from the wrong figure: the 40 above is inherited from the
     * `^[a-z0-9_-]{0,40}$` pattern on `POST /checkout`, as the paragraph before
     * this one says, and never from the length of any courier's name. The
     * confusion is traceable — `zr_express` *is* a live string in this
     * codebase, but it is the feature-flag key `SettingsService::features()`
     * derives from `ENABLE_ZR_EXPRESS`, not a provider name.
     */
    private const MAX_PROVIDER = 40;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        return new self(self::normalize($payload, true));
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        return new self(self::normalize($payload, false));
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @return list<LineItemInput> */
    public function lineItems(): array
    {
        return $this->fields['line_items'] ?? [];
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return [
            ...self::STRING_FIELDS,
            'status',
            'customer_id',
            'billing',
            'shipping',
            'line_items',
            'shipping_amount',
            // Beside `shipping_amount`, which is what it is a pair with. Order
            // is free here — the "Unknown field." sweep below diffs against
            // this list but reports in *payload* order — so the list is
            // arranged to read, and the two facts about one shipping line
            // belong next to each other. What is not free is the order of the
            // validation blocks in normalize(); see the one that handles this.
            'shipping_provider',
            // Where the parcel goes, in `Shipping\Destination::toArray()`'s own
            // order — wilaya, commune, journey. Kept together and kept last,
            // because they are one statement in three keys and reading them
            // apart is how a wilaya ends up beside another wilaya's commune.
            'wilaya_id',
            'commune_id',
            'delivery_type',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws ApiException with a per-field breakdown in error.details.fields
     */
    private static function normalize(array $payload, bool $isCreate): array
    {
        $errors = [];
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $errors[(string) $field] = 'Unknown field.';
        }

        foreach (self::STRING_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            if ($payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $payload[$field]);

            if (mb_strlen($value) > self::MAX_NOTE) {
                $errors[$field] = 'Must be at most ' . self::MAX_NOTE . ' characters.';
                continue;
            }

            $clean[$field] = $value;
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? OrderStatus::normalize((string) $payload['status']) : '';

            if (!OrderStatus::isKnown($status)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', OrderStatus::ALL) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        if (array_key_exists('customer_id', $payload)) {
            // 0 is a guest order, which is the normal case for a storefront
            // that does not force registration — so it is a value, not a gap.
            if (!is_numeric($payload['customer_id']) || (int) $payload['customer_id'] < 0) {
                $errors['customer_id'] = 'Must be a user id, or 0 for a guest.';
            } else {
                $clean['customer_id'] = (int) $payload['customer_id'];
            }
        }

        if (array_key_exists('billing', $payload)) {
            $clean['billing'] = AddressInput::forBilling($payload['billing'], $errors);
        }

        if (array_key_exists('shipping', $payload)) {
            $clean['shipping'] = AddressInput::forShipping($payload['shipping'], $errors);
        }

        if (array_key_exists('line_items', $payload)) {
            $clean['line_items'] = LineItemInput::listFromPayload($payload['line_items'], $errors);
        } elseif ($isCreate) {
            $errors['line_items'] = 'An order needs at least one line item.';
        }

        /*
         * Validated last, after the lines, because it is the second half of the
         * same sum: the order's total is the lines plus this. Its position is
         * also what keeps every existing error ordering intact — the breakdown
         * is built in field order and the REST suite asserts that order.
         *
         * `null` and `""` mean "this request says nothing about delivery", so
         * they are dropped rather than refused and rather than stored. That is
         * `LineItemInput`'s treatment of an absent `price`, deliberately
         * identical: the two fields are one concept — an amount a person typed
         * on an order — and a caller who learns the emptiness rule on a line
         * must not have to learn a second one here.
         *
         * **What the two empties do not share is what they mean afterwards.**
         * An empty line price hands that line back to the catalogue, because a
         * line has a catalogue price to fall back to. Delivery has no
         * catalogue, so an empty `shipping_amount` cannot mean "re-quote it" —
         * there is nothing to re-quote against without a destination, and §14's
         * tariff is reached from a cart, not from an order. It means *leave the
         * order's shipping line exactly as it is*, which is the only reading
         * that lets the round trip work: `OrderPresenter` emits `null` for a
         * line the checkout quoted, and PATCHing a fetched order back must not
         * delete the delivery charge the shopper already paid for.
         *
         * `0` is therefore the way to say "no delivery charge", and it is a
         * real statement rather than an absence — a zero shipping line, the
         * same shape `Shipping\RateResolver::quote()` writes when a basket
         * crosses a free-delivery threshold. It is also the only way to cancel
         * a fee, which is why conflating it with empty would strand every order
         * that was ever charged one.
         */
        if (array_key_exists('shipping_amount', $payload)
            && !in_array($payload['shipping_amount'], [null, ''], true)) {
            $amount = self::amount($payload['shipping_amount'], 'shipping_amount', $errors);

            if ($amount !== null) {
                $clean['shipping_amount'] = $amount;
            }
        }

        /*
         * Last, and after `shipping_amount` for the same reason that one is
         * after the lines: the breakdown is built in block order and the REST
         * suite asserts that order, so a new rule goes at the end or it
         * renumbers somebody's assertion. Being last is also the honest
         * position — this is the only field here that says nothing about money,
         * and every other block has already had its say by the time it runs.
         *
         * The empty handling is `shipping_amount`'s — `null` and `""` say
         * nothing and are dropped — and the class docblock argues why the two
         * fields must share it rather than each having a defensible rule of
         * their own. The emptiness test is *inside* `provider()` here rather
         * than in this condition, which is the one deliberate divergence from
         * the block above: a name is normalized before it is judged, so
         * `"  "` and `" Yalidine "` are the same statements as `""` and
         * `"yalidine"`. `shipping_amount` cannot do that — `is_numeric()` reads
         * `" 450 "` for itself — so it tests emptiness up front and this cannot.
         */
        if (array_key_exists('shipping_provider', $payload)) {
            $provider = self::provider($payload['shipping_provider'], $errors);

            if ($provider !== null && $provider !== '') {
                $clean['shipping_provider'] = $provider;
            }
        }

        /*
         * The destination, last of all, on the rule the block above states: a
         * new rule goes at the end or it renumbers somebody's assertion, since
         * the breakdown is built in block order and the REST suite asserts that
         * order.
         *
         * The three run in `Destination::toArray()`'s order — wilaya, commune,
         * journey — so a form that reddens boxes top to bottom reddens them the
         * way it drew them. Each reports independently: a payload with a bad
         * wilaya *and* a bad commune gets two errors, because a picker bound to
         * two selects has two boxes to point at, and this is the one place in
         * this class where two fields are so nearly one field that collapsing
         * them would have looked reasonable.
         *
         * The emptiness test is up front here rather than inside the helper,
         * which is `shipping_amount`'s arrangement and not
         * `shipping_provider`'s. The difference is the one that method's
         * comment identifies: a courier name has to be normalized before it can
         * be judged empty, so `"  "` and `""` are one statement. An id has no
         * such normalization — `is_numeric()` reads `" 16 "` for itself — so
         * there is nothing to do first, and the condition can say plainly what
         * it means.
         */
        foreach (['wilaya_id', 'commune_id'] as $field) {
            if (!array_key_exists($field, $payload) || in_array($payload[$field], [null, ''], true)) {
                continue;
            }

            $id = self::destinationId($payload[$field], $field, $errors);

            if ($id !== null) {
                $clean[$field] = $id;
            }
        }

        if (array_key_exists('delivery_type', $payload)
            && !in_array($payload['delivery_type'], [null, ''], true)) {
            $type = self::deliveryType($payload['delivery_type'], $errors);

            if ($type !== null) {
                $clean['delivery_type'] = $type;
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The order data is invalid.', ['fields' => $errors]);
        }

        return $clean;
    }

    /**
     * A stated delivery fee as the decimal string the caller typed.
     *
     * Null on refusal, with the reason already recorded under `$field`. Three
     * distinct ways to be wrong, three messages: a single "invalid amount"
     * tells a form which box to redden and nothing else.
     *
     * Zero is allowed and negative is not, for `LineItemInput::amount()`'s
     * reason and one of its own. The shared reason: a negative amount on an
     * order is a refund, and a refund is a different object with a different
     * route, not an order line with a minus sign. The one specific to shipping:
     * nothing downstream would catch it. `calculate_totals()` clamps a negative
     * **fee** so it cannot exceed what the order is worth
     * (`abstract-wc-order.php:2168-2172`, the `0 > $fee_total` branch) and
     * applies no such clamp to shipping — `:2158-2163` sums the shipping lines
     * exactly as they stand. A negative delivery fee would subtract from the
     * order total unchecked, which is a discount granted through a field named
     * for a charge.
     *
     * ## This is the third copy of this rule, and it is a copy on purpose
     *
     * The other two are `LineItemInput::amount()` and
     * `Shipping\ShippingRuleInput::money()`. All three refuse with the same
     * three sentences — `Must be an amount.`, `Cannot be negative.`,
     * `Is implausibly large.` — and that identity is a contract a panel binds
     * to, not a coincidence: a form that shows one wording for a line price and
     * another for a delivery fee is a form the operator has to read twice.
     *
     * Reusing step 2's helper was the alternative and was not taken.
     * `LineItemInput::amount()` is private, and the only way to call it from
     * here is to publish it — which exports a *line*'s validator as this
     * module's general money check, and the next caller reaches for it on
     * something that is neither a line nor a price. `ShippingRuleInput::money()`
     * is worse in the other direction: it lives in `Shipping\`, so `Orders\`
     * would take a cross-module dependency to check a string, and it
     * `number_format()`s to two decimals against a store precision it hard-codes
     * — right for a rule stored in our own `decimal(10,2)` column, wrong for an
     * amount whose rounding belongs to WooCommerce when it reaches the line
     * (the argument `LineItemInput`'s constructor property makes at length).
     *
     * The honest reading is that this API wants **one** money validator, shared
     * by all three, and that extraction is a refactor across two modules with
     * its own argument to make — not a side effect of adding a field. Until
     * somebody makes it, the three copies are kept identical by the unit tests
     * that assert each message by name.
     *
     * @param array<string, string> $errors
     */
    /**
     * A stated courier name, lower-cased and trimmed, or `''` for "says
     * nothing". Null on refusal, with the reason recorded.
     *
     * Three return values rather than two, because there are three outcomes and
     * folding "says nothing" into either of the others loses something: as null
     * it would look like a refusal that forgot to record an error, and as a
     * value it would write an empty `method_id` and un-choose a courier the
     * caller never mentioned.
     *
     * ## The normalization is `ProviderRegistry`'s, deliberately
     *
     * `ProviderRegistry::has()` and `::get()` both look up
     * `strtolower(trim($name))`, so a name normalized any other way here would
     * be a name that passes this validator and then misses in the registry —
     * or worse, passes both and gets written to `method_id` in a spelling the
     * registry would not match on the way back out, which is the one that
     * breaks backend step 2's fifth item at confirmation time rather than at
     * write time. Same transformation, same order, so the string this stores is
     * exactly the string the registry answers to.
     *
     * `null` is checked before `is_scalar()` because `is_scalar(null)` is
     * false: without the early return, the documented "empty says nothing" rule
     * would report `Must be a string.` for the one value most likely to arrive
     * from a client echoing back an order it just read.
     *
     * @param array<string, string> $errors
     */
    private static function provider(mixed $value, array &$errors): ?string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value)) {
            // `ShipmentInput::fromPayload()`'s wording for the same refusal on
            // the same kind of field, and identical on purpose: a panel that
            // can create a shipment and edit an order should not have to render
            // two sentences for one mistake.
            $errors['shipping_provider'] = 'Must be a string.';

            return null;
        }

        $name = strtolower(trim((string) $value));

        if (mb_strlen($name) > self::MAX_PROVIDER) {
            // `Is implausibly large.`'s sibling, and phrased to match it: the
            // three money validators keep one wording between them and this is
            // the same courtesy for the field beside them.
            $errors['shipping_provider'] = 'Is implausibly long.';

            return null;
        }

        return $name;
    }

    /**
     * A geography row id as an int, or null on refusal with the reason
     * recorded.
     *
     * One sentence, not three, and the contrast with `amount()` above is the
     * argument for it. A money field has three distinct ways to be wrong that a
     * form can act on differently — the wrong kind of value, a sign, a
     * magnitude — so it gets three messages. An id has one: it is either a
     * positive whole number that might name a row, or it is not. `-4`, `0`,
     * `"sixteen"` and `[16]` are the same mistake made four ways, and a picker
     * that produced any of them is broken in the same place.
     *
     * `Must be a positive id.` is `Shipping\ShipmentInput::requiredId()`'s
     * wording, unchanged, on fields with the identical names. That identity is
     * a contract rather than a coincidence, exactly as the three money
     * validators keep one wording between them: a panel that offers a
     * destination picker on the order drawer and the same picker on the parcel
     * drawer must not render two sentences for one mistake.
     *
     * **Required-ness is deliberately not here**, which is the one place this
     * diverges from the method it borrows the sentence from. `ShipmentInput`
     * reports `Required.` for an absent id because a shipment cannot exist
     * without a destination. An order can — most of this shop's orders did,
     * before this field — so absent is a state and not an error, and what
     * refuses a *half* destination is `OrderService::guardDestinationResolves()`,
     * which is the only thing that can see the order's stored half.
     *
     * @param array<string, string> $errors
     */
    private static function destinationId(mixed $value, string $field, array &$errors): ?int
    {
        // is_numeric() before the cast, and it is what refuses `[16]`, `true`
        // and `"16abc"` alike — (int) would turn every one of those into a
        // number and hand a routing decision to PHP's juggling rules. The
        // float check is the same care one level down: `16.5` is numeric and
        // casts to a real commune id, and a picker that produced it is not
        // pointing at that commune.
        if (!is_numeric($value) || (float) $value !== floor((float) $value) || (int) $value < 1) {
            $errors[$field] = 'Must be a positive id.';

            return null;
        }

        return (int) $value;
    }

    /**
     * `home` or `desk`, normalized, or null on refusal.
     *
     * The normalization is `Destination::isKnownDeliveryType()`'s own —
     * `strtolower(trim())` — applied here before the value is stored so that
     * what lands in `OrderRepository::DELIVERY_TYPE_META` is what
     * `ShipmentSubscriber::destinationOf()` will match on. That method lowers
     * and trims what it reads, so a stored `" Desk "` would in fact survive the
     * round trip; it is normalized anyway because the *presenter* has to
     * publish a value this validator would accept back, and `" Desk "` is not
     * one. The same discipline `provider()` applies for the registry's sake.
     *
     * The message is `Shipping\ShipmentInput`'s and
     * `Shipping\ShippingRuleInput`'s, built from the same constant, so the
     * three cannot list different journeys.
     *
     * @param array<string, string> $errors
     */
    private static function deliveryType(mixed $value, array &$errors): ?string
    {
        $type = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        if (!Destination::isKnownDeliveryType($type)) {
            $errors['delivery_type'] = 'Must be one of: ' . implode(', ', Destination::DELIVERY_TYPES) . '.';

            return null;
        }

        return $type;
    }

    private static function amount(mixed $value, string $field, array &$errors): ?string
    {
        if (!is_numeric($value)) {
            $errors[$field] = 'Must be an amount.';

            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            $errors[$field] = 'Cannot be negative.';

            return null;
        }

        // Negated rather than `>`, which also catches the `INF` a JSON literal
        // like 1e400 decodes to — no comparison against a real number would.
        if (!($amount <= self::MAX_SHIPPING_AMOUNT)) {
            $errors[$field] = 'Is implausibly large.';

            return null;
        }

        // `is_numeric()` tolerates surrounding whitespace; the stored string
        // should not carry it onto the order's shipping line.
        return trim((string) $value);
    }
}
