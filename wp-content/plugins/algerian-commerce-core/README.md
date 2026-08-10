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

Milestone 5 is complete and roadmap §47 (product CRUD) is in. Not implemented yet: rate limiting, orders, customers, inventory,
shipping, payments, CMS.

```
algerian-commerce-core.php   bootstrap: header, constants, autoload, lifecycle hooks
src/Core/                    Autoloader, Config, Logger, Plugin (wiring + lifecycle)
src/Core/Migrations/         Migration interface, MigrationPlan (ordering), MigrationRunner
src/Permissions/             Capabilities (the matrix), Roles (install), Permissions (enforcement)
src/Audit/                   AuditEvent, AuditRepository, AuditLogger
src/Products/                ProductController, ProductService, ProductInput,
                             ProductRepository, ProductPresenter
src/API/                     Response envelope, ApiException, ErrorNormalizer, Cors, OriginPolicy,
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
| GET | `/products` | `ac_manage_products` | list (paginated; `search`, `sku`, `status`, `category`) |
| POST | `/products` | `ac_manage_products` | create → 201 |
| GET | `/products/{id}` | `ac_manage_products` | read |
| PATCH | `/products/{id}` | `ac_manage_products` | partial update |
| DELETE | `/products/{id}` | `ac_manage_products` | trash, or `?force=true` to delete permanently |

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

## CORS

The allowlist is exact on scheme, host and port — no wildcards, no subdomain or prefix matching.
`https://store.example.dz` does not admit `https://store.example.dz.attacker.com`.

```bash
AC_CORS_ORIGINS=https://store.example.dz,https://admin.example.dz
```

Unset falls back to the development origins `http://localhost:3000,http://localhost:3001`
(roadmap §46). A malformed entry — or `*` — is dropped rather than honoured, so a misconfiguration
fails closed and blocks everything instead of opening the API. Set the variable explicitly in staging
and production; it is passed into the container by `compose.yaml`.

**Why this replaces core's handler.** WordPress's `rest_send_cors_headers()` reflects *whatever*
Origin the request carried along with `Access-Control-Allow-Credentials: true`, and never consults
`allowed_http_origins` — that filter only governs `is_allowed_http_origin()`, which the REST CORS path
does not call. Core can afford it because its endpoints need a nonce or Authorization header a foreign
page cannot obtain. Our private API cannot, so `Cors` removes core's handler and applies the allowlist
to `algerian-commerce/v1` only; `wp/v2`, `wc/v3` and everything else keep core's behaviour.

A refused origin gets **no** `Access-Control-Allow-Origin` at all — omitting the header is what makes
the browser block the response, there is no "deny" header. `Vary: Origin` is sent either way so a
shared cache cannot serve an approved response to a different origin. Refusals are logged with the
offending origin, because a blocked call leaves no other server-side trace.

## Products

Simple products only, for now. Layering follows [../../../docs/ARCHITECTURE.md](../../../docs/ARCHITECTURE.md) §2:

```
ProductController   routes, args schema, JSON body → service
ProductService      authorization, duplicate-SKU and cross-field rules, audit
ProductInput        validation and normalization (pure, no WordPress)
ProductRepository   the only place that knows WC_Product exists
ProductPresenter    the wire format
```

Rules worth knowing before extending it:

- **Unknown fields are rejected**, not ignored — `stock_quantiy` returns 400 rather than silently
  doing nothing. Errors come back as a per-field map, all problems at once, in `error.details.fields`.
- **Prices stay strings** end to end. They are decimal DZD amounts; a float would introduce rounding
  into money.
- **A duplicate SKU is 409, not 400.** The payload is well formed; the catalogue already contains it.
- **A PATCH that sends only `sale_price`** is checked against the *stored* regular price, otherwise a
  product could be put on "sale" above its own price.
- **`WC_Data_Exception` is translated** into the error envelope, so WooCommerce's own validation does
  not surface as a 500.
