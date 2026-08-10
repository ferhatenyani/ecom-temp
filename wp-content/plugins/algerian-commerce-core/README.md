# algerian-commerce-core

The application layer for the Algerian headless commerce backend. WordPress is the platform and
WooCommerce is the commerce engine; all reusable business logic lives here and is exposed over one
REST namespace.

No frontend: this plugin renders no HTML, ships no CSS or JavaScript, and adds no admin screens. It
answers HTTP requests with JSON. See [../../../docs/ARCHITECTURE.md](../../../docs/ARCHITECTURE.md).

## Current state

Milestone 4 (foundation) and Milestone 5 (security foundation) — §4 items 17–21: plugin bootstrap,
PSR-4 autoloading, configuration and feature flags, logging with secret redaction, the
`algerian-commerce/v1` namespace, the shared response envelope, error handling, the health endpoint,
the migration runner, roles and capabilities, and audit recording.

Not implemented yet: rate limiting and CORS (roadmap §46), products, orders, customers, inventory,
shipping, payments, CMS.

```
algerian-commerce-core.php   bootstrap: header, constants, autoload, lifecycle hooks
src/Core/                    Autoloader, Config, Logger, Plugin (wiring + lifecycle)
src/Core/Migrations/         Migration interface, MigrationPlan (ordering), MigrationRunner
src/Permissions/             Capabilities (the matrix), Roles (install), Permissions (enforcement)
src/Audit/                   AuditEvent, AuditRepository, AuditLogger
src/API/                     Response envelope, ApiException, ErrorNormalizer,
                             AbstractController, RestApi, HealthController, AuditLogController
src/CLI/                     WP-CLI commands
migrations/                  001_create_audit_logs.php, …
tests/Unit/                  unit tests — no WordPress required
```

## Endpoints

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/wp-json/algerian-commerce/v1/health` | public | stack liveness |
| GET | `/wp-json/algerian-commerce/v1/audit-logs` | `ac_view_audit_logs` | read the audit trail (paginated, filterable by `action`, `resource_type`, `actor_id`) |

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
- **No `down()`.** Roadmap §43 forbids a production migration that depends on destroying existing
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

## Roles and capabilities

Seven roles, thirteen capabilities (docs/PLAN.md §3). Capabilities carry an `ac_` prefix — WordPress
capabilities share one global namespace with core, WooCommerce and every plugin, and an unprefixed
`manage_products` can be granted by something unrelated.

| Role | Capabilities |
| --- | --- |
| `ac_super_admin` | all 13 |
| `ac_admin` | all except `manage_settings`, `manage_users` |
| `ac_manager` | products, inventory, orders, customers, coupons, shipping, analytics |
| `ac_product_manager` | products, inventory, analytics |
| `ac_order_manager` | orders, customers, shipping, analytics |
| `ac_marketing_manager` | marketing, content, coupons, analytics |
| `ac_support_agent` | customers, analytics |

`manage_users` and `manage_settings` belong to Super Admin alone — that boundary is what stops an
Admin account from granting itself more. The WordPress `administrator` role receives every capability
so installing the plugin never locks the site owner out.

```bash
docker compose run --rm wpcli wp algerian-commerce roles          # install / re-sync
docker compose run --rm wpcli wp algerian-commerce roles --list   # show the matrix
```

Roles install on activation. Bump `Roles::VERSION` when the matrix changes, since roles are stored in
the database rather than computed per request. Roles are **not** removed on deactivation — that would
strip capabilities from real accounts during a routine update; `Roles::remove()` is for uninstall.

### Enforcing them

Two layers, both required by [../../../docs/SECURITY.md](../../../docs/SECURITY.md):

```php
// 1. On the route — nothing reaches the controller without it.
'permission_callback' => Permissions::callback(Capabilities::MANAGE_PRODUCTS),

// 2. In the service — the IDOR defence. Holding the capability does not
//    imply access to *that* record.
Permissions::assert(Capabilities::MANAGE_ORDERS);
Permissions::assertOwnsOr($order->customerId(), Capabilities::MANAGE_ORDERS);
```

`callback()` returns 401 when signed out and 403 when signed in without the capability, so clients can
tell "log in" from "you may not".

## Audit trail

`AuditLogger::record()` writes to `ac_audit_logs`. Call it from services, not controllers — the
service knows what actually happened, whereas the edge only knows what was requested.

```php
$this->auditLogger->record('product.price_changed', 'product', $id, ['old' => 1200, 'new' => 1500]);
```

- **Metadata is redacted inside `AuditEvent`**, not at the call site. A caller that forgets is how a
  secret ends up in an append-only table it can never be edited out of.
- **Fields are truncated to their column widths.** MySQL in strict mode rejects an over-length value,
  which would turn a long SKU into a failed audit write on an otherwise successful operation.
- **A failed audit write logs an error but does not abort the operation.** An unwritable log table
  would otherwise take the whole API down; the health endpoint reports database problems separately.
- **The IP is `REMOTE_ADDR` only.** `X-Forwarded-For` is client-controlled, and a forged address in an
  append-only trail is worse than the proxy's real one. Revisit when a trusted proxy exists.

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
