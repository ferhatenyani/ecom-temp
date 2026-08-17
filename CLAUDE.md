# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

Roadmap §1–§53, §55–§69 and §59b–§59d are implemented and `main` is deployable. The plugin at
[wp-content/plugins/algerian-commerce-core/](wp-content/plugins/algerian-commerce-core/) holds real code — bootstrap,
REST foundation, migrations, RBAC, audit trail, products, inventory, orders, COD, shipping, Yalidine,
ZR Express, the payment abstraction, Chargily, the CMS, media, SEO, the marketing event layer, analytics,
import/export, the cart and checkout, shopper accounts, coupons, notifications and the development seed — and its
[README](wp-content/plugins/algerian-commerce-core/README.md) is the reference for what exists and why each decision
went the way it did. Read it before extending a module. [scripts/](scripts/) is complete: `setup.sh`, `reset.sh`,
`seed.sh`, `health.sh`, `backup.sh`, `restore.sh`, `test.sh`, `test-api.sh`. [backups/](backups/) holds only its own
`.gitignore` — a backup carries `.env` and every customer record, so nothing in there is ever committed.

The single source of truth for what to build is
[ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md](ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md) (81 sections). Read the
relevant section before implementing a feature; section 4 gives the exact implementation order, section 3 the
milestones, and section 29 the per-feature loop. **§50–§53, §55–§69 and §59b–§59d are done** — orders, notes,
timeline, customers, the Algerian geography mechanism, cash on delivery, the shipping abstraction, §14's
shipping rules, the Yalidine and ZR Express adapters, the security review, the payment abstraction and
Chargily with PLAN §19's transaction table, §60's three webhooks, §61's CMS and media endpoints,
§62's SEO, §62b's marketing event layer, §63's analytics, §64's import/export, §65's testing audit,
§68's version pinning, §69's API walkthrough, §59b's cart and checkout, §59c's shopper
accounts, §59d's coupons and notifications, §66's automation scripts and §67's seed data.
**§4's build order is now complete through step 42 (backup/recovery); steps 43 and 44 are the Next.js
admin and storefront, which are separate repositories.** §70's contract is written and checked, §71's
client configuration and §73's provisioning flow are built, and §72 needed no work — `Config::FLAGS`
already declares all nine and `GET /settings` reports them. What is left in *this* repository is
`docs/DEPLOYMENT.md` and §74–§76's production separation. **SMS and WhatsApp are deliberately not
implemented** — no messaging, notifications or automations — beyond the flags that would gate them.

**Password reset exists, and the SMTP settings finally reach WordPress** — PLAN §29/§30, the one thing
§59c deferred. Two gaps were closed together because neither was useful alone. **`SMTP_HOST`,
`SMTP_USERNAME` and `SMTP_PASSWORD` were documented in `.env.example`, read by `Config`, and passed to
nothing** — not even forwarded into the containers by `compose.yaml`, so `getenv()` returned nothing
whatever anyone put in the file. That is §61's whole `AC_RATE_LIMIT_*` finding again.
`Notifications\MailTransport` is one `phpmailer_init` hook registered from the bootstrap, because a
transport nobody instantiated configures nothing and fails identically to a wrong password. `SMTP_PORT`
and `SMTP_ENCRYPTION` are new and separate, because 587/STARTTLS and 465/implicit-TLS are both common and
guessing one from the other yields a connection that succeeds and then hangs; an unrecognised value falls
back to **`tls`, never `none`**, so a typo cannot send credentials in the clear.
`wp algerian-commerce mail-check [--to=]` is the operator's check, and prints whether the password is set
rather than what it is.

**§59c's argument against password reset is answered rather than dropped.** It read: *a reset link
generated but never sent is worse than an absent feature, because it looks like one that works.* So both
calls verify the shop can send **and** knows its storefront address **before** minting a token, and
answer 503 naming which half is missing — `mail_not_configured` or `storefront_url_not_set` (§71 stores
that URL; §62 refused to guess the same value for canonical URLs). Neither precondition is about the
caller, so neither leaks. **It does not use the notification queue**: that drains every five minutes, and
a shopper staring at "check your email" will request another rather than wait. Four rules, each because
the obvious implementation leaks something — a known, an unknown and a **staff** address answer
identically (asserted as identical responses, not matching wording); a staff account cannot be reset
through the customer door, gated by the same `AccountSession::isShopper()` that refuses a staff login, or
§44's Application-Password rule has a second door past it; the link's destination is configuration and
there is **no `redirect_to`**, which is how reset-link poisoning works; and a successful reset issues
**no session**, because a token that arrived by email is weaker evidence than a password — it reports
`sessions_revoked: true` instead. Tokens are WordPress's own, so hashing, the 24-hour expiry and single
use come free, and expired/wrong/already-used all answer the same way.

