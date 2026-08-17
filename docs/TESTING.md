# Testing Strategy

Roadmap §65, §69. Source: `docs/PLAN.md` §45, `docs/SECURITY.md` → "Security tests to write".

This document is the map from what §65 asks for to what actually runs, written after walking the two
against each other. Where a line was already covered under a different name, that is recorded rather than
duplicated — a second set of assertions about the same behaviour is a second thing to keep in step, and
the copy is the one that rots. Where a line was genuinely uncovered, the test was written and is named
here. Where a line does not apply to this architecture, the argument is on the record and the rule-out
lives in `docs/SECURITY.md` beside the rule it qualifies.

## How to run it

```bash
scripts/test.sh              # every stage
scripts/test.sh unit         # one stage
```

Five stages, each present because the one before it is blind to something:

| Stage | What it can see | What it cannot |
|---|---|---|
| `versions` | the running stack against `compose.yaml`'s pins (§68) | anything about the code |
| `syntax` | `php -l` over `src/`, `integrations/`, `migrations/`, `tests/` | whether any of it is correct |
| `unit` | pure logic — calculations, validation, mappings, policies | WordPress, WooCommerce, the database |
| `rest` | routing, args, permissions, services against a real database | the `Authorization` header, rate limiting, `rest_pre_serve_request` |
| `http` | authentication, rate limiting, CORS, download headers, real uploads | nothing above it — it is the slowest and runs last |

**The `rest` stage is structurally blind to three things**, and this is why the `http` stage is not
redundant. `rest_do_request()` never parses an `Authorization` header, so no in-process test can observe
authentication or rate limiting — a brute-force guard that let every attempt through once shipped with a
completely green unit suite for exactly that reason. It never runs `rest_pre_serve_request`, so CORS
headers and §64's file downloads are invisible to it. And `wp_handle_upload()` ends in
`move_uploaded_file()`, which by design fails for anything that did not arrive over a real POST, so a
real upload can only happen in `http`.

## §65's five categories

### Unit — 1,370 tests, 3,030 assertions, 81 files

| §65 asks for | Covered by |
|---|---|
| price calculations | `RateResolverTest`, `RevenueReportTest`, `MetricsTest`, `ProductInputTest` |
| shipping calculations | `RateResolverTest`, `ShippingRuleInputTest`, `DestinationMatcherTest` |
| COD risk | `CodStateTest`, `CodStatusTest`, `CodStatisticsTest`, `CodAttemptInputTest` |
| provider mapping | `YalidineStatusMapTest`, `YalidineParcelTest`, `ZRExpressProviderTest`, `ChargilyProviderTest`, `MetaProviderTest`, `CourierWebhookTest` |
| validation | every `*InputTest` — 14 of them — plus `ProductFiltersTest` for the read side |
| permissions | `CapabilitiesTest`, `IdentityTest` |

Everything here is pure: no WordPress, no database, no clock. That is a deliberate constraint rather than
an accident — `AnalyticsRange` takes the current instant as an argument so that "what does `7d` mean at a
timezone boundary" is decidable in a test, and `AnalyticsCache::key()` is pure so that "a payload with
money in it never shares a key with one without" is a unit test rather than an argument.

### Integration — 24 suites in `tests/Api/`

§65's four words (WordPress, WooCommerce, custom plugin, database) are not four separate suites here.
Every `tests/Api/` suite is an integration test by that definition: it runs inside a booted WordPress,
against WooCommerce's real CRUD and a real MySQL, through the plugin's own routes. Splitting them by
which layer they touch would produce four suites that all boot the same stack.

`MigrationPlanTest` covers the schema layer separately, and asserts the thing that actually goes wrong:
`Schema::VERSION` equals the highest migration on disk, so a migration cannot ship without the version
bump that makes installs run it.

### API — the eight lines, against one resource

§65 lists success, bad input, unauthenticated, unauthorized, not found, duplicate, pagination, boundary
values. Every suite covers its own routes against most of these; `tests/Api/products.php` covers all
eight explicitly, in order, and is the reference shape for the next one.

