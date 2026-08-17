# API Reference

Roadmap §39 (the contract) and §70 (the Next.js integration). This is the document to read before
writing a line of the storefront or the admin panel.

**Base URL**

```
https://<host>/wp-json/algerian-commerce/v1
```

**Talk to this namespace and nothing else.** WordPress's `/wp-json/wp/v2` and WooCommerce's
`/wp-json/wc/v3` are both reachable on the same host, and both are the wrong contract: they expose the
platform's data models rather than this shop's, they carry no `ac_*` capability checks, and nothing in
this repository promises they keep working. Roadmap §70 states the rule; everything below is what you get
in exchange for following it.

Versioning is in the path. A breaking change becomes `/v2`; `/v1` keeps its shape.

---

## The envelope

Every JSON response, success or failure, has the same outer shape.

```json
{ "success": true, "data": { } }
```

```json
{ "success": false, "error": { "code": "not_found", "message": "…", "details": {} } }
```

List endpoints add `meta`:

```json
{
  "success": true,
  "data": [ … ],
  "meta": { "total": 137, "page": 1, "per_page": 20, "total_pages": 7 }
}
```

Some endpoints add other keys to `meta` — analytics adds `money_visible`, the CMS homepage adds a report
of sections it had to drop. They are documented with their routes.

**Two deliberate exceptions**, both bounded:

- **CSV exports** (`GET /export/*`) return the file itself with `Content-Type: text/csv` and a
  `Content-Disposition` filename. Only 2xx bodies; an export *error* still comes back in the envelope, so
  a client never saves an error message as `products.csv`.
- **`GET /health`** carries `status` beside `data`, so a load balancer can read one field.

---

## Errors

| HTTP | `error.code` | Means |
|---|---|---|
| 400 | `invalid_request` | The payload is wrong. `details.fields` names every bad field, not just the first. |
| 401 | `unauthenticated` | No credential, or one that did not verify. |
| 403 | `forbidden` | Authenticated, but lacking the capability. |
| 404 | `not_found` | No such resource — **also** what an unconfigured webhook route returns. |
| 409 | `conflict` | The request is well formed but the state refuses it: a duplicate SKU, an illegal status move. |
| 413 | — | The upload is over the cap. |
| 415 | — | The upload is not an accepted image type. |
| 429 | — | Rate limited. Carries `Retry-After`. |
| 500 | `internal_error` | A fault here. Never carries diagnostics. |

Validation errors are per-field and complete:

```json
{
  "success": false,
  "error": {
    "code": "invalid_request",
    "message": "The product data is invalid.",
    "details": { "fields": { "sale_price": "Cannot exceed the regular price.", "sku": "Required." } }
  }
}
```

**Unknown fields are rejected, not ignored.** Sending `stock_quantiy` is a 400 naming it. The one
exception is round-tripping: fields this API *emits* but does not accept (`id`, `price`, `date_created`,
…) are dropped silently, so `GET`, edit one field, `PATCH` the whole object back works.

---

## Authentication

Three different credentials, for three different callers. They are not interchangeable, and mixing them
up is the most common integration mistake.

| Caller | Credential | Sent as | Used by |
|---|---|---|---|
| Staff / admin panel | WordPress **Application Password** | `Authorization: Basic base64(user:password)` | every `ac_*` route |
| Shopper (signed in) | **Customer session** | `X-Customer-Token: <token>` | `/account/*` |
| Shopper (anonymous or not) | **Cart token** | `Cart-Token: <token>` | `/cart/*`, `/checkout/*` |
| Anyone | none | — | `/health`, `/locations/*`, `/webhooks/*`, the namespace index |

Both shopper tokens can also travel as a query parameter (`customer_token`, `cart_token`) for clients
that cannot set headers. Prefer the header.

### Staff credentials never reach the browser

This is the rule the whole architecture rests on (roadmap §19, §44). The flow is:

```
browser → your Next.js server → this API
```

The Application Password lives in the Next.js **server-side** environment only. Never in a
`NEXT_PUBLIC_*` variable, never in a client component, never in a response your page returns. A leaked
Application Password is full admin access to the shop.

Application Passwords require HTTPS. On plain HTTP, WordPress refuses them unless the environment
declares itself local — which is why `WP_ENVIRONMENT_TYPE` matters and why staging and production must
run TLS.

### The customer session