**§71 is `src/Settings/` — `GET`/`PATCH /settings`, no migration, one option — and the design is that
almost nothing is stored.** §71 and PLAN §48 both describe an outcome ("configuration rather than
forks"); the mechanism is **one document assembled from the systems that already own each value**, not
one table copying them. Before it, configuring a client meant knowing the store name is a WordPress
option, the currency a WooCommerce one, the courier settings four `ac_*_settings` rows and the flags
environment variables — four systems, no list, nothing to say you had missed one. `ac_client_settings`
holds only what has no owner: contact details, the trade-register block (`rc`, `nif`, `nis`, `ai`),
social links, the logo, and the storefront URL this backend cannot derive (§62's canonical argument).
`store.name` is written *through* to WordPress. **`tests/Api/settings.php` renames `blogname` behind the
API's back, asserts the document followed, and asserts the option holds no name of its own** — a copy
passes the first and fails the second. §63's argument against a rollup and §68's against a version table,
a third time.

**The refusals are the rest of it**, each by name with its reason — the `CustomerInput` device.
`currency`, because WooCommerce records it *per order* so a change splits the order book rather than
converting it. `features`, because `ENABLE_*` decides which providers register, once, at bootstrap.
Secrets, because an options row is readable by any plugin and survives every database dump. `providers`
is read-only and reports what actually **registered**, which follows from flags *and* credentials — a
flag that is on with no key produces a provider that never loads, and this is the only place that gap
shows. **`ac_manage_settings` gets its first call site**, unused since §45's matrix, the state
`assertOwnsOr()` was in before §59c; it stays Super Admin's, and an Admin holding the other ten
management capabilities is refused, asserted beside a positive control.

**§73 is now a script except its last step, and `client.json` was the missing one.** Clone, `.env`,
**set client configuration**, `up`, `setup.sh`, configure integrations, deploy — everything but the third
was already a command. `setup.sh` applies `client.json` when present, over **STDIN** because the file
sits at the repository root and only the plugin directory is mounted. A missing file is fine; a present
one that fails to apply **stops the setup**, because a shop deployed with somebody else's trade register
is worse than one with none. It carries no secrets and is gitignored anyway — it is one client's details
in what is meant to be the template. JSON has no comments, so keys beginning `_` are ignored rather than
rejected. Verified 2026-08-17 both ways: a valid file applies and continues, an invalid one prints the
per-field reason and stops.

**§70 is [docs/API.md](docs/API.md), and it is a check rather than a document.** The rule §70 states was
already obeyed — the API is `/algerian-commerce/v1`, nothing depends on `/wp/v2` or `/wc/v3`, `Cors`
serves an allowlist. What was missing was the contract *written down*: the envelope, the error codes, the
three credentials, CORS, rate limits, pagination, every route with its capability, a checkout walkthrough,
and the list of what bites people. **The three credentials lead it, because confusing them is the
integration mistake it exists to prevent** — an Application Password over `Authorization: Basic`, a
shopper session over `X-Customer-Token`, a cart token over `Cart-Token`, none interchangeable.

**A reference that drifts from the router is worse than none**, so `scripts/test-api.sh` → "documented
contract" fetches the namespace index over HTTP, normalises `(?P<id>\d+)` to `{id}`, and asserts every
registered route appears in the document — with a floor of 80, because a grep matching nothing reports a
fully documented API exactly as a fully documented one does. §68's argument a second time. **It found two
defects on its first run**: the CMS page route is `{slug}` and the document wrote `{path}`, and
`/inventory/lookup` had been written with its query string attached so it matched nothing. The check runs
in `test-api.sh` rather than a `tests/Api` suite because `docs/` is not inside the plugin and only the
plugin is mounted into the containers. It is one-directional on purpose: a documented route with no
registration is legitimate, since the courier webhooks are 404 until their secrets are set.

**§66 is six scripts and §67 is `src/Seed/` plus `data/seed/`, built together because `seed.sh` is
§67's delivery mechanism.** No migration and no table — `Schema::VERSION` is still 10.

**The sixth script is `restore.sh`, which §66 does not list, and it is the reason the list works.** "A
backup is not valid until a restore has been tested" is an instruction to write a second script, not a
note to be careful. `scripts/restore.sh --verify <dir>` starts a throwaway MySQL of the pinned version,
restores the dump into it and compares every table's `COUNT(*)` against the manifest each backup carries
— the running stack is untouched, which is what makes the drill something that gets *done*. Run
2026-08-16: **62 tables, every count matching**. It failed twice first, and both are worth knowing:
`mysqladmin ping` answers from the entrypoint's *temporary* server before root has a password, so the
readiness gate must be a real query; and `docker exec -i` inside the comparison loop **ate the manifest
from stdin**, comparing one table and reporting success in green — so the script now refuses to pass when
it compared nothing. `backup.sh` dumps from inside `db` (never `wp db export`, which is broken here for
the `caching_sha2_password` reason), takes uploads out of the volume with `docker compose cp`, and writes
`.env` at 0600 into a directory at 0700 under a `backups/.gitignore` that ignores everything but itself.

**`setup.sh` pays the waiting list this file kept.** WooCommerce installs at `x-tested-versions`'s pinned
version with `--force`; `woocommerce_currency` is set to `DZD`; Akismet and Hello Dolly are deleted; the
`ac_*` roles are installed before anything writes, or seeding fails as a wall of 403s that reads like a
broken seeder. It also sets pretty permalinks, enables HPOS, and generates the two database passwords
into a fresh `.env` — `compose.yaml` interpolates them, so a blank value creates a MySQL install with no
password rather than an error. Every step is idempotent; re-run it after `docker compose down`.
**`health.sh` checks only what `GET /health` cannot see** — the container layer (`wpcli` is
run-on-demand, so "can it run `wp`" is not "is it in `ps`") and the transport (answering
`rest_do_request()` and answering Apache on the published port are different claims) — then asks the
endpoint for the other five and reports its verdict per check. It does **not** check versions;
`scripts/test.sh versions` is §68's record and a second copy would drift. **`reset.sh` is destructive in
four ways and none is the default**: `down -v` is never reached without an explicit answer, it refuses
unless `WP_ENVIRONMENT_TYPE` is `local`/`development`, it prints counts read from the database it is
about to drop, and the confirmation is the typed word `DESTROY` — `y` is muscle memory.

**Every seeded row goes through a service; not one through `$wpdb`.** That is §64's rule ("an import must
not be a back door around `ac_inventory_movements`") applied to the friendlier-sounding case. A seed that
bypassed `ProductService` could build a shop the API would refuse — a duplicate SKU, a variation whose
attribute the parent does not offer — and every test written against it would test an unreachable state.
Two consequences: it **runs as an administrator**, because services assert capabilities and a seeder with
no identity would have to bypass the check; and it is idempotent on natural keys — SKU, email, coupon
code — with orders keyed by the `ac_seed_orders` **option** rather than a marker on the order, because
`OrderRepository` is the only file that touches one. **Geography is not seeded**: §51 already ships 69
wilayas and 1,541 communes, a courier matches communes *by name*, and `seed.sh` calls `import-algeria`
first rather than inventing a second Algiers.

**`SeedDataset` is pure, and it exists for one rule that has no other home.** Everything else it checks
fails loudly when the seeder runs; **PLAN §46's "never use real customer data" does not** — a fixture
carrying a colleague's real address seeds perfectly and mails them the first time somebody drains the
queue. So every seeded email must sit on a domain RFC 6761 or RFC 2606 reserves, checked exactly enough
that `badexample.com` fails where `mail.example.com` passes.

**Seeding orders touches two mailers and they needed opposite answers.** `ac_notifications` is deferred,
so the seeder notes the highest id and drops what it added (`--keep-notifications` opts out). **WooCommerce's
own mailer is neither deferred nor ours**: `WC_Emails` sends *synchronously* inside
`woocommerce_order_status_changed`, and the first run here attempted **25 sends** — visible only as
`sendmail: can't connect` because this machine has no MTA. On a machine with one, a fictional order would
have mailed the shop's real admin address. It is short-circuited through `pre_wp_mail` for the duration
of the writes, and `--keep-notifications` deliberately does not re-open it: a queue can be inspected and
drained on purpose, a synchronous send cannot. `tests/Api/seed.php` asserts the filter is removed again,
because a seeder that left it would silence every later suite in the same process.

**One limitation, named rather than worked around: every seeded order is dated now**, because this API
accepts no order date, so §63's time series shows one day. Adding `date_created` to `OrderInput` is a
real decision about §50 — an admin who can backdate an order can move revenue between months — not
something a fixture loader gets to make.

**§59b is `src/Cart/` — seven cart routes and two checkout routes, no migration and no table**
(`Schema::VERSION` is still 9). The cart *is* `WC_Cart`: WooCommerce does line totals, tax, rounding,
stock and §21's coupon rules, and this module owns only the boundary. Store API's **cart** was reusable
and its **shipping and checkout** halves were not — measured 2026-08-16, this install has zero
WooCommerce payment gateways and zero shipping zones, because §58 put payment behind
`PaymentProviderInterface` and §14 replaced zones with `ac_shipping_rates`, so `wc/store/v1/checkout`
cannot take a payment here and its `shipping_rates` is always empty.

**A cart needs a session and there are no cookies, so `StoreApi\SessionHandler` is swapped in** — the
cookie-free, signed-token handler WooCommerce ships for its own Store API, which keeps the token's
signature, expiry and secret WooCommerce's problem rather than a credential this project mints. Three
things that cost real time and are all load-bearing. **`wc_load_cart()` does not populate the cart**:
`get_cart_from_session()` is hooked to `wp_loaded`, which fired long before any REST route, so you get a
valid session holding the shopper's items beside an *empty cart* and no error — and neither
`WC_Cart::get_cart()` (guarded by `did_action`) nor a fresh `new WC_Cart()` fixes it; constructing
`WC_Cart_Session` directly is the unguarded public path and it is idempotent. **`WC_Cart::needs_shipping()`
is permanently false here**, because it returns false when `wc_get_shipping_method_count()` is zero and
§14 uses no zones — a cart of rugs reported `needs_shipping: false`, checkout skipped the §14 quote, and
an order was created short by the whole delivery charge; `CartService::needsShipping()` asks the products
instead. And **`CartSession::load()` keys on the token, not on "already loaded"** — the load-once guard
made "a forged token opens an empty cart" pass *against the previous caller's cart* in the in-process
suite, leaving the module's central security property untested.

**`/cart` and `/checkout` are public, and the token is the owner.** No capability could gate them: a
shopper has no WordPress account and §44 forbids giving one an Application Password, so requiring auth
would mean the storefront proxying every quantity change with an admin credential. The token is signed
with the site salt, expires in 48 hours, and a forged one opens an *empty* cart rather than somebody
else's. **`LineInput` accepts `product_id`, `variation_id` and `quantity` and refuses `price`,
`line_total`, `subtotal`, `total`, `discount` and `currency` by name with a reason** — the
`CustomerInput` device. Shipping comes from `RateResolver` against the destination and the free-shipping
threshold is compared against the *cart's* subtotal, because a caller that could state its own subtotal
could claim to have crossed one. **Checkout does not take the money**: it creates a `pending` order and
returns `next: {action: create_payment, endpoint: /orders/{id}/payments}`, §58's existing route, because
a payment that fails must not orphan an order that succeeded. `RateResolver` is used directly rather
than `ShippingService::rates()`, which asserts `ac_manage_shipping` — a shopper being quoted a delivery
price is not reading the shop's shipping configuration.

**§59c is `src/Account/` — seven routes, no migration and no table**; sessions live in WordPress's own
`session_tokens` user meta. **The IDOR §65 named is closed, and `Permissions::assertOwnsOr()` finally has
call sites** — written in §50, unused until now. `/account/orders` has no `customer_id` parameter to
redirect it (the id comes from the session), and `AccountService::order()` checks ownership in the
service layer, because a check living only in the storefront is one the second client removes. Proven as
*A is refused B's order **and** A is served their own*, against a real second account with a real order.
**A guest order is reachable by nobody** — `customer_id` 0 can never match a session, and the only
evidence linking a shopper to one is an email address, which would make it readable by anyone who could
name it.

**The session is a WordPress auth-cookie string, not a token this project invented.**
`wp_generate_auth_cookie()` / `wp_validate_auth_cookie()`, which buys five properties free and measured:
a tampered payload, a logout, a password change and an expiry all invalidate it. It is returned in the
**body**, not set as a cookie — this API cannot set one the storefront's origin would return, and a
cross-site cookie is what §65's CSRF rule-out depends on not existing; the Next.js server puts it in its
own HTTP-only cookie so the browser never holds it, which is what §44 protects. **Two §44 rules are
asserted rather than assumed**: a customer receives no Application Password, and a staff account is
refused at `/account/login` even with the right password (checked against the `ac_*` capability
vocabulary, not the role name), or the customer door becomes a second admin login minting a bearer token.

**Two things §59c found.** `RateLimitGuard` hooks `application_password_failed_authentication` and so
does **not** watch a customer login — `AccountService` records the failure itself and
`scripts/test-api.sh` asserts the 429, since only that stage sees a client IP; without it customer logins
were unlimited. And **authentication must answer before input validation**: `POST /account/password`
validated its payload first, so an anonymous caller got a 400 listing the endpoint's fields instead of a
401. **Password reset is now built** (PLAN §29, §30) — see the mail section above. The deferral's argument
was answered rather than dropped: it refuses with a 503 when the shop cannot send, instead of minting a
token that goes nowhere.

**Two steps were added to §4's build sequence after §69, and both are now built: `32b` cart and
checkout (§59b) and `32c` customer accounts and sessions (§59c).** They are not new scope — PLAN §53 always required `cart`,
`checkout`, `customer accounts` and `orders` of the storefront — but the only storefront entry in the
list was step 44, one line reading "Next.js storefront", and §44 deferred customer sessions to "the
storefront work" with no step to defer them to. Both have substantial backend consequences: a cart
decides prices, stock and shipping costs, and **every number in it arrives from a browser**, so all of
them are re-read server-side on every mutation. **`32c` carries this project's first real IDOR** —
order history, where a shopper edits `/orders/123` to `/orders/124` and reads a stranger's name, phone
and address, with nothing erroring because the request is valid and only the authorization is missing.
`Permissions::assertOwnsOr()` has been written and unused since §50 waiting for exactly that; wiring it
in and testing it ("customer A is refused customer B's order **and** served their own") is part of the
step, not a follow-up. `docs/SECURITY.md` → "Security tests to write" carries the rule.

**§65 was an audit, not a feature, and [docs/TESTING.md](docs/TESTING.md) is its deliverable** — the map
from each of §65's five categories to the test that covers it, what was already covered under a different
name, and what does not apply here with the argument. Read it before adding a suite. Four things it
changed. **`/products` had no `tests/Api` suite**: products are §47, built before the convention, and
every module from §49 on has one — `tests/Api/products.php` now covers §65's eight API lines in order.
**It found a 500 on its first run**, fixed in `ProductRepository::skuExists()`:
`wc_get_product_id_by_sku()` excludes trashed products but `wc_product_meta_lookup` keeps their row, so
the duplicate check said "free" for a SKU the insert then refused from inside `save()`. **SQL injection
got two tests and nothing was wrong with the SQL** — `tests/Unit/SqlSafetyTest` walks all 60 `$wpdb` call
sites statically (and proves it can still fail, against a hostile fixture), while
`tests/Api/security.php` asserts the thing a concatenated `WHERE` actually fails: **a payload must not
widen a result set**, because "200, no crash" is what one returns. And **CSRF is ruled out with an
argument rather than tested** — `docs/SECURITY.md` → "CSRF", measured over real HTTP on 2026-08-16:
the credential is an `Authorization` header a cross-origin page cannot set, and core forces
`wp_set_current_user(0)` on any REST request with cookies and no `X-WP-Nonce`, so cookies are never
sufficient whatever their state.

**`tests/Api/security.php` reads the router, which is the one thing a per-feature suite cannot do.**
Every route must declare a guard, with `/health`, the namespace index, `/locations/*` and `/webhooks/*`
an explicit allowlist; and one Support Agent credential is swept across every GET route at once, which
is what catches a route added later with the wrong guard. **The sweep carries a control and needs it**:
where an administrator gets 200 the Support Agent must get **403**, not a 404 or a validation error —
writing that control found two routes that refuse everybody for their own reasons and would otherwise
have proved nothing. That discipline is the section's other output: a refusal and an unreachable route
look identical from outside, so every negative test needs a positive control.

**IDOR's owner half is named as untestable rather than skipped.** `Permissions::assertOwnsOr()` exists
with no call sites, because every route carries a management capability and a shopper reading their own
order needs §44's customer session. What *is* testable is type confusion — WordPress keeps posts,
products, orders and attachments in one id space — and all thirteen crossings are asserted to 404.
When §44 lands, the owner-scoped tests are part of it.

**§68 added no version table, on purpose.** Five of its six components were already pinned by
`compose.yaml`'s image tags; **WooCommerce was the sixth and nothing pinned it**, because it installs
into the volume, so it is now declared in the same file under `x-tested-versions` and §66's `setup.sh`
will install that version. A table in a document would be a second copy of numbers `compose.yaml` already
states, and the copy is what drifts — so the record is a check: `scripts/test.sh versions` reads the pins
out of `compose.yaml` and compares them against the running stack. Verified 2026-08-16, all matching.

**§69 was partly satisfied already, and the gap was writes over the wire.** Every `tests/Api` suite goes
through `rest_do_request()`, so no test had sent a POST or PATCH through Apache, the permalink rewrite
and an `Authorization` header. `scripts/test-api.sh` → "CRUD over HTTP" is that walkthrough, and it lives
there rather than in a document because a documented walkthrough is one nobody re-runs. It is thin on
business rules by design — what it proves is the transport, and it is what found the 500.

**§59d is steps 33 and 34, built together** — 33 was owed and 34 had nothing to say until orders,
payments and shipments existed. **Coupons are `src/Coupons/`, six routes, no migration** (coupons are
`shop_coupon` posts; HPOS moved only orders). The step was owed because §59b shipped `POST /cart/coupons`
against a discount the API could not create. `ac_manage_coupons` already existed — **no new capability**.
**PLAN §21 asks for ten things and WooCommerce supplies nine**: "maximum discount" is not one of them —
`maximum_amount` caps the *cart*, not the discount — so `maximum_discount` is refused by name with the
reason. **Two round-trip bugs, both found on the suite's first run and both the same shape**: the read
body could not be written back, because the presenter emitted ISO dates the input refused, and because
WooCommerce stores an absent threshold as `'0'` which the presenter published as `"0.00"` and the input
then compared as a real minimum. Thresholds are now `null` when absent, matching `usage_limit`.

**Notifications are `src/Notifications/` — migration 010, `Schema::VERSION` is now 10, and no REST
routes**: §29 asks for an abstraction, not an endpoint. **Nothing is sent on a request path** — §62b's
argument, stronger here, because an SMTP server that hangs would hang a checkout and one that is down
would fail an order that had already taken money. `notify()` writes a row; `wp algerian-commerce
send-notifications` sends. **The claim and the queue are one table** with `UNIQUE (channel, dedupe_key)`,
which is why the subscriber filters no hooks at all: eight firings produce one message, guaranteed by the
database rather than by a comparison that has to be right in eight places. **The message is frozen at
queue time**, so an order refunded after queueing still delivers the confirmation that was true when it
was placed. `ac_shipment_saved` is the one hook this project added, in `ShipmentRepository::update()`
rather than the service, because the poller writes without going through the service and "delivered"
almost always arrives from a poll. **Low stock claims once and is re-armed on restock** — WooCommerce
provides the first half and not the second. **A COD order is not a paid order**: the payment message is
gated on `$order->is_paid()`, or every COD customer is told their money arrived. **Password reset is deliberately not in this queue** and never was going to be: a five-minute drain is
not a password reset. It sends synchronously through `MailTransport` — see above. §29's other four
channels stay deferred for want of credentials; each is one class plus one `add()`, and **SMS and
WhatsApp are explicitly out of scope**.

§63 is `src/Analytics/` — seven read-only endpoints (`overview`, `revenue`, `orders`, `products`,
`customers`, `shipping`, `cod`) and **no migration**; `Schema::VERSION` is still 9.

**`ac_analytics_aggregates` was deliberately not built, and the evidence is the argument.** WooCommerce
Admin already ships that rollup (`wc_order_stats`, `wc_order_product_lookup`), filled by an Action
Scheduler importer — and on this install those tables hold **0 rows against 912 orders**, with **1,426
`wc-admin_import_orders` jobs pending since 2026-08-11** and no scheduled action ever completed, because
nothing drives WP-Cron on a headless backend nobody browses. That is §62b's "Meta for WooCommerce"
argument a third time, and worse: the tables *exist*, so reading them returns zeros rather than failing.
Our own rollup inherits the same missing driver. So §63 is bounded queries on WooCommerce's
`type_status_date` index, a window capped at **366 days**, and `AnalyticsCache` — a response cache
(`AC_ANALYTICS_CACHE_TTL`, 60s, `0` off) that cannot drift past its TTL and needs no scheduler. The
trigger for revisiting is named: `AnalyticsRepository::ordersByWilaya()`, and any rollup must ship with a
driver that is **not** WP-Cron.

**`AnalyticsRepository` is the one exception to "OrderRepository is the only file that touches an
order", and it is narrow.** Reporting needs `SUM`/`GROUP BY` and WooCommerce publishes no API for either
(`wc_get_orders()` counts, which is how the COD funnel works; it cannot sum or rank). Four rules bound
it: no `WC_Order` is ever loaded or returned, the table name comes from
`OrderUtil::get_table_for_orders()` and never a literal, **a legacy install answers 501 rather than
zeros** (the HPOS query against `wp_posts` returns no rows and no error), and every query is read-only.
It is also the only place `ac_shipments` is read *in aggregate*; `ShipmentRepository` is still the only
place a row is read or written as a `Shipment`.

**`ac_view_analytics` was already too wide** — every role in PLAN §3 holds it, Support Agent included.
The rule, now in `docs/SECURITY.md` → "Authorization": **reporting may not disclose in aggregate what the
caller cannot already read in detail.** Money additionally requires `ac_manage_orders`, the capability
that already reads an order's total; counts and rates need only `ac_view_analytics`. `/analytics/revenue`
answers 403 without it, elsewhere the money block is absent and `meta.money_visible` says so. **No new
capability was invented** — §61's media gap set that precedent. The cache key carries the money flag, or
the cache serves an administrator's figures to whoever asks next.

Three things §63 settled about the numbers themselves. **Counts are of every order; only sums have a
currency** — WooCommerce records it per order and this install holds 890 `USD` orders from before anyone
set `DZD`, so filtering whole queries put "22 orders placed" beside a COD funnel reporting 615; the
currency lives in a `CASE`, and `excluded_currencies` reports the rest. **`refunded` counts as a revenue
status**: a fully refunded order belongs in gross with its refund subtracted, netting to zero, where
excluding the order but counting the refund nets to *minus* the sale — and refunds are keyed to the
parent order's date, because `net = gross − refunds` is only true of one set of orders. **A wilaya comes
off the shipment, never the address**, because `ShipmentInput` already refuses to fuzzy-match a commune
name and a report must not make the guess the shipping module declined; unshipped orders are reported as
`unattributed` with the reason attached. Of PLAN §28's ten figures, **three are named as unavailable
rather than emitted as zero** — shipping cost (migration 004 has no cost column), payment fees
(migration 007 has none either) and margin (no cost of goods exists, and §28 says to calculate profit
only where reliable data does).

§62b is `src/Marketing/` plus `integrations/Meta/`, and `ac_marketing_events` is migration 009
(`Schema::VERSION` is 9). The abstraction is §58's unchanged — `MarketingProviderInterface`,
`Plugin::marketingProviders()` as the only place a pixel id, a token or `ENABLE_MARKETING_PIXELS` is
read, our value objects across the boundary, no `WC_Order` in an adapter. **This repository owns the
server half only**: `fbevents.js` and `fbq()` belong to the Next.js storefront, and a WooCommerce pixel
plugin cannot do it because those inject through template hooks that never run headless — inert, and
looking installed.

**Deduplication is the whole problem, and the id goes one way.** Meta discards the browser's copy only
when both carry the same `event_name` *and* `event_id`, so the backend mints it
(`MarketingEvent::idFor()`, derived from the order and hashed so a sequential order number is not handed
to an ad network) and `POST /marketing/events/purchase` tells the storefront. A retried request, a
refreshed page and a second tab therefore produce **one** conversion. Only what the server witnessed is
sent — `PageView`, `Search` and `ViewContent` are browser facts, and a server reporting them is guessing.

**Nothing is sent on the checkout path.** The request claims a row and returns; `wp algerian-commerce
sync-marketing` (and a five-minute cron) drains it. The claim and the queue are deliberately **one
table** with `UNIQUE (provider, event_id)` — a claim in one table and a job in another can disagree, and
the disagreement is a conversion sent twice or never. `payload` is frozen at claim time, so a refund
between the sale and the send cannot change the reported value.

**`Marketing\UserData` is where PII stops.** Private constructor, hashes on the way in, so no object
holds a customer's email en route to an ad network and neither does the queue, which outlives the
request. Hashing is not anonymisation — this is still customer PII going to a third party. The Algerian
trap: a shop stores `0551020304`, and Meta's "strip leading zeros, add the country code" read naively
gives `551020304`, which is a phone number nowhere; the trunk prefix is **replaced** by `213`.

Graph API **v26.0** is pinned in `MetaSettings` per §68. Everything came from Meta's documentation read
2026-08-16 per §54, and **nothing has run against a live dataset** — no ad account, which is §56's
situation. `grep -rn ASSUMPTION integrations/Meta src/Marketing`. When a token exists, set
`test_event_code` in `ac_meta_settings` and watch Test Events.

§64 is `src/ImportExport/` — six endpoints, **no migration** (`Schema::VERSION` is still 9): two imports
(`/import/products`, `/import/inventory`) and four exports (products, inventory, orders, customers).

**The pipeline is stateless and `dry_run` defaults to true.** §64's "confirm" step looks like it needs a
job held between two requests; instead `dry_run: true` returns the preview and the error report and
`dry_run: false` applies the same file. There is no `ac_import_jobs` table and **no uploaded file is
retained** — SECURITY.md's "File uploads" rule says accepting a file is the most dangerous thing this API
does, and one kept for later is that danger with a longer fuse. The trigger for revisiting is named: when a
catalogue outgrows `CsvReader::MAX_ROWS` and needs batching, the table earns its place. Defaulting
`dry_run` to true is the safety property — a client that forgets the flag previews, never writes.

**WooCommerce's CSV engine is reused, and this is deliberately *not* another §61.** The SEO plugin, the
pixel plugin and wc-admin's analytics tables were all rejected because the half that runs is a rendering or
scheduler concern that never executes headless. The CSV engine is plain PHP that reads a file and calls the
product CRUD; only its *loader* is admin-gated. Measured 2026-08-16: with `is_admin()` false, five
`require_once`s (`WooCsv`) produced a valid 40-column export. Forking forty columns of variations,
attributes and meta would break the "never fork their data models" rule and produce a file no other
WooCommerce tool could read.

**A CSV is a document a spreadsheet will run.** A cell starting `=`, `+`, `-`, `@`, tab or CR is a formula,
and the attacker needs one product name. `CsvWriter` escapes exactly as `WC_CSV_Exporter::escape_data()`
does — products use WooCommerce's exporter and everything else uses ours, so `tests/Api/import-export.php`
asserts the two still agree after a WooCommerce upgrade.

Three things §64 found. **`update_existing` is a mode switch whose name reads as a modifier**: `false`
creates new SKUs and skips existing ones, `true` updates existing ones and skips new ones, and *neither*
does both — so the API says `mode=create|update`. **A product dry run is a parse and a lookup, not a
rehearsal**, because `WC_Product_CSV_Importer` has no dry-run mode; it runs WooCommerce's own parser and
says so in `preview_only` rather than letting someone read an optimistic report as a promise. **An export
is not a JSON resource**: `API/FileDownload` is the one deliberate exception to the envelope, bounded to
2xx bodies on opt-in routes with a filename that is ours — and since `rest_do_request()` never runs
`rest_pre_serve_request`, the download headers are checked in `scripts/test-api.sh`, as media uploads are.
**Every imported stock change goes through `InventoryService`**, so two thousand rows write two thousand
ledger movements on purpose: an import must not be a back door around `ac_inventory_movements`.

§62 is `src/SEO/`, and it is **a block on the payloads that already exist** rather than an endpoint:
`seo` appears on `GET /products/{id}` and `GET /cms/pages/{path}`, and is written through the
resource's own PATCH, so SEO errors land in the same `fields` list as the rest of a product write. No
migration, no table. Five stored overrides — title, description, canonical, robots, image — and
everything else derived, so a shop that has never opened an SEO field still serves a sensible title,
description and share image. `og` follows title/description rather than being stored twice, and
anything unpublished defaults to **noindex** because this API serves a draft long before it is public.
**A canonical URL is never derived**: WordPress's permalink points at this backend, the storefront is
another origin, and a guessed canonical would tell Google the shop lives on the admin domain.
`SEO/` contains no WooCommerce — a caller flattens a product or a post into a `SeoSubject`.

**No SEO plugin was installed**, and PLAN §25 says to use one, so the reason is on the record: in a
headless install the half of an SEO plugin that *renders* never runs, and its sitemap and `robots.txt`
are emitted on the backend's domain where they are worse than absent. It is the argument §62b makes
against "Meta for WooCommerce", applied to the same shape of problem. Rank Math remains the upgrade
path — it publishes a `getHead` endpoint for headless installs — and taking it means writing a
`RankMathSource` in place of `SeoRepository`, with nothing above `SeoResolver` changing.

§61 is `src/CMS/` and `src/Media/`, and it added **no migration and no table** — `Schema::VERSION` is still
8. "WordPress stores content" was the instruction and it was taken literally: banners and FAQs are post
types (`ac_banner`, `ac_faq`, grouped by the `ac_faq_category` taxonomy), menus are core nav menus assigned
to the `primary`/`footer` locations the plugin registers, pages are pages addressed by **path** — `legal/terms`,
because that is how WordPress addresses a hierarchical page and a bare slug would have to pick between two
children called `terms`. The homepage is the one thing that is not a post: the `ac_cms_homepage` option, one
document of §23's `{type, data}` sections, edited whole with `wp option update`. A malformed section is
dropped **and reported** in the response `meta`, because an option is edited by hand and a section that
vanishes silently is the one failure a content manager cannot diagnose. CMS is read-only, as §61 specifies;
a write surface is PLAN §52's admin coverage, not this.

**Map only the primitive post-type capabilities.** Registering a post type with
`'edit_post' => 'ac_manage_content'` writes that name into WordPress's global `$post_type_meta_caps`, after
which *every* check of `ac_manage_content` anywhere maps to `delete_post` with no post id and resolves to
`do_not_allow` — every CMS and media route answered 403 to the exact capability being asked about,
administrators included. `map_meta_cap => true` derives the three meta capabilities from the primitives.
`tests/Api/cms.php` caught it.

**`POST /media` is the highest-risk endpoint in this API** — the only one that writes a file a web server
might later execute — and `docs/SECURITY.md` → "File uploads" is now the rule for it the way "Webhooks" is
for inbound requests. Read the section, not this paragraph. The short form: four independent checks in a
load-bearing order (size, filename, contents, extension), with `wp_handle_upload()`'s own as a fifth from an
allowlist generated from ours; jpg/jpeg/png/webp only, each exclusion reasoned; the stored name is **ours**,
with the extension taken from the sniffed type rather than the client's; and every accepted image is
re-encoded from decoded pixels, which is what strips metadata and what makes a polyglot inert. All of it
lives in one pure class, `Media\UploadPolicy`, so every §65 abuse case is a unit test.
`ImageSanitizer` **pins the editor to GD**, measured rather than assumed: `WP_Image_Editor_Imagick::save()`
keeps EXIF and JPEG comments, and the two containers in this stack disagreed about which editor WordPress
picks — a security property that depends on which PHP process ran is not a property.
A **Product Manager deliberately cannot upload**: PLAN §3 defines no media capability, and both ways of
closing that gap are worse than naming it.

Two environment changes came with it, and both are load-bearing: `docker/php-uploads.ini` raises
`upload_max_filesize` from the image's 2 MB (PHP discards an oversized body before any application code
runs, so the app cap and PHP's move together), and `docker/apache-wordpress.conf` makes
`wp-content/uploads` non-executable — the layer that does not depend on the allowlist or the re-encode
being right. `scripts/test-api.sh` asserts both.

All three inbound endpoints now exist — `/webhooks/chargily`, `/webhooks/yalidine`, `/webhooks/zrexpress`
(§60 writes the last one `zr-express`; the route follows `ZRExpressProvider::NAME`, which is already in every
`ac_shipments` row). Everything a webhook route does lives once, in `API/AbstractWebhookController`, and the
event claim is `Commerce/WebhookEventRepository` — it moved out of `Payments/` when shipping needed it too,
because a `Shipping/` class reaching into `Payments/` would invent a dependency the business does not have.
**Both couriers re-fetch rather than believe the payload**, including ZR Express, which signs properly: its
webhook reference documents `state.name` as a display string while the live API returns the snake_case
identifiers `ZRExpressStateMap` maps, and two documented shapes for the field that decides a parcel's status
is where believing a payload writes something nothing else can reason about. So `ShipmentWebhookResult`
carries no status at all and every verified event ends in `getShipmentStatus()` — the poller's own path. A
parcel's status still never moves the order. Yalidine's `security_token` gets **no timestamp check**: nothing
is signed, so a `date` in the body is attacker-controlled and checking it would be theatre.
`signature_url` — a link to the customer's handwritten signature — joined `Logger::SENSITIVE_EXACT`.

**That rule is `docs/SECURITY.md` → "Webhooks", and it is not negotiable per provider.** The short form:
the secret is `.env`-only and reaches the verifier through the bootstrap, as API credentials do; a webhook
route exists only when its provider is registered, so an unconfigured secret is a 404 rather than an open
door; verification runs on the **raw body** before any JSON decode, with `hash_equals()`; a real signature
(Svix for ZR Express, Chargily) may be acted on, while a body secret that binds to nothing (Yalidine's
`security_token`) is a **hint to re-fetch and never a source of truth**; replay is stopped by a 5-minute
timestamp tolerance where the timestamp is signed, plus an event id *claimed* by a write-once insert whose
duplicate-key failure is the answer; an unverified request gets 401 `webhook_unverified` and is told nothing
about which check failed. Read the section, not this paragraph, before writing one.

§55 also recorded that **Yalidine label URLs carry an access token** — `label` and `labels` are credentials
to one customer's name, phone and address, not links. They are stored in `ac_shipments.metadata` and served
behind `ac_manage_shipping` on purpose, and they are in `Logger::SENSITIVE_EXACT` so they can never reach a
log. A provider field holding a tokenised URL joins that list when its adapter is written.

COD state is order meta plus audit events, never new order statuses (PLAN §8) and never a table of its own.
A COD outcome does not change the order's status; the order's cancellation closes the COD state through
`CodSubscriber`, which is the direction that keeps `Orders/` unaware of `COD/`. `ENABLE_COD` is not read by
that module — it gates what checkout offers, and **that is now `Payments/`**: `Plugin::paymentProviders()`
registers `CashOnDeliveryProvider` only when the flag is on. The two never overlap — `COD/` is the phone
call before dispatch, `Payments/` is how an order is paid for — and neither reads the other.

Payments follow the shipping rule exactly (§58): providers implement `Payments\PaymentProviderInterface`
and are registered in `Plugin::paymentProviders()`, the only place a gateway's credentials and feature flag
are read. Everything crossing the boundary is one of our value objects — `PaymentRequest` in,
`PaymentResult` / `PaymentReport` / `WebhookResult` out — and **an adapter never sees a `WC_Order`.**
`CashOnDeliveryProvider` is the `ManualProvider` of this layer: no HTTP, no credentials, a method real shops
actually use, and proof the seam works before §59 puts a network call behind it.
`PaymentStatus::accepts()` carries the one rule that protects money: from `paid`, **only `refunded`** —
providers send late `pending` events and webhooks arrive out of order, and without it one of them un-pays a
settled order. PLAN §19's transaction table arrived with Chargily in §59, and waiting paid: `amount` is `decimal(12,2)`
rather than an integer of minor units, because Chargily turned out to quote in **dinars**.

Chargily (§59) is `integrations/Chargily/`, **verified against the live test API on 2026-08-15** — test keys
are free, so unlike §56 nothing stayed a guess and `grep -rn ASSUMPTION integrations/Chargily` is empty.
Four things the run settled that its reference does not say: `expired` is a real status though the documented
enum omits it; a fractional amount is accepted though the type is documented `integer`; `checkout_url` comes
back `http://` though the docs write `https://`, and is corrected because that is where a shopper types card
details; and every response embeds the merchant's own record (trade register, NIS, NIF, `satim_credentials`),
which is why stored metadata is an allowlist rather than the payload.
**Chargily has one secret, not two** — it signs webhooks with the API secret key itself, so
`CHARGILY_WEBHOOK_SECRET` was deleted rather than left as a slot that could only be filled in wrongly, and
the key's `test_sk_` prefix picks the environment so a live key cannot be pointed at the test URL.
**A verified webhook is still re-fetched**: its checkout object carries no `currency`, so SECURITY.md's
"amount *and* currency" re-check is unsatisfiable from the payload — the signature proves the sender, the
re-fetch proves the money. `PaymentReport::matches()` was tightened accordingly: a report stating no currency
now fails a check that was given one instead of skipping it.
`ac_payment_transactions` is migration 007 and `ac_webhook_events` migration 008 (`Schema::VERSION` is 8);
the event claim is a write-once insert whose duplicate-key failure *is* the answer, never a read.
**Several transactions per order is the design**, and there is deliberately no mirror of migration 006's
"one live per order" index: a duplicated parcel is a van, a duplicated checkout is a link nobody clicks.
`wp algerian-commerce sync-payments` (hourly cron too) is the safety net under the five-minute replay window,
since Chargily does not document its retry schedule — a refused late retry costs minutes, not a payment.

Shipping follows the same rule: a parcel's status never moves the order. `ac_shipments` is migration 004.
**One live shipment per order is enforced by the schema, not by a check** (migration 006):
`live_order_id` holds the order id while a parcel is live and NULL once it is finished, and a unique index
ignores NULLs — so re-sending after a failed delivery still works. `ShipmentRepository::claimOrder()` takes
a MySQL `GET_LOCK` for the order across the whole of `ShippingService::create()`, courier call included,
because the index would otherwise refuse the duplicate *row* only after the duplicate *parcel* was real.
The lock is the defence; the index is the guarantee. Never reintroduce a bare read as the guard.
Providers implement `Shipping\ShippingProviderInterface` and are registered in
`Plugin::shippingProviders()`, which is the only place a courier's credentials and feature flag are read;
`ManualProvider` (in-house delivery), `Integrations\Yalidine\YalidineProvider` and
`Integrations\ZRExpress\ZRExpressProvider` all ship today. Everything crossing the provider boundary is one
of our value objects — an adapter never sees a `WC_Order`. **Adding the second courier changed nothing above
`ShippingProviderInterface`**, and the two disagree about almost everything: names against UUIDs, inline
recipients against customer records, one identifier against two, an idempotent merchant reference against one
that duplicates. Keep it that way.

ZR Express (§57) had a specification — an OpenAPI definition per endpoint at
`docs.zrexpress.app/reference/*.md` — and was verified live on 2026-08-15. Its `state.name` values are stable
snake_case identifiers (12 mapped in `ZRExpressStateMap`, unknown ones raise); its webhooks are signed by
**Svix**, a published scheme, so the webhook slice has nothing left to invent. Two traps its reference
implementation falls into and this adapter does not: `parcels/search` **ignores `filters`** and returns
everything, so lookups use `keyword` *and* verify `externalId` on the row; and `POST parcels` returns only an
id, so the tracking-number read-back must never be allowed to fail the create — a parcel exists by then.

Yalidine (§56) was written with no merchant account, from three agreeing implementations rather than from
memory (§54 forbids memory, not working code), and **verified against the live API on 2026-08-14** with
another project's credentials. What is still unproven is marked `ASSUMPTION (unverified)` in
`integrations/Yalidine/` — `grep -rn ASSUMPTION integrations/Yalidine` — and what was proven says so with the
date. Do not delete either kind of marker without evidence. Three assumptions were **wrong** and the code
now reflects reality: `GET parcels/{tracking}` is wrapped in `{data:[…]}` (a missing parcel is a 200 with
`total_data: 0`, not a 404); `order_id` is **not** an idempotency key, so the adapter runs
`GET parcels/?order_id=` before creating; and `DELETE parcels/{tracking}` exists, so cancellation works.
The quota is published on every response (`second/minute/hour/day-quota-left`, 5/50/1000/10000), which is
why the poller batches 25.
A courier's coverage is **data it publishes**, loaded by `wp algerian-commerce sync-destinations` into
`ac_geo_provider_destinations`, including its own spelling of every wilaya and commune, because Yalidine
matches those names exactly. Never hard-code a wilaya-name table or a set of unsupported wilayas. Commune
matching is by accent-folded name and never fuzzy — the report names the nearest candidate for a person to
judge. A **wilaya** may also match on its official code, which the live run showed identical to the
courier's id in 54 of 54 cases: name first, code only as a tie-break, and every such row reports itself.
Two known coverage gaps are data, not bugs: ~338 communes are transliteration variance, and 95 sit in the
11 wilayas created after 2019 that Yalidine still files under their old parent.
Status sync is a poll (`wp algerian-commerce sync-shipments`, plus an hourly cron); the webhook is now
unblocked but stays a *hint*, because `security_token` is a shared secret in the body rather than a
signature — it binds to nothing, so a verified delivery means "go and re-fetch `GET parcels/{tracking}`",
never "believe this payload". Per-client settings are the `ac_yalidine_settings` option — origin wilaya, insurance,
parcel defaults — never `.env` and never constants, because the plugin is cloned per client.

**No courier webhook has ever been received, and a live-API note does not cover one.** §56 and §57 verified
the *outbound* APIs on 2026-08-14/15; the inbound half has never run. `ac_webhook_events` holds rows for
`chargily` and the test double only, and neither courier's `*_WEBHOOK_SECRET` is set, so both routes 404
today. Every test payload is *constructed* from published documentation, which proves each verifier matches
the scheme as written and cannot prove the sender implements it — `grep -rn ASSUMPTION integrations/Yalidine
integrations/ZRExpress`, and see `docs/SECURITY.md` → "A verifier written from a specification is not a
verified verifier". Both failure modes are quiet 401s that look like silence rather than an error. Neither is
an outage, because a verified courier event is only ever a hint to re-fetch and the poller keeps parcels
current either way. **The ledger is the standing check**: a row with `provider` `yalidine` or `zr-express`
means one genuinely arrived, and the markers come out then and not before.

What the shop *charges* is separate from what a courier quotes: `ac_shipping_rates` (migration 005,
`Schema::VERSION` is 6) holds the tariff, and `RateResolver` picks the narrowest matching rule — commune beats
wilaya beats the national fallback, and rules are never added together. `GET /shipping/rates` returns both
sources, each labelled. Deliberately not WooCommerce shipping zones: those key on postcodes, which the commune
dataset does not have.

Geographic data is complete: **69 wilayas** (the 2019 reform's 58 plus the eleven former circonscriptions
administratives, since promoted) and 1,541 communes, with Arabic names, daira and coordinates. Both files are
**generated**, never hand-written — `scripts/build-algeria-dataset.php` converts the source CSV in
`data/algeria/sources/`, and `wp algerian-commerce import-algeria` loads the result. Regenerate rather than editing
the JSON by hand. Postal codes are absent from the source and deliberately left empty; `national_code` is the
national commune code, not a postal code.

[docs/PLAN.md](docs/PLAN.md) — the functional specification — answers *what* we build.
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (layering, module map, provider abstraction, data/schema design) and
[docs/SECURITY.md](docs/SECURITY.md) (read before touching auth, payments, webhooks, uploads, or any integration —
its "Webhooks" section is the §55 rule every inbound endpoint follows, its "File uploads" section is the
§61 rule every route that writes a file follows, and its "CSRF" section is the §65 rule-out) and
[docs/TESTING.md](docs/TESTING.md) (§65's map — read before writing a suite) supersede the roadmap for their
topics. [docs/API.md](docs/API.md) is §70's contract and is verified by `scripts/test-api.sh` — read it
before changing a route's shape. `docs/DEPLOYMENT.md` does not exist yet.

## Architecture

Headless commerce backend, intended to be cloned and reused per Algerian client:

```
WordPress (platform)  +  WooCommerce (commerce engine)
                 |
        algerian-commerce-core  ←— all business logic lives here
                 |
   REST API: /wp-json/algerian-commerce/v1
                 |
   Next.js store + Next.js admin (separate repos, HTTP clients only)
```

The hard boundary: **never modify WordPress or WooCommerce core**, and never fork their data models. Use WooCommerce's
supported APIs for products/orders/customers. Add custom tables only for genuinely custom, high-volume domains — audit
events, shipment records, payment transactions, notification events, analytics aggregates.

Plugin layout (roadmap §37): `src/` grouped by domain, PSR-4 root namespace `AlgerianCommerce\` → `src/`. Built so far:
`Core/`, `API/`, `Auth/`, `Security/`, `Permissions/`, `Audit/`, `Commerce/`, `Products/`, `Inventory/`, `Orders/`,
`Customers/`, `Account/`, `Cart/`, `Coupons/`, `Notifications/`, `COD/`, `Payments/`, `Shipping/`, `Geography/`, `CMS/`, `Media/`, `SEO/`, `Marketing/`,
`Analytics/`, `ImportExport/`, `Seed/`, `Settings/`, `Http/`, `CLI/`, plus `integrations/Yalidine/` (namespace
`AlgerianCommerce\Integrations\` → `integrations/`, registered by the plugin bootstrap even when Composer's
autoloader is present, because a dumped autoloader is a snapshot), alongside `data/`, `migrations/` and
`tests/`, plus `integrations/ZRExpress/`, `integrations/Chargily/` and `integrations/Meta/`.

**The bundled PSR-4 autoloader is registered for `src/` behind Composer, not instead of it.** Composer runs
with `optimize-autoloader`, so it dumps a *classmap* — a snapshot of the files present when somebody last ran
`dump-autoload` — and moving one class between two directories under `src/` made every request fatal on a
healthy checkout. The bootstrap already carried that reasoning for `integrations/`; §60 applied it to `src/`,
where a classmap makes it more necessary rather than less.

Bulk reference data lives in `data/` as JSON and is loaded by a WP-CLI importer — never inlined into PHP files
(roadmap §51). Datasets ship inside the plugin, because the plugin is what gets cloned and deployed per client.

A commerce domain may depend on another in one direction only, where the business genuinely nests — `Customers/`
reads `Orders/` because a customer's history *is* orders. A value object two domains both need goes in `Commerce/`
(`AddressInput`), not into whichever domain got there first.

WooCommerce runs with **HPOS enabled**, and the plugin declares `custom_order_tables` compatibility. Reach orders only
through `wc_get_order()`, `wc_get_orders()` and the `WC_Order` CRUD — never `get_post()`, `get_post_meta()` or `$wpdb`
against `wp_posts`. Direct reads work on a legacy install and silently return nothing here.

Third-party providers (Yalidine, ZR Express for shipping; Chargily for payments) sit behind adapters in `integrations/` —
domain code must never call a provider SDK or endpoint directly. Do not implement an adapter from memory or guesswork;
work from the provider's current official docs supplied in the prompt.

Schema changes go through numbered files in `migrations/` (`001_create_audit_logs.php`,
`002_create_inventory_movements.php`, …) gated on an `AC_DB_VERSION` constant that must equal the highest migration on
disk — a unit test enforces that. Migrations must never require deleting existing data, and there is no `down()`.

## Environment

Docker Compose (`compose.yaml`) runs three services: `db` (mysql:8.4.11 LTS), `wordpress` (7.0.4, PHP 8.4) and
`wpcli` (2.12.0, run-on-demand), with WooCommerce 11.0.1. Every tag is pinned to an exact build; upgrade one
at a time on a branch and re-run the suites.
`wp-content/plugins/algerian-commerce-core` is bind-mounted into both `wordpress` and `wpcli`; the rest of WordPress lives
in the `wordpress_data` volume and is deliberately not version-controlled.

Two things about that volume that cost an afternoon each if unknown. **Bumping the `wordpress` image tag does
not upgrade an existing install** — the entrypoint copies WordPress in only when `index.php` and
`wp-includes/version.php` are both absent, so use `wp core update` and keep the tag in step. And **the two
images disagree about who `www-data` is**: uid 33 in the Debian `wordpress` image, uid 82 in the Alpine
`wordpress:cli` image, which is why `wpcli` pins `user: "33:33"`. Without it WP-CLI reads everything and
writes nothing, and the tempting fix — `chmod 777 wp-content` — was in place here and has been removed. Do
not put it back: a world-writable plugin directory is arbitrary code execution waiting for one other bug.

**The store currency is `DZD`, and it is an option in the volume rather than anything version-controlled.**
A fresh install comes back as `USD`, which is wrong here in a way nothing fails loudly about: prices still
render, orders still save, and §62's `priceCurrency` quietly publishes the wrong currency to Google while
§62b reports conversions in the wrong one to Meta. Set it with
`wp option update woocommerce_currency DZD`; it belongs in §66's `setup.sh` when that exists, beside
deleting the bundled plugins. Note that WooCommerce records the currency **per order**, so changing it
does not rewrite orders already taken.

WordPress's bundled Akismet and Hello Dolly are **deleted**, not deactivated — neither does anything on a
headless backend and unused code still has to be patched. They live in the volume, so a fresh install brings
them back; deleting them belongs in §66's `setup.sh` when it exists.

**`db` is MySQL 8.4 LTS**, upgraded in place from 8.0.46 after that line reached end of life on 2026-04-30.
Supported to 2032-04-30. `caching_sha2_password` is still how it authenticates, so `wp db query` remains
broken in this stack for the reason above — use `wp eval` with `$wpdb`.

**A major MySQL upgrade has no in-place downgrade.** Booting a new major against the volume rewrites it
irreversibly; retagging back does not work. Before the next one, do all three: dump while the *old* version
is still running, **verify the dump by restoring it into a throwaway container of the target version** and
diffing row counts (SECURITY.md: an untested backup is not a backup), and stop the database with
`docker compose stop -t 120 db`. The default 10-second timeout SIGKILLs mysqld mid-shutdown — exit 137 —
which makes the next boot do crash recovery *and* a version upgrade at once.

```bash
docker compose up -d                  # start (WordPress at http://localhost:8090)
docker compose ps
docker compose logs -f wordpress
docker compose down                   # stop; add -v to wipe the DB and WP install
```

WP-CLI is the administration interface — prefer it over dashboard clicks so setup stays reproducible:

```bash
docker compose run --rm wpcli wp plugin list
docker compose run --rm wpcli wp plugin install woocommerce --activate
docker compose run --rm wpcli wp plugin activate algerian-commerce-core
docker compose run --rm wpcli wp eval '...'          # SQL: use $wpdb here, not `wp db query`
```

**`wp db query` does not work in this stack.** The `wordpress:cli` image ships a MariaDB client with no
`caching_sha2_password` plugin, which is how MySQL 8 authenticates, so it fails before running anything. Reach for
`wp eval` with `$wpdb` instead — it goes through PHP's driver and works. `wp eval-file -` reads a script from STDIN,
which is the practical way to exercise routes with `rest_do_request()`.

Health check:

```bash
curl http://localhost:8090/wp-json/algerian-commerce/v1/health   # → {"success":true,"status":"ok"}
```

`.env` (gitignored) holds `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `WP_PORT`, and provider credentials
(`YALIDINE_API_ID`, `YALIDINE_API_TOKEN`, `YALIDINE_WEBHOOK_SECRET` — Yalidine authenticates with two headers,
not a key/secret pair — plus `ZR_EXPRESS_TENANT_ID`, `ZR_EXPRESS_API_KEY`, `ZR_EXPRESS_WEBHOOK_SECRET`,
`CHARGILY_SECRET_KEY` — **one key, which also signs the webhooks** — plus `META_PIXEL_ID` and
`META_CAPI_ACCESS_TOKEN`, which are **not the same kind of thing**: the pixel id ships in browser
JavaScript and `/marketing/config` serves it, while the token authorises writing conversions into an ad
account and appears in no response ever — and `SMTP_*`); `.env.example` mirrors
those keys with blank values. `WP_PORT` is honoured — `compose.yaml` publishes `${WP_PORT:-8090}:80`.
**A variable in `.env` reaches the plugin only if `compose.yaml` passes it into the container**, in both the
`wordpress` and `wpcli` services — `Config` reads `getenv()`, and the containers get nothing by default.
§61 found the whole `AC_RATE_LIMIT_*` group in exactly that state — documented in `.env.example`, read by
`RateLimiter`, and passed through by nothing — and added them alongside `AC_RATE_LIMIT_UPLOADS` and
`AC_MEDIA_MAX_BYTES`. `AC_ANALYTICS_CACHE_TTL` (§63) went in the same way.

`scripts/test.sh` runs every stage — `versions` (§68), `syntax`, `unit`, `rest` (in-process,
`tests/Api/`) and `http` (`scripts/test-api.sh`). Pass a stage name to run just one.
**The `http` stage is not redundant, and is blind to nothing the others see**: `rest_do_request()` never
parses an `Authorization` header, never runs `rest_pre_serve_request` (so CORS headers and §64's
downloads are invisible to it), and cannot perform a real upload because `wp_handle_upload()` ends in
`move_uploaded_file()`. Run it before touching authentication, rate limiting, CORS, uploads or any write
path. [docs/TESTING.md](docs/TESTING.md) has the per-stage table and the conventions for adding a suite.
`scripts/{setup,reset,seed,health,backup,restore}.sh` (§66) all exist. `setup.sh` is idempotent and is
the script to re-run after `docker compose down`; `seed.sh` loads §51's geography and then §67's
fixtures; `health.sh` checks the container layer and the transport and delegates the rest to
`GET /health`; `backup.sh` dumps from inside the `db` container because `wp db export` is broken here;
`restore.sh --verify` is the drill that makes a backup a backup; and `reset.sh` destroys both volumes
behind a typed `DESTROY` and an environment check.

## API conventions

Namespace `algerian-commerce/v1`. Every response uses the same envelope:

```json
{ "success": true, "data": {} }
{ "success": false, "error": { "code": "invalid_product", "message": "…", "details": {} } }
```

Pagination applied consistently across list endpoints — use `AbstractController::paginationArgs()` and `idArg()` rather
than hand-rolling them. Every private route needs an explicit authorization check — registering a route without a real
`permission_callback` is a bug. CORS uses an environment-specific allowlist (`http://localhost:3000`,
`http://localhost:3001` in dev); never `Access-Control-Allow-Origin: *` on private routes.

An arg that declares a `sanitize_callback` **must** also declare `'validate_callback' => 'rest_validate_request_arg'`.
WordPress only runs a validate_callback when one is registered, and a custom sanitize_callback displaces the default
that would otherwise validate — leaving `minimum`, `maximum`, `enum` and `pattern` silently unenforced.

Privileged calls go browser → Next.js server → WordPress API. Admin credentials and privileged tokens must never reach
browser JavaScript.

Payment status is always verified server-side against the provider — never trusted from a client callback. Webhooks must
be signature-verified and idempotent (replaying the same event must not double-apply it).

## Working rules

- Implement one phase at a time. Do not build ahead into later milestones without being asked.
- Feature branches `feat/<area>` (`feat/core-plugin`, `feat/rbac`, `feat/products`, …); keep `main` deployable. Never
  force-push or rewrite `main` history.
- Write tests alongside features — price/shipping/COD-risk calculations, permissions, and API behavior (success, bad
  input, unauthenticated, unauthorized, not found, duplicate, pagination, boundaries).
- Never log API secrets, payment secrets, or full auth headers.
- Pin versions (WordPress, WooCommerce, PHP, MySQL, plugins) once the environment is known-good; upgrade one thing at a
  time on a branch.
- Add a Composer package only with a stated reason.
- Ask before destructive operations (dropping data, `docker compose down -v`, resets).
- After implementing: report files changed, tests run, and what is still broken or unverified.