- **DELETE trashes by default**; `?force=true` is permanent. Both are audited, with distinct actions.

Writes are recorded to the audit trail as `product.created`, `product.updated` (with a before/after
diff and the field list), `product.trashed` and `product.deleted`.

### Variations and attributes

Attributes come in WooCommerce's two flavours and the repository handles the difference: **global**
(`{"id": 3, ...}`) stores term ids against a registered taxonomy, **custom** (`{"name": "Size", ...}`)
stores plain strings on the product. `id: 0` means custom — that is what the read endpoint emits, and
accepting it is what makes GET → edit → PATCH work.

Terms are resolved, never created. A typo in an option is a 400, not a new permanent taxonomy term.

```
GET    /products/{id}/variations
POST   /products/{id}/variations
GET    /products/{id}/variations/{variationId}
PATCH  /products/{id}/variations/{variationId}
DELETE /products/{id}/variations/{variationId}
```

Variations are nested under the parent, and the service checks the variation actually belongs to the
product in the path — `/products/5/variations/99` where 99 belongs to product 7 is a 404, not a leak.

Rules enforced in `VariationService` because none of them can be checked from the payload alone:

- The parent must be `type: variable` — otherwise 409 telling you to change the type first.
- Every attribute key must be one the parent marked `variation: true`, and every value must be one of
  that attribute's options. An empty value means "any".
- **A duplicate attribute combination is 409.** WooCommerce happily stores two variations claiming the
  same combination, and then only one of them is ever selectable.
- Every write syncs the parent, which caches price range and stock status from its children.

Changing `type` from simple to variable is supported: product type lives in the `product_type`
taxonomy and the PHP class is chosen from it at load, so the repository updates the term, clears the
caches and re-loads rather than calling a setter.

### Images

Assigned by attachment id; each id is verified to be a real image attachment before it is stored,
because WooCommerce accepts any post id and an unchecked value yields a product whose image resolves
to nothing. `0` clears. Products carry `image_id` + `gallery_image_ids` (writable) alongside `image`
and `gallery` (read-only, with URLs), so a client gets the URLs without a second request and can
still PATCH the object straight back. Variations take a single `image_id`.

Image **upload** is not here. It belongs with PLAN.md §24 Media — MIME and extension allowlists, size
caps, metadata stripping, non-executable storage — and deserves its own security review.

### Bulk operations

```
POST /products/bulk
{"action": "update", "items": [{"id": 1, "status": "draft"}, …]}
{"action": "delete", "ids": [1, 2], "force": true}
```

Every item runs through the ordinary single-item service, so bulk inherits validation, authorization
and audit instead of reimplementing looser copies. A failing item is recorded and the batch
continues — a bulk price update should not abandon 99 good products because the 40th has a duplicate
SKU.

The response is **200 with a per-item result list**, not 207:

```json
{ "success": true,
  "data": [ {"id": 26, "success": true},
            {"id": 27, "success": false, "error": {"code": "invalid_request", …}} ],
  "meta": { "total": 3, "succeeded": 1, "failed": 2 } }
```

Partial success is the expected outcome here rather than an exception, and the caller has to read the
per-item results either way. Batches are capped at 100 items, and a duplicate id in one batch is
rejected up front — two entries for one product make the result depend on ordering.

### Duplicate, sorting, categories

`POST /products/{id}/duplicate` copies attributes, categories, images and every variation. The copy is
always a **draft with a cleared SKU**, so an accidental duplicate cannot appear in the storefront or
collide on SKU. It does not use WooCommerce's own duplicator, which lives in an admin-only class that
echoes and redirects.

`GET /products?orderby=&order=` accepts `date`, `id`, `title`, `price`, `sku`, `menu_order`,
`popularity`, `rating`, constrained by an enum so an unknown value is a 400 rather than a silent
fallback. Lists exclude variations — those are addressed through their parent.

`GET /product-categories` is read-only, paginated, filterable by `search` and `parent`.

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
