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

Milestone 5 is complete; roadmap §47 (product CRUD), §49 (inventory), §44 (authentication), §50
(orders and customers) and §51 (Algerian geography) are in, along with rate limiting. Not implemented
yet: 2FA, customer sessions, COD, shipping, payments, analytics, CMS.

```
algerian-commerce-core.php   bootstrap: header, constants, autoload, lifecycle hooks
src/Core/                    Autoloader, Config, Logger, Plugin (wiring + lifecycle)
src/Core/Migrations/         Migration interface, MigrationPlan (ordering), MigrationRunner
src/Permissions/             Capabilities (the matrix), Roles (install), Permissions (enforcement)
src/Audit/                   AuditEvent, AuditRepository, AuditLogger
src/Auth/                    AuthController (/auth/me), AuthService, Identity
src/Security/                RateLimit, RateLimitStore, RateLimiter, RateLimitGuard
src/Products/                ProductController, ProductService, ProductInput,
                             ProductRepository, ProductPresenter
src/Inventory/               InventoryController, InventoryService, StockAdjustment,
                             InventorySettingsInput, BulkStockRequest, InventoryRepository,
                             MovementReason, InventoryMovement, MovementRepository, StockLedger,
                             InventoryPresenter
src/Commerce/                AddressInput — shared by orders and customers
src/Orders/                  OrderController, OrderService, OrderStatus, OrderInput,
                             LineItemInput, OrderNoteInput, OrderTimeline,
                             OrderRepository, OrderPresenter, OrderStockSubscriber
src/Customers/               CustomerController, CustomerService, CustomerInput,
                             CustomerStatistics, CustomerRepository, CustomerPresenter
src/Geography/               LocationController, GeoService, GeoDataset, GeoSlug,
                             GeoRepository, GeoImporter
data/algeria/                wilayas.json, communes.json, provider-destinations.json,
                             sources/ (the CSV they are built from)
src/API/                     Response envelope, ApiException, ErrorNormalizer, Cors, OriginPolicy,
                             AbstractController, RestApi, HealthController, AuditLogController
src/CLI/                     WP-CLI commands
migrations/                  001_create_audit_logs.php, 002_create_inventory_movements.php, …
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
| GET | `/inventory` | `ac_manage_inventory` | stock levels (paginated; `search`, `sku`, `status`, `category`, `stock_status`, `manage_stock`, `include_variations`) |
| GET | `/inventory/{id}` | `ac_manage_inventory` | one product's or variation's stock |
| PATCH | `/inventory/{id}` | `ac_manage_inventory` | stock **settings** — `manage_stock`, `stock_status`, `backorders`, `low_stock_amount` |
| POST | `/inventory/{id}/adjust` | `ac_manage_inventory` | move a **quantity** → movement + audit |
| POST | `/inventory/bulk` | `ac_manage_inventory` | batch adjustments → 200 with per-item results |
| GET | `/inventory/lookup?sku=` | `ac_manage_inventory` | exact SKU lookup → the item, or 404 |
| GET | `/inventory/low-stock` | `ac_manage_inventory` | low-stock report, lowest first |
| GET | `/inventory/movements` | `ac_manage_inventory` | the ledger (paginated; `product_id`, `reason`, `order_id`, `actor_id`, `date_from`, `date_to`) |
| GET | `/inventory/movements/summary` | `ac_manage_inventory` | net change and movement count per reason |
| GET | `/orders` | `ac_manage_orders` | list (paginated; `search`, `status`, `customer_id`, `date_from`, `date_to`, `orderby`, `order`) |
| POST | `/orders` | `ac_manage_orders` | create → 201 |
| GET | `/orders/{id}` | `ac_manage_orders` | read |
| PATCH | `/orders/{id}` | `ac_manage_orders` | partial update, including status |
| POST | `/orders/{id}/cancel` | `ac_manage_orders` | cancel, with an optional `reason` |
| GET | `/orders/{id}/notes` | `ac_manage_orders` | notes, newest first (`limit`) |
| POST | `/orders/{id}/notes` | `ac_manage_orders` | add a note → 201 |
| GET | `/orders/{id}/timeline` | `ac_manage_orders` | notes, audit events and stock movements, merged (`limit`) |
| GET | `/customers` | `ac_manage_customers` | list (paginated; `search`, `orderby`, `order`) |
| GET | `/customers/{id}` | `ac_manage_customers` | profile **and** lifetime statistics |
| PATCH | `/customers/{id}` | `ac_manage_customers` | name, email and addresses — never roles or credentials |
| GET | `/customers/{id}/orders` | `ac_manage_customers` | order history (paginated; `status`, `orderby`, `order`) |
| GET | `/locations/wilayas` | **public** | all 58 wilayas (`search`, `active_only`) |
| GET | `/locations/wilayas/{id}` | **public** | one wilaya, by its official code |
| GET | `/locations/wilayas/{id}/communes` | **public** | its communes (`search`, `postal_code`, `active_only`) |
| GET | `/locations/communes/{id}` | **public** | one commune |
| GET | `/locations/coverage` | **public** | how much of the dataset is loaded |

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
| `{prefix}ac_inventory_movements` | 002 | append-only stock ledger — every change to a quantity |

`ac_audit_logs` is indexed on `actor_id`, `action`, `(resource_type, resource_id)` and `created_at`.
Rows are never updated; `created_at` is indexed so retention pruning is a ranged `DELETE`. The
retention window is a client policy decision and is not enforced by the schema.

`ac_inventory_movements` is indexed on `product_id`, `created_at`, `reason` and `order_id`.
Quantities are **signed** bigints: negative stock is how a backordered line is represented, and
integers keep `SUM()` exact. WooCommerce's `wc_stock_amount()` casts to int unless a store filters
it, so a shop selling by weight needs a forward migration to widen those columns.

## Authentication

Roadmap §44. **There is no login endpoint here, and that is the design.** WordPress verifies an
Application Password sent as HTTP Basic auth on `determine_current_user`, before any route in this
plugin runs. Issuing our own tokens would mean owning a credential store, rotation and revocation
that core already provides and that other people already audit.

```
Browser  →  Next.js server  →  WordPress API
            (holds the Application Password)