`POST /account/register` and `POST /account/login` return a token in the **response body**. It is a
WordPress auth-cookie string, not a token this project invented, which is what makes a password change, a
logout and an expiry invalidate it for free.

It is deliberately not set as a cookie. Your Next.js server should put it in its own HTTP-only cookie so
the browser never holds it, then send it on as `X-Customer-Token`.

A customer session is **not** an admin credential and opens no `ac_*` route. A staff account is refused
at `/account/login` even with the correct password.

### The cart token

`/cart` and `/checkout` are public, because a shopper has no account and requiring one would mean
proxying every quantity change with an admin credential. **The token is the owner.** It is signed with
the site salt and expires in 48 hours. A forged or expired token opens an *empty* cart, never somebody
else's.

Keep it. Send it back on every cart call. Losing it loses the cart.

---

## CORS

Only origins on the allowlist get CORS headers. Set it with `AC_CORS_ORIGINS` (comma-separated). In
development the defaults are `http://localhost:3000` and `http://localhost:3001`.

There is no `*` on private routes. If your fetches fail in the browser but work from your server, your
origin is not on the list.

---

## Rate limits

Per credential (or per IP for anonymous callers), in a rolling 60-second window:

| Bucket | Default | Override |
|---|---|---|
| reads | 600/min | `AC_RATE_LIMIT_READS` |
| writes | 120/min | `AC_RATE_LIMIT_WRITES` |
| uploads | 30/min | `AC_RATE_LIMIT_UPLOADS` |
| failed logins | 10 per 15 min, per IP | `AC_RATE_LIMIT_AUTH_FAILURES` |

A 429 carries `Retry-After` in seconds. A locked-out address is refused **even with the correct
password** until the window passes — an operator lifts it with
`wp algerian-commerce unlock <ip|login>`.

---

## Pagination

Every list endpoint takes `page` (default 1) and `per_page` (default **20**, maximum **100**), and
returns the `meta` block shown above. Most also take `orderby` and `order`. Asking for more than 100 is
clamped, not an error.

---

## Capabilities

Every private route requires one capability. The roles that hold them are roadmap §45's matrix;
`GET /auth/me` tells a signed-in caller exactly which it has, which is what an admin panel should use to
decide what to render.

| Capability | Covers |
|---|---|
| `ac_manage_products` | products, variations, categories, product import/export |
| `ac_manage_inventory` | stock, movements, inventory import/export |
| `ac_manage_orders` | orders, notes, timeline, COD attempts, order export |
| `ac_manage_customers` | customers, their orders, customer export |
| `ac_manage_coupons` | coupons |
| `ac_manage_shipping` | shipments, rates, rules, providers |
| `ac_manage_payments` | transactions, payment creation, verification |
| `ac_manage_content` | CMS reads, media |
| `ac_manage_marketing` | pixel config, conversion events |
| `ac_view_analytics` | all analytics; **money additionally needs `ac_manage_orders`** |
| `ac_view_audit_logs` | the audit trail |

---

# Routes

Capability shown per route. `public` means no credential; the shopper-token routes are marked.

## Health and identity

| Method | Route | Guard |
|---|---|---|
| GET | `/health` | public |
| GET | `/auth/me` | signed in (any credential) |

## Products

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/products` | `ac_manage_products` |
| GET, PATCH, DELETE | `/products/{id}` | `ac_manage_products` |
| POST | `/products/{id}/duplicate` | `ac_manage_products` |
| POST | `/products/bulk` | `ac_manage_products` |
| GET, POST | `/products/{id}/variations` | `ac_manage_products` |
| GET, PATCH, DELETE | `/products/{id}/variations/{variation_id}` | `ac_manage_products` |
| GET | `/product-categories` | `ac_manage_products` |

List filters: `page per_page category search sku status orderby order`.
`DELETE` trashes; `?force=true` removes permanently.
A product's `seo` block is read and written here — there is no separate SEO endpoint.

## Inventory

| Method | Route | Guard |
|---|---|---|
| GET | `/inventory` | `ac_manage_inventory` |
| GET, PATCH | `/inventory/{id}` | `ac_manage_inventory` |
| GET | `/inventory/lookup` | `ac_manage_inventory` — takes `?sku=` |
| GET | `/inventory/low-stock` | `ac_manage_inventory` |
| POST | `/inventory/{id}/adjust` | `ac_manage_inventory` |
| POST | `/inventory/bulk` | `ac_manage_inventory` |
| GET | `/inventory/movements` | `ac_manage_inventory` |
| GET | `/inventory/movements/summary` | `ac_manage_inventory` |

Every stock change writes a movement row. There is no path that changes stock without one.

## Orders

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/orders` | `ac_manage_orders` |
| GET, PATCH | `/orders/{id}` | `ac_manage_orders` |
| POST | `/orders/{id}/cancel` | `ac_manage_orders` |
| GET, POST | `/orders/{id}/notes` | `ac_manage_orders` |
| GET | `/orders/{id}/timeline` | `ac_manage_orders` |

