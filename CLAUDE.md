# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

Roadmap §1–§53 are implemented and `main` is deployable. The plugin at
[wp-content/plugins/algerian-commerce-core/](wp-content/plugins/algerian-commerce-core/) holds real code — bootstrap,
REST foundation, migrations, RBAC, audit trail, products, inventory, orders, COD and shipping — and its
[README](wp-content/plugins/algerian-commerce-core/README.md) is the reference for what exists and why each decision
went the way it did. Read it before extending a module. [scripts/](scripts/) holds `test.sh` and `test-api.sh`; the
rest of §66 and [backups/](backups/) are still empty placeholders.

The single source of truth for what to build is
[ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md](ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md) (81 sections). Read the
relevant section before implementing a feature; section 4 gives the exact implementation order, section 3 the
milestones, and section 29 the per-feature loop. **§50–§53 are done** — orders, notes, timeline, customers,
the Algerian geography mechanism, cash on delivery, and the shipping abstraction. **Next up is §56, Yalidine —
which cannot start until that provider's current official API documentation is supplied in the prompt (§54).**
§14 (shipping rules: zones, wilaya pricing, free-shipping thresholds) is also still open.

COD state is order meta plus audit events, never new order statuses (PLAN §8) and never a table of its own.
A COD outcome does not change the order's status; the order's cancellation closes the COD state through
`CodSubscriber`, which is the direction that keeps `Orders/` unaware of `COD/`. `ENABLE_COD` is not read by
that module — it gates what checkout offers, which is §58.

Shipping follows the same rule: a parcel's status never moves the order. `ac_shipments` is migration 004 and
`Schema::VERSION` is 4. Providers implement `Shipping\ShippingProviderInterface` and are registered in
`Plugin::shippingProviders()`, which is the only place a courier's credentials and feature flag are read;
`ManualProvider` (in-house delivery) is the working implementation that ships today. Everything crossing the
provider boundary is one of our value objects — an adapter never sees a `WC_Order`.

Geographic data is complete: **69 wilayas** (the 2019 reform's 58 plus the eleven former circonscriptions
administratives, since promoted) and 1,541 communes, with Arabic names, daira and coordinates. Both files are
**generated**, never hand-written — `scripts/build-algeria-dataset.php` converts the source CSV in
`data/algeria/sources/`, and `wp algerian-commerce import-algeria` loads the result. Regenerate rather than editing
the JSON by hand. Postal codes are absent from the source and deliberately left empty; `national_code` is the
national commune code, not a postal code.

[docs/PLAN.md](docs/PLAN.md) — the functional specification — answers *what* we build.
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (layering, module map, provider abstraction, data/schema design) and
[docs/SECURITY.md](docs/SECURITY.md) (read before touching auth, payments, webhooks, uploads, or any integration)
supersede the roadmap for their topics. `docs/API.md` and `docs/DEPLOYMENT.md` (§55) do not exist yet.

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
`Customers/`, `COD/`, `Shipping/`, `Geography/`, `CLI/`, alongside `data/`, `migrations/` and `tests/`. Still to
come: `Payments/`, `Analytics/`, `CMS/`, … and `integrations/{Yalidine,Zedair,Chargily}/`.

Bulk reference data lives in `data/` as JSON and is loaded by a WP-CLI importer — never inlined into PHP files
(roadmap §51). Datasets ship inside the plugin, because the plugin is what gets cloned and deployed per client.

A commerce domain may depend on another in one direction only, where the business genuinely nests — `Customers/`
reads `Orders/` because a customer's history *is* orders. A value object two domains both need goes in `Commerce/`
(`AddressInput`), not into whichever domain got there first.

WooCommerce runs with **HPOS enabled**, and the plugin declares `custom_order_tables` compatibility. Reach orders only
through `wc_get_order()`, `wc_get_orders()` and the `WC_Order` CRUD — never `get_post()`, `get_post_meta()` or `$wpdb`
against `wp_posts`. Direct reads work on a legacy install and silently return nothing here.

Third-party providers (Yalidine, Zedair for shipping; Chargily for payments) sit behind adapters in `integrations/` —
domain code must never call a provider SDK or endpoint directly. Do not implement an adapter from memory or guesswork;
work from the provider's current official docs supplied in the prompt.

Schema changes go through numbered files in `migrations/` (`001_create_audit_logs.php`,
`002_create_inventory_movements.php`, …) gated on an `AC_DB_VERSION` constant that must equal the highest migration on
disk — a unit test enforces that. Migrations must never require deleting existing data, and there is no `down()`.

## Environment

Docker Compose (`compose.yaml`) runs three services: `db` (mysql:8.0), `wordpress`, and `wpcli` (run-on-demand).
`wp-content/plugins/algerian-commerce-core` is bind-mounted into both `wordpress` and `wpcli`; the rest of WordPress lives
in the `wordpress_data` volume and is deliberately not version-controlled.

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
(`YALIDINE_*`, `ZEDAIR_*`, `CHARGILY_*`, `SMTP_*`); `.env.example` mirrors those keys with blank values. One gap left to
fix when touching config: `compose.yaml` hardcodes port `8090` instead of using `${WP_PORT}`, so changing `WP_PORT`
currently does nothing.

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
