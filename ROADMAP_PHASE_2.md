# Algerian Headless E-Commerce Backend — Phase 2

## Four features the first roadmap did not have

**Companion to
[ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md](ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md).**
That document remains authoritative for §1–§81 and for §4's build order. This one adds
§82–§85 and steps 45–48, and supersedes nothing. Where the two disagree about a rule,
the first one wins — every standing rule it states (the envelope, the provider
abstraction, "never fork their data models", `docs/SECURITY.md` → "Webhooks" and "File
uploads") applies here unchanged.

------------------------------------------------------------------------

## How to read this document

Each section states **where the feature starts from** — verified against the code on
2026-08-17, with file references — then what to build, then the decisions that are not
the implementer's to make, then what is deliberately refused and why.

The refusals are the load-bearing part. Three of these four features are ones where the
obvious implementation is wrong in a way that does not fail loudly: a facet that counts
against itself, a configurator that lets the customer price their own options, a
tracking page enumerable by order number, a campaign that mails people who never asked
for it. Each of those ships, looks correct, and is discovered later by somebody else.

## Contents

- [82. Advanced Filtering and Faceted Search](#82-advanced-filtering-and-faceted-search)
- [83. Product Configurators](#83-product-configurators)
- [84. Order Tracking](#84-order-tracking)
- [85. Email Marketing Campaigns](#85-email-marketing-campaigns)
- [86. Build Order and Definition of Done](#86-build-order-and-definition-of-done)

------------------------------------------------------------------------

# 82. Advanced Filtering and Faceted Search

## Where this starts from

`GET /products` accepts `search`, `sku`, `status`, `category`, `orderby`, `order`, `page`
and `per_page` — `ProductController::indexArgs()` — and resolves them through
`wc_get_products()` with `paginate => true` in `ProductRepository::paginate()`. Sorting
already offers `popularity` and `rating`, which read WooCommerce's
`wc_product_meta_lookup` table.

So the catalogue can be *listed and searched*. What it cannot do is narrow — no price
range, no attribute, no tag, no stock status, no on-sale — and it has never returned a
count of anything.

## What to build

``` text
GET /products
  filters:  min_price, max_price, attributes[pa_size]=m,l, tag,
            stock_status, on_sale, featured, category (repeatable), rating_min
  facets:   ?facets=attributes,price,category,stock_status
            → counts per value, plus the price range of the current result set
```

Filters extend the existing args. Facets are an **opt-in block in the response**, never
computed for a caller who did not ask, because a facet query costs more than the listing
it decorates and most callers of `/products` are the admin app, which does not need one.

## The rule that makes a facet correct

**A facet's counts are computed against every filter except its own.** If the shopper has
selected `pa_size=m`, the size facet must still report how many products exist in `l` and
`xl` — otherwise selecting one option makes every sibling read zero, and the shopper's only
way out of a dead end is the back button. Every other facet *does* narrow by `pa_size=m`.

That means a faceted response is not one query. It is one query for the result set, plus
one per facet group with that group's own filter lifted. State that up front, because the
naive implementation — count the rows you already fetched — is wrong in exactly this way
and passes any test written against a single filter.

## Measure before writing SQL

WooCommerce's Store API publishes `wc/store/v1/products/collection-data`, which returns
attribute counts, a price range and rating counts for a filtered collection. **§59b's
finding is the precedent and the warning at once**: the Store API's *cart* half was
reusable here and its *shipping and checkout* halves were not, and the only way that was
established was by measuring this install rather than reading the documentation.

So the first task in this section is a measurement, not a design:

1. Call `wc/store/v1/products/collection-data` in this stack with `is_admin()` false and a
   real filter set, exactly as §64 measured `WC_Product_CSV_Exporter`.
2. Record whether the counts are correct, whether they respect the "except its own"
   rule, and whether it works with `pa_*` taxonomies this install actually registers.
3. Write the result into the "What was built" subsection with the date, whichever way it
   goes.

If it works, this section is a thin adapter over it and the facet SQL is never written. If
it does not, the fallback is `ProductRepository`'s second exception, and it is bounded the
way `AnalyticsRepository` is bounded: read-only, no `WC_Product` ever loaded, table names
from WooCommerce's own accessors and never a literal, and a hard cap on the number of facet
values returned. **Do not skip step 1 to get to step 3.** Forty columns of hand-written
aggregate SQL that WooCommerce already ships is the mistake §64 avoided by measuring.

## Custom attributes cannot be faceted, and the API must say so

`AttributeInput` already distinguishes the two kinds: a **global** attribute is a registered
taxonomy such as `pa_size` whose options are terms, and a **custom** attribute is a string
stored on one product. A custom attribute has no term to count and no shared vocabulary —
two products both saying "Red" are two unrelated strings.

The API therefore reports **which attributes are facetable** rather than silently omitting
the others. A shop that discovers its filters do not work, with no error anywhere, will
conclude the feature is broken; a shop told "this attribute is custom, make it global to
filter on it" fixes it in a minute. This is §61's malformed-homepage-section rule: a thing
that vanishes silently is the one failure a content manager cannot diagnose.

## Search stays a substring match, and this is named rather than hidden

WordPress's `s` parameter is a `LIKE` against post title and content. It is not relevance
ranking, it does not handle typos, it does not stem, and it does not know that "chemise"
and "chemises" are the same query. **No search engine is being installed** — that is the
§62 argument about SEO plugins in a different suit: the piece that would justify
Elasticsearch is a catalogue this shop does not have yet, and an unused search cluster is
one more thing to patch.

The trigger for revisiting is named: when a catalogue passes roughly ten thousand products
or when the storefront's analytics show a meaningful rate of searches returning nothing,
the substring match has stopped being adequate and a real index earns its place.

## Refusals

- **No stored search history or "popular searches"** — that is a customer-behaviour
  dataset with no owner in this API and no consent story. §85 has the consent machinery;
  until something needs it, do not collect it.
- **No facet on a custom attribute**, per above.
- **No unbounded facet list.** Cap the values returned per group and report that the list
  was truncated, per §66's rule that a bounded result which does not say it is bounded
  reads as a complete one.

## Security

Every new filter is attacker-controlled and every one is an enum, a number or a taxonomy
name. Three things:

- Each arg declares `'validate_callback' => 'rest_validate_request_arg'` alongside its
  `sanitize_callback`. This is CLAUDE.md's standing rule and it exists because a custom
  sanitize callback displaces the default that would otherwise enforce `minimum`,
  `maximum`, `enum` and `pattern`.
- **An attribute taxonomy name arriving from a request is validated against the registered
  taxonomies before it reaches a query**, never interpolated. If the fallback path is
  taken, every new `$wpdb` call site must pass `tests/Unit/SqlSafetyTest`, which walks all
  of them statically.
- The §65 assertion is the one that matters: **a filter payload must not widen a result
  set.** "200, no crash" is what a working injection returns.

## Tests

Extend `tests/Api/products.php`. Against §67's seeded catalogue: each filter narrows to a
known count; two filters compose; a facet group reports non-zero counts for values the
current selection excludes (the "except its own" rule, which is the one assertion that
catches the wrong implementation); an unknown attribute is a 400 and not a 500; and the
sweep in `tests/Api/security.php` still passes with the new args.

## What was built

**§82 is `src/Products/ProductFilters.php`, `AttributeCatalogue.php` and `FacetResolver.php`
— nine new filters on `GET /products` and an opt-in `meta.facets` block. No migration, no
table, and `ProductRepository` gained no second `$wpdb` call site**, so §65's
`SqlSafetyTest` has nothing new to walk. `Schema::VERSION` is unchanged.

**Step 1 was done first and it decided the section.** Measured on this install
2026-08-17, `is_admin()` false, through `rest_do_request()`, against a throwaway fixture of
six products carrying one global attribute with three terms:
`wc/store/v1/products/collection-data` answers 200, accepts every filter this section
names, and **already obeys the "except its own" rule.** Filtered to one attribute term with
`query_type => 'or'`, counts came back 2 / 2 / 2 — that group's own filter lifted, its
siblings still real — while `'and'` reported only the selected term. `price_range` lifts its
own `min_price`/`max_price`; `stock_status_counts` lifts its own `stock_status`;
`calculate_taxonomy_counts` lifts its own `category`. Cost: five queries and ~15 ms for four
groups. **So the facet SQL was never written**, and `or` is not a preference but the rule.
This is §64's measurement of `WC_Product_CSV_Exporter` a second time, and §59b's warning
honoured — the Store API's *collection-data* half is reusable here exactly as its cart half
was, and only measuring said so.

**The filters could not be an adapter, and that is where the section's real finding is.**
Facets come from WooCommerce's published-only collection; the listing must still show an
admin their drafts, so `ProductRepository::paginate()` applies the filters itself — and
**`wc_get_products()` silently ignores three of the args it needs.** Measured the same day:
a `meta_query` for a 150–450 price band returned all six fixture products, priced 100 to
590, both alone and beside a `tax_query` that *did* apply; `attribute` + `attribute_term`
returned all six for a single term; there is no `on_sale` query var at all. None of the
three errors. A filter that does not filter is the §82 failure mode in its purest form —
a price band matching everything looks exactly like a shop whose prices are all in range.
What *does* work, and is therefore what the code uses: `tax_query` (attributes, categories
and tags, one AND-ed clause list), `stock_status`, `featured`, and `include`. Price and
rating go through `woocommerce_product_data_store_cpt_get_products_query`, WooCommerce's own
documented seam, attached for one call and removed in a `finally` — a band left hooked would
quietly narrow every later product query in the request. On-sale goes through
`wc_get_product_ids_on_sale()` into `include`, **with `[0]` as the sentinel for an empty
list**, because WP_Query reads `post__in => []` as no restriction and a shop with nothing on
sale would otherwise answer `on_sale=true` with its whole catalogue.

Four smaller things the measurement settled. **Store API prices are minor units** — 59000
for 590.00 DZD — converted at the boundary, since this API publishes decimal strings.
**An unknown taxonomy is a 200 with an empty list, not an error**, so `AttributeCatalogue`
refuses one before the call: a 400 naming the attribute, saying only a global attribute has
a term to count, and listing the ones this shop offers in
`details.facetable_attributes`. That is §82's custom-attribute rule and §61's
malformed-section rule in one response. **Counts cover published products only**, and the
block says so in `scope` and `scope_note` rather than leaving an admin to find seven rows
beside a count of six. **Every group is capped at 50** with `truncated` and `total_values`
beside it, per §66.

**`category` widened from a single id to `12,15`** — §82's "category (repeatable)" — and
`tag` arrived in the same form. `category=12` is unchanged for every existing caller. This
is the one contract change in the section; `docs/API.md` carries the whole parameter table.

**The suite builds its own catalogue, and had to.** §82 says to test against §67's seed, but
the seeded shop has nothing to facet: its two attribute-bearing products carry *custom*
attributes and this install registers no global attribute taxonomies at all, so every count
would have been zero and the suite would have passed against anything.
`tests/Api/products.php` → "§82 filtering and faceted search" therefore creates two global
attributes, five terms and six products with known prices, and deletes them again. **It
carries two attributes rather than one on purpose**: an implementation that lifts *every*
attribute filter instead of only the group's own passes "the selected facet lifts its OWN
filter" and fails "every OTHER facet still narrows by it". `tests/Unit/ProductFiltersTest`
holds the shapes and the truncation cap, which cannot be reached through the API without
inventing fifty-one terms that each have a product.

**One trap worth the next implementer's time: an attribute created in the same process
cannot be counted.** `ProductCollectionData` skips any taxonomy failing
`taxonomy_is_product_attribute()`, which tests `taxonomy_exists()` **and** membership of the
`$wc_product_attributes` global that `WC_Post_Types::register_taxonomies()` fills on `init`.
So `wc_create_attribute()` plus `register_taxonomy()` yields an attribute that is registered,
queryable and invisible to the facet counter, which answers 200 with an empty list. A live
shop never meets it — the attribute is created by one request and counted by a later one —
but the first fixture did, and the suite now fills the global itself.

**Refused, as §82 asks.** No search engine and no change to search: WordPress's `s` is still
a substring `LIKE`, with the trigger named — roughly ten thousand products, or a measurable
rate of searches returning nothing. No stored search history or "popular searches". No facet
on a custom attribute, refused by name with the reason rather than omitted. No unbounded
facet list.

**Nothing here is an assumption**: every claim above was measured against this stack on
2026-08-17, and `grep -rn ASSUMPTION src/Products` is empty. What is *not* proven is
behaviour at catalogue scale — the fixture is six products and the shop is forty. The
trigger for revisiting is `FacetResolver`'s five queries per faceted request: if a facet call
becomes slow on a real catalogue, the answer is a response cache in the manner of
`AnalyticsCache`, not hand-written SQL.

------------------------------------------------------------------------

# 83. Product Configurators

## Where this starts from — and what this section is *not*

**Variants are built.** `/products/{id}/variations` with `VariationService`,
`VariationInput` and `VariationRepository`; attribute validation against the parent's
offered attributes; `ProductInput::TYPES` is `simple` and `variable`. Nothing in this
section revisits that.

What does not exist is a **configurator**: a customer-facing option that changes the price
or the contents of a line without being a variation. Engraving text. Gift wrap. "Choose 3
of these 6". A bundle sold as one product that draws stock from three.

## The boundary, stated once

**A variation is a thing with a SKU and stock. An option is a modifier with neither.**

That is the whole design rule, and the reason a configurator cannot be built out of more
variations: variations are *enumerated* and options are *combinatorial*. Five attributes of
six options each is 7,776 variations, and every one of them is a post row, a meta lookup
row and a line in every export. The same product with five option groups is one product.

If a choice needs its own stock count or its own SKU, it is a variation and belongs to
§47. If it does not, it is an option and belongs here. Anything ambiguous is a variation —
that is the direction that stays correct as the shop grows.

## What to build

``` text
product:   an option-set definition — groups, choices, price deltas,
           required/optional, min/max selections
cart:      LineInput accepts a chosen-options map; the SERVER prices it
order:     chosen options land on the order line item, visible to fulfilment
bundles:   one purchasable product that decrements several products' stock
```

## The single most important sentence in this section

**The client sends the option *choice*; the server reads the *price*.**

`LineInput` today accepts `product_id`, `variation_id` and `quantity`, and refuses
`price`, `line_total`, `subtotal`, `total`, `discount` and `currency` **by name with a
reason** — the `CustomerInput` device. This section adds the first field to that input
since §59b, and it must not weaken the rule.

So the payload carries `options: {engraving: "AB", wrap: "gold"}` — identifiers only. The
surcharge for `wrap=gold` is read from the product's stored definition on every cart
mutation and again at checkout, exactly as price and stock already are
— `CartService::present()` calls `calculate_totals()` on **every** response, not only after
a mutation, and its docblock says why. A payload field named `option_price`, `surcharge`, or anything
that would let a caller state what an option costs is refused by name, with the reason,
beside the six that are already there.

This is §59b's rule at its sharpest. A cart that trusts the price in its own payload is a
shop that sells at whatever the customer types; a configurator that trusts an option's
price is the same shop with a smaller blast radius and a longer fuse, because the totals
still look plausible.

## Storage: product meta, and the trigger for a table is named

The option-set definition is an allowlisted JSON document in product meta, validated on
write by a pure input class in the manner of `HomepageSections`. **No migration and no
table**, which follows §61 and §62: WordPress stores the content, we store the structure,
and a table is added when something needs a query the meta cannot serve.

The trigger is named: when option sets are **shared across products** — one "Gift wrap"
definition applied to two hundred items, edited once — a copy in each product's meta is
the thing that drifts, and `ac_option_sets` earns its place. Until then it does not.

Bundle contents are the same document with a different group type, so a bundle is not a
second mechanism.

## Bundles are an inventory feature wearing a catalogue costume

A bundle that sells three products for one price must decrement three products' stock, and
**every one of those movements goes through `InventoryService`**. That is §64's rule — "an
import must not be a back door around `ac_inventory_movements`" — applied to the case that
sounds even more harmless. A bundle that adjusted stock directly would produce a ledger
where the numbers do not reconcile and no movement explains why.

Two consequences the implementer will hit:

- **A bundle's purchasability is the minimum of its components'**, recomputed on the
  server, not a stock field of its own. A bundle showing "in stock" because nobody
  refreshed it is an oversell.
- **A partially-refundable bundle is out of scope for this step.** Name it in the section's
  "What was built" rather than half-building it.

## Refusals

- **No conditional-logic engine.** "Show group B only when group A is `gold`" is a rules
  language, and a rules language authored by a marketing manager and evaluated on the
  server is a small programming environment with no sandbox. v1 has required/optional and
  min/max selections, and nothing else.
- **No per-option image uploads in this step.** `POST /media` is the highest-risk endpoint
  in this API (`docs/SECURITY.md` → "File uploads") and an option group is not a reason to
  widen its call sites. Options reference an existing attachment id or nothing.
- **No free-text option without a length cap and a sanitiser.** Engraving text ends up on a
  packing slip, in an email and in a CSV export — §64's formula-injection rule (`=`, `+`,
  `-`, `@`, tab, CR) applies to it the moment it reaches `CsvWriter`.
- **No customer-supplied file as an option value.** Same reason as the images.

## Tests

The pricing calculator is pure — strings and arrays in, a decimal out — so it is
`tests/Unit/`, and it is where the boundary cases live: an unknown option id, a required
group omitted, a min/max violation, a negative delta, a delta that would take a line below
zero.

`tests/Api/` covers the part a unit test cannot: **a cart payload carrying its own
surcharge is refused with the field named**, and the positive control beside it — the same
cart without that field is priced correctly by the server. §65's rule: a refusal and an
unreachable route look identical from outside, so every negative test needs a positive
control.

## What was built

**§83 is five classes in `src/Products/` — `OptionSet`, `OptionSelection`,
`OptionSetRepository`, `BundleAvailability`, `BundleStock` — plus
`Cart\OptionPriceSubscriber`. No migration, no table, no new route and no new
capability**; `Schema::VERSION` is unchanged. A definition is written and read as `options`
on the product it belongs to, and a bundle is a **group type** rather than a product type,
so `ProductInput::TYPES` is still `simple` and `variable`.

**The client sends the choice; the server reads the price**, and the shape of the code is
that sentence. `LineInput` gained `options` — a map of group id to chosen value,
identifiers and free text only — and gained four refusals beside §59b's six:
`option_price`, `options_price`, `surcharge` and `option_total`, each **by name with the
reason**. The cart stores only what was chosen; the surcharge is recomputed from the
product's own definition on every `calculate_totals()`, which `CartService::present()` runs
on every response and not only after a mutation. Nothing about money survives in the
session, so there is no stale number and nothing to tamper with if the session's signing is
ever loosened.

**The bug that proves the section's point was in this code, and it was found by writing the
positive control rather than the refusal.** `OptionPriceSubscriber` calls `set_price()` on
the cart line's product object; that object lives in the session; `calculate_totals()` runs
more than once per request. So reading the base price back off it re-added the surcharge
every pass. Measured 2026-08-17: a 1,000 DZD mug with a 250 wrap and a 500 engraving — 1,750
correct — came back at **3,250**, and a second line at 2,500 for a 500 surcharge. Nothing
errored, the totals were consistent with themselves and the line items looked like money.
That is §83's own warning about a configurator whose failure "still looks plausible",
arriving in the implementation of §83. The base is now always a fresh `wc_get_product()`,
and `docs/SECURITY.md` carries the rule.

**Two more of the same shape.** `CartController::respond()` carried only `cart` and `token`,
so a line whose options could no longer be priced reported nothing to anybody — it now
carries `meta.problems`, and `CheckoutService` refuses to place an order while that list is
non-empty. And `ProductRepository` constructed its **own** `OptionSetRepository`, so
clearing a product's options left the cart holding the memoised old document: one product,
two answers, in one request. It takes the shared instance now. Both were found by
`tests/Api/options.php`, and both are the §82 pattern again — nothing throws and the output
looks right.

**Storage is one product meta key holding one JSON document**, validated by a pure class in
the manner of `HomepageSections`, with the two manners that class establishes:
`fromPayload()` is strict and names the position (`options.groups[0].choices[2].price_delta`),
`fromStored()` is lenient and drops a bad group **while reporting it** in
`options_problems`. Unparseable meta is reported rather than read as "this product has no
options" — the distinction a unit test insisted on, and §61's silent-vanishing rule one
layer down. The trigger for `ac_option_sets` is named and nothing needs it yet: option sets
**shared across products**, where a copy in each product's meta is the thing that drifts.

**Bundles decrement through the ledger, and the deviation from §83's wording is deliberate.**
§83 says component movements go through `InventoryService`; they go through `StockLedger`,
because `InventoryService::adjust()` asserts `ac_manage_inventory` and stock moves here on
an order *status transition* — fired by a Chargily webhook with no user at all, by the
hourly poller, by wp-admin, by WP-CLI. That is the argument `OrderStockSubscriber` already
records for the identical situation. The rule §64 actually protects is that nothing moves
stock without writing a movement, and that holds: `wc_update_product_stock()` for the
change, one `ac_inventory_movements` row per component, keyed to the order. Verified — two
kits of one mug and two boxes took mug 50→48 and box 10→6, wrote exactly two ledger rows
whose figures match the shelf, did **not** decrement again on the `completed` transition,
and restored both on cancellation.

**A bundle's availability is computed on every read**, never stored — §83's oversell rule —
as the minimum of `floor(component stock ÷ component quantity)`, with a deleted or
out-of-stock component meaning zero rather than "unbounded". The shortfall message names
neither the component nor its stock: `POST /cart/items` is public, and a reason reading
"only 3 left of SKU X" turns it into an inventory read for anyone willing to guess.

**Refused, as §83 asks, each by name.** No conditional-logic engine — required/optional and
min/max, nothing else. No per-option uploads; a choice references an existing attachment id
or nothing, checked against the database on write. No customer-supplied files. Free text is
capped by its group and stripped of markup and control characters — but a leading `=` is
**deliberately left alone**, because §64's escape belongs at the CSV boundary where
`CsvWriter` already does it, and stripping it here would mangle "A=B" on a keepsake while
protecting no other export path. **A partially-refundable bundle is out of scope**, named
rather than half-built: refunding two of three components means deciding what fraction of
one price each was sold at, which is a pricing question §83 does not answer. A whole-order
restock returns everything, and does.

**Two limitations named rather than worked around.** Bundles **do not recurse** — a
component that is itself a bundle has its own stock reduced and its components left alone;
the direct self-reference is refused on write, and a longer cycle would need a graph walk on
every order transition that nothing has asked for. And a **variation inherits its parent's
option set** rather than carrying its own, which is the combinatorial explosion this section
exists to avoid, one level down.

**Nothing here is an assumption** — `grep -rn ASSUMPTION src/Products src/Cart` is empty, and
every figure above was measured against this stack on 2026-08-17.

------------------------------------------------------------------------

# 84. Order Tracking

## Where this starts from

The data exists and has existed since §55. `ac_shipments` (migration 004) holds
`order_id`, `provider`, `provider_shipment_id`, `tracking_number`, `status`, `metadata`,
`created_at` and `updated_at`; `ShipmentStatus` has eleven values from `pending` to
`returned`; `ShipmentPoller` keeps them current on an hourly cron and the two courier
webhooks re-fetch into the same path.

**The gap is exposure, not collection.** `AccountService::order()` returns a bare
`OrderPresenter::toArray()` — no shipment block at all. The only way to read a tracking
number today is `GET /orders/{id}/shipments`, which is gated on `ac_manage_shipping`. A
customer cannot see where their parcel is, and neither can the storefront on their behalf
without an admin credential, which §44 forbids.

So this is a small section whose entire risk is authorization.

## Two doors, and they are not the same door

``` text
GET /account/orders/{id}   → gains a `shipment` block   (session-owned)
GET /orders/track          → status and history only    (token-owned, public)
```

**The first is easy** and is three lines: the session already resolves the customer,
`Permissions::assertOwnsOr()` already runs, and the shipment is a repository read keyed on
the order id.

**The second is the whole section**, because guest checkout is built and supported and
`AccountService::order()` refuses `customer_id = 0` outright — §59c's reasoning was that
"the only evidence linking a shopper to a guest order is an email address, which would make
it readable by anyone who could name it", and that reasoning was correct. But a COD shop in
Algeria takes a large share of its orders as guests, so tracking that excludes them is not
tracking.

## The public route is token-owned, and the alternatives are enumerable

**Mint an unguessable per-order tracking token at checkout**, return it in the checkout
response beside the existing `next: {action: create_payment, …}` block, and make it the
only key the public route accepts.

The obvious alternative — order number plus phone number — is enumerable and the
arithmetic says so. Order numbers are sequential. An Algerian mobile number is ten digits
with a known operator prefix, so the search space per order is around ten million and a
shop's entire order book, with every customer's name, address and phone, is a few million
requests behind a form. Order number plus email is worse: an attacker who knows one
customer's address walks the order numbers.

Requirements on the token:

- Not derived from the order id, and not sequential. It is random, or it is an HMAC over
  the order id with the site salt — the same construction `CartSession` already uses.
- **Rate-limited**, through `RateLimiter` with its own `AC_RATE_LIMIT_*` group, because it
  is an unauthenticated read and the guard that watches Application Password failures does
  not watch it. §59c found exactly this gap for customer logins and it was fixed by having
  the service record the failure itself.
- Revocable, or at least expiring some fixed period after the shipment reaches a terminal
  status. A tracking link in an email lives forever otherwise.

## What the public route may disclose, by name

``` text
allowed:  order number, order status, shipment status, status history
          with timestamps, courier name, tracking number,
          destination WILAYA only, estimated delivery if the courier gives one
refused:  full address, commune, phone, email, customer name,
          line items, quantities, totals, payment method, order notes
```

**A tracking page that echoes the delivery address turns a leaked link into a doxxing
tool**, and tracking links leak — they are forwarded, pasted into chats and screenshotted.
The destination wilaya is enough for a customer to recognise their own parcel and is not
enough to find anybody.

**The courier's label URL must never appear**, under any circumstance. §55 put `label` and
`labels` into `Logger::SENSITIVE_EXACT` because a Yalidine label URL carries an access
token and resolves to a document holding one customer's name, phone and address — they are
credentials, not links. Any new field from a provider that carries a tokenised URL joins
that list when its adapter is written.

## Two standing rules that apply unchanged

- **A parcel's status never moves the order.** This has been true since §55 and a tracking
  view does not get to be the exception. The response *presents* the order status and the
  shipment status side by side and merges neither.
- **`ShipmentRepository` remains the only place a shipment row is read or written as a
  `Shipment`.** `AnalyticsRepository` is the one aggregate exception and this is not a
  second one.

## Notifications come nearly free, and should ship with this

`ac_shipment_saved` already fires from `ShipmentRepository::update()` — deliberately in the
repository rather than the service, because the poller writes without going through the
service and "delivered" almost always arrives from a poll — and `NotificationSubscriber`
already claims on it. So a "your parcel is on its way" email carrying the tracking link is
a message template and a queue row, not a mechanism.

One precondition, and it is the one `PasswordResetService` already established: **the link
needs the storefront URL, which this backend cannot derive.** §71 stores it in
`ac_client_settings`; §62 refused to guess the same value for canonical URLs. If it is
unset, queue the notification without a link or do not queue it — do not invent a URL on
the admin domain, which sends a customer to a login screen they have no account for.

## Tests

`tests/Api/` — customer A is refused customer B's tracking **and** served their own (§59c's
shape, with the positive control); a guest order is reachable with its token and returns
404 without it; the token is not derivable from the order id; and the refused-fields list
above is asserted **field by field**, because the failure mode is a presenter that grows a
field later and nobody notices.

`scripts/test-api.sh` — the rate limit, since only the HTTP stage sees a client IP.

## What was built

**§84 is `src/Tracking/` — `TrackingToken`, `TrackingLink`, `TrackingPresenter`, `TrackingService`,
`TrackingController` — one public route, no migration, no table and no new capability**;
`Schema::VERSION` is still 10. §84 predicted correctly that this would be a small section whose
entire risk is authorization, and the code is shaped that way: two of the five classes are pure and
exist so that the token's properties and the disclosure list are arithmetic rather than review.

**The first door is three lines and the prediction held.** `AccountService::order()` gained
`$payload['shipment'] = $this->tracking?->forOwner($orderId)` — placed *after*
`Permissions::assertOwnsOr()`, which is the whole point of where it sits. `null` while an order has
no parcel, which is the ordinary state of a `pending` order rather than an error.

**The second door is the section, and the token is `{order id}.{128 bits of HMAC-SHA256}`** over the
order id and a per-order nonce, keyed on `wp_salt('auth')`, compared with `hash_equals()`. §84
offered "random, or an HMAC over the order id with the site salt" and the HMAC won for a reason §84
does not state: **verifying a MAC needs the message**, so either the order id is in the token or the
token has to be *searched for* — and searching means a `meta_query` on an unauthenticated route,
which is where §82's measurement bites (`wc_get_products()` ignores three of the args it was given
and returns everything). The id in the clear is exactly what WooCommerce's own cart token does,
carrying the customer id in readable base64 with a signature over it, and it discloses nothing
because the response publishes the order number anyway.

**The nonce was not in §84's list and it earns its place twice.** It makes the link **revocable** —
§84's first option, which an HMAC over the order id alone cannot be — with one write, no revocation
list and no effect on any other order. And it means **an order that was never issued a link has no
valid token at all**: without it every order in the shop would be trackable from the salt alone, so
one database dump would expose tracking for the whole order book forever.

**Expiry is 90 days after the parcel reaches a terminal status, and it answers 410 while everything
else answers 404.** The asymmetry is deliberate rather than sloppy: reaching the 410 requires a valid
MAC, so its holder learns nothing they did not already have, while one answer for malformed,
wrong-MAC, unknown-order and revoked is what stops the route telling somebody guessing which half of
the guess was right. **An order with no parcel never expires**, named rather than hidden — nothing has
happened to it yet, and a link that must die today is what revocation is for.

**`TrackingPresenter` filters what it is handed rather than what it is promised, and that is the
design decision in the disclosure list.** It takes a whole shipment row — `metadata` included — and
reads an allowlist of keys out of it, so a future caller passing `Shipment::toArray()` whole still
cannot publish a Yalidine `label`. Filtering the input *contract* instead would have made §84's "the
courier's label URL must never appear, under any circumstance" depend on every future caller reading
a docblock. **The owner view excludes metadata too**: the field is a bearer credential to one
customer's own name, phone and address, and a storefront rendering it would put it in browser history
and in `Referer`.

**The disclosure list is asserted twice — by key and by value — because the key half cannot catch a
rename.** `tests/Unit/TrackingPresenterTest` hands the presenter a hostile row (a label URL, a phone,
a street address, a commune name) and asserts the output's keys are exactly `PUBLIC_FIELDS`;
`tests/Api/tracking.php` does the same over a real order with a real address and then greps the
encoded response for each forbidden value. A presenter emitting `courier_label` passes the first and
fails the second. That is §65's "assert the discrimination, not the outcome" applied to a payload
rather than to a query.

**The destination is a canonical id, and never the address.** `_ac_wilaya_id` — which §59b's checkout
writes, the id the shopper picked from `GET /locations/*` — then a parcel's own recorded `wilaya_id`
(`ManualProvider` writes one, Yalidine writes a *name*), then `null`. Every candidate is resolved
against the §51 dataset, so an id that is not a wilaya becomes `null` rather than reaching the
response. §63's rule reads "a wilaya comes off the shipment, never the address" and this narrows it:
the order now carries one too, and it is the better source because it is what the tariff was quoted
against.

**`estimated_delivery` is in the contract and is null on every install today.** Measured 2026-08-17:
neither `StatusReport` nor `ShipmentResult` has such a field, and neither Yalidine nor ZR Express
publishes one in the responses §56 and §57 recorded. So it is §84's hook rather than a feature, and it
reads through **a two-key allowlist and a date-shape check on the value** — either gate alone would
let a provider publish a label URL on a public page under a plausible name. Named in `docs/API.md` so
nobody builds UI that requires it.

**The rate limit is its own group and deliberately not the failed-login counter.**
`AC_RATE_LIMIT_TRACKING`, 20 a minute per IP, enforced in `TrackingService` on every call before
anything is looked up — because `RateLimitGuard` hooks
`application_password_failed_authentication`, which a token this project defined never touches. That
is §59c's finding for customer logins, arriving again. Sharing the login counter, which
`PasswordResetService` does, would have made **any forwarded tracking link a denial of service
against the shop's own customers signing in**, so `scripts/test-api.sh` asserts the separation: while
tracking is throttled a wrong login must answer 401 rather than 429, and an authenticated read must
still answer 200. The variable is in `.env.example` **and** in both `compose.yaml` services, because
§61 found the whole `AC_RATE_LIMIT_*` group documented, read, and passed through by nothing.

**Notifications came nearly free, as §84 said, and the precondition is `PasswordResetService`'s.**
`NotificationSubscriber` gained a `TrackingLink` and puts `tracking_url` in the shipment context;
`NotificationMessages::shipmentBody()` appends it when it is non-empty. `urlFor()` answers `''` when
§71 has no `store.storefront_url`, so the message is queued **without a link** rather than with one on
the admin domain. §84 asked for "queue without a link or do not queue it" and the first is right here:
a reset with no link is useless, while "your parcel is on its way, tracking number X" is worth sending
on its own — which is why this does not copy the reset's 503.

**Verified 2026-08-17 against this stack.** 87 assertions in `tests/Api/tracking.php`, 77 unit tests
across the two pure classes, and 13 checks in `scripts/test-api.sh` — including the two things only
that stage can see: the 429, and the `X-Tracking-Token` header path that `rest_do_request()` cannot
carry. The header exists because a query string is written to access logs and to `Referer` on every
outbound link from a tracking page; the query parameter stays because an email client's link cannot
set a header, which is where these tokens actually come from.

**One test artefact worth knowing about, because it will bite the next suite.**
`AccountSession::require()` calls `wp_set_current_user()`, and a `tests/Api` file is one PHP process —
so after any `/account/*` call the shopper is still the current user, and a later assertion that a
staff route answers **401** gets **403** instead, for a reason that has nothing to do with the route
under test. `tests/Api/tracking.php` clears it with `wp_set_current_user(0)` and says why.

**Nothing here is an assumption** — `grep -rn ASSUMPTION src/Tracking` is empty.

------------------------------------------------------------------------

# 85. Email Marketing Campaigns

## Where this starts from

`src/Notifications/` exists and is **transactional**: `notify()` writes a row into
`ac_notifications` (migration 010) and `wp algerian-commerce send-notifications` sends it.
`EmailChannel` sends `text/plain` through `wp_mail()`; `MailTransport` configures SMTP;
`wp algerian-commerce mail-check` is the operator's diagnostic;
`PasswordResetService` proved the synchronous path works and named its preconditions.

`ac_manage_marketing` exists and Marketing Manager holds it. Customers have no tags, no
groups and no consent flag.

## A campaign is not a notification, and the difference is the unique key

`ac_notifications` carries `UNIQUE (channel, dedupe_key)`, and that index is the module's
entire guarantee: eight order hooks produce one email, enforced by the database rather than
by a comparison that has to be right in eight places.

**A campaign has the opposite requirement.** One message, thousands of recipients, each
needing its own status, its own attempt count and its own error — so that a drain
interrupted at recipient 3,000 resumes at 3,001 instead of starting again. Squeezing that
into a table designed to collapse duplicates would either break the index or defeat it.

There is a second reason, and it is the one that decides it: **a 5,000-recipient campaign
sharing the transactional queue delays every order confirmation behind it.** A customer
waiting to learn their order was received is not going to wait out a newsletter.

So: two tables, two drains.

``` text
migration 011  ac_campaigns            the message, its template, its audience,
                                       its status, its counts
migration 012  ac_campaign_recipients  one row per recipient — status, attempts,
                                       last_error, sent_at
migration 013  ac_customer_segments    a saved audience definition (see below)

Schema::VERSION → 13, and AC_DB_VERSION must match the highest file on disk;
a unit test enforces that.
```

## Nothing is sent on a request path

`POST /campaigns/{id}/send` resolves the audience, writes the recipient rows, marks the
campaign `sending` and returns. `wp algerian-commerce send-campaigns` drains it, with a
batch size and a **rate cap**.

The rate cap is not a nicety. An SMTP provider will throttle or cut off a sender that
bursts, and the failure mode of a half-sent campaign with no per-recipient record is a
shop that cannot answer "who got this?" and re-sends to everybody. The per-recipient rows
are what make a resume correct; the rate cap is what keeps a resume from being needed.

This is §62b's and §29's argument, and it is not close here.

## "The clients of his choice" — three ways, because they answer different questions

``` text
1. explicit ids      — a list the admin picked in the admin app
2. a saved segment   — a stored filter, reusable, named
3. everyone eligible — subject to consent, below
```

**A segment is a stored query, not a stored membership list.** "Customers in Alger who
ordered in the last 90 days" is a definition, and materialising it into a list means it is
wrong the next day. Storing criteria means the admin edits one thing and every campaign
that uses it follows.

Criteria worth supporting in v1, all of which the existing repositories can already
express or nearly so: total spent above or below a figure, order count, last-order date
range, destination wilaya (from the shipment, per §63 — **never fuzzy-matched from the
address**, which `ShipmentInput` already refuses to do), has or has not ordered a given
product, and registration date.

**The resolved list is frozen into `ac_campaign_recipients` when the send is claimed.** A
segment that grows mid-drain would otherwise mail people the admin never previewed and
never counted — the same argument migration 009 and migration 010 both make for freezing a
payload at queue time, one level up.

**Manual customer tags are deferred, with the trigger named.** A stored query cannot
express "the people who phoned us about the delayed shipment". When somebody asks to mail
exactly that group, `ac_customer_tags` earns its place; until then a query covers the
cases a shop actually has.

## Consent, which is the section's legal and practical core

**A customer who bought something consented to an order confirmation. They did not consent
to a newsletter.** That distinction is the whole difference between §29's module and this
one, and it has to be built in rather than remembered.

- A `marketing_consent` flag per customer, **default false**, set only by an explicit
  action at registration or checkout — an unticked box, never a pre-ticked one, and never
  inferred from having placed an order.
- **The consent filter lives in the repository that resolves an audience, not in the
  caller.** Same reason `AccountService::order()` checks ownership in the service layer: a
  check living only in the admin app is one the second client removes.
- **An unsubscribe link in every campaign email, mandatory, one click, no login.** A
  per-recipient signed token and a public route. Requiring an account to unsubscribe is how
  a shop's domain ends up on a blocklist.
- Unsubscribing is idempotent and is an audit event.
- Transactional mail is **not** gated on this flag. A customer who unsubscribes from
  marketing still gets their order confirmation, and a shop that stops sending those has
  broken something worse than it fixed.

**§54's rule applies to law as much as to APIs: do not write the consent rule from
memory.** Algeria's Law 18-07 on the protection of natural persons in the processing of
personal data governs this, and the implementer reads the current text — or has the client
confirm with counsel — before deciding what an opt-in must look like and how long a
recipient record may be kept. Nothing in this section is a legal opinion; the engineering
requirements above are the floor, not the ceiling.

## Templates — HTML, which reverses a decision `NotificationMessages` made on purpose

That class's docblock is explicit: plain text, deliberately, because "an HTML template is a
rendering concern" and because text is the shape that survives SMS and WhatsApp — the next
two channels §29 names. **That argument is correct and it does not transfer.** A campaign
is email-only. There is no SMS version of a newsletter layout.

So campaigns get HTML templates, and the reversal is stated here rather than made quietly.
Four rules:

- **Multipart, always.** Every campaign sends an HTML part *and* a text part. A text-only
  client shows a blank message otherwise, and HTML-only mail scores worse with spam
  filters. The text part is authored, not stripped from the HTML.
- **A template is a post type, `ac_email_template`.** No migration. §61 made banners and
  FAQs post types on the instruction that "WordPress stores content", and this is the same
  thing: revisions come free, the admin app already knows how to edit one, and the media
  library is already there for images.
- **Placeholders, not code.** `{{customer_name}}`, `{{shop_name}}`, `{{order_number}}`,
  `{{unsubscribe_url}}` — an allowlist of tokens replaced by a **pure** renderer. Never
  `eval`, never a shortcode, never `do_blocks()`. A template is authored by a user, and
  rendering it as code is remote code execution granted to whoever holds
  `ac_manage_marketing`. An unknown token renders empty and is **reported** in the
  response, per §61's malformed-section precedent.
- **`wp_kses` on save with an email-safe allowlist**: no `<script>`, no `<iframe>`, no
  `on*` attributes, no `javascript:` URLs. A template is stored and re-rendered, so a
  stored XSS here fires in the admin's own preview.

`{{unsubscribe_url}}` is **appended automatically when absent** rather than rejected.
Rejecting it is the rule a hurried admin works around by pasting a dead link.

## Two routes that exist because of how this goes wrong

- `POST /campaigns/{id}/test` — send one copy to a named address. The first thing anybody
  does with a template is get it wrong, and the second thing is send it to five thousand
  people.
- `GET /campaigns/{id}/preview` — the rendered HTML for one sample recipient, so the
  merge fields are checked before the test send rather than after it.

## Capability: no new one, and a second one on the send

`ac_manage_marketing` covers drafting, templates and segments — no new capability, matching
§61's media precedent and §63's analytics one.

**Sending additionally requires `ac_manage_customers`.** §63 set this pattern: money in
analytics additionally requires `ac_manage_orders`, the capability that already reads an
order's total. A campaign discloses nothing, but it *reaches* every customer record in the
shop, and the person permitted to mail the customer list should be the person already
trusted with the customer list. Assert it with a positive control beside it, per §65.

Every send is an audit event carrying the campaign id and the recipient **count** — never
the recipient list.

## PII, and the one place this project cannot avoid it

`Marketing\UserData` (§62b) is where PII stops on the way to an ad network: private
constructor, hashes on the way in, so no object en route to Meta holds a customer's email.

**Here it cannot work that way** — an SMTP server needs a real address, and
`ac_campaign_recipients` outlives the request. So state it plainly rather than pretending
otherwise:

- The table holds real addresses. That is unavoidable and it is why the table is covered by
  §66's backup rules and by `backups/.gitignore`.
- Recipient rows are **purged** some fixed period after a campaign completes, keeping the
  aggregate counts on `ac_campaigns` and dropping the addresses.
- **No address reaches a log.** `EmailChannel` already declines to log recipients, with the
  reason — "which customer was emailed about which order" is exactly the PII
  `docs/SECURITY.md` keeps out of logs — and the campaign drain inherits that discipline.

## Deliverability will be the part that actually fails

The SMTP transport works and has never sent volume. Before a first real campaign:

- **SPF, DKIM and DMARC on the sending domain.** A campaign from an unauthenticated domain
  goes to spam, and the shop concludes the feature is broken rather than the DNS.
- A `From` address on the shop's own domain, not a free mailbox provider.
- `wp algerian-commerce mail-check` extended to report on the sending domain's records, so
  the diagnosis is a command rather than a support conversation.

This is a **deployment** prerequisite and belongs in `docs/DEPLOYMENT.md`, which does not
exist yet and is already outstanding from §74–§76. Note the dependency; do not let this
section become the place deployment documentation gets written.

## Refusals

- **No open/click tracking pixels in v1.** A tracking pixel is a per-recipient identifier
  in a URL, which is a consent question and a PII question at once, and it is separable
  from the feature that sends the mail. When it is built, it is built with the consent
  machinery this section establishes and not around it.
- **No automated drip sequences or abandoned-cart flows.** Those are triggered campaigns —
  a scheduler question — and this project has ruled twice (§62b, §63) that WP-Cron is not a
  driver it will depend on. Named as the next step, not folded into this one.
- **No SMS or WhatsApp campaigns.** CLAUDE.md is explicit that those are deliberately not
  implemented, and a campaign engine is not the place to reverse that.
- **No third-party ESP integration** (Mailchimp, Brevo, SendGrid APIs) in this step. If one
  is added later it goes behind `NotificationChannelInterface`, which is already the seam —
  a channel is free to treat `body` as a payload and render its own, which
  `NotificationMessages` anticipated in writing.

## Tests

- **Pure**: the template renderer — every token, an unknown token, a token in an attribute
  position, a template with no `{{unsubscribe_url}}`, a template containing `<script>`.
- **Consent**: a customer without the flag is absent from a resolved audience, **and** the
  same customer with the flag is present. The positive control is the assertion that
  catches a resolver returning an empty list for an unrelated reason.
- **Idempotency**: a drain interrupted and resumed sends each recipient exactly once. This
  is what the per-recipient row is for and it is the test that proves the table earned its
  place.
- **Isolation**: a queued campaign does not delay the transactional queue. `tests/Api/`
  already learned this shape — the notification suite must start from an empty queue
  (commit `17aae18`).
- **Authorization**: `ac_manage_marketing` alone can draft and cannot send; both
  capabilities can send; a Support Agent gets 403 on every route, which the existing
  router sweep in `tests/Api/security.php` will cover once the routes are registered.
- **HTTP stage**: the unsubscribe route with no credential at all, since `rest_do_request()`
  does not exercise the real request path.

------------------------------------------------------------------------

# 86. Build Order and Definition of Done

## Where these sit in §4's sequence

The main roadmap's list ends at 44. These continue it rather than renumbering it, in the
manner of 28b, 32b, 32c and 37b:

``` text
45. Advanced filtering and faceted search      (§82)
46. Product configurators                      (§83)
47. Order tracking                             (§84)
48. Email marketing campaigns                  (§85)
```

**Steps 43 and 44 are separate repositories**, so none of these is blocked by them. But
**45, 46 and 47 each change an endpoint the storefront consumes**, so a storefront built
before them is a storefront that gets revisited. Prefer landing 45–47 before step 44
opens. 48 touches no storefront contract except the unsubscribe route and can go last or
in parallel.

Within the four, the only hard dependency is that **47 is cheapest and lowest-risk** —
the data already exists — so it is a reasonable first slice if the goal is something
shipped. **48 is the largest by a distance**: three migrations, a second queue, a consent
model, a template renderer and a deliverability prerequisite that lives outside this
repository.

## Definition of done, per feature

§29's per-feature loop applies unchanged. On top of it, each of these four is done when:

1. The feature branch is `feat/<area>` and `main` is still deployable.
2. **A "What was built" subsection is written into this document**, in the manner of the
   main roadmap's — what was built, what was measured, what was refused and why, and every
   assumption that is still an assumption marked `ASSUMPTION` in the code so
   `grep -rn ASSUMPTION` finds it.
3. `scripts/test.sh` passes every stage, and the **`http` stage specifically** has been run
   — §82 adds request args, §83 adds a write path, §84 adds a public rate-limited route and
   §85 adds an unauthenticated one, and `rest_do_request()` is blind to authentication
   headers, CORS and rate limiting.
4. `docs/API.md` carries every new route, because `scripts/test-api.sh` → "documented
   contract" asserts that the router and the document agree and will fail otherwise.
5. `docs/SECURITY.md` carries any new rule — the tracking token's construction and its
   disclosure list (§84), the template sanitiser and the consent rule (§85).
6. `docs/TESTING.md` carries any new suite, per §65's map.
7. `CLAUDE.md`'s project-state section is updated. It is the file that tells the next
   session what exists, and it is already behind — it still records password reset as
   deliberately not built, two commits after `58830a9` and `e95537d` built and merged it.

## Standing rules, restated because these four are where they get broken

- **Never modify WordPress or WooCommerce core, and never fork their data models.** §82 is
  the section most likely to violate this; measure the Store API first.
- **Every number arriving from a browser is a request, never a fact.** §83 is where this
  gets broken next.
- **Every private route declares a real `permission_callback`**, and every negative test
  carries a positive control.
- **Nothing that can be slow happens on a request path.** §85 has no excuse; §62b, §63 and
  §29 all settled it.
- **Add a table only when something needs a query the existing storage cannot serve**, and
  when a table is refused, name the trigger for revisiting it.
- **Ask before destructive operations.** Migrations 011–013 add tables and never require
  deleting data; there is no `down()`.