```

The credential belongs to a dedicated service account, never to a human's login, and never reaches
browser JavaScript. Application Passwords are individually revocable, so one integration can be cut
off without touching anything else.

```bash
# create the service account and its credential
docker compose run --rm wpcli wp user create ac_service svc@example.dz --role=ac_admin
docker compose run --rm wpcli wp user application-password create ac_service nextjs-server

curl -u "ac_service:xxxx xxxx xxxx xxxx" \
  http://localhost:8090/wp-json/algerian-commerce/v1/auth/me
```

**`wp_is_application_passwords_supported()` is `is_ssl() || 'local' === wp_get_environment_type()`.**
Development is plain HTTP, so `compose.yaml` sets `WP_ENVIRONMENT_TYPE=local` — without it every
request is rejected and the cause is invisible. Staging and production run TLS and must set the
variable to their real environment instead.

Basic auth survives this stack because mod_php populates `PHP_AUTH_USER` / `PHP_AUTH_PW`, which is
what core reads. The raw `Authorization` header does *not* reach PHP through the rewrite — worth
knowing before anyone adds a Bearer-token scheme, which would need `CGIPassAuth On` or a
`SetEnvIf` in [docker/apache-wordpress.conf](../../../docker/apache-wordpress.conf).

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/auth/me` | any signed-in caller | identity, roles and this plugin's capabilities |

`/auth/me` is what the Next.js server calls at startup to confirm its credential is valid and
carries the capabilities it expects, rather than discovering a misconfigured account on the first
customer order. Two rules about its response:

- **The capability list is for rendering, never for access control.** Every route still enforces its
  own `permission_callback` and every service still calls `Permissions::assert()`.
- **Only this plugin's `ac_` capabilities are returned.** A WordPress administrator carries dozens of
  core capabilities (`install_plugins`, `edit_themes`) that say nothing about commerce and would hand
  a client a map of the platform underneath. Denied capabilities (`allcaps` entries set to `false`)
  are dropped too — a present key is not a grant.

Still outstanding for §44: **2FA for privileged users** (PLAN §3, SECURITY.md) and a customer-facing
session strategy. Customers must not use Application Passwords — that is a server-to-server
mechanism, and SECURITY.md calls for HTTP-only cookie sessions on the storefront.

## Rate limiting

docs/SECURITY.md, docs/PLAN.md §35. Scoped to `algerian-commerce/v1` only — throttling `wp/v2` or
`wc/v3` would change behaviour other code depends on, exactly as the CORS handler leaves them alone.

| Limit | Default | Window | Keyed on |
| --- | --- | --- | --- |
| reads | 600 | 1 min | identity **and** IP |
| writes | 120 | 1 min | identity **and** IP |
| failed authentications | 10 | 15 min | IP |