Statuses: `pending processing on-hold completed cancelled refunded failed`.
**Not every move is allowed.** A new order may only be created as `pending`, `processing`, `on-hold`,
`completed` or `failed`. `cancelled` and `refunded` are terminal. `refunded` is reachable only from
`processing` or `completed`. An illegal move is a 409 listing what is allowed.

Line items may only be rewritten while the order holds no stock.

## Cash on delivery

| Method | Route | Guard |
|---|---|---|
| GET, PATCH | `/orders/{id}/cod` | `ac_manage_orders` |
| POST | `/orders/{id}/cod/attempts` | `ac_manage_orders` |
| GET | `/cod/statistics` | `ac_view_analytics` |

COD is order metadata and audit events, never a status. A COD outcome does not move the order.

## Customers

| Method | Route | Guard |
|---|---|---|
| GET | `/customers` | `ac_manage_customers` |
| GET, PATCH | `/customers/{id}` | `ac_manage_customers` |
| GET | `/customers/{id}/orders` | `ac_manage_customers` |

`roles`, `capabilities` and `user_pass` are refused **by name** — this is the one write a non-staff caller
can make to a WordPress user, so the refusal is explicit rather than incidental.

## Shopper accounts (storefront)

| Method | Route | Guard |
|---|---|---|
| POST | `/account/register` | public |
| POST | `/account/login` | public |
| POST | `/account/logout` | customer token |
| GET, PATCH | `/account` | customer token |
| POST | `/account/password` | customer token |
| GET | `/account/orders` | customer token |
| GET | `/account/orders/{id}` | customer token |

`/account/orders` has no `customer_id` parameter — the identity comes from the session and cannot be
redirected. `/account/orders/{id}` checks ownership in the service layer: customer A asking for customer
B's order gets **403**, and is served their own order normally. Both halves are tested.

**Guest orders are reachable by nobody here.** An order placed without an account has no owner to match.

There is **no password reset by email** yet — it needs a synchronous mail path that does not exist.

## Cart and checkout (storefront)

| Method | Route | Guard |
|---|---|---|
| GET | `/cart` | cart token |
| POST | `/cart/items` | cart token |
| PATCH, DELETE | `/cart/items/{key}` | cart token |
| DELETE | `/cart` | cart token |
| POST | `/cart/coupons` | cart token |
| DELETE | `/cart/coupons/{code}` | cart token |
| GET | `/checkout/shipping-rates` | cart token |
| POST | `/checkout` | cart token |

**Every number is re-read server-side.** `/cart/items` accepts `product_id`, `variation_id` and
`quantity` — and refuses `price`, `line_total`, `subtotal`, `total`, `discount` and `currency` **by name,
with a reason**. Prices, totals, tax, stock and coupon rules all come from the catalogue, never from you.

Shipping is quoted against the destination, and a free-shipping threshold is compared against the cart's
own subtotal.

**Checkout does not take the money.** It creates a `pending` order and hands back:

```json
{
  "success": true,
  "data": {
    "order": { "id": 4211, "status": "pending", "total": "26350.00" },
    "next": {
      "action": "create_payment",
      "endpoint": "/orders/4211/payments",
      "payment_method": "chargily"
    }
  }
}
```

Call that endpoint next. A payment that fails must not orphan an order that succeeded.

