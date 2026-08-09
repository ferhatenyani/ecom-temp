# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

This repo is **scaffolding only** — no commits yet, no PHP code written. The directories
[wp-content/plugins/algerian-commerce-core/](wp-content/plugins/algerian-commerce-core/), [docs/](docs/), [scripts/](scripts/), and
[backups/](backups/) are empty placeholders. The single source of truth for what to build is
[ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md](ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md) (81 sections). Read the
relevant section before implementing a feature; section 70 gives the exact implementation order and section 69 the milestones.

[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (layering, module map, provider abstraction, data/schema design) and
[docs/SECURITY.md](docs/SECURITY.md) (read before touching auth, payments, webhooks, uploads, or any integration) now
supersede the roadmap for their topics. `docs/PLAN.md` — the functional specification — has not been imported yet.

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

Planned plugin layout (roadmap §50): `src/` grouped by domain (`Core/`, `API/`, `Auth/`, `Permissions/`, `Products/`,
`Orders/`, `Inventory/`, `Shipping/`, `Payments/`, `COD/`, `Audit/`, …), `integrations/{Yalidine,Zedair,Chargily}/`,
`migrations/`, `tests/`. PSR-4 root namespace `AlgerianCommerce\` → `src/`.

Third-party providers (Yalidine, Zedair for shipping; Chargily for payments) sit behind adapters in `integrations/` —
domain code must never call a provider SDK or endpoint directly. Do not implement an adapter from memory or guesswork;
work from the provider's current official docs supplied in the prompt.

Schema changes go through numbered files in `migrations/` (`001_create_audit_logs.php`, …) gated on an `AC_DB_VERSION`
constant. Migrations must never require deleting existing data.

## Environment

Docker Compose (`compose.yml`) runs three services: `db` (mysql:8.0), `wordpress`, and `wpcli` (run-on-demand).
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
docker compose run --rm wpcli wp db query "..."
```

Health check once the plugin foundation exists:

```bash
curl http://localhost:8090/wp-json/algerian-commerce/v1/health   # → {"success":true,"status":"ok"}
```

`.env` (gitignored) holds `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `WP_PORT`, and provider credentials
(`YALIDINE_*`, `ZEDAIR_*`, `CHARGILY_*`, `SMTP_*`). Two gaps to fix when touching config: `.env.example` is currently
empty and should mirror `.env`'s keys with blank values, and `compose.yml` hardcodes port `8090` instead of using
`${WP_PORT}`.

The roadmap plans `scripts/{setup,reset,seed,health,test,backup}.sh` (§46); none exist yet. `reset.sh` is destructive by
design and must say so loudly.

## API conventions

Namespace `algerian-commerce/v1`. Every response uses the same envelope:

```json
{ "success": true, "data": {} }
{ "success": false, "error": { "code": "invalid_product", "message": "…", "details": {} } }
```

Pagination applied consistently across list endpoints. Every private route needs an explicit authorization check —
registering a route without a real `permission_callback` is a bug. CORS uses an environment-specific allowlist
(`http://localhost:3000`, `http://localhost:3001` in dev); never `Access-Control-Allow-Origin: *` on private routes.

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
