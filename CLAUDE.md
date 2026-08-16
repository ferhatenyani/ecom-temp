# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

Roadmap §1–§53 and §55–§62b are implemented and `main` is deployable. The plugin at
[wp-content/plugins/algerian-commerce-core/](wp-content/plugins/algerian-commerce-core/) holds real code — bootstrap,
REST foundation, migrations, RBAC, audit trail, products, inventory, orders, COD, shipping, Yalidine,
ZR Express, the payment abstraction, Chargily, the CMS, media, SEO and the marketing event layer — and its
[README](wp-content/plugins/algerian-commerce-core/README.md) is the reference for what exists and why each decision
went the way it did. Read it before extending a module. [scripts/](scripts/) holds `test.sh` and `test-api.sh`; the
rest of §66 and [backups/](backups/) are still empty placeholders.

The single source of truth for what to build is
[ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md](ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md) (81 sections). Read the
relevant section before implementing a feature; section 4 gives the exact implementation order, section 3 the
milestones, and section 29 the per-feature loop. **§50–§53 and §55–§62b are done** — orders, notes,
timeline, customers, the Algerian geography mechanism, cash on delivery, the shipping abstraction, §14's
shipping rules, the Yalidine and ZR Express adapters, the security review, the payment abstraction and
Chargily with PLAN §19's transaction table, §60's three webhooks, §61's CMS and media endpoints, and
§62's SEO and §62b's marketing event layer. **Next up is §63, analytics.**

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
its "Webhooks" section is the §55 rule every inbound endpoint follows, and its "File uploads" section is the
§61 rule every route that writes a file follows) supersede the roadmap for their
topics. `docs/API.md` and `docs/DEPLOYMENT.md` do not exist yet.

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
`Customers/`, `COD/`, `Payments/`, `Shipping/`, `Geography/`, `CMS/`, `Media/`, `SEO/`, `Marketing/`, `Http/`, `CLI/`, plus `integrations/Yalidine/` (namespace
`AlgerianCommerce\Integrations\` → `integrations/`, registered by the plugin bootstrap even when Composer's
autoloader is present, because a dumped autoloader is a snapshot), alongside `data/`, `migrations/` and
`tests/`, plus `integrations/ZRExpress/`, `integrations/Chargily/` and `integrations/Meta/`. Still to come:
`Analytics/`, `Notifications/`, …

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
`AC_MEDIA_MAX_BYTES`.

`scripts/test.sh` runs every stage — `syntax`, `unit`, `rest` (in-process, `tests/Api/`) and `http`
(`scripts/test-api.sh`). Pass a stage name to run just one. The `http` stage is not redundant:
`rest_do_request()` never parses an `Authorization` header, so nothing before it can see
authentication or rate limiting. Run it before touching either. The rest of
`scripts/{setup,reset,seed,health,backup}.sh` (§66) does not exist yet. `reset.sh` is destructive by
design and must say so loudly, and `backup.sh` cannot use `wp db query`/`wp db export` — see §66.

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