## Coupons

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/coupons` | `ac_manage_coupons` |
| GET, PATCH, DELETE | `/coupons/{id}` | `ac_manage_coupons` |

Types: `percent`, `fixed_cart`, `fixed_product`. Codes are lowercased on save.
`maximum_discount` is **refused by name** — WooCommerce has no such field, and `maximum_amount` caps the
*cart*, not the discount.

## Shipping

| Method | Route | Guard |
|---|---|---|
| GET | `/shipping/providers` | `ac_manage_shipping` |
| GET | `/shipping/rates` | `ac_manage_shipping` |
| GET, POST | `/shipping/rules` | `ac_manage_shipping` |
| GET, PATCH, DELETE | `/shipping/rules/{id}` | `ac_manage_shipping` |
| GET | `/shipments` | `ac_manage_shipping` |
| GET, POST | `/orders/{id}/shipments` | `ac_manage_shipping` |
| GET, PATCH | `/shipments/{id}` | `ac_manage_shipping` |
| POST | `/shipments/{id}/cancel` | `ac_manage_shipping` |
| POST | `/shipments/{id}/sync` | `ac_manage_shipping` |

What the shop *charges* (`/shipping/rules`) is separate from what a courier *quotes*. The narrowest
matching rule wins — commune beats wilaya beats the national fallback — and rules are never added
together.

**One live shipment per order**, enforced by the database. **A parcel's status never moves the order.**

Shipment `label` URLs carry an access token and are credentials to a customer's name, phone and address.
Never put one in a client-side page or a log.

## Payments

| Method | Route | Guard |
|---|---|---|
| GET | `/payments` | `ac_manage_payments` |
| GET | `/payments/{id}` | `ac_manage_payments` |
| GET | `/payments/methods` | `ac_manage_payments` |
| GET, POST | `/orders/{id}/payments` | `ac_manage_payments` |
| POST | `/payments/{id}/verify` | `ac_manage_payments` |

`POST /orders/{id}/payments` returns the provider's `checkout_url` for a redirect. Payment status is
always verified server-side against the provider — **never trusted from a client callback**. From
`paid`, the only permitted move is `refunded`.

## Locations

| Method | Route | Guard |
|---|---|---|
| GET | `/locations/wilayas` | public |
| GET | `/locations/wilayas/{id}` | public |
| GET | `/locations/wilayas/{id}/communes` | public |
| GET | `/locations/communes/{id}` | public |
| GET | `/locations/coverage` | public |

Public because an address form needs them before anyone signs in. 69 wilayas, 1,541 communes, with Arabic
names, daira and coordinates. Postal codes are deliberately empty — they are not in the source data.

## CMS

| Method | Route | Guard |
|---|---|---|
| GET | `/cms/homepage` | `ac_manage_content` |
| GET | `/cms/pages/{slug}` | `ac_manage_content` |
| GET | `/cms/banners` | `ac_manage_content` |
| GET | `/cms/faqs` | `ac_manage_content` |
| GET | `/cms/menus/{location}` | `ac_manage_content` |

Read-only by design. The page parameter is named `slug` but takes a **full path** — `legal/terms`, not
`terms` — because that is how WordPress addresses a hierarchical page, and a bare slug cannot choose
between two children both called `terms`. Menu locations are `primary` and `footer`. A page's `seo` block
comes back with it.

## Media

| Method | Route | Guard |
|---|---|---|
| GET | `/media` | `ac_manage_content` |
| POST | `/media` | `ac_manage_content` |
| GET, PATCH, DELETE | `/media/{id}` | `ac_manage_content` |

`POST /media` is `multipart/form-data` with the file in a field named `file`, plus optional `alt`,
`title`, `caption`. **jpg, jpeg, png and webp only.** The stored filename is generated here, not taken
from you, and the extension comes from the sniffed type. Every accepted image is re-encoded from decoded
pixels, which strips metadata. Over the cap is 413, wrong type is 415.

Note the capability: a Product Manager **cannot** upload. That is a documented gap, not an oversight.

## Analytics

| Method | Route | Guard |
|---|---|---|
| GET | `/analytics/overview` | `ac_view_analytics` |
| GET | `/analytics/revenue` | `ac_view_analytics` **+ `ac_manage_orders`** |
| GET | `/analytics/orders` | `ac_view_analytics` |
| GET | `/analytics/products` | `ac_view_analytics` |
| GET | `/analytics/customers` | `ac_view_analytics` |
| GET | `/analytics/shipping` | `ac_view_analytics` |
| GET | `/analytics/cod` | `ac_view_analytics` |

Range: `range` (e.g. `7d`, `30d`) or `date_from`/`date_to`. **Maximum window 366 days.**

**Money is gated separately.** Without `ac_manage_orders`, `/analytics/revenue` is 403 and every other
endpoint omits its money block, saying so in `meta.money_visible`. Reporting may not disclose in aggregate
what the caller cannot read in detail.

Responses are cached for `AC_ANALYTICS_CACHE_TTL` seconds (60 by default, `0` to disable).

Three figures are reported as **unavailable rather than zero**: shipping cost, payment fees and margin.
The data to compute them honestly does not exist.

## Import and export

| Method | Route | Guard |
|---|---|---|
| POST | `/import/products` | `ac_manage_products` |
| POST | `/import/inventory` | `ac_manage_inventory` |
| GET | `/export/products` | `ac_manage_products` |
| GET | `/export/inventory` | `ac_manage_inventory` |
| GET | `/export/orders` | `ac_manage_orders` |
| GET | `/export/customers` | `ac_manage_customers` |

Imports take the **CSV file as the raw request body** with `Content-Type: text/csv` — not JSON, not
multipart. `dry_run` defaults to **true**: a client that forgets the flag previews and never writes.
`mode` is `create` or `update` (products) — neither does both.

Exports return the file, not the envelope. Every imported stock change writes a ledger movement.

## Marketing

| Method | Route | Guard |
|---|---|---|
| GET | `/marketing/config` | `ac_manage_marketing` |
| POST | `/marketing/events/purchase` | `ac_manage_marketing` |

`/marketing/config` serves the **public** pixel id for the browser. The Conversions API token appears in
no response, ever.

**The backend mints the event id and tells you.** Send the same `event_name` and `event_id` from the
browser or Meta will count the conversion twice. Only server-witnessed events are sent here — `PageView`,
`Search` and `ViewContent` are the browser's to report.

## Webhooks

| Method | Route | Guard |
|---|---|---|
| POST | `/webhooks/chargily` | signature |
| POST | `/webhooks/yalidine` | shared secret |
| POST | `/webhooks/zr-express` | Svix signature |

**There is one route per registered provider, and a route exists only when that provider does.** An
unconfigured webhook is a 404, not an open door — so the list above depends on which credentials are set.
Two more appear because their providers are always registered and neither accepts anything:
`/webhooks/cod` and `/webhooks/manual` answer 400 `webhook_unsupported`, because cash on delivery and
in-house delivery have nobody to send them.

An unverified request is 401 `webhook_unverified` and is told nothing about which check failed. Replaying
an event never applies it twice.

Not for your frontend to call.

## Audit

| Method | Route | Guard |
|---|---|---|
| GET | `/audit-logs` | `ac_view_audit_logs` |

---

# A storefront checkout, end to end

```
POST /cart/items          { product_id, quantity }        → keep the Cart-Token
GET  /locations/wilayas                                   → fill the address form
GET  /locations/wilayas/{id}/communes
GET  /checkout/shipping-rates?wilaya_id=&commune_id=      → show delivery options
POST /cart/coupons        { code }                        (optional)
GET  /cart                                                → final totals, from the server
POST /checkout            { wilaya_id, commune_id, delivery_type, payment_method }
                                                          → order created, status pending