**Writing it was the largest finding of this audit.** Products are §47 — the first commerce module, built
before `tests/Api/` was a convention — and every module from §49 onward got a suite while products kept
only the incidental coverage it picked up from `inventory.php` (four calls) and `seo.php` (three).
Nothing drove product CRUD hard, and the first walkthrough that did found a 500 (see "What the audit
found" below).

**§82 extended it rather than adding a suite**, because filtering is `GET /products` and a second file
asserting things about the same route is a second place to forget. The section is `tests/Api/products.php`
→ "§82 filtering and faceted search", and it builds its **own** catalogue: two global attributes, five
terms, six products with known prices, deleted again at the end. That is not the usual preference for
§67's seed — the seeded shop has nothing to facet. Its two attribute-bearing products carry *custom*
attributes ("Taille", "Finition" as plain strings on one product each) and this install registers no
global attribute taxonomies at all, so a suite written against the seed would assert counts of zero and
pass whatever the code did. Fixture counts are exact and every filter assertion is a *narrowing* of one
positive control: "the unfiltered fixture is six products".

Two assertions in it are the ones that catch a wrong implementation rather than a broken one.
**"the selected facet lifts its OWN filter (2/2/2)"** is §82's rule — with `matière = laine` selected the
matière facet must still report cuivre and argent, or every sibling reads zero and the shopper's only way
out is the back button. **"every OTHER facet still narrows by it"** is the half a single-attribute fixture
cannot see, and it is why the fixture carries two attributes rather than one; a naive implementation that
lifts *all* attribute filters passes the first assertion and fails this one. The same pair is asserted for
price, which lifts its own band and narrows by everything else.

Writing it found a real trap, recorded here because the next fixture will hit it too: **an attribute
created in the same process cannot be counted.** `ProductCollectionData` skips any taxonomy failing
`taxonomy_is_product_attribute()`, which tests `taxonomy_exists()` *and* membership of the
`$wc_product_attributes` global that `WC_Post_Types::register_taxonomies()` fills on `init`. So
`wc_create_attribute()` plus `register_taxonomy()` gives an attribute that is registered, queryable and
invisible to the facet counter — which answers 200 with an empty list. A live shop never sees it, because
the attribute is created by one request and counted by a later one; only a fixture inside one process can.
The suite fills the global itself, which is what the next request would do.

`tests/Api/cart.php` (§59b) is the other suite written to this shape, and it carries the module's
central security property: **a forged cart token opens an empty cart rather than somebody else's.**
Writing it found that the property was untestable as first built — `CartSession` guarded on "already
loaded", which is right inside one HTTP request and wrong inside a suite that makes forty
`rest_do_request()` calls in one process, so the assertion passed against the *previous caller's* cart.
It now keys on the token. That is the second time in this document a control turned a passing assertion
into a real one.

### Provider — Yalidine, ZR Express, Chargily

| Provider | Sandbox | State |
|---|---|---|
| Yalidine | none — no merchant account | Verified against the **live** API 2026-08-14 with another project's credentials. Unproven parts carry `ASSUMPTION (unverified)`. |
| ZR Express | none | Verified against the **live** API 2026-08-15. |
| Chargily | yes — test keys are free | Verified against the **live test** API 2026-08-15. `grep -rn ASSUMPTION integrations/Chargily` is empty. |
| Meta CAPI | a dataset would need an ad account | **Never run against a live dataset.** `grep -rn ASSUMPTION integrations/Meta src/Marketing`. |

Unit tests for all four run against **recorded** responses (`tests/Support/RecordedHttpClient`), so the
suite never touches the network. That is what makes them runnable; it is not what makes them right, and
the live-run dates above are the part that does.

**No courier webhook has ever been received.** The outbound APIs were verified on the dates above; the
inbound half has not run. Every webhook test payload is *constructed* from published documentation, which
proves each verifier matches the scheme as written and cannot prove the sender implements it. See
`docs/SECURITY.md` → "A verifier written from a specification is not a verified verifier". The standing
check is `ac_webhook_events`: a row with provider `yalidine` or `zr-express` means one genuinely arrived.

### Security — §65's nine, one by one

| §65 asks for | Where it is answered |
|---|---|
| SQL injection | `tests/Unit/SqlSafetyTest` (static, every `$wpdb` call site) + `tests/Api/security.php` (payloads through every request-controlled arg) |
| XSS | `tests/Api/security.php`, `CsvWriterTest`, `UploadPolicyTest`, `tests/Api/seo.php` |
| CSRF | **ruled out** — `docs/SECURITY.md` → "CSRF"; the properties it rests on are asserted in `scripts/test-api.sh` |
| IDOR | `tests/Api/security.php` — type confusion. The owner dimension does not exist yet; see below. |
| privilege escalation | `tests/Api/security.php` (route sweep + write payloads), `CapabilitiesTest`, `tests/Api/analytics.php` (the money split) |
| rate limits | `scripts/test-api.sh` — the only stage that can see them — plus `RateLimiterTest`, `RateLimitTest` |
| file upload abuse | `UploadPolicyTest`, `tests/Api/media.php`, `scripts/test-api.sh` (real upload, re-encode, non-executable directory) |
| webhook forgery | `tests/Api/shipping-webhooks.php`, `tests/Api/payments.php`, `CourierWebhookTest` |
| replay | the same three — the idempotency claim is a write-once insert whose duplicate-key failure *is* the answer |

#### SQL injection, in two halves

The static half is the durable one. `SqlSafetyTest` walks every `$wpdb->query/get_results/get_row/
get_var/get_col` call site in `src/`, `integrations/` and `migrations/` — 60 of them — and requires the
SQL to be either prepared or free of variables beyond an approved table expression. It also asserts that
no table name is built from anything but `$wpdb->prefix` plus a literal or
`OrderUtil::get_table_for_orders()`, because the first check is worthless without the second. It is a
guard, not a proof: it follows an assignment one step and it cannot see whether the arguments *to*
`prepare()` are right. It proves it can still fail, against a fixture of four ways the real mistake gets
written.

The behavioural half is in `tests/Api/security.php`, and its assertion is deliberately not "HTTP 200, no
crash" — a concatenated `WHERE` returns 200 too. **A payload must not widen a result set**: each one is
compared against a benign nonsense value and both must match nothing. That is the assertion a
string-concatenated query actually fails.

`Analytics/AnalyticsRepository` is the widest SQL surface in the codebase and the newest, so it is
covered specifically. Its request-controlled inputs are only the window (`range`, `date_from`,
`date_to`); the status list and currency come from constants and the shop's own option, never from a
caller. Every payload sent at the window is refused at validation before a query runs.

#### XSS

**This API answers `application/json` and never HTML**, with `X-Content-Type-Options: nosniff`, so there
is no context here for a script to execute in. The escaping that matters happens in the Next.js
storefront, which is a different repository. What this side owes is that a payload is stored and returned
as *data*, and that no route can be talked into emitting it somewhere that runs it.

Two places on this side genuinely do render into an executing context, and both are covered where they
live: **a CSV cell is a formula a spreadsheet will run** (`CsvWriter` escapes exactly as
`WC_CSV_Exporter::escape_data()` does, and `tests/Api/import-export.php` asserts the two still agree
after a WooCommerce upgrade), and **an image can be script** (`UploadPolicy` refuses SVG outright and
every accepted image is re-encoded from decoded pixels).

#### IDOR — the finding is that the dimension does not exist yet

`Permissions::assertOwnsOr()` exists and has **no call sites**. That is not an oversight:
every route in this API carries a management capability, so a caller who may read one order may read them
all, and `OrderService` records why — a shopper reading their own order needs the customer session
strategy deferred in roadmap §44. Writing "customer A cannot read customer B's order" today would be
testing a route that does not exist.

What *is* reachable is the other half of the same bug class. WordPress keeps posts, products, orders,
attachments and refunds in one id space, so `GET /media/{id}` with a product id is a real request that a
repository reading `get_post()` without a type check would answer. All thirteen such crossings are
asserted to 404 in `tests/Api/security.php`.

**The owner-scoped tests belong beside those, and they now have a step to belong to: roadmap 32c
(§59c), customer accounts and sessions.** Order history is where the first real IDOR lands — a shopper
editing `/orders/123` to `/orders/124`. Write them in the shape "customer A is refused customer B's
order **and** customer A is served their own": a refusal on its own proves only that the route is
broken, which is this document's standing rule about controls applied to the case that will most need
it.

#### Privilege escalation — the sweep is the new part

Each feature's own suite asserts a 403 for the wrong capability on its own routes. What none of them can
catch is a route added *later* with the wrong guard, because they do not know it exists. So
`tests/Api/security.php` reads the router and does two things no per-feature suite can:

- **Every route declares a guard.** The only `__return_true` routes are a named allowlist — `/health`,
  the namespace index, `/locations/*` and `/webhooks/*` — each justified where it is written. This makes
  `docs/SECURITY.md`'s "registering a route without a real `permission_callback` is a bug" executable.
- **One under-privileged credential against every GET route at once.** A Support Agent (holding only
  `ac_manage_customers` and `ac_view_analytics`) must reach nothing else. The sweep is GET-only on
  purpose: a sweep that posted to every route would, on the day the guard is broken, perform the write it
  is trying to prove impossible.

The sweep carries its own control, without which it means nothing: **where an administrator is served
200, the Support Agent must be refused 403** — not 404, and not a validation error that would have
happened to anybody. Writing that control found two routes (`/inventory/lookup`, `/shipping/rates`) that
answer 400 to everyone because they require query arguments, which would have made three of the sweep's
refusals prove nothing at all.

## What the audit found

**A trashed product's SKU produced a 500.** `wc_get_product_id_by_sku()` excludes `post_status = 'trash'`,
but WooCommerce's product data store does not — `wc_product_meta_lookup` keeps the trashed row, and
inserting against it throws from inside `$product->save()`. So the conflict check answered "free" for a
SKU the write was about to refuse, and an admin who trashed a product and re-created it got
`500 internal_error` every time. `ProductRepository::skuExists()` now looks in the trash too, through
`wc_get_products()` rather than SQL against WooCommerce's table, and the 409 names the trashed product
rather than saying "already in use" about something that is no longer in the catalogue. Regression test:
`tests/Api/products.php` → "the SKU the trash keeps".

**`/products` had no API suite.** Covered above; `tests/Api/products.php` is the fix, and the 500 above is
what the gap was hiding.

**Two of §65's categories were already covered under other names, and are recorded rather than
duplicated.** "An unknown `orderby` is refused" in `tests/Api/orders.php` and `customers.php` is an
ORDER BY injection test — an `ORDER BY` cannot be parameterised, so every repository answers it with an
allowlist and the route with an `enum`. And every `*InputTest`'s "unknown fields are rejected" is the
mass-assignment half of privilege escalation; `CustomerInputTest` is the sharp end, because a WordPress
user carries a role, a capability map and a password hash.

**CSRF does not apply, and the argument is now on the record** rather than assumed. See
`docs/SECURITY.md` → "CSRF".

**Nothing was found wrong with the SQL.** Every call site was already prepared, every table name already
built from `$wpdb->prefix`, and every injection payload already treated as data. The tests were written
so that the next repository is checked too, not because this one failed.

## §68 — version pinning

The tested versions live in `compose.yaml` and nowhere else. Five of §68's six components are pinned by
`image:` tags; **WooCommerce was the sixth and nothing pinned it**, because it is a plugin installed into
a volume that is not version-controlled, so `wp plugin install woocommerce` took whatever was current.
It is now declared in the same file under `x-tested-versions`.

`scripts/test.sh versions` reads those pins out of `compose.yaml` and compares them against the stack that
is actually running. That is deliberately a check rather than a table: a table in this document would be a
second copy of numbers that already exist, and the copy is what drifts. Verified 2026-08-16 —
WordPress 7.0.4, PHP 8.4, MySQL 8.4.11, WP-CLI 2.12.0, WooCommerce 11.0.1, all matching.

The upgrade drill is §68's own: **branch → upgrade one thing → run every stage → inspect logs → test
integrations → review → merge.** Two traps specific to this stack are in `CLAUDE.md` → Environment and
must be read before touching an image tag: bumping the `wordpress` tag does not upgrade an existing
install, and a major MySQL upgrade has no in-place downgrade.

## §69 — API testing before Next.js

**Partly satisfied when the audit started; satisfied now.** The `tests/Api` suites already covered
product, order and customer behaviour in depth, and `scripts/test-api.sh` already covered authentication,
rate limiting, media uploads and §64's exports over real HTTP. What nothing covered was a **write** over
real HTTP: every `tests/Api` suite reaches the router through `rest_do_request()`, so no test had ever
sent a POST or PATCH through Apache, the permalink rewrite and an `Authorization` header, or read our
envelope back off the wire.

`scripts/test-api.sh` → "CRUD over HTTP" is that walkthrough, and it is what §69 asks for in the order
§69 asks for it: product create, read, update, duplicate-SKU conflict, trash, force-delete; order and
customer queries; pagination and a bad argument; and permissions with a second, lower-privileged
credential. It is deliberately thin on business rules, which have their own suites — what it proves is the
transport, not the logic. It found the trashed-SKU 500 on its first run.

Order writes are deliberately not in it: an order write leaves stock movements and audit rows behind in a
database this script does not own, and `tests/Api/orders.php` already drives the whole lifecycle
in-process.

## Conventions for the next suite

- **A test that cannot fail is worse than no test.** Assert the discrimination, not the outcome: a
  payload compared against a benign value, an upload fetched to a file before grepping it, a floor on how
  many things a scanner found. Several assertions in this codebase have already passed against an empty
  body.
- **Every refusal needs a control.** A 403 and an unreachable route look identical from outside.
- **Cover the category where it lives.** A new inbound endpoint's forgery test belongs in that endpoint's
  suite, not in `security.php`; `security.php` holds what is about the API as a whole.
- **Suites clean up after themselves**, trash included, so a second run starts where the first did.

## The seed suite, and why a data loader has a `tests/Api` suite

`tests/Api/seed.php` (roadmap §67) registers no routes and tests none. It is here because the rules that
matter about the fixtures are the ones a unit test cannot reach.

`tests/Unit/SeedDatasetTest` covers the validator against synthetic input — 61 tests over category
references, SKU uniqueness across the product/variation namespace, variation attribute matching, legal
status transitions, and PLAN §46's reserved-domain rule. The suite points the same validator at the
**shipped** `data/seed/*.json`, which is the half that catches a fixture edited after the validator was
written, and then asserts what the seeder produced: every SKU resolves, the variable product carries its
variations and has a derived price, every shopper is a `customer` account, an order reached `cancelled`
and another `refunded` (neither is a creatable status, so reaching them proves the second transition
ran), every order is priced above zero, and a seeded product has rows in `ac_inventory_movements`.

Three of its assertions are there because the thing they check has no other witness.

**"a dry run writes nothing"** counts products either side of the call. A dry run that quietly wrote
would look identical in every other assertion.

**The notification pair** deletes one seeded order first, so the re-run genuinely creates one — otherwise
"nothing was queued" would be asserted against a run that wrote nothing at all, which is a test that
passes forever. It then asserts both doors: the queue returns to its previous size by default, and
`--keep-notifications` actually keeps rows.

**"wp_mail is not left short-circuited"** is the one that guards the rest of the process. The seeder
filters `pre_wp_mail` for the duration of its writes, because WooCommerce's own transactional mail sends
*synchronously* inside the status transition and there is nothing to discard afterwards. A seeder that
forgot to remove that filter would silence every later suite's mail and every real send in the same
request, and nothing else would notice.
