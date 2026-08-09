# algerian-commerce-core

The application layer for the Algerian headless commerce backend. WordPress is the platform and
WooCommerce is the commerce engine; all reusable business logic lives here and is exposed over one
REST namespace.

No frontend: this plugin renders no HTML, ships no CSS or JavaScript, and adds no admin screens. It
answers HTTP requests with JSON. See [../../../docs/ARCHITECTURE.md](../../../docs/ARCHITECTURE.md).

## Current state

Milestone 4 (foundation) complete, and §70 item 19 (database migrations) on top of it: plugin
bootstrap, PSR-4 autoloading, configuration and feature flags, logging with secret redaction, the
`algerian-commerce/v1` namespace, the shared response envelope, error handling, the health endpoint,
and the migration runner with the audit log table.

Not implemented yet: RBAC and capabilities (§70 item 20), audit recording (item 21), products,
orders, customers, inventory, shipping, payments, CMS.

```
algerian-commerce-core.php   bootstrap: header, constants, autoload, lifecycle hooks
src/Core/                    Autoloader, Config, Logger, Plugin (wiring + lifecycle)
src/Core/Migrations/         Migration interface, MigrationPlan (ordering), MigrationRunner
src/API/                     Response envelope, ApiException, ErrorNormalizer,
                             AbstractController, RestApi, HealthController
src/CLI/                     WP-CLI commands
migrations/                  001_create_audit_logs.php, …
tests/Unit/                  unit tests — no WordPress required
```

## Endpoints

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/wp-json/algerian-commerce/v1/health` | public | stack liveness |

```bash
curl http://localhost:8090/wp-json/algerian-commerce/v1/health
# {"success":true,"status":"ok","data":{"checks":{...}}}
```

Returns HTTP 503 with `"status":"degraded"` when any check fails. Note that `success` stays `true`
on a degraded response — the request succeeded in *reporting* a problem. Clients must branch on the
HTTP status or on `status`, not on `success`.

The `schema` check compares the stored schema version against `DB_VERSION`, so a deploy that shipped
new files without running migrations shows up as degraded rather than as mysterious query failures.

## Database migrations

Schema changes are numbered files in `migrations/`, named `NNN_snake_case.php`, each returning a
`Migration` instance. The number is the version — a migration cannot disagree with its own ordering.

```bash
docker compose run --rm wpcli wp algerian-commerce migrate --dry-run
docker compose run --rm wpcli wp algerian-commerce migrate
```

They also run on plugin activation. The CLI command exists for deploys that update files without
reactivating, which would otherwise leave the schema behind.

Rules that the runner enforces or relies on:

- **`AC_DB_VERSION` must equal the highest migration on disk.** A unit test asserts this, so shipping
  a migration without bumping the constant fails the suite rather than silently skipping installs.
- **The stored version advances after each migration**, not once at the end — if 003 fails, 001 and
  002 stay applied and the next run resumes at 003. MySQL will not roll DDL back in a transaction,
  so resumability is the mitigation.
- **No `down()`.** Roadmap §49 forbids a production migration that depends on destroying existing
  data, and a rollback path invites exactly that. Reverse a mistake with a new forward migration.
- **Use `dbDelta()`**, which is idempotent for table creation, and follow its formatting rules (two
  spaces after `PRIMARY KEY`, stable `KEY` names) or it will try to add duplicate indexes on re-run.
- Files that do not match the naming convention are ignored, and two files claiming the same version
  is a hard error — otherwise apply order would depend on the filesystem.

### Tables

| Table | Since | Purpose |
| --- | --- | --- |
| `{prefix}ac_audit_logs` | 001 | append-only record of privileged actions |

`ac_audit_logs` is indexed on `actor_id`, `action`, `(resource_type, resource_id)` and `created_at`.
Rows are never updated; `created_at` is indexed so retention pruning is a ranged `DELETE`. The
retention window is a client policy decision and is not enforced by the schema.

## Response contract

```json
{ "success": true, "data": {} }
{ "success": false, "error": { "code": "not_found", "message": "…", "details": {} } }
```

Controllers never build an envelope by hand — use `Response::success()` / `Response::error()`, or
throw an `ApiException`, whose code and HTTP status the base controller maps for you. Errors raised
by WordPress itself (no matching route, failed `permission_callback`, schema validation) are
rewritten into the same envelope by `ErrorNormalizer`, scoped to this namespace only.

## Adding a controller

1. Extend `AbstractController` and implement `registerRoutes()`.
2. Wrap the callback in `$this->handle(...)` so it shares the error contract.
3. Give every route a real `permission_callback` — `__return_true` requires a justifying comment.
4. Register it in `Plugin::restApi()`.

## Development

Composer is optional at runtime: without `vendor/`, the bundled PSR-4 autoloader in
`src/Core/Autoloader.php` covers `src/`. It is required to run the tests.

```bash
# install dev dependencies (from this directory)
docker run --rm -v "$PWD":/app -w /app composer:2 install

# run the unit suite
docker compose exec wordpress sh -c \
  'cd /var/www/html/wp-content/plugins/algerian-commerce-core && php vendor/bin/phpunit'

# lint
docker compose exec wordpress sh -c \
  'find /var/www/html/wp-content/plugins/algerian-commerce-core -name "*.php" -exec php -l {} \;'
```

Unit tests must run without booting WordPress. Integration tests that need a live WordPress arrive
with the WP test suite in a later milestone.

## Configuration

Secrets come from environment variables only — never the options table, never code. Feature flags
(`ENABLE_COD`, `ENABLE_CHARGILY`, `ENABLE_YALIDINE`, `ENABLE_ZEDAIR`, …) all default to off so one
codebase can serve multiple clients. `AC_LOG_LEVEL` sets the log floor (`debug`, `info`, `warning`,
`error`); it defaults to `debug` when `WP_DEBUG` is on, otherwise `info`.
