# Architecture

How the Algerian headless commerce backend is structured. This document answers **how is it built** —
`docs/PLAN.md` answers *what* we build, `CLAUDE.md` answers *how Claude works on it*.

Source roadmap: [`ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md`](../ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md) §20, §34, §37, §49–§52.

---

## 1. System context

```
        Next.js Storefront            Next.js Admin
                |                          |
                +------------+-------------+
                             |
                    HTTPS (JSON, CORS allowlist)
                             |
                /wp-json/algerian-commerce/v1
                             |
                  algerian-commerce-core            <-- our application layer
                             |
                   WooCommerce  +  WordPress        <-- platform, never modified
                             |
                          MySQL
```

Frontends are separate repositories and are **clients only**. They talk to `/algerian-commerce/v1`, not to
`/wp/v2`, `/wc/v3`, or third-party plugin endpoints, so the platform underneath stays replaceable.

## 2. Request layering

Every request travels the same path. No layer may skip downward past its neighbour.

```
REST API            route registration, args schema, permission_callback
    ↓
Controllers         parse request → DTO, call one service, format response
    ↓
Services            orchestration, transactions, events, business decisions
    ↓
Domain              entities, value objects, pure calculation and rules
    ↓
WooCommerce adapters  translate domain ↔ WC_Product / WC_Order / WC_Customer
    ↓
WooCommerce / WordPress
```

Rules that make the layering real:

- Controllers contain no business logic and never touch `$wpdb` or `WC_*` classes directly.
- Domain code has no WordPress dependency — it must be unit-testable without booting WordPress.
- Only adapters and repositories know WooCommerce CRUD, meta keys, and taxonomy names.
- Only repositories issue SQL, always via `$wpdb->prepare()`.
- **No provider-specific logic inside order controllers or order services.**

## 3. Module map

Inside `wp-content/plugins/algerian-commerce-core/`:

```
algerian-commerce-core.php     bootstrap: constants, autoload, container, hooks
composer.json                  PSR-4  AlgerianCommerce\  →  src/
src/
  Core/          container, plugin lifecycle, activation, config, logging
  API/           REST bootstrap, response envelope, error mapping, pagination, CORS
  Auth/          authentication strategies, session/token handling
  Permissions/   roles, capabilities, permission_callback helpers
  Products/      Customers/  Orders/  Inventory/   commerce domains
  COD/           cash-on-delivery state machine and risk signals
  Shipping/      ShippingService + ShippingProviderInterface
  Payments/      PaymentService + PaymentProviderInterface
  Analytics/     aggregation and reporting
  CMS/  Marketing/  Notifications/  Settings/  ImportExport/
  Audit/         audit event recording
  Security/      validation, sanitization, rate limiting, webhook verification
integrations/
  Yalidine/  Zedair/  Chargily/     provider adapters only
migrations/      001_*.php, 002_*.php … gated by AC_DB_VERSION
tests/
```

**Dependency direction:** `API → Services → Domain → Adapters → WooCommerce`. Domain modules never
depend on `API/`; `integrations/` never depend on each other; `Core/` depends on nothing above it.

## 4. Provider abstraction

The whole point is that the core never learns a provider's name.

```
ShippingService                       PaymentService
      |                                     |
      +-- YalidineProvider                  +-- ChargilyProvider
      +-- ZedairProvider                    +-- (future provider)
      +-- (future provider)
```

```php
interface ShippingProviderInterface
{
    public function createShipment(array $order): ShipmentResult;
    public function cancelShipment(string $trackingId): bool;
    public function getShipmentStatus(string $trackingId): ShipmentStatus;
    public function getShippingRates(array $destination): array;
}

interface PaymentProviderInterface
{
    public function createPayment(array $order): PaymentResult;
    public function verifyPayment(string $paymentId): PaymentStatus;
    public function handleWebhook(array $payload, array $headers): WebhookResult;
}
```

