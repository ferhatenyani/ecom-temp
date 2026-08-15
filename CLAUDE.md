# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

Roadmap §1–§53 and §55–§57 are implemented and `main` is deployable. The plugin at
[wp-content/plugins/algerian-commerce-core/](wp-content/plugins/algerian-commerce-core/) holds real code — bootstrap,
REST foundation, migrations, RBAC, audit trail, products, inventory, orders, COD, shipping, Yalidine and
ZR Express — and its
[README](wp-content/plugins/algerian-commerce-core/README.md) is the reference for what exists and why each decision
went the way it did. Read it before extending a module. [scripts/](scripts/) holds `test.sh` and `test-api.sh`; the
rest of §66 and [backups/](backups/) are still empty placeholders.

The single source of truth for what to build is
[ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md](ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md) (81 sections). Read the
relevant section before implementing a feature; section 4 gives the exact implementation order, section 3 the
milestones, and section 29 the per-feature loop. **§50–§53 and §55–§57 are done** — orders, notes,
timeline, customers, the Algerian geography mechanism, cash on delivery, the shipping abstraction, §14's
shipping rules, the Yalidine and ZR Express adapters, and the security review. **Next up is §58, the payment
abstraction**, plus the two couriers' webhooks, which §56 and §57 deferred until §55 — and which are now
unblocked, because §55 ended with a written webhook rule.

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
that module — it gates what checkout offers, which is §58.

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
its "Webhooks" section is the §55 rule every inbound endpoint follows) supersede the roadmap for their
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
`Customers/`, `COD/`, `Shipping/`, `Geography/`, `Http/`, `CLI/`, plus `integrations/Yalidine/` (namespace
`AlgerianCommerce\Integrations\` → `integrations/`, registered by the plugin bootstrap even when Composer's
autoloader is present, because a dumped autoloader is a snapshot), alongside `data/`, `migrations/` and
`tests/`, plus `integrations/ZRExpress/`. Still to come: `Payments/`, `Analytics/`, `CMS/`, … and
`integrations/Chargily/`.

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
`CHARGILY_*`, `SMTP_*`); `.env.example` mirrors those keys with blank values. `WP_PORT` is honoured —
`compose.yaml` publishes `${WP_PORT:-8090}:80`.

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