Both counters run on every guarded request and the stricter wins: the identity counter bounds one
compromised credential, the IP counter bounds a flood that has no identity yet. Over-limit returns
**429 with `Retry-After`**, and rejections are logged — a blocked request leaves no other
server-side trace.

**The authentication limit is the one that matters most.** Enabling Application Passwords put a
credential-guessing surface on the network, and WordPress does not throttle it at all.

Two hooks close it, and the second is not optional:

- `wp_is_application_passwords_available` returns false for a blocked address, so core never
  attempts the password comparison. Skipping that hash is what stops the endpoint being a CPU oracle.
- `rest_authentication_errors` turns the resulting 401 into a 429.

`rest_pre_dispatch` alone is **not** enough, and it is worth knowing why: when Basic auth
credentials are present and wrong, core fails the request during authentication and serves 401
without ever dispatching. A guard that only runs at dispatch watches an attacker walk straight past
it, answering 401 to every attempt forever. That is exactly what the first version of this did, and
only a live test caught it — the unit tests were perfectly green.

### The availability trade

The lockout is keyed on IP, so an attacker guessing against a known service account locks out
everyone sharing that address — **including the Next.js server**. Every brute-force defence makes
this trade; this is the escape hatch it has to ship with:

```bash
AC_RATE_LIMIT_TRUSTED_IPS=203.0.113.7      # the application server
```

A trusted address skips the lockout but is still rate limited normally. Exact IPs only —
CIDR ranges and hostnames are dropped rather than honoured, so a typo cannot silently exempt
something unintended. `AC_RATE_LIMIT_DISABLED=true` is the blunt kill switch.

**How wide is the blast radius, really?** Narrower than it first looks. The counter is keyed on IP,
so an attacker on a different address cannot lock out your application server — that is the point of
keying on IP. The exposure is a shared egress address: an attacker behind the same NAT as the
Next.js server, which `AC_RATE_LIMIT_TRUSTED_IPS` is there to cover.

### Getting back in

Counter keys are hashed, so there is no transient name to guess. Recovery is a command:

```bash
docker compose run --rm wpcli wp algerian-commerce unlock 203.0.113.7
# Success: Cleared 10 recorded failure(s) for 203.0.113.7 (backend: transient).
```

The address is the one WordPress sees in `REMOTE_ADDR`, which behind Docker is the gateway rather
than your laptop — `docker compose logs wordpress` shows it in the access lines.

### Where the boundary falls

The failure from the request in flight is recorded during authentication, before
`rest_authentication_errors` runs. The request that spends the last of the budget therefore reports
**429 rather than 401** — a caller is told the moment it is exhausted rather than one request later.

### Storage

Counters prefer a persistent object cache, where `wp_cache_incr()` is atomic. Without one they fall
back to transients, which is a read-then-write and can **undercount** under concurrency — it lets a
few extra requests through, and can never lock out a legitimate caller. That is a deliberate,
bounded compromise, not an oversight. **Run a persistent object cache in production**; without one
these counters also land in the options table. The window is fixed, not sliding: a caller can spend
one allowance at the end of a window and another at the start of the next. It bounds sustained
abuse, which is the job; it is not a traffic shaper.

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

## Inventory

Roadmap §49, docs/PLAN.md §7. One rule shapes the whole module:

> A **quantity** changes only through `POST /inventory/{id}/adjust`, and every adjustment writes a
> ledger row. **Settings** change through `PATCH /inventory/{id}` and write no ledger row, because
> no units moved.

That is why `PATCH /inventory/{id}` rejects `stock_quantity` with a message naming the adjust
endpoint rather than a bare "Unknown field."

```
InventoryController     routes, args schema, JSON body → service
InventoryService        authorization, state guards, ledger + audit
StockAdjustment         mode/quantity/reason validation and arithmetic (pure)
InventorySettingsInput  settings validation (pure)
BulkStockRequest        batch shape (pure)
InventoryRepository     the only place that knows WooCommerce's stock API exists
MovementRepository      the only place that touches ac_inventory_movements
StockLedger             writes movements; shared with the product/variation services
InventoryPresenter      the wire format
```

### Adjusting

```jsonc
POST /inventory/12/adjust
{ "mode": "set" | "increase" | "decrease", "quantity": 12, "reason": "restock", "note": "PO 4471" }
```

- **`increase` / `decrease` are race-safe; `set` is not.** WooCommerce applies the first two as a
  relative SQL update (`meta_value = meta_value - n`), so two concurrent decrements compose. `set`
  is last-writer-wins. Receiving or writing off goods should use the relative modes; a stock count
  uses `set`.
