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
(orders and customers), §51 (Algerian geography), §52 (COD), §53 (the shipping abstraction), §4
step 28b (shipping rules and pricing, PLAN §14), §56 (Yalidine), §57 (ZR Express), §58 (the payment
abstraction), §59 (Chargily), §60 (all three webhooks), §61 (CMS and media) and §62 (SEO) are in,
and §62b (the marketing event layer and Meta's Conversions API), §63 (analytics) and §64
(import/export), along with rate limiting. Not implemented yet: 2FA, customer sessions, notifications.

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
src/COD/                     CodController, CodService, CodStatus, CodState, CodAttemptInput,
                             CodSettingsInput, CodStatistics, CodRepository, CodSubscriber
src/Shipping/                ShippingProviderInterface, ShippingController, ShippingService,
                             ShipmentStatus, Destination, ShipmentRequest, ShipmentResult,
                             StatusReport, RateQuote, Shipment, ShipmentInput,
                             ProviderRegistry, ManualProvider, ShipmentRepository,
                             ShippingRule, ShippingRuleInput, RateResolver,
                             ShippingRuleRepository, ShipmentPoller,
                             DestinationCatalogueInterface, ProviderPlace,
                             DestinationMatcher, DestinationSyncPlan,
                             DestinationSyncService, ProviderDestination,
                             DestinationDirectoryInterface, GeoDestinationDirectory
src/Http/                    HttpClientInterface, HttpResponse, WpHttpClient,
                             HttpTransportException — the seam that makes an
                             adapter testable without a network
integrations/Yalidine/       YalidineProvider, YalidineClient, YalidineParcel,
                             YalidineStatusMap, YalidineDestinations,
                             YalidineSettings, YalidineCredentials
integrations/ZRExpress/      ZRExpressProvider, ZRExpressClient, ZRExpressParcel,
                             ZRExpressStateMap, ZRExpressTerritories,
                             ZRExpressSettings, ZRExpressCredentials
src/Geography/               LocationController, GeoService, GeoDataset, GeoSlug,
                             GeoRepository, GeoImporter
src/CMS/                     ContentTypes (the post types, taxonomy and menu
                             locations), CmsController, CmsService,
                             CmsRepository, CmsPresenter, HomepageSections
src/Media/                   MediaController, MediaService, MediaRepository,
                             MediaPresenter, MediaInput, UploadedFile,
                             UploadPolicy (every rule POST /media enforces),
                             ImageSanitizer (the metadata strip)
src/SEO/                     SeoFields (the rules, pure), SeoInput, SeoSubject,
                             SeoRepository, SeoResolver — a block on the
                             product and page payloads, not an endpoint
src/Marketing/               MarketingProviderInterface, MarketingEvent,
                             MarketingResult, MarketingProviderRegistry,
                             MarketingService, MarketingEventRepository,
                             MarketingController, UserData (the hashing —
                             raw PII never leaves it)
integrations/Meta/           MetaProvider, MetaClient, MetaCredentials,
                             MetaSettings — the Conversions API, server half only
src/ImportExport/            CsvWriter (formula escaping, pure), CsvReader,
                             InventoryRow, ImportReport, WooCsv (loads
                             WooCommerce's CSV engine), ProductCsvExporter,
                             ImportService, ExportService, ImportExportController
src/Analytics/               AnalyticsRange (the window, pure), Metrics,
                             RevenueReport (PLAN §28, pure), AnalyticsCache,
                             AnalyticsRepository (the aggregate SQL, all of it),
                             AnalyticsService, AnalyticsController
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
| GET | `/orders/{id}/cod` | `ac_manage_orders` | the order's COD state |
| PATCH | `/orders/{id}/cod` | `ac_manage_orders` | turn COD on or off for that order (`enabled`) |
| POST | `/orders/{id}/cod/attempts` | `ac_manage_orders` | record a confirmation call → 201 (`outcome`, `reason`) |
| GET | `/cod/statistics` | `ac_view_analytics` | the COD funnel (`customer_id`, `date_from`, `date_to`) |
| GET | `/shipping/providers` | `ac_manage_shipping` | the couriers this shop has, and which is default |
| GET | `/shipping/rates` | `ac_manage_shipping` | quotes for a destination (`wilaya_id`, `commune_id`, `delivery_type`, `provider`, `subtotal`) |
| GET | `/shipping/rules` | `ac_manage_shipping` | the shop's tariff, narrowest first (`wilaya_id`, `commune_id`, `provider`, `delivery_type`, `is_active`) |
| POST | `/shipping/rules` | `ac_manage_shipping` | add a rate rule → 201 |
| GET | `/shipping/rules/{id}` | `ac_manage_shipping` | read |
| PATCH | `/shipping/rules/{id}` | `ac_manage_shipping` | change a price, threshold or estimate |
| DELETE | `/shipping/rules/{id}` | `ac_manage_shipping` | remove a rule |
| GET | `/orders/{id}/shipments` | `ac_manage_shipping` | the order's parcels, newest first |
| POST | `/orders/{id}/shipments` | `ac_manage_shipping` | hand a parcel to a courier → 201 |
| GET | `/shipments` | `ac_manage_shipping` | list (paginated; `order_id`, `provider`, `status`, `tracking_number`, `date_from`, `date_to`) |
| GET | `/shipments/{id}` | `ac_manage_shipping` | read |
| PATCH | `/shipments/{id}` | `ac_manage_shipping` | move it on by hand (`status`) |
| POST | `/shipments/{id}/cancel` | `ac_manage_shipping` | call it off at the provider |
| POST | `/shipments/{id}/sync` | `ac_manage_shipping` | ask the courier where it is |
| GET | `/customers` | `ac_manage_customers` | list (paginated; `search`, `orderby`, `order`) |
| GET | `/customers/{id}` | `ac_manage_customers` | profile **and** lifetime statistics |
| PATCH | `/customers/{id}` | `ac_manage_customers` | name, email and addresses — never roles or credentials |
| GET | `/customers/{id}/orders` | `ac_manage_customers` | order history (paginated; `status`, `orderby`, `order`) |
| GET | `/locations/wilayas` | **public** | all 69 wilayas (`search`, `active_only`) |
| GET | `/locations/wilayas/{id}` | **public** | one wilaya, by its official code |
| GET | `/locations/wilayas/{id}/communes` | **public** | its communes (`search`, `postal_code`, `active_only`) |
| GET | `/locations/communes/{id}` | **public** | one commune |
| GET | `/locations/coverage` | **public** | how much of the dataset is loaded |
| GET | `/cms/homepage` | `ac_manage_content` | the ordered `{type, data}` sections §23 defines |
| GET | `/cms/pages/{path}` | `ac_manage_content` | a published page, by **path** (`legal/terms`), content rendered |
| GET | `/cms/banners` | `ac_manage_content` | list (paginated; `placement`, `search`) |
| GET | `/cms/faqs` | `ac_manage_content` | list (paginated; `category`, `search`) |
| GET | `/cms/menus/{location}` | `ac_manage_content` | a nav menu as a tree (`primary`, `footer`) |
| POST | `/media` | `ac_manage_content` | multipart upload, one file, `alt`/`title`/`caption` alongside → 201 |
| GET | `/media` | `ac_manage_content` | the library (paginated; `search`, `type`, `orderby`, `order`) |
| GET | `/media/{id}` | `ac_manage_content` | read |
| PATCH | `/media/{id}` | `ac_manage_content` | alt text, title, caption — never the bytes |
| DELETE | `/media/{id}` | `ac_manage_content` | permanent, file included |
| GET | `/analytics/overview` | `ac_view_analytics` | the dashboard headline (`range`, `date_from`, `date_to`) |
| GET | `/analytics/revenue` | + `ac_manage_orders` | PLAN §28's financial report |
| GET | `/analytics/orders` | `ac_view_analytics` | counts by status, cancellations, refunds |
| GET | `/analytics/products` | `ac_view_analytics` | best sellers and the low-stock count |
| GET | `/analytics/customers` | `ac_view_analytics` | new against returning, and guest orders |
| GET | `/analytics/shipping` | `ac_view_analytics` | delivery rate, provider performance, by wilaya |
| GET | `/analytics/cod` | `ac_view_analytics` | the COD funnel over a window |
| POST | `/import/products` | `ac_manage_products` | CSV body; `dry_run` (default true), `mode=create\|update` |
| POST | `/import/inventory` | `ac_manage_inventory` | CSV body; `dry_run` (default true) |
| GET | `/export/products` | `ac_manage_products` | WooCommerce's own 40-column CSV |
| GET | `/export/inventory` | `ac_manage_inventory` | the columns `/import/inventory` reads back |
| GET | `/export/orders` | `ac_manage_orders` | one row per order (`status`, `date_from`, `date_to`, `limit`) |
| GET | `/export/customers` | `ac_manage_customers` | customer records, without lifetime statistics |

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
- **The trash counts, and finding that out cost a 500.** `wc_get_product_id_by_sku()` excludes
  `post_status = 'trash'`, but WooCommerce's data store does not — `wc_product_meta_lookup` keeps the
  trashed product's row and inserting against it throws *"already present in the lookup table"* from
  inside `$product->save()`. So the conflict check answered "free" for a SKU the write was about to
  refuse, and an admin who trashed a product and re-created it got `500 internal_error` every time.
  `ProductRepository::skuExists()` now checks the trash too — through `wc_get_products()` with the
  statuses named, not SQL against WooCommerce's table — and the 409 carries `trashed_product_id`,
  because "already in use" about a product no longer in the catalogue is the sort of answer that costs
  somebody an afternoon. Found by roadmap §69's HTTP walkthrough; the regression test is
  `tests/Api/products.php` → "the SKU the trash keeps".
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
the state where the money went back. COD history is answered by `GET /cod/statistics?customer_id=`
(below), and customer-level notes and an account status flag are recorded in the roadmap rather than
invented here.

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

## Coupons (§21, step 33)

```bash
GET/POST /coupons            GET/PATCH/DELETE /coupons/{id}
```

No migration and no table: coupons are `shop_coupon` posts. HPOS moved *orders* to custom tables and
left these where they were, so `WP_Query` is WooCommerce's current storage for them — the "never
`get_post()`" rule is about orders specifically.

**This step was owed.** §59b shipped `POST /cart/coupons`, so a shopper could apply a discount the API
had no way to create; a shop had to open wp-admin. `tests/Api/coupons.php` closes the loop — it creates
a coupon through the API and asserts it takes 500 DZD off a §59b cart.

`ac_manage_coupons` already existed (Admin, Manager, Marketing Manager) — **no new capability**. The
admin/shopper split matters: a public endpoint that *listed* coupons would hand every visitor the shop's
discount schedule, including codes meant for one customer's apology. Applying a code you know is a
different act from discovering which codes exist.

**PLAN §21 asks for ten things and WooCommerce supplies nine.** "Maximum discount where supported" is the
tenth, and it is not supported: `maximum_amount` caps the *cart a coupon may be used against*, not the
discount produced. A 50% coupon on a 100,000 DZD cart discounts 50,000 and nothing stops it. So
`maximum_discount` is **refused by name with the reason**, and `maximum_amount` is exposed under its real
meaning — a shop owner must not set one believing they set the other.

### Two round-trip bugs, both found on the suite's first run

Both the same shape: **the read body could not be written back.**

- `CouponPresenter` emitted `date_expires` as ISO 8601 while `CouponInput` demanded `Y-m-d`.
- WooCommerce stores an absent threshold as the string `'0'`. The presenter published that as `"0.00"`
  and the input compared it as a real minimum, so every coupon with a minimum spend and no maximum failed
  `min ≤ max` against a maximum that did not exist.

A client PATCHing back a body this API had just produced got a 400 about two fields it never touched.
Thresholds are now `null` when absent — the treatment `usage_limit` already had — and the input accepts
either date shape.

## Notifications (§29, §30, step 34)

Migration **010**. No REST routes: §29 asks for an abstraction, not an endpoint.

```bash
wp algerian-commerce send-notifications [--limit=<n>] [--channel=<name>] [--summary]
```

```text
NotificationChannelInterface  →  EmailChannel
Plugin::notificationChannels()   the only place a channel's configuration is read
ac_notifications                 the claim and the queue, one table
```

**Nothing is sent on a request path.** §62b settled this for marketing conversions and it is stronger
here: a confirmation is queued while an order is saved, so an SMTP server that takes thirty seconds puts
thirty seconds on a checkout, and one that is down fails an order that has already taken money.

**The claim and the queue are one table.** `UNIQUE (channel, dedupe_key)` makes "this event, on this
subject, once" a database guarantee — which is why `NotificationSubscriber` filters no hooks at all.
`woocommerce_order_status_changed` fires on every transition and `ac_shipment_saved` on every write;
eight firings produce one message, without a comparison that has to be right in eight places.

**The message is frozen when the row is written.** An order refunded between queueing and sending must
still deliver the confirmation that was true when it was placed, or a customer gets a receipt describing
a state they never saw. The suite changes an order's total after queueing and asserts the message does
not follow.

**`ac_shipment_saved` is the one hook this project added**, in `ShipmentRepository::update()` rather than
`ShippingService`, because there are two write paths and only one goes through the service: an admin
changing a status, and `ShipmentPoller` recording what the courier said. The second is the one that
matters — "delivered" almost always arrives from a poll.

**The CLI command is the drive; the cron is a convenience.** Nothing runs WP-Cron on a headless backend
nobody browses (§63 refused a rollup table over exactly that). A deployment that wants its customers to
receive mail points a system scheduler at `send-notifications`.

Three behaviours worth knowing:

- **Low stock claims once and is re-armed on restock.** Deduplication stops an hourly email about a line
  that has been low all week; `woocommerce_product_set_stock` clears the claim once the product is back
  above its threshold, so the next fall warns again. WooCommerce provides the first half, not the second.
- **A COD order is not a paid order.** `processing` is reached without payment for cash on delivery, so
  the payment message is gated on `$order->is_paid()` — or every COD customer is told their money arrived.
- **A permanent failure is not retried.** A malformed address is `rejected` and parked; a timeout is
  `failed` and retried up to `MAX_ATTEMPTS`, so one dead row does not hold up the queue behind it.

### Deferred, with reasons

**Password reset by email.** §30 lists it and it does not belong in this queue: a five-minute drain is
right for a shipment update and wrong for a reset, and sending it inline puts the SMTP timeout back on a
user-facing request. It needs a synchronous mail path this project does not have.

**SMS, WhatsApp, push, in-app.** §29 names them as *potential* channels and says to activate only what is
configured; none has credentials here. Each is one class implementing `NotificationChannelInterface` plus
one `add()`.

**No successful send is asserted anywhere.** This stack has no SMTP server: `wp_mail()` fails with
`sendmail: can't connect`, the row stays pending with the reason in `last_error`, and that is the queue
working correctly. A test that needed a mail server would be a test nobody could run.

## Shopper accounts (§59c)

```bash
POST /account/register   {email, password, first_name?, last_name?}   -> {customer, token, expires_at}
POST /account/login      {email, password}
POST /account/logout
GET  /account            PATCH /account      POST /account/password   {current_password, new_password}
GET  /account/orders     GET   /account/orders/{id}
```

No migration and no table. Sessions live in WordPress's own `session_tokens` user meta.

The session travels as the `X-Customer-Token` header (or a `customer_token` parameter) and is returned in
the response body.

### The IDOR this section exists to close

Order history is where a shopper edits `/orders/123` to `/orders/124` and reads a stranger's name, phone
and address — with nothing erroring, because the request is valid and only the authorization is missing.
Three things had to hold and **the third is the one that gets skipped**:

1. no `customer_id` parameter exists — `AccountSession` takes no user id, so the list cannot be
   redirected by any spelling of one;
2. the Next.js server never forwards a browser-supplied id under its privileged credential — that half
   lives in the storefront repository;
3. **this API checks ownership itself**, in `AccountService::order()`, because a check that lives only in
   the storefront is a check the second client removes.

`Permissions::assertOwnsOr()` was written in §50 and had no call sites until this module. It has two now.
`tests/Api/account.php` proves it in the shape §65 requires — *A is refused B's order **and** A is
served their own* — against a real second account with a real order, because a refusal alone proves only
that the route is broken. It also fires `customer_id`, `customer`, `user_id`, `id` and `author` at
`/account/orders` and asserts the list does not move.

**A guest order belongs to nobody and stays unreachable.** `customer_id` 0 can never match a session.
Deliberate: the only evidence linking a shopper to a guest order is an email address, and treating that
as proof of ownership would make the order readable by anyone who could name the address on it.

### The session is core's, not ours

`wp_generate_auth_cookie()` in, `wp_validate_auth_cookie()` out. Five properties come free, all measured
before the module was written:

```text
a valid token           -> the user id
a tampered payload      -> false   (HMAC over wp_salt('logged_in'))
after logout            -> false   (bound to a WP_Session_Tokens entry)
after a password change -> false   (the HMAC covers a fragment of the hash)
after expiry            -> false
```

Writing our own would mean owning all five; `Auth/AuthService` already declined to reimplement credential
storage for the same reason. A shopper who changes their password logs every stolen session out, and
nothing here arranges it.

**Returned in the body, never set as a cookie.** This API cannot set a cookie the storefront's origin
would return, and a cross-site cookie is precisely what §65's CSRF rule-out depends on not existing. The
Next.js server puts the token in its own HTTP-only cookie, so the browser never holds it — which is the
property §44 protects, satisfied more strictly than by a cookie this API could set.

### Two §44 rules, asserted rather than assumed

- **A customer never receives an Application Password.** Checked in the suite, because the failure is
  silent: an account quietly holding one would authenticate against every staff route with the credential
  this module handed it.
- **A staff account cannot use the customer door.** `authenticate()` refuses any account holding an
  `ac_*` capability even with the right password — checked against the capability vocabulary rather than
  the role name, since a site owner can add a capability to any role. Otherwise this is a second login
  for administrators, minting a bearer token that bypasses the Application Passwords §44 chose.

Login failures answer with **one message for every cause**. A login that says "no such account" is a
user-enumeration oracle over the shop's customer list. Registration is the one deliberate exception: a
duplicate email is a 409, because a signup form that accepts a duplicate silently cannot tell a customer
why they later cannot sign in.

### Two things this section found

- **The brute-force guard does not watch this door.** `RateLimitGuard` hooks
  `application_password_failed_authentication`, WordPress's admin path; a customer login goes nowhere
  near it. `AccountService` records the failure itself and `scripts/test-api.sh` asserts the 429 — only
  that stage can see a client IP. Without it, customer logins were unlimited.
- **Authentication must answer before input validation.** `POST /account/password` validated its payload
  first, so an anonymous caller received a 400 listing the endpoint's fields rather than a 401.

### Deferred

**Password reset by email.** The token half is easy; delivery has no home until `Notifications/` exists
(PLAN §29, §30). A reset link generated and never sent is worse than an absent feature, because it looks
like one that works. Registration already shows the gap: WooCommerce's new-account email fails in
development with `sendmail: can't connect`, which is a configuration fact rather than a defect.

## Cart and checkout (§59b)

```bash
GET    /cart                          POST   /cart/items          {product_id, variation_id, quantity}
DELETE /cart                          PATCH  /cart/items/{key}    {quantity}   0 removes
POST   /cart/coupons {code}           DELETE /cart/items/{key}
DELETE /cart/coupons/{code}
GET    /checkout/shipping-rates?wilaya_id=&commune_id=&delivery_type=
POST   /checkout                      {billing, shipping?, wilaya_id, commune_id, delivery_type,
                                       payment_method?, customer_note?}
```

No migration and no table. The cart lives in `wp_woocommerce_sessions`, which is WooCommerce's.

**The cart is `WC_Cart`.** Line totals, tax, rounding, stock and §21's coupon rules — usage limits,
expiry, minimum spend, product and category restrictions — are all WooCommerce's, and reimplementing
them would fork the data model this project does not fork and re-derive security-critical arithmetic
that is already written. This module owns the boundary: validation in, our envelope out, errors that
name the field.

Store API's *cart* was reusable and its *shipping and checkout* halves were not. Measured 2026-08-16:
this install has **zero WooCommerce payment gateways enabled** and **zero shipping zones**, because §58
put payment behind `PaymentProviderInterface` and §14 replaced zones with `ac_shipping_rates`. So
`wc/store/v1/checkout` cannot take a payment here and its `shipping_rates` is always empty. Store API
also sits on core's CORS, which reflects any origin with `Allow-Credentials: true`; `API/Cors` governs
our namespace only.

### The session — three things that cost real time

There are no cookies in a headless request, so `StoreApi\SessionHandler` is swapped in: WooCommerce's
own cookie-free handler, keyed by a signed token in a header. Using it keeps the signature, the expiry
and the secret WooCommerce's problem — a hand-rolled cart token is a credential, and `Auth/AuthService`
already declined to write one of those.

```php
add_filter('woocommerce_session_handler', fn () => SessionHandler::class, 0);
wc_load_cart();
(new WC_Cart_Session(WC()->cart))->get_cart_from_session();   // <-- required, see below
```

- **`wc_load_cart()` does not populate the cart.** `WC_Cart_Session::get_cart_from_session()` is hooked
  to `wp_loaded`, which fired long before any REST route runs, so the request gets a valid session
  holding the shopper's items beside an **empty cart** — data present, nothing raised, basket reported
  empty. Neither `WC_Cart::get_cart()` (guarded by `did_action('woocommerce_cart_loaded_from_session')`,
  already fired) nor a fresh `new WC_Cart()` fixes it. Constructing `WC_Cart_Session` directly is the
  public path that is not guarded, and running it twice does not duplicate lines.
- **`WC_Cart::needs_shipping()` is permanently false here.** It returns false when
  `wc_get_shipping_method_count()` is zero — a count of methods in *WooCommerce's shipping zones*, which
  §14 deliberately does not use. A cart holding a rug reported `needs_shipping: false`, checkout skipped
  the §14 quote on the strength of it, and an order was created with no delivery charge and a total
  short by the entire shipping cost. `CartService::needsShipping()` asks the products instead, which is
  a fact about the goods that no zone configuration can invalidate.
- **`CartSession::load()` keys on the token, not on "already loaded".** The load-once guard is right
  inside one HTTP request and wrong inside `tests/Api/cart.php`, where forty `rest_do_request()` calls
  share a process: "a forged token opens an empty cart" passed **against the previous caller's cart**,
  leaving the module's central security property untested. `$_SERVER['HTTP_CART_TOKEN']` is also
  cleared rather than left stale, or an anonymous request inherits the last basket.

### Public, and the token is the owner

`/cart` and `/checkout` answer `__return_true` — the third and fourth entries in that allowlist after
`/health`, `/locations/*` and the webhook routes. No capability could gate them: a shopper has no
WordPress account at this point and §44 forbids giving one an Application Password, so requiring
authentication would mean the storefront proxying every quantity change with an admin credential.

The token is signed with the site salt, expires in 48 hours, and a **forged one opens an empty cart
rather than somebody else's** — stronger than "unguessable", and asserted in `tests/Api/cart.php` beside
the control that stops the assertion passing vacuously. It is accepted as the `Cart-Token` header
(Store API's name, so a storefront can move either way) or a `cart_token` parameter, and returned in
`meta.cart_token` rather than a response header — a header is invisible to `rest_do_request()`, which is
the blindness that put §64's download headers in `scripts/test-api.sh`. An empty cart is issued no
token: a shopper who has added nothing needs no identity, and minting one means a session row for every
crawler.

### Every number in a cart is a request, not a fact

`LineInput` accepts `product_id`, `variation_id` and `quantity`. It refuses `price`, `line_total`,
`line_subtotal`, `subtotal`, `total`, `discount` and `currency` **by name, with a reason** — the
`CustomerInput` device, for the same reason: a client that sends a price is a client whose author
believes it decides one, and a 400 saying so is what corrects that before production.

Prices are re-read from the catalogue on every response, not cached from when the line was added.
Shipping comes from `RateResolver` against the destination, never from the payload, and a free-shipping
threshold is compared against the **cart's** subtotal — a caller that could state its own subtotal could
claim to have crossed one.

`RateResolver` is called directly rather than `ShippingService::rates()`, which asserts
`ac_manage_shipping`: a shopper being quoted a delivery price is not reading the shop's shipping
configuration. A courier's own quote is never consulted — what the shop charges is `ac_shipping_rates`
and nothing else.

### Checkout does not take the money

It creates a `pending` order and answers with a hand-off:

```json
{"order": {"id": 2398, "total": "15450.00", "payment_method": "cod"},
 "next": {"action": "create_payment", "endpoint": "/orders/2398/payments"}}
```

`POST /orders/{id}/payments` is §58's existing route and already owns the transaction row, the audit
entry and the provider call. A payment that fails must not orphan an order that succeeded. The cart is
emptied only once the order exists, so a failure anywhere above leaves the basket intact — an order that
was never created is a retry, an emptied cart is a customer starting again.

The destination is stored on the order as `_ac_wilaya_id` / `_ac_commune_id` / `_ac_delivery_type`, so a
later shipment does not have to recover it from a free-text address — the guess `Shipping\ShipmentInput`
refuses to make. A shipping address is optional and its absence means "same as billing"; `null` is a
choice, a malformed object is still an error.

## Cash on delivery

Roadmap §52, docs/PLAN.md §12. COD is how most Algerian orders are actually paid, so the shop phones
the customer before it ships anything, and this module is the record of those calls.

```bash
curl -u "$CRED" .../orders/268/cod
# {"enabled":true,"status":"pending","attempts":0,"confirmed_at":null,"cancelled_at":null,
#  "last_attempt_at":null,"reason":"","allowed_outcomes":["confirmed","rejected","unreachable"]}

curl -u "$CRED" -X POST .../orders/268/cod/attempts -d '{"outcome":"unreachable","reason":"phone off"}'
# 201 → status "unreachable", attempts 1, last_attempt_at stamped
```

### These are not order statuses

`pending → confirmed | rejected | unreachable | cancelled` is a second state machine that runs
*alongside* WooCommerce's, never inside it. PLAN.md §8 lists "COD Pending Confirmation" and "COD
Confirmed" among the operational states and then says not to create redundant statuses when metadata
will do. The two answer different questions — *where is this order in the shop's workflow* versus
*has the customer said yes on the phone* — and a COD shop needs both at once.

So **a COD outcome never changes the order's status.** Confirming records that the customer agreed
and stops there; whether the order then moves to `processing` is a decision made through
`PATCH /orders/{id}`, and preparing a shipment is §53's. A confirmation that quietly advanced the
order would also quietly move stock.

The reverse direction *is* wired up, because it has to be: `CodSubscriber` listens to
`woocommerce_order_status_cancelled` and closes the COD state whatever cancelled the order — this
API, wp-admin, WP-CLI, cron, a gateway. A confirmation queue still calling customers about cancelled
orders is the most visible way a COD workflow embarrasses a shop. It writes no audit row, for the
same reason order-driven stock movements do not: `order.cancelled` is already in the trail, with its
actor and its reason, and this is the consequence of that event rather than a second one.

It also keeps the dependency running one way (ARCHITECTURE §3): `COD/` reads orders, `Orders/` has
never heard of COD. That is why `OrderPresenter` emits no `cod` block — an order list that wants COD
status per row is a real need, but inverting the dependency to serve it is not the way to meet it,
and there is no admin UI asking yet.

### Every legal move is listed, including the ones that stay put

There is no blanket "a state may be re-set to itself" rule as there is for order statuses, because
recording an outcome is an *event*: it increments the attempt counter. `unreachable → unreachable` is
a second failed phone call and has to be legal; `rejected → rejected` would be a call to a customer
this shop has already stopped calling. `confirmed → rejected` is absent while `confirmed → cancelled`
is present — a customer who says yes and later changes their mind has cancelled, and folding the two
together would make the confirmation rate count one event two different ways.

A refused outcome is a **409 naming what is reachable**, so a client renders the buttons that will
work instead of guessing:

```json
{"success":false,"error":{"code":"conflict",
 "message":"A COD order cannot move from \"confirmed\" to \"rejected\".",
 "details":{"from":"confirmed","to":"rejected","allowed":["confirmed"]}}}
```

`GET /orders/{id}/cod` carries the same list as `allowed_outcomes`, so the 409 should be rare.

### Stored as order meta, with the audit trail as its history

The `_ac_cod_*` keys hold the *current* state of one order, which is what order meta is for, reached
through `WC_Order`'s CRUD so the HPOS declaration stays true here too. There is **no migration and no
new table** — `Schema::VERSION` is still 3. The history of who called and what was said is already
append-only in `ac_audit_logs` (`cod.attempt_recorded`, `cod.settings_changed`) and already merged
into `GET /orders/{id}/timeline`, so a `ac_cod_attempts` table would be a third copy of what two
stores hold between them:

```
2026-08-11T22:59:11+00:00 audit  Order cancelled — customer unreachable for three days
2026-08-11T22:59:11+00:00 audit  COD confirmation attempt 2 — confirmed
2026-08-11T22:58:32+00:00 audit  COD confirmation attempt 1 — unreachable — phone off
2026-08-11T22:58:32+00:00 audit  Order created
```

`reason` is the reason given with the **most recent outcome**, and a cancellation does not overwrite
it — the hook carries no reason, and the one an operator typed is already on the `order.cancelled`
audit row with its actor and timestamp. §52 lists "cancellation reason" among the COD fields; it is
recorded, in the store that keeps history, rather than copied into a field that only ever holds the
latest value.

Nothing is written until something happens. An order paid `cod` that no one has touched reads as
`enabled: true, status: pending` — derived from the payment method — so orders that predate this
module behave correctly and no meta row exists for an order nobody has worked on yet. Once the flag
is written explicitly it wins, which is how COD is turned off for a single order without rewriting
how it was paid. `attempts` is stored as a string like all meta, and `'0'` is truthy in PHP: reading
the flag with a plain cast would silently re-enable COD on every order it had been turned off for.

### The funnel

`GET /cod/statistics` costs a fixed number of `COUNT(*)`s no matter how large the order book is —
counts, never loaded orders. **Every rate shares one denominator: every COD order in scope.** Rates
with different denominators cannot be compared, and the tempting alternative — dividing by "orders
that reached a decision" — changes meaning as the pending queue drains. The consequence is worth
stating: a window that includes today is depressed by the orders nobody has called yet, and the
honest way to read it is next to `by_status.pending`.

Confirmation counts orders that were **ever** confirmed, from the `confirmed_at` stamp rather than
the current status, so an order confirmed and later cancelled appears in both the confirmation and
cancellation rates. Both things happened; a shop that counted only the cancellation would conclude
its confirmation process was failing when what failed came after it.

Counting matches what `GET /orders/{id}/cod` reports, deliberately — the funnel runs a second pass
for orders paid `cod` that carry no COD meta, because an order the API calls COD must not be missing
from the statistics that describe COD orders.

`delivery` and `return` are read from the *order* status (`completed`, `refunded`), which is the only
record of an outcome that exists today. The courier's own verdict arrives with §53 and both rates
should be re-derived from it then.

`?customer_id=` is the same funnel scoped to one buyer, which is how PLAN.md §9's "COD history" is
answered. It is a risk **signal**: §52 says not to automatically ban a customer on a single weak
signal, so nothing here blocks, flags or bans anybody. It reports, and a person decides.

### `ENABLE_COD` is deliberately not consulted

The flag decides whether checkout *offers* cash on delivery, which is the payment abstraction in §58.
These endpoints are the operational handling of orders that already exist. A shop that stops taking
new COD orders still has hundreds in flight, and a flag that froze their confirmation queue would
strand exactly the orders that most need finishing.

## Shipping

Roadmap §53, docs/PLAN.md §13. The abstraction, in-house delivery, **Yalidine** (§56) and **ZR
Express** (§57). Nothing above `ShippingProviderInterface` changed when either courier arrived — which
is the property §53 existed to establish, now demonstrated twice rather than asserted. The second one
is the real evidence: two couriers that disagree about almost everything — names against UUIDs,
inline recipients against customer records, one identifier against two — fit the same interface
unmodified.

```php
interface ShippingProviderInterface
{
    public function name(): string;
    public function label(): string;
    public function createShipment(ShipmentRequest $request): ShipmentResult;
    public function cancelShipment(string $providerShipmentId): bool;
    public function getShipmentStatus(string $providerShipmentId): StatusReport;
    /** @return list<RateQuote> */
    public function getShippingRates(Destination $destination): array;
}
```

Everything crossing that boundary is a value object of ours. An adapter never sees a `WC_Order`, so
it cannot reach into an order, read a meta key, or depend on how this shop stores things — and it can
be tested without a database. `ProviderRegistryTest` fakes a whole courier in ten lines, which is the
real measure of whether the seam works: if a second provider were hard to fake, Yalidine would be
hard to add.

The roadmap sketches `createShipment(array $order)`. Typed objects are used instead, because with a
bare array every adapter re-derives what a valid request looks like and the third one gets it subtly
wrong. A provider is also addressed by its **own shipment id**, not by the tracking number: at some
couriers those are the same string and at others they are not, and assuming they are the same is a
bug that only appears at the one that disagrees.

### In-house delivery is a real provider, not a stub

`ManualProvider` is the shop's own driver. A large share of Algerian shops deliver inside their own
wilaya and hand only the distant orders to a courier, so this is something a client actually uses —
and it means the abstraction ships with a working implementation rather than none. Its tracking
number is ours (`MAN-42-2` — the second parcel for order 42), prefixed so nobody mistakes it for a
Yalidine code in a list of both.

It refuses `getShipmentStatus()` with a 409 rather than answering, and the distinction matters:
returning `created` would look like a successful sync, and since a person may already have advanced
the parcel to `in_transit`, the next poll would quietly walk it *backwards*. It quotes no rates
either — not "free", but unpriced, because what a shop charges for its own delivery is §14's zone and
wilaya pricing and a `0.00` here would settle that question on §14's behalf.

### A parcel's status is what the courier says it is

There is no transition matrix, unlike orders and COD, and that is deliberate. We do not control a
parcel — a courier does, and it reports late and out of order. Refusing a status because it did not
follow the sequence we expected would mean our record disagreeing with the physical world to defend a
diagram. One rule *is* enforced: **a finished shipment stays finished.** Once a parcel is delivered,
returned, cancelled or failed, a replayed webhook or a poll that crossed a delivery in flight cannot
reopen it (docs/SECURITY.md — duplicate delivery must never duplicate a shipment or an order
transition).

The vocabulary is ours, and short: `pending`, `created`, `picked_up`, `in_transit`,
`out_for_delivery`, `delivered`, `returning`, `returned`, `cancelled`, `failed`. Each adapter maps its provider's
states onto these **and keeps the provider's own spelling** in `metadata.provider_status`. That
second value is there because a status mapping is the part of a courier integration most likely to be
quietly wrong — a provider adds a state, the adapter's `match` falls through to a default, and every
parcel in that state reads as `in_transit` for a month. With the raw value stored next to the mapped
one that is a query; without it, an outage nobody can explain.

**`returning` was added by §56**, and it is not a Yalidine detail: every Algerian courier carries an
undelivered parcel back through its own network, and that trip takes days. Yalidine spends seven of
its thirty-six states there. `returned` is terminal, so using it would stop tracking a parcel that is
still moving and would leave a COD shop unable to tell *on its way back to me* from *back in my
hands*; `in_transit` reads to an operator as heading to the customer. It is live, not terminal, so no
migration — the column is `varchar(30)`.

### The rules the service enforces

**A shipment never changes the order's status.** Same reasoning as COD: PLAN.md §8 lists "Shipping
Prepared", "Shipped" and "Delivered" among the operational states and then says not to create
redundant statuses when metadata will do. A parcel's progress is a fact about the parcel; whether the
order is then completed is the shop's decision, and on a COD order one taken with money in mind.

**One live shipment per order.** Two parcels for one order is two vans, one of them delivering to a
customer who has already been served. A *finished* shipment does not block a new one, so a re-send
after a failed delivery works without deleting history — and the attempt number is what keeps the two
parcels' tracking numbers apart.

**The destination is validated against the §51 dataset before a provider sees it**, by wilaya and
commune id, and the pair is checked together. A commune id from the new dropdown with a wilaya left
over from the previous selection is the mistake an address form actually makes, and Algeria has
several communes of the same name in different wilayas. This is also why the destination is not
derived from the order's shipping address: `city` and `state` are free text there, and fuzzy-matching
a name spelled several ways in two languages sends parcels to the wrong daira.

**The provider is called last**, after everything that can be refused has been refused. A 400 that
arrives *after* a courier has accepted a parcel leaves a real van carrying an order this system has
no record of. If the row cannot be written even so, `shipment.record_failed` goes to the audit trail
with the tracking number on it — that table has a different failure mode, and it is the last place
the number can still be saved.

### Storage

`ac_shipments` (migration 004, extended by 006; `Schema::VERSION` 6) holds PLAN.md §15's field list and
nothing more. What is particular to one courier — pickup desks, label URLs, parcel dimensions — goes in
`metadata` as JSON, so a third provider is a data change rather than a migration. Nothing in the core may
read a key out of that JSON, or the abstraction has leaked. (A label URL is a credential, not a link —
see [Security review](#security-review-55).)

**One live shipment per order is the schema's job, not a check's.** It used to be a read:
`ShippingService::create()` looked for a live shipment, found none, called the courier, then wrote the
row. Two requests arriving together — a double-clicked button, a retrying client, a future bulk-ship —
both read "none", both handed over a parcel, and the customer got two while the shop paid twice.

Two pieces close it, and they do different jobs:

```
claimOrder()   MySQL GET_LOCK on the order, held across the whole of
               create() including the courier call. The second request
               is refused immediately with 409 and never calls anyone.
               → this is what stops the second parcel existing

live_order_id  the order id while the parcel is live, NULL once it is
               finished, with a UNIQUE index. A unique index ignores
               NULLs, so it reads as "one live shipment per order, any
               number of finished ones" — a re-send after a failed
               delivery still works, which is what 004 was protecting.
               → this is what holds when something bypasses the lock
```

The index alone would not have been enough: it refuses the duplicate *row*, and by then the duplicate
*parcel* is already real — a tidy table and a van carrying the order twice, which is the failure §53's
"call the provider last" rule exists to avoid, arriving through a different door. The lock alone would
not be enough either, since it only covers code paths that remember to take it.

`live_order_id` is derived in `Shipment::toRow()` from `ShipmentStatus::isLive()`, never passed in, so the
live/finished vocabulary stays in the one class that owns it — §56 added `returning` to it and will not be
the last change. Migration 006 backfills existing rows; an install that somehow already has two live
shipments on one order keeps the newest claim and releases the older ones, which are left live, visible and
pollable rather than deleted — a constraint can only honestly promise about data it arrived before.

Unlike the stock ledger these rows are **updated**: a shipment is one parcel whose status changes, not
a history of events. The history is in the audit trail (`shipment.created`, `shipment.status_changed`,
`shipment.cancelled`), which is the store that is append-only by design, and it is recorded against
the *order* — so a parcel's progress lands in the timeline a shop already reads, tracking number
included:

```
2026-08-12T00:17:35+00:00 audit  Shipment created with manual — MAN-400-2
2026-08-12T00:11:05+00:00 audit  Shipment picked_up → delivered (manual)
```

There is deliberately **no unique key** on `(provider, provider_shipment_id)`. It looks like the
idempotency guard, but a shipment created locally and not yet accepted has an empty id and MySQL
treats `''` as a value rather than as absent, so the second such row would be refused. "One live
shipment per order" is a business rule with a reason to give the caller, so the service enforces it
and answers 409.

### What the shop charges — the tariff

Roadmap §4 step 28b, docs/PLAN.md §14. A rule is one price for one kind of
destination, and `0`/`''` mean "any", so a real tariff is a national rate plus
its exceptions rather than sixty-nine rows:

```bash
curl -u "$CRED" -X POST .../shipping/rules -d '{"amount":"800","free_over":"10000"}'
curl -u "$CRED" -X POST .../shipping/rules -d '{"wilaya_id":16,"amount":"500","estimated_days":2}'
curl -u "$CRED" -X POST .../shipping/rules -d '{"wilaya_id":16,"commune_id":1234,"amount":"300"}'

curl -u "$CRED" ".../shipping/rates?wilaya_id=16&commune_id=1234&subtotal=2000.00"
# [{"provider":"manual","service":"standard","label":"Delivery","amount":"300.00",
#   "currency":"DZD","estimated_days":null,"source":"rules","free_shipping":false}]
```

**The narrowest matching rule wins, and only that one.** Rules are not added
together and do not fall back field by field: a shop that has priced a commune
means that price, not that price plus the wilaya's. Every combination of
dimensions scores uniquely — commune 8, wilaya 4, delivery type 2, provider 1 —
so two rules can never tie, which matters because a tie would make the price
depend on row order and change when an unrelated rule was edited. A narrower
*place* deliberately outranks a courier-specific rule: where a parcel is going
is the stronger fact about what it costs.

**Not WooCommerce shipping zones**, and not by preference. WooCommerce keys a
zone on country, state and *postcode*; §51's dataset has no postal codes at all,
and pricing here is routinely per commune, which WooCommerce has no level for.
The storefront is headless, so WC's cart never computes this. Modelling 1,541
communes as postcode lists inside a structure designed for a different shape
would be forking a WooCommerce model rather than using one — which is what
CLAUDE.md forbids. A custom table for a genuinely custom domain is the sanctioned
answer.

**Free-shipping thresholds are compared in integer minor units.** `4999.99 >=
5000.00` on floats is a comparison of two numbers that are not what they were
written as, and the customer one centime short of free delivery is exactly the
one who notices. A basket *exactly* at the threshold qualifies: "free over 5000"
is read by a customer as "spend 5000 and delivery is free", and charging that
basket is the reading nobody expects. No threshold is applied when no `subtotal`
is given — "what does delivery here cost" and "what does delivering *this basket*
here cost" are different questions, and answering the second with an empty basket
quotes full price to someone who qualifies.

The quote endpoint returns the shop's own price **and** the courier's, each
labelled with its `source`. Both are real and they routinely disagree: the shop
is charging the customer, the courier is charging the shop. With no `provider`,
every configured courier is quoted, which is what PLAN §14's "provider selection"
needs to be possible at all.

`is_active` suspends a rule without losing it; deleting one is a real delete,
because a tariff row is configuration rather than the record of something that
happened — and the audit trail keeps what it said, which is what anyone asking
"why was this customer charged that" actually needs.

## Yalidine

Roadmap §56. The adapter lives in [`integrations/Yalidine/`](integrations/Yalidine/) — outside `src/`,
because "which of this code is ours and which is shaped by somebody else's API" is worth being a
directory rather than a convention.

### Written blind, then verified

It was written without a merchant account or a sandbox, from three independent implementations that
agree on every endpoint and field name — chiefly a Spring Boot service running in production against
the live API. Roadmap §54 forbids writing an adapter from memory; it does not forbid writing one from
working code. Everywhere those sources were silent, the guess was marked in the code rather than
smoothed over:

```bash
grep -rn 'ASSUMPTION' integrations/Yalidine
```

**On 2026-08-14 those markers were tested against the live API**, with the merchant credentials of
that same project and its owner's permission: read-only calls first, then two test parcels created
and deleted again. Most held. Three did not — which is the entire reason for writing an assumption
down instead of letting it pass for knowledge.

| Assumed | Reality |
| --- | --- |
| `page` selects a page on list endpoints | confirmed |
| wilaya row is `{id, name, zone, is_deliverable}` | confirmed — `is_deliverable` is `1`/`0`, not a JSON boolean |
| commune row shape | confirmed, plus `delivery_time_parcel` / `delivery_time_payment` |
| dimensions and weight are optional | confirmed — a parcel was accepted without any of them |
| `GET parcels/{tracking}` returns a bare object | **wrong: wrapped in `{data:[…]}`**, and a parcel it has forgotten is a 200 with `total_data: 0` rather than a 404 |
| a repeated `order_id` returns the same parcel | **wrong: two parcels, two tracking numbers** |
| cancellation is not in the API | **wrong: `DELETE parcels/{tracking}` works** |
| a rejection is a bare `[]` | half right — a bad commune name comes back as `success: false` with a message naming the field. The `[]` the production logs recorded is rarer than assumed; both are handled |
| `Retry-After` is a number of seconds | still unverified — provoking a 429 means exhausting a live merchant's quota, which is not a reasonable price for reading one header |
| `freeshipping: true` = collect exactly `price` | half verified — the flag round-trips as `1` and the parcel still quotes a `delivery_fee`, so what it changes is who absorbs that fee, which is visible only in a payout |

The quota turned out to be published on **every** response rather than only at a 429:
`second-quota-left`, `minute-quota-left`, `hour-quota-left`, `day-quota-left` — 5, 50, 1,000 and
10,000 on the account tested. The client reads them and waits out a second whose allowance is already
spent instead of earning the refusal, and the poller works in batches of 25 because of that
50-a-minute line.

### Getting a store ready, in order

```bash
wp algerian-commerce import-algeria                                # §51, once
wp algerian-commerce shipping-check                                # are the keys good?
wp algerian-commerce sync-destinations --provider=yalidine --dry-run
wp algerian-commerce sync-destinations --provider=yalidine
wp option update ac_yalidine_settings --format=json '{"origin_wilaya_id":16}'
wp algerian-commerce shipping-check                                # ready?
```

Four things have to line up before a parcel can exist, and each fails differently: credentials in
`.env`, the geography imported, the destinations synced, an origin wilaya set. `shipping-check` is
there so nobody discovers them one 409 at a time while a customer waits.

### Credentials are `.env`; everything else is a setting

`YALIDINE_API_ID`, `YALIDINE_API_TOKEN` and `YALIDINE_WEBHOOK_SECRET` are read **only** in
`Plugin::shippingProviders()`, along with `ENABLE_YALIDINE`. The flag alone is not enough: a courier
registered without keys would show up in `GET /shipping/providers`, be pickable in an admin UI, and
fail at the one moment that costs an order.

Everything a client configures is the `ac_yalidine_settings` option, because this plugin is cloned per
client and a warehouse's wilaya is not a secret:

| key | default | what it is |
| --- | --- | --- |
| `origin_wilaya_id` | `0` | the wilaya this shop ships **from**, by §51 id. Unset refuses to create a parcel |
| `do_insurance` | `false` | whether Yalidine insures the declared value |
| `has_exchange` | `false` | whether the driver takes something back |
| `freeshipping` | `true` | delivery is already inside the order total, so the driver must not charge it again |
| `length` `width` `height` `weight` | `0` | omitted from the payload when zero, rather than sent as a parcel of no size |
| `base_url` | `https://api.yalidine.app/v1/` | https only |
| `timeout` | `15` | seconds |

The reference implementation compiles one client's origin into a 58-case `switch` with a default of
"Béjaïa", and keeps a hard-coded set of unsupported wilayas beside it. Both are per-account facts
pretending to be constants, and this section exists to not do that.

### Destinations are data the courier gives us

`wp algerian-commerce sync-destinations` reads Yalidine's own `wilayas/`, `communes/` and `centers/`
lists into `ac_geo_provider_destinations`: our wilaya or commune id, their id, and **their spelling of
the name**. That last field is the point. Yalidine addresses a parcel by `to_wilaya_name` and
`to_commune_name`, matched exactly, and answers a name it does not recognise with an empty array and
no message at all — which is why "Bouzaréah" works and "Bouzzerea" does not.

Matching is on the accent-folded name (`GeoSlug`, the same natural key the geography importer uses).
A commune only ever matches inside its own wilaya, because Algeria has several communes of the same
name in different ones.

**A wilaya may also be matched on its official code**, and only a wilaya. §56 said never to parse a
provider's ids — written when nobody could check them. The live run checked: across the whole
published list, every wilaya matched by name carried an id identical to the official Algerian code
(54 agreements, no disagreement), while four failed on spelling alone and took 96 communes down with
them, because our dataset took its wilaya names from WooCommerce and so says *Algiers* where every
courier in this market says *Alger*. So the code breaks a tie the name could not: it is consulted
only for a wilaya no name placed, never for a commune, never over a name, and never for a place
another wilaya already claimed. Each such row records `matched_by: code` and is listed in the report,
because nobody chose that match:

```
Matched on the official code — the two names disagree (4):
  wilaya   Alger → Algiers (code 16)
  wilaya   Tipaza → Tipasa (code 42)
```

**Gaps are reported, never guessed at.** The reference implementation falls back to substring matching
at parcel-creation time, which is how a parcel ends up addressed to a place nobody chose. Here a place
that will not match stays unmatched and is named in the report — in both directions, plus the wilayas
this account cannot reach, which is Yalidine's own `is_deliverable` rather than a list in our code.
An unmatched commune is shown with the nearest name we hold and how far away it is, so a person can
settle it in a second without a machine settling it wrongly in a millisecond:

```
Published by the courier, not in this store's geography (355):
  commune  Abou El Hassan — nearest of ours: Abou El Hassane (1)
  commune  Ouled Ahmed Tammi — nearest of ours: Ouled Ahmed Timmi (1)
In this store's geography, not published by the courier (254):
  wilaya   Aflou
Published, and this account cannot deliver there (51):
  wilaya   In Guezzam
```

That distance is a hint and never an action. At one edit these are plainly the same place spelled
differently; at three, *Bitam* and *Batna* are neighbours too, and that mistake is a van driven to
the wrong town.

**The live run's real numbers**, against a working merchant account: of 1,541 communes Yalidine
publishes, 1,261 destinations mapped. What is left is two honest kinds of gap, and neither is a bug
in the sync:

- **~338 transliteration variances** — *In Zghmir* against our *Ain Zghmir*. Two sources romanising
  Arabic differently. Closing them properly means an alias in the §51 dataset, reviewed by someone
  who knows the country, not a fuzzy match.
- **95 communes in the 11 wilayas created after 2019** — Aflou, Barika, Boussaâda and the rest. We
  model 69 wilayas; Yalidine still models 58 and files those communes under their old parent. That is
  a structural disagreement about Algeria's map, it will affect ZR Express too (§57), and it is
  §51-shaped work rather than adapter-shaped.

Stop desks are folded into their commune's row rather than given rows of their own — the table is one
row per (provider, wilaya, commune), and a desk is a property of delivering there.

### The 36 statuses, and the two traps in them

`YalidineStatusMap` maps the complete `last_status` vocabulary onto ours, matched **by whole label,
folded for accents and case** — the dashboard's spelling and the API's may differ, and nobody can
check which without an account.

Two labels are traps that a keyword match walks into, and the reference implementation does:
*Tentative échouée* is a delivery attempt that failed and will be retried — the parcel is with a
driver, not lost — and *Bloqué* is a hold, not an ending. Neither is terminal.

An unrecognised label **throws**, and does not default to anything. A `match` that falls through is
how every parcel in a newly added state reads as normal for a month.

### Polling now, webhooks later

`wp algerian-commerce sync-shipments` — and an hourly WP-Cron event — asks each courier where its live
parcels are, oldest check first, in batches, skipping parcels checked in the last half hour. A quota
or an authentication failure ends the run rather than spending 49 more requests learning the same
thing.

Webhooks are deliberately not here yet. A webhook is an unauthenticated request from the internet that
moves data in this shop; it needs §55's security review, replay protection and idempotency first, and
Yalidine's "signature" is a shared secret in the request *body* (`security_token`) rather than a
signature over it. §56 also requires the payload to be treated as a hint and the parcel re-fetched
anyway — which is exactly this code path, so it is what the webhook will be checked against.

WP-Cron only fires when someone visits the site, which is the wrong property for a shop that is quiet
overnight while parcels move. It is the floor, not the plan: a real deployment points a scheduler at
the command.

### Cancellation, and idempotency we have to provide ourselves

**Cancelling works** — `DELETE parcels/{tracking}`, answering
`[{"tracking": "…", "deleted": true}]`, or `deleted: false` with a reason when it will not. The
adapter first refused to cancel at all, because no source documented the endpoint and §54 forbids
inventing one whose failure mode is a destroyed parcel record. Probing it cost nothing: a delete
aimed at a tracking number that cannot exist answered without destroying anything, and that was the
whole question settled. A refusal comes back as `false`, which is exactly what the interface asks for
— a parcel already collected is a legitimate answer, not a fault — and the shipment stays live,
because the parcel is.

**Yalidine will happily create the same parcel twice.** Posting one `order_id` twice produced two
tracking numbers, so the merchant reference is not the idempotency key §53 assumed it was. The guard
is ours: the adapter runs `GET parcels/?order_id=` before it creates, and hands back the parcel that
already exists rather than putting a second van on the road. Best effort by design — if that lookup
itself fails the create still goes ahead, because a courier that cannot answer a question is not a
reason to refuse a shipment.

### What Yalidine still will not do

**Choosing a specific stop desk.** A collected parcel goes to the first desk the sync recorded in that
commune, and a commune with no desk is refused rather than quietly delivered to the door. Letting a
*customer* pick a desk needs a stop-desk id on the shipment request and a way for a storefront to list
them, which is a checkout question (§58) rather than an adapter one.

**Rates.** `GET fees/` prices a whole wilaya per commune, and all four services — express and
economic, each to the door or to a desk — are returned. An unconfigured or unmapped store quotes
nothing instead of throwing, because `GET /shipping/rates` asks every courier in one call and one
unfinished setup must not take the price list down.

### Still to revisit

§52's COD delivery and return rates still read the *order* status, because with only in-house delivery
a shipment status was hand-entered and no more authoritative than the order. Now that a real courier
reports its own, both should be re-derived from `ac_shipments`. Whether a delivered parcel completes
the order is still deferred: that is an automatic order transition triggered by a third party, and it
wants the replay design the webhook slice brings.

## ZR Express

Roadmap §57, in [`integrations/ZRExpress/`](integrations/ZRExpress/). The second courier, and the
test of whether §53's abstraction was real: **nothing above `ShippingProviderInterface` changed to
add it.** The destination sync, the status poller, `shipping-check` and the CLI it all runs through
were written for Yalidine without knowing this provider existed, and they took it unmodified.

The section was called "Zedair" in the original plan. No such courier exists — `ENABLE_ZEDAIR` and
`ZEDAIR_*` are now `ENABLE_ZR_EXPRESS`, `ZR_EXPRESS_TENANT_ID`, `ZR_EXPRESS_API_KEY` and
`ZR_EXPRESS_WEBHOOK_SECRET`.

```bash
wp algerian-commerce sync-destinations --provider=zrexpress
wp algerian-commerce shipping-check
```

### Written from a specification, then verified

Unlike Yalidine, this one is documented: ZR Express publishes an OpenAPI definition per endpoint
under `docs.zrexpress.app/reference/*.md`, which is where every field name here comes from. It was
then **verified against the live API on 2026-08-15** with a merchant account — read-only calls, then
one test parcel created, retried, polled, deleted and confirmed gone.

That closed both gaps §57 recorded as open:

- **The parcel states.** Not published, and the reference implementation guesses them with substring
  matching on French labels. They are not labels at all: `state.name` is a stable snake_case
  identifier with a separate human `description`, which is far safer to map — an identifier does not
  change because somebody fixed an accent. Twelve were observed across real parcels' state histories
  and are mapped in `ZRExpressStateMap`; anything else raises rather than guessing.
- **The webhook signature.** It is **Svix** — a published scheme (`svix-id`, `svix-timestamp`,
  `svix-signature`, HMAC-SHA256 over `id.timestamp.body`), not something to invent. The webhook still
  waits for §55, as Yalidine's does, but it is no longer a design question.

### Three ways it differs from Yalidine

| | Yalidine | ZR Express |
| --- | --- | --- |
| Destination | wilaya and commune **names**, matched exactly | `cityTerritoryId` and `districtTerritoryId`, **UUIDs** |
| Recipient | inline on the parcel | a **customer record** — search by phone, create if absent, then the parcel carries the UUID |
| Handles | the tracking number is the only id | the parcel id and the tracking number are **different strings** (`16-F51X9VP5QT-ZR`) |

That third row is why `ShipmentResult` has carried both since §53, on the theory that some courier
would eventually disagree. This is that courier.

`externalId` — our `"42-2"` — **is** an idempotency key here: a repeat is refused with a 409, unlike
Yalidine, which cheerfully makes a second parcel. The adapter recovers the existing one instead of
failing.

### Two defects found in the reference implementation

Both are in production code that this adapter was written from, and both were caught by testing
against the live API rather than by reading:

- **`filters` is silently ignored.** `parcels/search` accepts a `filters` object and pays no
  attention to it — filtering by `externalId` returned all 706 parcels on the account. The reference
  implementation recovers duplicate parcels that way, so it takes whichever parcel happens to be
  first and treats it as the customer's. Here the search uses `keyword`, which does match, **and the
  returned row's `externalId` is checked anyway** before it is accepted. The same care applies one
  step earlier: a customer is only reused when a phone number matches exactly, because a near miss
  puts a stranger's name on somebody's delivery.
- **A read-back can lose a parcel.** `POST parcels` answers with an id alone, so the tracking number
  needs a second call — and on the first live run that second call timed out. The parcel existed at
  the courier and this shop reported the whole create as failed. Now nothing after the parcel exists
  is allowed to throw: a shipment with an id and no tracking number is recoverable, one with neither
  is a parcel nobody knows about.

### Coverage, and what the account may quote

A live sync mapped **1,485 destinations** — 54 wilayas, 1,531 communes and 77 pickup points as ZR
Express publishes them. Four wilayas matched on the official code rather than the name (`MSila` →
our `M'Sila`, `Alger` → `Algiers`), and about 100 communes are the same transliteration variance
Yalidine shows.

Coverage is `delivery.canSend`, published per territory: 11 wilayas this account may not send to, and
four it does not list at all — Illizi, Tindouf, Bordj Badji Mokhtar and Djanet, which is exactly the
`UNSUPPORTED_WILAYAS` set the reference hard-codes. §57 forbids copying that list, and the reason is
visible here: it is a per-account fact the API already states.

Rates come from `delivery-pricing/rates/{territoryId}`, asked of the commune first and the wilaya
when there is no commune-level price. ZR Express restricts rate lookups to the supplier's own origin
wilaya and says so in a sentence; that comes back as no quote and a logged explanation rather than an
error, because `GET /shipping/rates` asks every courier at once.

## Payments (§58)

The abstraction only. `PaymentProviderInterface` is the same shape
`ShippingProviderInterface` is, for the same reason: one codebase serves several Algerian clients, and a
gateway is added by writing an adapter without a line changing above the interface.

```php
interface PaymentProviderInterface
{
    public function name(): string;
    public function label(): string;
    public function createPayment(PaymentRequest $request): PaymentResult;
    public function verifyPayment(string $providerPaymentId): PaymentReport;
    public function handleWebhook(array $payload, array $headers, string $rawBody = ''): WebhookResult;
}
```

Roadmap §58 sketches `createPayment(array $order)` and `verifyPayment(): PaymentStatus`. Both became typed
objects, exactly as §53's `array $order` became `ShipmentRequest`: with bare arrays every adapter re-derives
what a valid request looks like and the second one gets it subtly wrong. `PaymentStatus` stayed the name of
the *vocabulary* — a set of constants like `ShipmentStatus` — so the thing coming back is `PaymentReport`.

**`CashOnDeliveryProvider` is the `ManualProvider` of this layer.** COD is how most Algerian e-commerce is
actually paid for, so it is a real method rather than a placeholder, and it means the whole seam is
exercisable before §59 puts a network call behind it. It starts `pending` and stays there: money moves at
the door, and a COD provider reporting `paid` at checkout would mark every unpaid order settled. It refuses
webhooks outright, the way `ManualProvider` refuses a status poll — a request arriving there means something
is misrouted, and returning "nothing to do" would hide that.

This is where **`ENABLE_COD` finally does something.** `COD/` owns the confirmation queue and deliberately
never reads that flag; it gates what checkout may *offer*, and `Plugin::paymentProviders()` is the gate.
`COD/` and `Payments/` do not read each other.

### The rules that protect money

```
amount comes from the order, never from the caller — PaymentInput has
  no amount field, and one sent is refused as an unknown field
amount + currency are re-checked against the order before anything is
  marked paid; a mismatch is a 409 and an audited event, because it is
  either an attack or a bug and both need a human
from `paid`, only `refunded` — PaymentStatus::accepts()
return_url must be an https URL, or this endpoint is an open redirect
  wearing a payment provider's credibility
```

`PaymentStatus::accepts()` earns its place on the third of those. Providers send late `pending` events and
webhooks arrive out of order; without the rule one of them silently un-pays a settled order, and the shop
holds the customer's money while shipping nothing. A `PaymentReport` with no amount **never** matches —
reading `''` as zero would compare equal to nothing and pass silently, which is the worst outcome available
to a check whose entire job is refusing.

### Deliberately not here

No transaction table and no REST controller — **both landed in §59 below**, with Chargily, the first provider
with anything worth storing. Designing those columns against an imagined provider is precisely what §56 says
not to do, and the delay paid: the amount is `decimal`, not an integer of minor units, because Chargily turned
out to quote in dinars.

## Chargily (§59)

Chargily Pay V2, behind the interface above without a line changing over it — the standard ZR Express met on
the shipping side.

```
createPayment   POST checkouts        → ULID + checkout_url, status "pending"
verifyPayment   GET  checkouts/{id}   → the authoritative answer about money
handleWebhook   `signature` header    → HMAC-SHA256 hex over the raw body
```

Written from [dev.chargily.com](https://dev.chargily.com/llms.txt) and cross-checked against Chargily's own
**MIT-licensed** WooCommerce plugin and PHP SDK — read for facts, never copied, exactly as §56 handled
Createk's Yalidine plugin. Their plugin is not usable here for a reason that has nothing to do with quality:
it is a WooCommerce *checkout gateway*, entered through `process_payment()` from a rendered checkout form with
a cart and a browser cookie, and a headless backend never calls it. It also keeps its API key in
`wp_options`, acts on the webhook payload's `status` without re-fetching, and claims no idempotency — three
things this plugin may not do.

### Verified live, 2026-08-15

Chargily hands out test keys to anyone with an email address, so unlike §56 nothing here had to stay a guess.
`grep -rn ASSUMPTION integrations/Chargily` is **empty**, and should stay that way or say why. Four things the
run settled that the reference does not say:

```
expired        a real status, absent from the documented enum — POST
                 checkouts/{id}/expire then read back gives "expired"
1500.50        a fractional amount is accepted, despite the type being
                 documented `integer`; nothing is rounded here either way
checkout_url   comes back as http://, though the docs write https:// —
                 the scheme is corrected, since that URL is where a
                 shopper types card details
account{…}     every response embeds the merchant's own record: company
                 name, trade register, NIS, NIF, satim_credentials —
                 which is why the stored metadata is an allowlist
```

### One key, not two

`.env` used to carry `CHARGILY_WEBHOOK_SECRET` beside `CHARGILY_SECRET_KEY`, written before anyone had read
the documentation and assuming ZR Express's shape. **Chargily signs webhooks with the API secret key
itself.** The second variable had nothing that could correctly go in it, and anything anyone did put there
would have failed every signature check in silence, so it was removed rather than left to be filled in
wrongly.

The key also **picks the environment**: `test_sk_…` is Test Mode, anything else is Live. Two settings that
must agree are one setting that eventually will not, and a live key pointed at the test URL is a shop that
takes no money and says nothing about it.

### Payments are re-fetched, never believed

A verified signature proves who sent a message. It does not prove the money, and here it *cannot*: the
checkout object inside a webhook has **no `currency` field** — a different shape from the API's, also calling
`checkout_url` plain `url` — so docs/SECURITY.md's "amount and currency are re-checked" is unsatisfiable from
the payload. Every path therefore ends in `verifyPayment()`:

```
verify()        an operator or the storefront asks
webhook()       Chargily says so, with a signature → claim → re-fetch
PaymentPoller   nobody said anything for a while, so we ask
```

`PaymentReport::matches()` was tightened here as a result: a report with **no** currency no longer passes a
check that was given one. The lenient reading answered "matches" on a payload where the currency comparison
had simply not run, which is the shape the rule exists to refuse.

### sync-payments

`wp algerian-commerce sync-payments`, plus an hourly cron, is the safety net under the five-minute replay
window. Chargily does not document its retry schedule, so a late retry is refused on purpose — and this is
what stops that costing a payment. It also covers the shop being down for the whole retry window, the
customer closing the tab before the redirect, and a checkout expiring quietly, which no gateway need announce.
A payment still `pending` after 24 hours is closed as `expired`; a checkout lives thirty minutes, so there is
no state in which it could still be paid, and polling a dead row hourly forever starves the live ones.

### Transactions (PLAN §19)

`ac_payment_transactions` is migration 007. **Several rows per order is the design** — a card is refused, the
customer tries again, and both attempts are facts — and the row is written **before** the gateway is called,
because docs/SECURITY.md wants every attempt recorded and the attempt worth having is the one where a gateway
may have taken money on a request this side then dropped. A create that throws closes its row as `failed`.

There is deliberately **no** mirror of migration 006's "one live per order" index. A duplicated parcel is a
van driving somewhere; a duplicated checkout is a link nobody clicks, which expires by itself in thirty
minutes. What protects the money needs no lock: a settled transaction refuses a second payment for the order,
`PaymentStatus::accepts()` refuses to un-pay a paid one, and the webhook event claim refuses to apply an event
twice.

`ac_webhook_events` is migration 008: `UNIQUE (provider, event_id)`, and the insert **is** the idempotency
test. There is no `has()` method, because a read-then-write races exactly when a gateway retries in parallel —
the defect migration 006 fixed for shipments, arriving through a different door.

### Endpoints

```
GET    /payments/methods              what this shop can take money with
POST   /orders/{id}/payments          open a checkout
GET    /orders/{id}/payments          attempts for one order
POST   /payments/{id}/verify          ask the gateway, write down the answer
GET    /payments/{id}                 one attempt
GET    /payments                      filter by provider, status, dates
POST   /webhooks/chargily             signature-verified, registered only
                                        when the provider is
```

All of them carry `ac_manage_payments`, which **Manager does not have** — running the shop day to day is not
the same job as reaching into what a gateway did with somebody's money. `POST /payments/{id}/verify` is a POST
rather than a GET because it calls a gateway and can settle an order; a GET that changes things is one browser
prefetch from doing it by accident.

## Courier webhooks (§60)

The other half of §60, deferred by §56 and §57 until §55 produced a written rule.
`ShippingProviderInterface` gained `handleWebhook()`; everything else about how an
inbound endpoint behaves already existed and was reused rather than copied.

```
POST /webhooks/yalidine
POST /webhooks/zrexpress     (§60 writes it "zr-express"; the route follows
                              ZRExpressProvider::NAME, which is already in
                              every ac_shipments row)
POST /webhooks/manual        registered, and answers webhook_unsupported
```

### One rule, two very different couriers

```
ZR Express   Svix. HMAC-SHA256 over {svix-id}.{svix-timestamp}.{body},
               base64, against the base64 key behind `whsec_`. Several
               space-separated "v1,<sig>" values may arrive and any one
               matching passes — that is how keys rotate. The timestamp
               is signed material, so the 5-minute tolerance binds.
Yalidine     `security_token` in the body. Compared with hash_equals(),
               and that is the whole of it: it binds to nothing, so it
               proves only that the sender once saw the token. **No
               timestamp check** — nothing is signed, so a `date` field
               in the body is attacker-controlled and checking it would
               be theatre that reads like security.
```

Svix is implemented rather than added as a Composer package: fifteen lines of HMAC
against a documented string, and the house rule is that a dependency needs a stated
reason.

### Both are re-fetched, and the signed one too

`ShipmentWebhookResult` carries **no status**. For Yalidine that is docs/SECURITY.md's
rule about a body secret. For ZR Express — which signs properly, and whose payload
docs/SECURITY.md would allow acting on — it is a fact found by reading: the webhook
reference documents `state.name` as a display string ("Out for Delivery"), while the
live API returns the stable snake_case identifiers `ZRExpressStateMap` maps and the
poller has read since §57. Two documented shapes for the field that decides a
parcel's status is where believing a payload writes something nothing else can
reason about.

So every verified event ends in `getShipmentStatus()` — the poller's own path. A
signature proves who sent the message; asking the courier proves where the parcel is.
**A parcel's status still never moves the order**, webhook or not.

### What an event can do

```
processed        verified, claimed, re-fetched, and the parcel moved
unchanged        the courier reports what we already had
duplicate        the claim was refused — and the courier is not asked again
unknown_parcel   verified, but no such parcel here (created in their
                   dashboard, typically). 200 and dropped
finished         a late event about a delivered parcel. Refused before
                   the courier call, so it costs nothing
ignored          verified, and names no parcel at all
```

`ac_webhook_events` (migration 008) is shared with payments, keyed by provider, and
`WebhookEventRepository` moved from `Payments/` to `Commerce/` when the second domain
needed it — a `Shipping/` class reaching into `Payments/` would invent a dependency
the business does not have.

`AbstractWebhookController` holds everything a route does: raw bytes first, the header
shape adapters expect, 401 with no detail, the warning line with the source IP and
nothing else, `__return_true` with the comment saying why. The payments and shipping
controllers are twelve lines each.

### Two things this section found

**`signature_url` is now in `Logger::SENSITIVE_EXACT`.** Yalidine's webhook carries a
link to the customer's *handwritten signature* collected at the door — biometric-adjacent
personal data attached to a name, a phone number and an address. It joins `label` and
`labels` under the rule CLAUDE.md already stated for tokenised provider URLs.

**The bundled PSR-4 autoloader is now registered for `src/` unconditionally**, behind
Composer rather than instead of it. `optimize-autoloader` is on, so Composer dumps a
classmap — a snapshot of the files that existed when somebody last ran
`dump-autoload`. Moving one class between two directories under `src/` made every
request fatal on a healthy checkout, because the map still pointed at the old path and
nothing else was listening. The plugin bootstrap already carried exactly this reasoning
for `integrations/`; it had never been applied to `src/`, where a classmap makes it
more necessary rather than less.

### No courier webhook has ever been received

Both verifiers are written from published documentation — Svix's scheme for ZR Express, Yalidine's
`security_token` — and every payload in `tests/Unit/CourierWebhookTest` and
`tests/Api/shipping-webhooks.php` is *constructed* from it. The suites prove each verifier matches the
scheme as written; they cannot prove the sender implements it. §56's and §57's "verified against the live
API" notes cover the **outbound** half only.

Both failure modes are quiet: a wrong scheme or a wrong field name refuses genuine events with 401, and a
courier retrying into a 401 tells the merchant nothing. Neither is an outage, because a verified courier
event is only ever a hint to re-fetch and the hourly poller keeps parcels current regardless.

`ac_webhook_events` is the standing check — it holds rows for `chargily` and the test double only, and a
row whose `provider` is `yalidine` or `zr-express` proves one genuinely arrived. Neither
`*_WEBHOOK_SECRET` is set today, so both routes 404 by §60's own rule and one could not arrive even if
sent. See `grep -rn ASSUMPTION integrations/Yalidine integrations/ZRExpress` and docs/SECURITY.md → "A
verifier written from a specification is not a verified verifier". Remove the markers when the ledger
says so.

## CMS (§61)

Five read endpoints over content WordPress already knows how to store. There is no write surface,
and that is what §61 specifies: WordPress stores content, Next.js renders it, and authoring is the
editor plus WP-CLI. A CMS write API belongs to PLAN §52's admin coverage.

### Nothing here is a custom table

| What | Where it lives |
| --- | --- |
| pages | core `page` post type, addressed by **path** |
| banners | `ac_banner` post type — title, caption, featured image, `menu_order`, plus link and placement meta |
| FAQs | `ac_faq` post type, grouped by the `ac_faq_category` taxonomy |
| menus | core nav menus, assigned to the `primary` / `footer` locations this plugin registers |
| homepage | the `ac_cms_homepage` option — one document of `{type, data}` sections |

`docs/ARCHITECTURE.md` §7 reserves custom tables for genuinely custom, high-volume domains, and a
shop's FAQ list is neither. `Schema::VERSION` is unchanged at 8.

The post types are registered `public => false, show_ui => true, show_in_rest => false`: a headless
backend must not give a banner a URL of its own, and `/wp/v2` is not this project's contract — a
second, unversioned way to read the same content is a second thing to secure. The editor screens are
WordPress's own, and they are the only authoring surface §61 asks for.

### One capability, both doors

`ac_manage_content` guards all five routes **and** the dashboard screens, because the plugin's roles
carry no core post capabilities: with the default `capability_type`, an `ac_admin` would have been
able to read a banner through the API and not see it in wp-admin.

Map the **primitive** capabilities only. Registering `'edit_post' => 'ac_manage_content'` writes that
name into WordPress's global `$post_type_meta_caps`, after which every check of `ac_manage_content`
anywhere maps to `delete_post` with no post id and resolves to `do_not_allow` — every CMS and media
route answered 403 to the exact capability being asked about, administrators included.
`map_meta_cap => true` derives the three meta capabilities from the primitives, which is the point of
it. `tests/Api/cms.php` is the only reason this did not ship.

### The homepage is a document, not eleven rows

One value, edited as a whole:

```bash
wp option update ac_cms_homepage --format=json \
  '{"sections":[{"type":"hero","data":{"title":"Soldes"}},{"type":"featured_products","data":{"limit":8}}]}'
```

`HomepageSections` is pure and normalises on read against §23's eleven types. A malformed section is
**dropped and reported** in the response `meta` — never silently. An option is edited by hand, and a
section that vanishes without a word is the one failure a content manager cannot diagnose. A garbled
option degrades to an empty homepage; it never 500s.

### A page is addressed by its path

`legal/terms`, not `terms`. That is how WordPress addresses a hierarchical page, and it is the
unambiguous reading — two children called `terms` under different parents are two pages, and a bare
slug lookup would have to pick one. Menus come back as a **tree**, because a navigation menu is one
and every client would otherwise rebuild the nesting from `menu_item_parent`, once each, differently.

## Media (§61)

`POST /media` is the highest-risk endpoint in this API: the only one that writes a file a web server
might later execute. **Read `docs/SECURITY.md` → "File uploads" before touching it** — that section
is the rule, the way "Webhooks" is for inbound requests. What follows is the shape, not the reasoning.

```
authorize → rate limit → read the multipart entry → validate every way
          → move → strip metadata → register → audit
```

Every rule lives in `UploadPolicy`, which is **pure**: no WordPress, no globals, so each §65 abuse
case is a unit test rather than a live experiment.

| Check | How |
| --- | --- |
| size | 8 MiB, clamped to PHP's `upload_max_filesize` — the lower of the two always wins |
| filename | a path, a `..`, a NUL or an interior `.php` is **rejected**, never repaired |
| contents | `finfo` magic bytes **and** `getimagesize()`, which must agree |
| extension | the allowlist, compared against what the contents proved |
| once more | `wp_handle_upload()`'s own check, from an allowlist generated from ours |

**jpg, jpeg, png, webp — nothing else.** `svg` is XML and carries `<script>`; `pdf` has its own
scripting engine; `gif` is only wanted for animation, which cannot survive the strip; `avif` decoding
is a GD build option, so a file accepted on one host would be unsanitisable on another.

### The stored file is not the file that was sent

The name is rewritten — the readable stem survives, folded to `[a-z0-9-]`, and the extension comes
from the **sniffed type** — and the bytes are re-encoded from decoded pixels, which is what strips
EXIF, GPS and comments, and what makes a polyglot inert. An image that cannot be re-encoded is
refused and unlinked rather than stored as it arrived.

`ImageSanitizer` pins the editor to GD through the `wp_image_editors` filter, and the reason is
measured rather than assumed: **`WP_Image_Editor_Imagick::save()` keeps EXIF and JPEG comments**
(`strip_meta()` is only reached through the resize path, and a same-size resize early-returns first),
while the two containers in this stack disagreed about which editor WordPress picks. A security
property that depends on which PHP process handled the request is not a property. The costs are one
JPEG recompression at quality 90 and no ICC profile; alpha survives, which was measured too.

### The gap this leaves, on purpose

A **Product Manager cannot upload.** They can point a product at an image that exists (§47c takes an
attachment id) and cannot create one. `docs/PLAN.md` §3 defines no media capability, and both ways of
closing the gap are worse than naming it: inventing `ac_manage_media` puts a capability in the matrix
PLAN.md does not have, and adding `ac_manage_content` to Product Manager hands whoever edits the
catalogue the homepage as well.

### Two things the environment had to change

`docker/php-uploads.ini` raises `upload_max_filesize` from the image's 2 MB, which is below one
photograph and would have made the 8 MiB cap unreachable — PHP discards an oversized body before any
application code runs, so the two numbers move together. And `docker/apache-wordpress.conf` makes
`wp-content/uploads` non-executable: the allowlist and the re-encode are application-layer defences,
and that block is the layer that does not depend on either being right. `scripts/test-api.sh` asserts
it, because a vhost edit could silently drop it.

### Where the upload tests live, and why they are split

`tests/Api/media.php` proves every hostile file is refused, through the real route. It stops there:
`wp_handle_upload()` finishes with `move_uploaded_file()`, which by design fails for anything that
did not arrive over a real POST, and `rest_do_request()` cannot make one. Everything below the
refusals — the file actually being written, the metadata actually gone, the payload actually gone —
is in `scripts/test-api.sh`, with `curl -F`. Neither stage can see what the other does.

Two bugs came out of that split. `wp_handle_upload()` takes its first argument **by reference**, so
an array literal is a fatal `TypeError` — and only a legitimate upload reaches that line, which is
exactly the case no refusal test exercises. And a traversal filename never reaches the application
over real HTTP at all: PHP's multipart parser applies `basename()` before `$_FILES` exists. Our check
is the layer that does not depend on that being true.

## SEO (§62)

`seo` is a block on the resources that already exist — `GET /products/{id}` and `GET /cms/pages/{path}`
— and it is written through the resource's own PATCH. No endpoint of its own, no table, no migration.

```jsonc
"seo": {
  "title": "Tapis berbère — Boutique",
  "description": "Fait main en laine naturelle.",
  "canonical": "",                                  // only ever what somebody set
  "robots": { "index": true, "follow": true, "directive": "index, follow" },
  "og": { "title": "…", "description": "…", "type": "product", "image": { … } },
  "image": { "id": 42, "src": "…", "thumbnail": "…", "alt": "…" },
  "structured_data": { "@type": "Product", "offers": { "price": "7500", … } },
  "overrides": ["title"]                            // which fields a person actually set
}
```

### Correct by default

Five stored overrides; everything else derived, so a shop that has never opened an SEO field still
serves a sensible title, description and share image everywhere. `overrides` tells an admin UI which
values were typed and which are placeholders — invisible otherwise.

| Field | Derived from |
| --- | --- |
| `title` | `"Name — Site"`, trimmed at 60 on a word boundary, never `"Site — Site"` |
| `description` | the short description, else the body — shortcodes and markup stripped, trimmed at 160 |
| `robots` | `index, follow`, but **noindex for anything unpublished** — this API serves a draft long before it is public, and a storefront preview must not be why it gets indexed |
| `image` | the featured image |
| `og` | follows `title` / `description` rather than storing them twice, which is two fields that drift apart in the one case nobody notices |
| `structured_data` | `Product` with an `Offer` where there is a price, `WebPage` otherwise. An offer is omitted rather than published with `price: ""`, which Google reads as malformed rather than absent. Nothing invents a rating, a review count or a brand |

### A canonical URL is never derived

The one hard rule. WordPress's permalink points at *this* backend, and the storefront is a different
origin with its own routing — a canonical guessed here would confidently tell Google that the shop's
pages live on the admin domain. It is the override or it is empty, and the payload carries the slug so
the side that knows its own URL scheme builds it. Overrides must be absolute `https`.

### No SEO plugin, and the door left open

PLAN §25 says to use one. This was built without one, on the reasoning §62b applies to "Meta for
WooCommerce": in a headless install the half that **renders** never runs, and its sitemap and
`robots.txt` are emitted on the backend's domain, where they are worse than absent. What remains is a
meta box over five fields, which is what `SEO/` is.

Rank Math is an upgrade path rather than a rejection — it publishes a `getHead` endpoint built for
headless installs. Taking it means writing a `RankMathSource` in place of `SeoRepository`; nothing
above `SeoResolver` changes.

### `SEO/` contains no WooCommerce

A product and a page have a title, some text, a picture and a slug, and everything this module does is
identical for both. The caller flattens either into a `SeoSubject`, so adding a third kind of content
means building one more subject rather than teaching the resolver about a third class.

Two bugs the tests caught, both in the same three lines: `strip_tags()` removes a `<script>` element's
tags and keeps what is *between* them, so a body ending in an analytics snippet published `var x = 1;`
as its meta description — and the first fix for it, whitespace around every tag, turned
`les <strong>58 wilayas</strong>.` into `58 wilayas .`. Block boundaries become a space; inline tags
do not.

## Marketing and the Meta Conversions API (§62b)

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/marketing/config` | `ac_manage_marketing` | public ids only, for the storefront |
| POST | `/marketing/events/purchase` | `ac_manage_marketing` | mint and queue the Purchase → `{ event_id, … }` |

Plus `wp algerian-commerce sync-marketing [--provider=] [--limit=] [--summary]`, and a cron event every
five minutes. `ac_marketing_events` is migration 009 (`Schema::VERSION` is 9).

### This repository owns the server half only

`fbevents.js` and the `fbq()` calls belong to the Next.js storefront, because WordPress renders no page
here. **Do not reach for a WooCommerce pixel plugin**: those inject their script through WooCommerce
template hooks, which never run in a headless install — the plugin is inert and looks installed, which
is the worst of both. What this side owns is the Conversions API: an outbound HTTP call carrying order
data and a long-lived token, which is a third-party integration like any other.

### Deduplication is the whole problem

The same Purchase reaches Meta twice, once from the browser and once from the server, and Meta discards
the copy only when both carry the same `event_name` **and** the same `event_id`. Two systems cannot each
invent that, so **the backend mints it and the storefront is told**:

```
POST /marketing/events/purchase {"order_id": 42}
  → {"event_id": "9f2c…", "event_name": "Purchase", "queued": ["meta"]}

storefront: fbq('track', 'Purchase', {...}, {eventID: '9f2c…'})
```

The id is **derived from the order**, so a retried request, a refreshed confirmation page and a second
browser tab all produce the same string — and therefore one conversion rather than three. It is hashed
rather than `purchase-42`, because that string goes to an ad network on every sale and a sequential id
would publish the shop's order volume to anyone counting.

Only what the server witnessed is sent from here. `PageView`, `Search` and `ViewContent` are browser
facts; a server reporting them is guessing, and a guessed event silently reprices somebody's ad spend.

### Never on the checkout path

The request writes a row and returns; `sync-marketing` sends it. A Meta outage must never fail or delay
an order — the same rule §57 applies when it refuses to let a read-back fail a parcel that already
exists. The claim and the queue are deliberately **one table**: a claim in one and a job in another can
disagree, and the disagreement is a conversion sent twice or never.

`payload` is frozen at claim time rather than rebuilt at drain time, so a refund or an edited line item
between the sale and the send cannot quietly change the reported value.

### Where the PII stops

`Marketing\UserData` has a private constructor and hashes on the way in, so no object in the system
holds a customer's email on its way to an ad network — an adapter cannot leak what it was never given,
and neither can the queue, which outlives the request. **Hashing is not anonymisation**: a SHA-256 of an
email is a stable identifier for that person, which is the point of sending it. This is customer PII
going to a third party and needs a lawful basis and a privacy notice.

One Algerian detail that would have silently halved the match rate: a shop stores `0551020304`, and
Meta's rule — "remove symbols, letters and leading zeros; include the country code" — read naively gives
`551020304`, which is not a phone number anywhere. The trunk prefix is **replaced** by `213`.

| Value | Kind | Where |
| --- | --- | --- |
| `META_PIXEL_ID` | **public** — it ships in browser JavaScript | `.env`, served by `/marketing/config` |
| `META_CAPI_ACCESS_TOKEN` | **credential** | `.env` only, in no response ever |
| Graph API version, `test_event_code` | per-client setting | `ac_meta_settings` option |

### Written from the docs, not run against a dataset

Field names, the endpoint shape, the hashing rules and the version pin come from Meta's current
documentation read **2026-08-16** (§54 forbids memory). Graph API **v26.0** is pinned per §68 — released
2026-07-29, and Meta changes payload requirements between versions while expiring each after about two
years.

Nothing here has touched a live dataset: that needs an ad account this project does not have, which is
§56's situation. `grep -rn ASSUMPTION integrations/Meta src/Marketing` lists what is unproven — chiefly
whether a 2xx can hide a partial failure, and whether a hyphen in a name is dropped or spaced. When a
token exists, set `test_event_code`, run `sync-marketing` and watch Test Events; that exercises the whole
path without polluting a client's attribution.

The three-state `MarketingResult` is why a bad payload is not retried forever: `sent`, `retryable` (a
timeout, a 5xx, a rate limit) and `rejected` (a malformed field, a refused token). An event retried
hourly for a week burns the account's rate limit and buries the ones that would have worked.

## Analytics (§63)

Seven read-only endpoints over one set of window arguments, and **no migration** — `Schema::VERSION`
stays 9.

```
GET /analytics/overview?range=30d
GET /analytics/revenue?range=custom&date_from=2026-08-01&date_to=2026-08-16
GET /analytics/{orders|products|customers|shipping|cod}?range=7d
```

`range` is `today`, `yesterday`, `7d`, `30d`, `90d` or `custom`; the three spans **include today**,
because "the last 7 days" that hides this morning's orders is the one users report as a bug. A custom
range needs both ends and covers at most **366 days** — an open `date_from` is the unbounded order-book
scan §63 forbids, arriving by omission. Windows are the *shop's* calendar days: `AnalyticsRange`
resolves them in the site timezone and hands the queries a UTC pair, because `date_created_gmt` stores
UTC and an Algiers shop closes its day an hour before a UTC server does. The end bound is exclusive, so
`yesterday` and `today` cannot both claim an order.

### `ac_analytics_aggregates` was not built

The table is planned in ARCHITECTURE §7 and §63 declined it, so the reason is on the record.

WooCommerce Admin already ships this rollup — `wc_order_stats`, `wc_order_product_lookup`,
`wc_customer_lookup` — filled by an importer scheduled through Action Scheduler. On this install those
tables hold **0 rows against 912 orders**, with **1,426 `wc-admin_import_orders` jobs pending since
2026-08-11** and no Action Scheduler action ever completed: nothing drives WP-Cron on a headless backend
nobody browses. It is the argument §62b makes against "Meta for WooCommerce" and §62 against an SEO
plugin — half of a WooCommerce feature that only runs when a page is rendered — and it is worse here,
because the tables *exist*, so reading them returns zeros rather than failing. A dashboard would report
a trading shop as having taken nothing.

A rollup of our own inherits exactly that dependency, and a pre-computed number is a number that can be
wrong and stay wrong — which is why `CustomerStatistics` is computed on read. So §63 uses bounded
queries on WooCommerce's own `type_status_date` index plus `AnalyticsCache`, a response cache that holds
an assembled payload for `AC_ANALYTICS_CACHE_TTL` seconds (60 by default, `0` off). A cache cannot drift
further than its TTL, expires by itself and needs no scheduler; every response carries `generated_at`.

The trigger for revisiting is named: `AnalyticsRepository::ordersByWilaya()`, the one query costing an
index lookup per order rather than a single grouped pass. When a 90-day window there stops fitting a
request, the rollup has earned its place — and it must ship with a driver that is **not** WP-Cron.

### Aggregate SQL, and the one exception to the HPOS rule

`Orders/OrderRepository` is still the only file allowed to touch an order *object*, and nothing in
`Analytics/` loads a `WC_Order`. What reporting needs is `SUM`, `GROUP BY` and `ORDER BY`, and
WooCommerce publishes no API for any of them — `wc_get_orders()` can count, which is how the COD funnel
works, but it cannot sum or rank, and assembling revenue in PHP from `limit => -1` is the scan §63
forbids.

So `AnalyticsRepository` is the single file running aggregate SQL over the order tables, under four
rules: no order object is loaded or returned; the table name comes from
`OrderUtil::get_table_for_orders()` and is never a literal; **a legacy install is refused with 501
rather than answered with zeros**, because the HPOS query against `wp_posts` returns no rows and no
error; and every query is read-only, so the whole feature's SQL surface reviews in one sitting. The
same file is the only place `ac_shipments` is read in aggregate — `ShipmentRepository` remains the only
place a row is read or written *as a `Shipment`*.

### Who is shown money

`ac_view_analytics` is held by **every** role in PLAN §3, Support Agent included — an account whose job
is `ac_manage_customers` and the telephone. Wiring turnover to it would hand the shop's revenue to every
account in the building. The rule §63 adds, now in SECURITY.md → "Authorization":

> Reporting may not disclose in aggregate what the caller cannot already read in detail.

An order's total is readable through `GET /orders` with `ac_manage_orders`, so summing those totals for
a caller who holds it discloses nothing new. Money therefore needs both capabilities; counts and rates
need only `ac_view_analytics`. `/analytics/revenue` is money end to end and answers **403** without it;
elsewhere the money block is simply absent and `meta.money_visible` plus `meta.money_requires` say why,
so a client can disable a card rather than render an empty one.

Super Admin, Admin, Manager and Order Manager see money; Product Manager, Marketing Manager and Support
Agent see volumes and rates. **No new capability was invented** — §61's media gap set that precedent. A
shop that wants its marketing manager to see revenue grants that account `ac_manage_orders`, which is a
deliberate act with a visible consequence: they can then read the orders too.

The cache key carries the money flag (`AnalyticsCache::key()`), or the cache would serve an
administrator's figures to whoever asked next. That function is pure so the property is a unit test.

### What this shop can honestly report

PLAN §28 lists ten figures. Seven are real — `order_total`, `gross`, `net`, `collected`, `discounts`,
`shipping_revenue`, `tax`, `refunds` and `average_order_value`. Three are named in `unavailable` with
their reasons rather than emitted as zero:

| Figure | Why not |
| --- | --- |
| `shipping_cost` | What a courier charges the shop is not recorded — migration 004 argues its own case for having no cost column. `shipping_revenue` is the separate figure of what the customer was charged. |
| `payment_fees` | Migration 007 refused a fee column; Chargily's `fees`/`fees_on_merchant`/`fees_on_customer` land in per-transaction `metadata`, and a second gateway would shape them differently. |
| `margin` | WooCommerce has no cost-of-goods field, and PLAN §28 says to calculate profit only where reliable cost data exists. |

A dashboard rendering "Margin: 0.00 DZD" has told the shop something false; one rendering nothing, with
a reason, has not.

`gross` counts `processing`, `on-hold`, `completed` and `refunded` — WooCommerce Analytics' own default
exclusion set, so a shop's other tools agree with this one. **`refunded` is counted on purpose:** a
fully refunded order made a sale and gave it back, so it belongs in gross with its refund subtracted,
netting to zero. Excluding the order while still counting the refund nets to *minus* the sale, and a
shop that refunded everything would report negative revenue it never took. Refunds are keyed to the
**parent order's** date, because `net = gross − refunds` is only a true sentence when both halves
describe the same orders. `collected` is `completed` alone — for a COD shop the money arrives when the
parcel does, which is the definition `CustomerStatistics` already uses.

### Counts are of every order; only money has a currency

WooCommerce records the currency **per order**, and this install holds 890 orders in `USD` from before
anyone set `DZD`. Filtering whole queries to the store currency put "22 orders placed" on a dashboard
beside a COD funnel reporting 615. So the currency lives in a `CASE` rather than a `WHERE`: an order
count is a fact about the shop's activity, a sum belongs to one currency, and `excluded_currencies`
reports what was left out instead of quietly shrinking the total. Money is summed as **integer minor
units** — never floats, never bcmath, which is an extension the two images in this stack disagree about.

### Revenue by wilaya comes off the shipment

`ShipmentInput` already refuses to fuzzy-match "Ouled Fayet" into a commune: an order's `state` and
`city` are free text in two languages, and getting it wrong sends a parcel to the wrong daira. A report
must not make the guess the shipping module declined. The canonical `wilaya_id` is on the parcel,
because an admin picked it from the §51 dropdowns, so orders with no shipment are reported as
`unattributed` with the reason attached. A map with a stated gap beats a map that is quietly wrong.
Where an order has been shipped twice — a re-send after a failed delivery, which migration 006 allows —
the latest parcel decides, so a total is never counted once per parcel.

### Delegation, not a second definition

`/analytics/cod` calls `CodService` and the low-stock count calls `InventoryRepository`. Both already
exist and are already served; a second definition of "confirmation rate" living here would eventually
disagree with the first, and the shop would have two numbers and no way to tell which was right. The
dependency runs one way — neither module has heard of analytics — and `tests/Api/analytics.php` asserts
that `/analytics/cod` and `/cod/statistics` still agree.

## Import and export (§64)

Six endpoints, **no migration** — `Schema::VERSION` stays 9.

```bash
# preview, then apply — the same file, twice
curl -u "$CRED" -H 'Content-Type: text/csv' --data-binary @stock.csv \
     "$API/import/inventory?dry_run=true"
curl -u "$CRED" -H 'Content-Type: text/csv' --data-binary @stock.csv \
     "$API/import/inventory?dry_run=false"

curl -u "$CRED" "$API/export/inventory?limit=500" -o stock.csv
```

### The pipeline is stateless, and `dry_run` defaults to true

§64's "confirm" step looks like it needs the server to hold a parsed job between two requests. It does
not: send the file with `dry_run: true` for the preview and the error report, then the same file with
`dry_run: false` to apply it. **The default is a preview**, so a client that forgets the flag never
writes — the reverse default means one malformed integration overwrites a catalogue on its first request.

There is no `ac_import_jobs` table and no uploaded file is retained between requests. docs/SECURITY.md →
"File uploads" opens by observing that accepting a file is the most dangerous thing this API does, and a
file kept for later is that danger with a longer fuse. The cost is one extra upload from a client that
already holds the file.

**When the table earns its place** is stated rather than left to be discovered: when a catalogue outgrows
`CsvReader::MAX_ROWS` and needs batching, something has to record "three thousand rows in". Same shape of
decision as §63's `ac_analytics_aggregates`.

### A CSV body, not a multipart upload

Three reasons, in order of weight. **Nothing is written where a web server can serve it** — a multipart
upload ends in `move_uploaded_file()` into `wp-content/uploads`, which §61 spends four checks and a
re-encode making safe; a body is a string, and where a third-party engine needs a path the file goes to
`get_temp_dir()` under a random name and is unlinked in a `finally`. **The privileged caller is a server**,
not a browser. And **it is testable**: `rest_do_request()` cannot perform a real multipart upload, which is
why the media suite can only prove refusals in-process.

### WooCommerce's CSV engine — and why this is not another §61

§61 rejected an SEO plugin, §62b a pixel plugin, §63 wc-admin's analytics tables: in each case the half
that runs is a rendering or scheduler concern that never executes headless. **The CSV engine is none of
those.** It is plain PHP that reads a file and calls the product CRUD, and only its *loader* is
admin-gated — the classes sit in `includes/import/` and `includes/export/`, outside `admin/`, and are
simply never required in a non-admin request. Measured 2026-08-16: with `is_admin()` false, five
`require_once`s produced a valid 40-column export.

So the product CSV format is reused, not reimplemented. Forking forty columns of variations, attributes,
cross-sells and meta would break "never fork their data models" and produce a file no other WooCommerce
tool could read — for a shop whose likeliest reason to export is to hand it to something else.

### A CSV is a document a spreadsheet will run

A cell beginning `=`, `+`, `-`, `@`, a tab or a carriage return is a **formula**, and formulas reach the
shell and the network. The attacker needs one product name or one customer's first name; the shop owner
runs it by opening their own export. `CsvWriter` prefixes a single quote, exactly as
`WC_CSV_Exporter::escape_data()` does — products use WooCommerce's exporter and the other three exports use
ours, so `tests/Api/import-export.php` asserts the two still agree after a WooCommerce upgrade.

### `update_existing` is a mode switch whose name reads as a modifier

Measured 2026-08-16:

| `update_existing` | new SKU | existing SKU |
| --- | --- | --- |
| `false` | imported (created) | skipped, unchanged |
| `true` | skipped — "No matching product exists to update" | updated |

**Neither setting does both halves.** Passed through under its own name it is a trap in both directions:
`true` reads as "create and also update" and creates nothing. The API says `mode=create` or `mode=update`,
which is what the two settings actually do, and `create` is the default because a first import is the
common case.

### What a dry run can and cannot promise

For inventory it is exact — the same code path, with the write skipped. For products it is **a parse and a
lookup, not a rehearsal**: `WC_Product_CSV_Importer` has no dry-run mode, and simulating one means
reimplementing the mapping this section refuses to fork. So it runs WooCommerce's *own* parser (a column
that parser cannot read fails in the preview too) and reports which SKUs exist, honouring the mode. It
cannot promise every write will succeed, and the response says so in `preview_only`.

### Every imported stock change goes through the ledger

An import is not a back door. Stock rows go through `InventoryService`, so two thousand rows write two
thousand `ac_inventory_movements` entries with an actor and a reason — deliberately, because that table's
whole purpose is that no quantity changes without one. A stock take uses `set`, not a delta, so running the
same file twice is safe; the second run reports the rows as `skipped, unchanged`.

An inventory import **never creates a product**: a typo'd SKU that silently created a nameless, priceless
product would be far worse than a reported failure. A SKU appearing twice in one file is refused with the
earlier line named, because applying both would make the result depend on row order.

### An export is a file, not an envelope

`API/FileDownload` is the one deliberate exception to the response envelope, bounded three ways: only a 2xx
body is served raw, only on routes that opt in, and the `Content-Disposition` filename is generated rather
than taken from input. Errors always come back in the envelope, so a client never saves an error message as
`products.csv`. Because `rest_do_request()` never runs `rest_pre_serve_request`, the download headers are
checked in `scripts/test-api.sh` — the in-process suite is structurally blind to them, exactly as it is to
a real media upload.

## Mail, and password reset (PLAN §29, §30)

Two things that had to arrive together, because neither is useful alone.

### The SMTP settings were wired to nothing

`SMTP_HOST`, `SMTP_USERNAME` and `SMTP_PASSWORD` were documented in `.env.example` and read by `Config`
from the beginning — and passed to nothing. They were not even forwarded into the containers by
`compose.yaml`, so `getenv()` returned nothing whatever anyone put in the file. **A variable this
repository documents and wires to nothing is a knob that turns nothing**, which is exactly the fault §61
found across the whole `AC_RATE_LIMIT_*` group.

`Notifications\MailTransport` closes it: one `phpmailer_init` hook, registered from the bootstrap
because a transport nobody instantiated configures nothing and fails identically to a wrong password.
`SMTP_PORT` and `SMTP_ENCRYPTION` were added because they genuinely vary — 587 with STARTTLS and 465 with
implicit TLS are both common, and guessing one from the other produces a connection that succeeds and
then hangs. An unrecognised encryption falls back to `tls`, never to `none`: a typo must not silently
send credentials in the clear.

With no `SMTP_HOST` it does nothing at all. That failure stays honest rather than becoming a half-working
transport.

`wp algerian-commerce mail-check [--to=<address>]` reports what is configured and, with `--to`, actually
sends — the only check that can fail for the real reason. It prints whether the password is set and never
what it is.

### Password reset, and the argument §59c made against it

§59c deferred this with: *"a reset link generated but never sent is worse than an absent feature, because
it looks like one that works."* That argument is answered rather than dropped. Both calls check the shop
can send **and** knows its storefront's address **before** minting a token, and answer 503 naming which
half is missing. Neither precondition is about the caller, so neither leaks anything.

It does not go through the notification queue. That queue drains every five minutes, and a shopper
staring at "check your email" will not wait five minutes — they will request another. The rest of §29 is
deferred *because* it is deferrable; a reset is the one message that is not.

Four rules, each because the obvious implementation leaks something:

- **A known address, an unknown address and a staff address answer identically.** The suite asserts the
  responses are identical rather than that the wording matches, because a message that drifts apart is
  the same disclosure.
- **A staff account cannot be reset here**, gated by the same `AccountSession::isShopper()` that refuses
  a staff login — otherwise §44's rule keeping administrators on Application Passwords has a second door
  straight past it.
- **The link's destination is configuration, never input.** There is no `redirect_to`; accepting one is
  how reset-link poisoning works.
- **A successful reset issues no session.** A token that arrived by email is weaker evidence than a
  password. It reports `sessions_revoked: true` instead — the password change invalidates every existing
  session, which is what makes a reset useful after an account is stolen.

Tokens are WordPress's own, so the hashing, the 24-hour expiry and the single use come free, and expired,
wrong and already-used all answer the same way.

## Client configuration (§71)

`src/Settings/` — `GET` and `PATCH /settings`, no migration and no table beyond one option.

§71's instruction is "use configuration and feature flags rather than forks"; PLAN §48's is "separate
reusable code from client configuration". Both describe an outcome. The mechanism that fell out of it is
**one document assembled from the systems that already own each value** — not one table that copies them.

Before this, configuring a client meant knowing that the store name is a WordPress option, the currency a
WooCommerce one, the courier settings four separate `ac_*_settings` rows and the feature flags
environment variables: four systems, no list, and nothing to tell a new operator they had missed one.
`GET /settings` is that list, built live on every request so it cannot drift from what it describes.

### Almost nothing is stored

`ac_client_settings` holds only the fields with no owner: contact details, the Algerian trade-register
block (`rc`, `nif`, `nis`, `ai`), social links, the logo and the storefront's address. Everything else is
read from whoever owns it. `store.name` and `store.description` are written *through* to WordPress rather
than kept, because WordPress's copy is the one WP-CLI, the admin screens and every email template read.

`tests/Api/settings.php` asserts the difference the only way it can be asserted: it renames `blogname`
behind the API's back and checks the document followed, then checks `ac_client_settings` does not hold a
name of its own. A copy would pass the first and fail the second.

This is §63's argument against an analytics rollup and §68's against a version table, a third time: the
copy is what goes stale, so there is no copy.

### The refusals are the design

Three things are refused **by name, with the reason returned**, rather than ignored — the `CustomerInput`
device, because a caller who sets `currency` and gets a 200 will believe the currency changed.

- **`currency`** — WooCommerce owns it and records it **per order**, so changing it later does not
  convert the order book, it splits it. `scripts/setup.sh` sets it once, at provisioning.
- **`features`** — `ENABLE_*` decides which providers *register*, and registration happens once at
  bootstrap. A flag flipped in the database mid-request would disagree with the registry already built.
- **secrets** — API keys, tokens and webhook secrets are environment variables and nothing else
  (docs/SECURITY.md). An options row is readable by any plugin and survives in every database dump.

`providers` is read-only for a subtler reason: it reports what actually registered, which follows from
the flags **and** the credentials. A flag that is on with no key produces a provider that never loads,
and this document is the only place that gap is visible.

### `ac_manage_settings` gets its first call site

The capability has existed since §45's matrix, held by Super Admin alone and checked by nothing — the
state `Permissions::assertOwnsOr()` was in before §59c. No new capability was invented. It stays Super
Admin's: this document names the shop's legal identity and the address of its storefront, and an Admin
holding the other ten management capabilities is still refused here. The suite asserts that pair, because
a refusal with no positive control proves nothing.

Writes are audited **by field name, never by value**. A change to a trade-register number is exactly what
an audit trail is for; the number itself does not belong in a table nobody ever cleans.

### `client.json` — §73's missing step

Roadmap §73's provisioning flow reads: clone the template, copy `.env`, **set client configuration**,
`docker compose up`, `setup.sh`, configure integrations, deploy. Every step but the third was already a
command. `wp algerian-commerce settings --from=client.json` is the third, and `scripts/setup.sh` applies
`client.json` automatically when one is present.

It is fed over STDIN, because `client.json` sits at the repository root and only the plugin directory is
mounted into the containers. A missing `client.json` is fine — a development machine has no client — but
a *present* one that fails to apply stops the setup, because a shop deployed with somebody else's contact
details is worse than one with none. JSON has no comments, so keys beginning `_` are ignored rather than
rejected: `client.json.example` is a file a human fills in, and it needs somewhere to explain itself.

## Development seed (§67)

`src/Seed/` and `data/seed/` — 5 categories, 12 products, 5 variations, 6 customers, 4 coupons and 11
orders, loaded by `wp algerian-commerce seed`. No migration and no table. `scripts/seed.sh` is the shell
wrapper and calls `import-algeria` first.

The fixtures are JSON and ship **inside the plugin**, beside §51's geography and for the same reason: the
plugin is what gets cloned per client, so a client replacing the demo catalogue replaces a file rather
than code.

### Everything goes through a service

Not one row is written with `$wpdb`. §64 already established that an import must not be a back door
around `ac_inventory_movements`; a seeder writing posts directly is the same back door with a friendlier
name. A seed that bypassed `ProductService` can produce a product the API would refuse — a duplicate SKU,
a sale price above the regular one, a variation whose attribute the parent does not offer — and then
every test written against it is a test against a state the shop cannot reach. Going through the services
makes the fixtures *proof the API can build this shop*.

Categories are the one exception, and only because there is nothing to go through:
`/product-categories` is read-only by design (managing them is PLAN §5, with its own phase), so those use
WordPress's own term API, which is what WooCommerce uses too.

Two consequences follow. **It runs as somebody** — services assert capabilities, so `SeedCommand` sets a
current user (`--as=<login>`, otherwise the first administrator); a seeder with no identity would have to
bypass the check, which is the one thing this module exists not to do. And **it is idempotent on natural
keys**: products and variations by SKU, customers by email, coupons by code. Orders have no natural key,
so the seed keeps its own ledger in the `ac_seed_orders` option rather than writing a marker onto the
order — `OrderRepository` is the only file that touches an order, and a seeder is not the place to make
the second exception. The ledger is checked against reality rather than believed, so an order deleted by
hand is recreated rather than reported as updated forever.

### `SeedDataset` is pure, and one of its rules has no other home

Most of what it checks would fail loudly anyway when the seeder ran: a product in a category nobody
defined, an order naming a SKU nobody sells, a variation attribute the parent does not offer (matched
exactly as `VariationService` keys them — lowercased — because a fixture capitalised differently is
accepted by every JSON parser and refused by the API).

**PLAN §46's "never use real customer data" is not like that.** A fixture carrying a colleague's real
address seeds perfectly and mails them the first time somebody drains the notification queue. So every
seeded email must be on a domain RFC 6761 or RFC 2606 reserves — `.test`, `.example`, `.invalid`,
`.localhost`, `example.com` and its siblings — checked exactly enough that `badexample.com` fails where
`mail.example.com` passes.

It also refuses `cancelled` and `refunded` as *starting* statuses, since neither is creatable
(`OrderStatus::CREATABLE`), and offers `final_status` for reaching them the way a real order does: a
second, legal transition.

### Two mailers, and they needed opposite answers

Seeding eleven orders queues a customer confirmation and an admin alert for each in `ac_notifications`.
Those are deferred — a row now, a drain later — so the seeder notes the highest id before it writes and
drops what it added, with `--keep-notifications` to opt out. The customer addresses reach nobody by
construction; the **admin alert goes to a real inbox**, which is what makes this more than tidiness.

**WooCommerce's own mailer is neither deferred nor ours.** `WC_Emails` sends synchronously inside
`woocommerce_order_status_changed`, so by the time `seed()` returns the mail has already left — there is
nothing to discard. The first run here attempted 25 sends, visible only as `sendmail: can't connect`
because this machine has no MTA. It is short-circuited through `pre_wp_mail` for the duration of the
writes, and `--keep-notifications` deliberately does **not** turn it back on: a queue can be inspected
and drained on purpose, a synchronous send has no such second look. `tests/Api/seed.php` asserts the
filter is removed again, because a seeder that left it would silence every later suite in the process.

### What the seed cannot do

Every seeded order is dated **now**, because this API accepts no order date — §63's time series therefore
shows one day. Adding `date_created` to `OrderInput` is a real decision about the orders module (an admin
who can backdate an order can move revenue between months), not one a fixture loader gets to make.

## Security review (§55)

§55 is a review rather than a feature: walk everything that ships — `Auth/`, `Security/`, `Permissions/`,
`Http/`, `COD/`, `Shipping/` and both integrations — against its checklist before a provider is switched on
for a real client. It gates the two courier webhooks and §58's payments, which is why it ran now.

What it did not change, because the list was already met: credentials are read from `.env` in exactly one
place (`Plugin::shippingProviders()`) and no accessor prints them; `sslverify` is hard-coded true with no
switch and `redirection => 0`, so a token never follows a redirect; both settings classes refuse a
non-`https://` base URL; every REST arg pairs `sanitize_callback` with `validate_callback`; the single retry
is 429-only and bounded, and a parcel create is never retried blind; the provider is called last in a
create, so a refusal cannot arrive after a van has one; every statement is prepared.

Four things it did change:

**Yalidine label URLs are credentials.** `label` and `labels` on a parcel response are URLs carrying an
access token — anyone holding one fetches the customer's name, phone and full address without a credential
of their own, and we can neither expire nor revoke it. They stay in `ac_shipments.metadata` and stay served
behind `ac_manage_shipping`, because an operator has to print a label; they are now in
`Logger::SENSITIVE_EXACT` so they can never reach a log or an audit row. Exact-match, not the substring
list, because "label" is an ordinary word — `provider_status_label` is ZR Express's wording for a parcel
state and carries nothing.

**A timeout needs a ceiling, not just a floor.** `timeout` is a per-client setting and had a minimum of one
second and no maximum, so `wp option update ac_yalidine_settings --format=json '{"timeout":600}'` would hold
a PHP worker for ten minutes per hung request, checkout included. Capped at
`YalidineSettings::MAX_TIMEOUT` / `ZRExpressSettings::MAX_TIMEOUT` = 60s, clamped and reported through
`problems()` like every other corrected value.

**Redaction was eating two diagnostics.** `Logger::redact()` masks any context key containing "key", which
silently redacted the hashed rate-limit bucket in `RateLimiter::enforce()` — whose own comment says that
value is what correlates repeats — and Yalidine's "which parcel did you answer about" list. Both were
written as `[redacted]` for their whole life. Renamed to `bucket` and `answered_for` rather than loosening
the matcher, which would have traded a harmless false positive for a possible false negative. Note that
`response_keys` would have been masked too; the substring does not care where it sits.

**`RateLimitGuard::addRetryAfterHeader()` read an absent array key**, raising an undefined-key warning on
every 429 that came from the `WP_Error` path — the header was still attached correctly, only the noise was
wrong.

The review's actual deliverable is the **webhook rule** in [docs/SECURITY.md](../../../docs/SECURITY.md)
under "Webhooks", settled before the first webhook exists so the three providers are not each argued out
separately: where the secret lives, verification on the raw body with `hash_equals()`, the hard split
between a real signature (Svix, Chargily) and a body secret that binds to nothing (Yalidine's
`security_token` — a hint to re-fetch, never a source of truth), a 5-minute timestamp tolerance where the
timestamp is signed, an event id *claimed* by a write-once insert rather than checked, and 401
`webhook_unverified` that says nothing about which check failed.

## Algerian geography

Roadmap §51, docs/PLAN.md §10. Wilayas, communes, postal codes, and the shipping providers'
destination ids kept separate from all of it.

```
data/algeria/wilayas.json                69 wilayas, with Arabic names
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

- **Wilayas** — codes 01–58 from WooCommerce's own `i18n/states.php` `DZ` block, ISO 3166-2 aligned.
  Codes 59–69 — the former circonscriptions administratives, since promoted to full wilayas — and
  every Arabic name come from the commune source, because WooCommerce's list still reflects the 2019
  map.
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

### 69 wilayas, and one correction the source needed

Algeria has **69 wilayas**: the 58 of the 2019 reform plus the eleven former circonscriptions
administratives — Aflou, Barika, El Kantara, Bir El Ater, El Aricha, Ksar Chellala, Ain Oussera,
Messaad, Ksar El Boukhari, Boussaâda and El Abiodh Sidi Cheikh — since promoted in full. Codes 1–69
are all real and are kept as the source has them.

Two corrections are applied, both printed with their evidence:

1. **The 2019 Touggourt split was half-applied.** Eleven rows carry Ouargla's code 30 while being
   named Touggourt, which exists separately as code 55. The script follows the name, and Touggourt
   ends with its 13 communes instead of 2. Derived from the file.
2. **The wilaya of Boussaâda did not contain the town of Bou Saada.** Its whole daira — Bou Saada,
   El Hamel, Oulteme — was still filed under M'Sila, where it sat before the promotion. A wilaya is
   named after its chef-lieu, and ten of the eleven new wilayas contain their namesake commune;
   Boussaâda was the only one that did not. The build script now runs that chef-lieu check on every
   run and prints any wilaya with no commune of its own name, so this class of misfiling is caught
   rather than noticed. Seven wilayas stay on that list permanently — Algiers/Alger Centre,
   Tipasa/Tipaza, In Salah/Ain Salah and so on — and those are spelling, not misfiling, which is why
   the check reports and never enforces.

Result: **69 wilayas, 1,541 communes — Algeria's exact count — every wilaya non-empty, and no two
communes in a wilaya colliding on their slug.**

A code above 69, if a future source carries one, is folded into the parent its `code_commune` implies
rather than inventing a wilaya — the leading digits of that code are the wilaya.

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

Roadmap §51 asks for them to be stored separately, and the reason is churn. Yalidine and ZR Express
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
`src/Core/Autoloader.php` covers `src/`. It is required to run the tests. `integrations/` gets that
autoloader **either way** — a Composer map is a snapshot, and a site that pulls a release adding an
adapter without re-running `composer dump-autoload` would otherwise fatal on every request rather
than merely lack a courier.

WP-CLI is the administration interface:

```bash
wp algerian-commerce migrate [--dry-run]        # apply pending migrations
wp algerian-commerce roles [--list]             # install / re-sync capabilities
wp algerian-commerce unlock <ip|login>          # lift a brute-force lockout
wp algerian-commerce import-algeria [--dry-run] # §51 geography
wp algerian-commerce seed [--dry-run] [--as=<login>] [--keep-notifications]
wp algerian-commerce settings [--from=<file>] [--format=table]  # §71 client config
wp algerian-commerce mail-check [--to=<address>]  # can this shop send email?
wp algerian-commerce shipping-check             # can this store ship anything?
wp algerian-commerce sync-destinations --provider=<name> [--dry-run] [--gaps=<n>]
wp algerian-commerce sync-shipments [--provider=] [--limit=] [--min-age=]
wp algerian-commerce sync-payments [--provider=] [--limit=] [--min-age=]
wp algerian-commerce sync-marketing [--provider=] [--limit=] [--summary]
```

```bash
# install dev dependencies (from this directory)
# -u matters: without it composer runs as root and leaves the working tree
# owned by root, after which you cannot edit your own files.
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v "$PWD":/app -w /app composer:2 install

# run the unit suite
docker compose exec wordpress sh -c \
  'cd /var/www/html/wp-content/plugins/algerian-commerce-core && php vendor/bin/phpunit'

# lint
docker compose exec wordpress sh -c \
  'find /var/www/html/wp-content/plugins/algerian-commerce-core -name "*.php" -exec php -l {} \;'
```

**Test metadata is attributes, never doc-comments.** PHPUnit 13 ignores `@dataProvider` entirely — it does
not warn, it simply does not wire the provider, and the test then fails with `ArgumentCountError` because it
was called with no arguments. Write `#[DataProvider('nameProvider')]` with
`use PHPUnit\Framework\Attributes\DataProvider;`, and note that a provider method must be `static`.

Unit tests must run without booting WordPress. `tests/Api/` is the integration suite, and it runs against
a booted WordPress, WooCommerce's real CRUD and a real MySQL.

```bash
scripts/test.sh              # every stage
scripts/test.sh unit         # one stage: versions | syntax | unit | rest | http
```

| Stage | What it covers | Blind to |
| --- | --- | --- |
| `versions` | the running stack against `compose.yaml`'s pins (§68) | the code |
| `syntax` | `php -l` over every file | everything else |
| `unit` | pure logic, no WordPress (`tests/Unit`) | anything touching WP |
| `rest` | routing, args, permissions, IDOR (`tests/Api`) | auth, rate limiting, response headers, real uploads |
| `http` | auth, rate limiting, CORS, downloads, uploads (`scripts/test-api.sh`) | — |

**Run the `http` one before touching auth, rate limiting, CORS, uploads or any write path.**
`rest_do_request()` — what the in-process checks use — is blind to three things, not one. It never parses
an `Authorization` header, so it cannot observe authentication or rate limiting. It never runs
`rest_pre_serve_request`, so CORS headers and §64's file downloads are invisible to it. And
`wp_handle_upload()` ends in `move_uploaded_file()`, which by design fails for anything that did not
arrive over a real POST.

The first rate-limit guard shipped letting every credential-guessing attempt through, with all 321 unit
tests green, because nothing exercised a real HTTP request with real credentials. Comment out the two
`add_filter` calls in `RateLimitGuard::register()` and the unit suite stays green while
`scripts/test-api.sh` turns red on exactly the right assertions — that is the regression it exists to
catch. §69's "CRUD over HTTP" block was added for the same reason and found a 500 on its first run.

`docs/TESTING.md` is roadmap §65's deliverable: the map from each of its five categories to the test that
covers it, what was already covered under a different name, and what does not apply here. **Read it
before adding a suite** — in particular its two conventions, both learned the hard way here. A refusal
and an unreachable route look identical from outside, so every negative test needs a positive control.
And an injection test that asserts only "no crash" passes against a concatenated query, so assert that a
payload does not *widen a result set*.

Two suites are about the API as a whole rather than one module:

- `tests/Api/security.php` — §65's security list. It reads the router, which no per-feature suite can:
  every route must declare a guard, and one Support Agent credential is swept across every GET route at
  once, which catches a route added later with the wrong one.
- `tests/Unit/SqlSafetyTest` — every `$wpdb` call site in `src/`, `integrations/` and `migrations/` is
  prepared or free of variables, and every table name is `$wpdb->prefix` plus a literal. A static guard
  against the repository written *next*; it proves it can still fail, against a hostile fixture.

## Configuration

Secrets come from environment variables only — never the options table, never code. Feature flags
(`ENABLE_COD`, `ENABLE_CHARGILY`, `ENABLE_YALIDINE`, `ENABLE_ZR_EXPRESS`, …) all default to off so one
codebase can serve multiple clients. `AC_LOG_LEVEL` sets the log floor (`debug`, `info`, `warning`,
`error`); it defaults to `debug` when `WP_DEBUG` is on, otherwise `info`.

The line between the two is per-client, not per-secret: a credential is an environment variable, and
anything a shop owner would reasonably change — the wilaya they ship from, whether they insure a
parcel — is a setting (`ac_yalidine_settings`). This plugin is cloned per client, so a value that
belongs to *one* client must never end up in code.

`AC_MEDIA_MAX_BYTES` (default 8388608) and `AC_RATE_LIMIT_UPLOADS` (default 30/minute) tune
`POST /media`. The size cap is only ever the **lower** of that value and PHP's `upload_max_filesize`,
so raising it means raising `docker/php-uploads.ini` too — otherwise the web server refuses the body
before this plugin can say anything about it.

`AC_ANALYTICS_CACHE_TTL` (default 60, `0` to disable) is how long an analytics response may be reused.
A value that is not a number falls back to the default rather than to zero: a typo in `.env` should not
quietly turn a performance feature off. It is a cache and never a rollup — see "Analytics (§63)".

**A variable reaches the plugin only if `compose.yaml` passes it into the container**, in both the
`wordpress` and `wpcli` services. `Config` reads `getenv()`, and the containers get nothing by
default; §61 found `AC_RATE_LIMIT_*` in that state — documented, read by `RateLimiter`, and
unreachable — and added them alongside the media keys.