POST /orders/{id}/payments                                → checkout_url, redirect the shopper
```

Cash on delivery ends at `/checkout`; there is no redirect.

After the order exists, `POST /marketing/events/purchase` returns the `event_id` your browser pixel must
reuse.

---

# Things that will bite you

- **Send tokens back.** Cart and customer tokens are the whole identity. Drop one and the cart is empty.
- **Never expose the Application Password.** Browser → your server → this API. Always.
- **You cannot set prices.** Anything resembling money in a cart payload is refused by name.
- **`GET` then `PATCH` the whole object works** — read-only fields are dropped, not rejected.
- **A 400 lists every bad field**, so render `details.fields` rather than the top-level message.
- **A 404 can mean "not configured"** on webhook routes, and "not yours" on `/account/orders/{id}`.
- **Statuses have rules.** Read the 409 body; it tells you which moves are legal.
- **Add your origin to `AC_CORS_ORIGINS`** before wondering why the browser fails and curl does not.
- **Exports are files.** Do not parse them as JSON.
- **`?force=true`** on a delete is permanent. Without it, things are trashed and still addressable.

---

# Verifying the contract

```bash
curl https://<host>/wp-json/algerian-commerce/v1/health
curl -u "<user>:<application-password>" https://<host>/wp-json/algerian-commerce/v1/auth/me
```

`scripts/test-api.sh` exercises this contract over real HTTP — authentication, rate limiting, CORS,
uploads, exports and a full product CRUD walkthrough — and is the executable version of this document
(roadmap §69). When the two disagree, the script is right.