- **The recorded delta comes from what WooCommerce wrote**, not from the request, and
  `quantity_before` is derived as `after - delta`. Pairing a stale read with a fresh write would
  produce rows that do not balance.
- **Adjusting a product that does not manage stock is 409**, not a silent success —
  `wc_update_product_stock()` no-ops in that case.
- **Driving stock below zero is 409** unless the product allows backorders, which is the only
  legitimate way for stock to go negative.
- **A variation that inherits its parent's stock** is adjusted through either id, but the movement
  records `stock_managed_by_id` — the row WooCommerce actually decrements.

### Reasons

A closed vocabulary, because the ledger exists to be grouped and summed. Manual reasons —
`correction`, `restock`, `damage`, `loss`, `customer_return`, `other` — are the only ones the API
accepts. System reasons are written by the plugin alone:

| Reason | Written by |
| --- | --- |
| `product_edit` | a `stock_quantity` sent to the product or variation write endpoints |
| `order_reduced`, `order_restored` | reserved for §50; **nothing writes them yet** |

The split is a security boundary: if `order_reduced` were settable by hand, a person could forge
rows that read as though an order caused them. A system reason on `/adjust` is rejected with the
same message as an unknown one, so the error does not confirm which reasons exist.

### The ledger has no gaps

`StockLedger` is injected into `ProductService` and `VariationService` too, because their write
endpoints also accept a `stock_quantity`. Creating a product with stock opens the ledger at that
quantity. Without this, a movement's `quantity_before` would not match the previous row's
`quantity_after` and nobody could explain the difference.

**Known gap:** WooCommerce reduces stock itself when an order is placed, and nothing records that
yet — it arrives with roadmap §50, which is what the `order_id` column and the two order reasons
are for. Until then the ledger accounts for every change made *through this API*, and a shop taking
live orders will show unexplained jumps.

A failed ledger write logs an error but does not fail the request: the stock has already moved by
then, there is no transaction spanning WooCommerce's write and ours, and reporting an error for a
change that did happen is worse than a gap. Same reasoning as `AuditLogger`.

### Reports

`GET /inventory/low-stock` is a separate route because it is a genuinely different query: the
threshold is per product (`_low_stock_amount`, falling back to the parent's, then to the store-wide
`woocommerce_notify_low_stock_amount`), so the comparison is between two columns and
`WC_Product_Query` only ever builds `meta_key = value`. It is one prepared read over
`wc_product_meta_lookup`, modelled on WooCommerce's own `ProductsLowInStock`. Backordered items are
excluded, as they are there — they are past "low" rather than approaching it.

The **out-of-stock report is `GET /inventory?stock_status=outofstock`**, since that is the ordinary
query with one value set and deserves no route of its own.

`GET /inventory` excludes variations by default, matching the products list; `include_variations=true`
brings them in. Sorting is limited to `date`, `id`, `title`, `sku` — ordering by quantity through
`wc_get_products()` needs a fragile `meta_key` passthrough, and the low-stock report already
answers "what needs attention" in quantity order.

SKU lookup takes a **query parameter**, not a path segment: WooCommerce SKUs are free text and
routinely contain `/`, which no amount of encoding makes safe in a REST route pattern.

## Orders

Roadmap §50, docs/PLAN.md §8. Order notes, the timeline and the customer endpoints are still to come.

```
OrderController      routes, args schema, JSON body → service
OrderService         authorization, transition and editability guards, audit
OrderStatus          the status vocabulary and the transition matrix (pure)
OrderInput           write payload validation (pure)
AddressInput         billing/shipping validation, shared (pure)
LineItemInput        one product line (pure)
OrderRepository      the only place that knows WC_Order exists
OrderPresenter       the wire format
OrderStockSubscriber WooCommerce's stock hooks → the §49 ledger
```

**There is no `DELETE /orders/{id}`.** An order is cancelled, never removed: it is the record of a
commercial event that accounting, the courier and the customer's history all keep referring to.

### Statuses and transitions