The order service says *create shipment*, never *call the Yalidine endpoint*. Provider selection comes from
configuration and feature flags (`ENABLE_YALIDINE`, `ENABLE_ZEDAIR`, `ENABLE_CHARGILY`, `ENABLE_COD`), so one
codebase serves multiple clients without forking.

Each adapter owns, for its provider alone: authentication, endpoint URLs, payload shapes, destination-ID
mapping, error mapping to our error codes, timeouts, retries, and idempotency keys. Adapters are written from
the provider's **current official documentation**, never from memory or from this repository's prose.

## 5. Data flow

**Read (`GET /products`)**

```
Controller → validate query args → ProductService::list(Criteria)
           → ProductRepository (WC_Product_Query) → domain objects
           → Presenter → { "success": true, "data": {...}, "meta": { pagination } }
```

**Write (`POST /orders`)**

```
Controller → validate + authorize → OrderService::create()
           → domain rules (pricing, COD eligibility, stock)
           → WooCommerce adapter persists the order
           → AuditService records who/what/when
           → events fire (notifications, shipment creation)
```

**Inbound webhook (`POST /webhooks/{provider}`)**

```
receive → verify signature → validate payload → identify event
        → idempotency check (event id seen before? stop)
        → process → store event → respond 2xx quickly
```

Duplicate delivery must never duplicate a payment, shipment, order transition, or notification.

## 6. API architecture

Namespace `algerian-commerce/v1`. One envelope everywhere:

```json
{ "success": true, "data": {} }
```

```json
{ "success": false, "error": { "code": "invalid_product", "message": "…", "details": {} } }
```

- Every route registers an explicit `permission_callback` and an args schema; validation happens before the
  controller body runs.
- Pagination is uniform across list endpoints (page/per_page + total counts in `meta`).
- Exceptions map to error codes in one place (`API/`), so no controller formats its own error.
- CORS uses an environment-specific origin allowlist. Never `*` on private routes.

Planned surface: `/products`, `/orders`, `/customers`, `/inventory`, `/analytics/*`, `/shipping/shipments`,
`/payments/checkout`, `/cms/*`, `/webhooks/{chargily,yalidine,zedair}`, `/health`.

## 7. Database architecture

WooCommerce owns products, orders, customers, and coupons — use its supported APIs and data models
(HPOS-compatible), and do **not** build parallel copies of them.

Custom tables (prefix `{$wpdb->prefix}ac_`) only for genuinely custom, high-volume domains:

| Table | Purpose |
| --- | --- |
| `ac_audit_logs` | who changed what, when, from where |
| `ac_shipments` | provider shipment records and tracking state |
| `ac_payment_transactions` | payment attempts, provider references, verification results |
| `ac_webhook_events` | received event ids — the idempotency ledger |
| `ac_notification_events` | queued/sent notifications |
| `ac_analytics_aggregates` | pre-computed metrics; dashboards must not scan all orders per request |
| `ac_geo_wilayas` / `ac_geo_communes` | Algerian geography + per-provider destination mappings |

Schema changes ship as numbered migrations (`migrations/001_create_audit_logs.php`, …) gated on
`AC_DB_VERSION`; bumping the constant runs the pending ones. A migration must never require deleting
existing data to succeed.

## 8. Authentication

```
Browser → Next.js server (holds the credential) → WordPress API
```

Privileged credentials never reach browser JavaScript. Admin operations are proxied server-side by the
Next.js admin. Customer sessions use a dedicated customer strategy with HTTP-only cookies — never an
administrator credential, never a long-lived privileged token in browser storage.

Authorization is always enforced in `permission_callback` and services. A hidden button in the frontend is
not an access control.

## 9. Environments

```
local (Docker)  →  GitHub  →  staging  →  production
```

Separate database, credentials, API keys, webhook secrets, and domain per environment. The development
Compose stack is never exposed to the internet and is not a production configuration.