The vocabulary is WooCommerce's, unchanged — `pending`, `processing`, `on-hold`, `completed`,
`cancelled`, `refunded`, `failed`. The operational states PLAN.md §8 lists ("COD Pending
Confirmation", "Shipped", "Delivered") are **not** statuses; they arrive as metadata and events in
§52 and §53, which is what "avoid creating redundant statuses" asks for.

What this plugin adds is a policy WooCommerce deliberately does not have. `set_status()` validates
the *name* and never the *move*, which is right for a gateway replaying a callback and wrong for an
admin API — the interesting mistakes are the ones that unwind a finished order.

| From | May become |
| --- | --- |
| `pending` | `processing`, `on-hold`, `completed`, `cancelled`, `failed` |
| `processing` | `on-hold`, `completed`, `cancelled`, `refunded`, `failed` |
| `on-hold` | `pending`, `processing`, `completed`, `cancelled`, `failed` |
| `completed` | `refunded` |
| `failed` | `pending`, `processing`, `on-hold`, `cancelled` |
| `cancelled`, `refunded` | nothing — terminal |

`refunded` is reachable only from `processing` and `completed`, the two statuses that imply money was
taken. Refunding an order that was never paid is a cancellation, and conflating them corrupts every
revenue figure derived from the order book. Re-setting the status an order already has is a
permitted no-op, so a retry and a full-object PATCH both succeed.

**Creatable is a separate list, not "reachable from `pending`".** `pending → cancelled` is a legal
move, but an order that is *born* cancelled records the calling-off of something never placed — so
`OrderStatus::CREATABLE` excludes both terminal statuses. Deriving one rule from the other is a bug
the REST suite caught.

### Prices come from the catalogue

`LineItemInput` has no price field and will not get one. A line is priced by `add_product()` from the
product, and every monetary field on the order — `total`, `subtotal`, `discount_total`,
`shipping_total`, `total_tax` — is read-only. A total a request can set is not a total.

Money is emitted as decimal strings. WooCommerce returns some totals as floats and others as
strings; the presenter puts all of them through `wc_format_decimal()` so no amount picks up
floating-point rounding on the way out.

### Editing an order that already holds stock

`PATCH` with `line_items` replaces the product lines wholesale, and is allowed while
`WC_Order::is_editable()` — WooCommerce's own rule, so `pending` and `on-hold` only. A `completed`
order's lines are what was invoiced and delivered; editing them rewrites history rather than
correcting it.

`on-hold` is editable *and* reduces stock, and that case is reconciled rather than refused. The
repository returns the units, replaces the lines and takes them again, through
`wc_maybe_increase_stock_levels()` and `wc_maybe_reduce_stock_levels()` — the `maybe_` wrappers
because they also maintain the order's `stock_reduced` flag, which the raw functions do not.

Both fire the hooks below, so amending 4 units down to 1 leaves three ledger rows — −4, +4, −1 —
netting to the single unit actually held. That verbosity is the point: the shelf really did move
three times, and a ledger showing only −1 could not be reconciled against the quantity anyone reads
back.

Skipping the reconciliation is not an option, and the failure is silent. Replacing the items destroys
the `_reduced_stock` marker WooCommerce writes on each one, and nothing afterwards can return those
units. Removing the guard on purpose stranded 5 units and left the ledger netting −5 instead of 0.

WooCommerce's own helper for adjusting a single line in place,
`wc_maybe_adjust_line_item_product_stock()`, is not usable here — it lives in an admin-only file that
is not loaded during a REST request.

Everything else — addresses, the customer note, the payment method — stays editable at any status,
because a phone typo on a shipped COD order has to be fixable.

### Notes and the timeline

`POST /orders/{id}/notes` takes `note` and an optional `customer_note`, which **defaults to false**,
and the default is the security-relevant part: WooCommerce emails a customer note to the buyer the
moment it is saved, so silence has to mean internal. The flag is not coerced either — the string
`"false"` is truthy in PHP, and accepting it would send an internal remark to the customer.

Notes are allowed at any status. The orders most in need of annotation — delivered, cancelled,
refunded — are exactly the ones no longer editable.

`GET /orders/{id}/timeline` merges three stores into one feed, newest first:

```
notes    WooCommerce comments      what a person wrote
audit    ac_audit_logs             who did what, append-only
stock    ac_inventory_movements    what happened to the shelf
```

Merged on read, not written to a fourth table — a timeline table would be a copy that can disagree
with its sources, and the sources are the records people trust. Each source is asked for its newest
`limit` and the merge keeps the newest `limit` of the union, which is exact: no source can contribute
to the top N without it being in its own top N.

Two details that are not obvious. Sorting happens on integer timestamps, because the three stores
format times differently and `'Y-m-d H:i:s'` does not compare against ISO-8601 — and WooCommerce
hands back note dates in the *site* timezone, so they are converted to UTC before they meet two
tables that store UTC. And `order.note_added` audit rows are dropped from the feed: the trail records
that a note was written because notes are comments an administrator can delete, but the note itself
is already in the feed, so keeping both would print every note twice.

## Customers

Roadmap §50, docs/PLAN.md §9.

**Guests are not customers here.** A cash-on-delivery shop takes most orders without an account;
those carry `customer_id` 0 and their buyer's details on the order itself. There is no user row to
list, profile or update, so they are found through `/orders` and its billing search.

There is no `POST` and no `DELETE`. Accounts are created by registration or checkout and removed
through WordPress's own user tools, both of which carry consequences — password mail, content
reassignment, GDPR erasure — belonging to `ac_manage_users`.

### The capability boundary is the whole design

`ac_manage_customers` is held by **Support Agent**, the thinnest role in the system. A customer is a
WordPress user, and a user object carries the password hash, the role and the capability map, so this
module is written to make sure support can correct an address and nothing else:

- `CustomerInput` refuses `password`, `user_pass`, `roles` and `capabilities` **by name**, with a
  message saying where the boundary is. None of them appear in a response, so nobody arrives at one
  by round-tripping — they are only ever typed on purpose.
- `role` *is* emitted, so it is dropped rather than refused. Dropping is what makes it safe: a
  dropped field is never applied. The danger would be accepting one.
- `CustomerRepository::find()` returns only users holding the `customer` role. `WC_Customer` wraps
  any WordPress user, so without that check `GET /customers/{id}` would hand a support account the
  administrator's email — and guarding the *list* against role enumeration while leaving the single
  read open would be no guard at all.
- The presenter emits identity, name and addresses. No capability map, no session tokens, no
  `user_activation_key`.

The cost of the role check is that a staff account which also shops has no customer profile. Its
orders are still visible under `/orders`.

### Statistics

`GET /customers/{id}` carries them; the list does not, because computing them per row would mean a
query per customer on a page of twenty, and a list is read to find someone rather than to study them.

They are computed on read and never stored. A cached lifetime total is a number that can be wrong,
and every event that invalidates it — a status change, a refund, an order deleted in wp-admin —
happens somewhere this plugin does not control. One query returns the customer's orders and a single
pass produces the counts, the revenue and the first and last order, so they cannot disagree with each
other the way five separate queries can. `limit => -1` is bounded by *one customer's* order count;
store-wide reporting is §60's `ac_analytics_aggregates`, which exists so a dashboard never scans the
order book.

**`total_revenue` counts completed orders only.** For a cash-on-delivery shop the money arrives when
the parcel does — an order in `processing` is stock committed and a courier booked, not revenue.
`average_order_value` divides by that same count, not by every order; dividing collected money by a
count that includes cancellations understates what a sale is worth.

**Money is summed in integer minor units, not as floats.** Adding `0.10` to itself a hundred times in
binary floating point does not give `10.00`, and a lifetime value that disagrees with the sum of its
own orders is worse than no figure. Totals are scaled to whole units, added as integers, and
formatted back at the end — with `round()` before the cast, because `(int)` truncates and `12.30 *
100` is `1229.999…`.

PLAN.md §9 lists "returned orders"; WooCommerce has no `returned` status, so it maps to `refunded`,
the state where the money went back. COD history is §52's, and customer-level notes and an account
status flag are recorded in the roadmap rather than invented here.

### Stock movements reach the ledger through hooks

`OrderStockSubscriber` listens to `woocommerce_reduce_order_item_stock` and
`woocommerce_restore_order_item_stock` rather than being called from `OrderService`. WooCommerce
moves order stock on a status *transition*, from places this plugin does not own — a gateway
completing a payment, a scheduled task expiring a held order, WP-CLI, wp-admin, a future storefront
checkout. A ledger fed only by our own service would miss every movement that did not come through
our API.

Both hooks fire after WooCommerce has written the new quantity, so the figures are the real ones, and
each item is marked once — a second transition into a reducing status writes no rows rather than
double-counting. Order-driven movements write a movement row and **no** audit row: the status change
that caused them is already audited.

### WooCommerce API notes worth keeping

Three things cost real debugging time here:

- **`remove_order_items('line_item')` cannot be followed by `add_product()`** on the same object. The
  plural form deletes immediately and unsets the in-memory group; the next `add_product()` re-reads
  that group and the line it just saved is dropped on the following save — leaving an order with the
  correct total and no items. Use `remove_item($id)` per line, which queues the deletion so
  `save_items()` processes it *before* writing the new lines.
- **`WC_Data_Store` is a `__call` decorator**, so `method_exists($store, …)` is always false. Use
  `$store->has_callable(…)`. Getting this wrong pinned `stock_reduced` to false everywhere and left
  the guard above as dead code that always passed.
- **`wc_get_orders()` returns refunds by default.** `shop_order_refund` is in the default `type`, and
  `WC_Order_Refund` does not extend `WC_Order`. Ask for `'type' => 'shop_order'` explicitly.

## Algerian geography

Roadmap §51, docs/PLAN.md §10. Wilayas, communes, postal codes, and the shipping providers'
destination ids kept separate from all of it.

```
data/algeria/wilayas.json                58 wilayas, with Arabic names
data/algeria/communes.json               1,541 communes, with Arabic names,
                                         daira, national code and coordinates
data/algeria/provider-destinations.json  empty until §53
data/algeria/sources/                    the CSV the two above are built from
```

```bash
docker compose run --rm wpcli wp algerian-commerce import-algeria --dry-run
docker compose run --rm wpcli wp algerian-commerce import-algeria
```

### Where the data comes from

Nothing here was written from memory. A wrong commune name is a rejected valid address and a failed
delivery, so both files are generated from sources that can be re-read and diffed:

- **Wilayas** — WooCommerce's own `i18n/states.php` `DZ` block, all 58 post-2019 wilayas, ISO 3166-2
  aligned. Arabic names come from the commune source.
- **Communes** — `data/algeria/sources/algeria_cities.csv`, 1,541 rows, converted by
  `scripts/build-algeria-dataset.php`.

```bash
docker compose run --rm -T --user "$(id -u):$(id -g)" -v "$PWD/scripts:/scripts" \
  --entrypoint php wpcli /scripts/build-algeria-dataset.php \
  /var/www/html/wp-content/plugins/algerian-commerce-core/data/algeria/sources/algeria_cities.csv \
  /var/www/html/wp-content/plugins/algerian-commerce-core/data/algeria
```

The build step exists so the datasets have a *provenance* rather than an origin story — a 1,541-row
file is only reviewable as a diff against a re-run.

### The source needed two corrections, and both are derived from it

The CSV carries **69** wilaya codes. Algeria has 58. The build script resolves the difference from
evidence inside the file and prints what it did, so neither correction rests on anyone's memory:

1. **Codes 59–69 are circonscriptions administratives**, not wilayas — Aflou, Barika, Messaad,
   Boussaâda and seven others. Their parent is read off `code_commune`, whose leading digits are the
   wilaya: Aflou's 9 communes all say `3` (Laghouat), Boussaâda's 13 all say `28` (M'Sila), and so on
   for all 11. That folds 92 communes back where they belong.
2. **The 2019 Touggourt split was half-applied.** Eleven rows carry Ouargla's code 30 while being
   named Touggourt, which exists separately as code 55. The script follows the name, and Touggourt
   ends with its 13 communes instead of 2.

One row in the source has a `code_commune` of `7003`, implying wilaya 70. The range check rejects it
rather than letting it vote, which is why El Kantara resolves cleanly to Biskra.

After both corrections: **58 wilayas, 1,541 communes — Algeria's exact count — every wilaya
non-empty, and no two communes in a wilaya colliding on their slug.**

### What is not in the data

**Postal codes.** The source has none. `code_commune` is the *national commune code*, three or four
digits against Algeria's five-digit postal codes, so it is stored as `national_code` and
`postal_code` is left empty. Mapping one to the other would have put a wrong postal code on every
address in the country.

Coordinates are carried for §53 shipping rather than used now — they arrive with the same rows, and
dropping them would cost a migration and a full re-import to undo.

### Why custom tables

WooCommerce stores a flat `state` string per address and has no concept below it. Communes are the
level Algerian couriers actually deliver to, there are ~1,500 of them, and shipping rates and
destination ids hang off them — the test docs/ARCHITECTURE.md §7 sets for a table of our own.

**The wilaya primary key is the official code, 1–58, not an auto-increment.** It is a real natural
key: every Algerian knows Alger is 16, it is on number plates and identity documents, and the 2019
reform that took the count from 48 to 58 *added* codes 49–58 rather than renumbering. So
`/locations/wilayas/16` is Alger, which is what anyone would guess.

Communes have no numbering anyone agrees on across sources, so they get an auto-increment id and a
natural unique key of `(wilaya_id, slug)`.

### Slugs are accent-folded, and that is the point

Algerian place names arrive spelled both ways — Béjaïa and Bejaia, M'Sila with a straight or a curly
apostrophe, Aïn Témouchent and Ain Temouchent. The slug folds all of it, so a dataset that corrects
its spelling next year **updates** the row instead of inserting a second commune beside the first.

`GeoSlug` uses a fixed replacement table rather than `iconv()//TRANSLIT`, whose output varies with the
C library and locale, and rather than `sanitize_title()`, which is filterable. A natural key that can
differ between two servers is not a key.

### Import is all-or-nothing, and never deletes

One bad row imports nothing. A half-loaded geography is the worst state available here: addresses
would validate in some wilayas and be rejected in others with no sign which. `GeoDataset` collects
every problem in the file and the command reports them together, because fixing 1,500 rows one run at
a time is not a workflow.

Re-importing is an upsert on the natural keys, so it is idempotent — the REST suite runs it twice and
asserts the second pass inserts nothing. Nothing is ever deleted: a commune dropped from a newer
dataset is deactivated, because a delivered order may still point at it.

### Provider ids live in their own table

Roadmap §51 asks for them to be stored separately, and the reason is churn. Yalidine and Zedair
renumber their destinations on their own schedule; holding their ids in a column on `ac_geo_communes`
would make a provider's housekeeping a migration of the canonical Algerian data, and adding a third
provider a schema change. `ac_geo_provider_destinations` carries one row per `(provider, wilaya,
commune)`, with `commune_id` 0 meaning a wilaya-level destination — how stopdesk services are
addressed. `destination_id` is a varchar because it is the provider's identifier and ours to store,
not to parse.

It stays empty until §53, when the adapters are written from each provider's current official docs.

### These are the only public endpoints

`/locations/*` is the one place in this plugin a `permission_callback` returns true. The payload is
Algeria's administrative divisions — public information with no customer, order or shop data in it,
and the same list every delivery site in the country shows. Guarding it would force the Next.js
server to proxy every commune autocomplete keystroke to fetch something that is on Wikipedia. Rate
limiting still applies; the guard is registered across the whole namespace.

There is no write surface: the dataset changes through WP-CLI, which is a deployment step, not a
request.

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
4. Use `$this->paginationArgs()` and `$this->idArg()` rather than hand-rolling them.
5. Register it in `Plugin::restApi()`.

### The args trap

**An arg with a `sanitize_callback` and no `validate_callback` is not validated at all.**
`WP_REST_Request::has_valid_params()` runs a `validate_callback` only when one is registered.
Constraints normally still apply because `sanitize_params()` defaults a *missing* `sanitize_callback`
to `rest_parse_request_arg()`, which validates on the way through — supplying your own replaces that
default and takes validation with it. `'minimum' => 1, 'sanitize_callback' => 'absint'` therefore
enforces nothing.

This bit every list endpoint here: `per_page` carried `'maximum' => 100` and `absint`, so
`?per_page=100000` was passed straight to the query. Wherever a `sanitize_callback` sits beside a
`minimum`, `maximum`, `enum` or `pattern`, spell out `'validate_callback' => 'rest_validate_request_arg'`.

`sanitize_key()` has a related edge: it strips periods, so it must not be used on dotted values such
as this plugin's audit action names.

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

Unit tests must run without booting WordPress. The full WP integration suite arrives with §65.

```bash
scripts/test.sh              # every stage
scripts/test.sh unit         # one stage: syntax | unit | rest | http
```

| Stage | What it covers | Blind to |
| --- | --- | --- |
| `syntax` | `php -l` over every file | everything else |
| `unit` | pure logic, no WordPress (`tests/Unit`) | anything touching WP |
| `rest` | routing, args, permissions, IDOR (`tests/Api`) | authentication, rate limiting |
| `http` | authentication, rate limiting (`scripts/test-api.sh`) | — |

**Run this one before touching auth or rate limiting.** `rest_do_request()` — what the in-process
checks use — never parses an `Authorization` header, so it cannot observe authentication or rate
limiting at all. The first rate-limit guard shipped letting every credential-guessing attempt
through, with all 321 unit tests green, because nothing exercised a real HTTP request with real
credentials. Comment out the two `add_filter` calls in `RateLimitGuard::register()` and the unit
suite stays green while `scripts/test-api.sh` turns red on exactly the right assertions — that is
the regression it exists to catch.

## Configuration

Secrets come from environment variables only — never the options table, never code. Feature flags
(`ENABLE_COD`, `ENABLE_CHARGILY`, `ENABLE_YALIDINE`, `ENABLE_ZEDAIR`, …) all default to off so one
codebase can serve multiple clients. `AC_LOG_LEVEL` sets the log floor (`debug`, `info`, `warning`,
`error`); it defaults to `debug` when `WP_DEBUG` is on, otherwise `info`.
