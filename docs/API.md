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

### The tracking token

`POST /checkout` returns a fourth thing, and it is not interchangeable with the other three. See
[Order tracking](#order-tracking-storefront) — it opens exactly one order's delivery status on
`GET /orders/track` and nothing else, and it is the only key a **guest** order has.

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
| order tracking | 20/min, per IP | `AC_RATE_LIMIT_TRACKING` |
| failed logins | 10 per 15 min, per IP | `AC_RATE_LIMIT_AUTH_FAILURES` |

`GET /orders/track` has its own, much smaller bucket because it is unauthenticated and its only key is a
MAC. It is **not** the failed-login bucket: a stale tracking link does not lock a customer out of signing
in.

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

**Gate on the capability, never on the role.** Every guard in this API is `current_user_can()`, which
is what let staff roles collapse to two tiers without touching a single one of them — see
[Staff users and roles](#staff-users-and-roles). A client that branches on a role name instead breaks the moment the
matrix moves; one that reads `/auth/me`'s capability list does not.

| Capability | Covers |
|---|---|
| `ac_manage_products` | products, variations, categories, product import/export |
| `ac_manage_inventory` | stock, movements, inventory import/export |
| `ac_manage_orders` | orders, notes, timeline, COD attempts, order export |
| `ac_manage_customers` | customers, their orders, customer export; also required to **send** a campaign |
| `ac_manage_coupons` | coupons |
| `ac_manage_shipping` | shipments, rates, rules, providers |
| `ac_manage_payments` | transactions, payment creation, verification |
| `ac_manage_content` | CMS reads, media |
| `ac_manage_marketing` | pixel config, conversion events, campaigns, segments, email templates |
| `ac_view_analytics` | all analytics; **money additionally needs `ac_manage_orders`** |
| `ac_manage_settings` | the client configuration — **Super Admin only** |
| `ac_manage_users` | staff accounts, roles, application passwords — **Super Admin only** |
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
| GET, POST | `/attributes` | `ac_manage_products` |
| GET, PATCH, DELETE | `/attributes/{id}` | `ac_manage_products` |
| GET, POST | `/attributes/{id}/terms` | `ac_manage_products` |
| PATCH, DELETE | `/attributes/{id}/terms/{term_id}` | `ac_manage_products` |

`DELETE` trashes; `?force=true` removes permanently.
A product's `seo` block is read and written here — there is no separate SEO endpoint.

**A variation's `sku` is its own, and `""` means it inherits the parent's.** WooCommerce resolves the
inherited value when it renders one; this API reports what is stored, so that a read body can be
written back. Send `""` to keep inheriting.

### Global attributes — §88

The attributes §82 filters and counts on. **Only a global attribute can be filtered or counted**, so
a shop with none has a faceted search that can never return a facet — these routes are how you get
one without opening wp-admin.

An attribute has two identifiers and both are published, because confusing them is the mistake this
endpoint exists to prevent: `slug` is what you send back here (`taille`), and `taxonomy` is what
`GET /products?attributes[pa_taille]=m` matches and what keys a `meta.facets` group (`pa_taille`). A
`pa_` prefix on a written slug is stripped rather than refused, since the API publishes that form
itself.

`name` is the label a shopper sees. `type` is validated against `wc_get_attribute_types()` and a bad
one is a 400 listing `details.available_types`. `order_by` is `menu_order`, `name`, `name_num` or
`id`. A slug over **29 bytes** is refused — WordPress caps a taxonomy name at 32 and `pa_` takes
three.

**Refused by name**: `terms` (managed one at a time at `/attributes/{id}/terms`, because deleting one
detaches every product using it), `attribute_id`, `attribute_name`.

`GET /attributes` is unpaginated — a shop has a handful — and omits usage. `GET /attributes/{id}`
adds `term_count` and `product_count`.

**A new attribute is usable immediately, in the same request.** WooCommerce's own API cannot do this:
`wc_create_attribute()` registers no taxonomy, so through `wc/v3` a new attribute takes no terms and
is invisible to the facet counter until the next request. Here `meta.filterable` reports it, terms
can be added straight away, and `GET /products?facets=attributes` lists it. Counts still cover
published products, so it counts zero until something is tagged and published.

### Deleting an attribute or a term

Both are refused while anything uses them, because WooCommerce reports nothing when they are not:
deleting an attribute removes every term and leaves each product referencing one that no longer
exists, and deleting a term detaches its products *and* breaks any variation that resolved through
it — a failure with no error and a long delay before the symptom.

```
DELETE /attributes/{id}            → 409 { products, product_ids, taxonomy }
DELETE /attributes/{id}/terms/{t}  → 409 { products, term_id }
```

`?force=true` does it anyway, and the response and the audit row both report `products_detached`.

**A term slug is a public identifier.** It is what `attributes[pa_taille]=m` matches, so renaming one
breaks every saved filter and every storefront link built on it. Not refused — sometimes a slug is
genuinely wrong — but never incidental: the response carries `meta.slug_changed: true` and the audit
row names both slugs. Renaming an *attribute's* slug is migrated by WooCommerce across products,
variations and term meta, so the catalogue survives; bookmarked URLs do not.

### Listing, filtering and facets — §82

| Parameter | Form | Notes |
|---|---|---|
| `page` `per_page` | integer | `per_page` is capped at 100 |
| `search` `sku` | string | `search` is a substring match, not relevance ranking |
| `status` | enum | `draft` `pending` `private` `publish` |
| `orderby` `order` | enum | `date id title price sku menu_order popularity rating` |
| `category` `tag` | `12` or `12,15` | **term ids**, repeatable as a comma list |
| `min_price` `max_price` | number | the **effective** price — a discounted product filters at its sale price |
| `attributes[pa_size]` | `m,l` | term **slugs**; values within one attribute are alternatives, different attributes narrow together |
| `stock_status` | enum | `instock` `outofstock` `onbackorder` |
| `on_sale` `featured` | boolean | absent is not `false` |
| `rating_min` | 0–5 | |
| `facets` | `attributes,price,category,tag,stock_status,rating` | opt-in |

`attributes[size]=m` resolves too — the `pa_` prefix is optional. **Only a global
attribute can be filtered or counted**: an attribute typed directly onto one
product has no shared vocabulary and no term to count. Naming one is a `400`
whose `details.facetable_attributes` lists what this shop does offer.

### Configurable options — §83

A product may carry an **option set**: choices that change the price without
being variations. Written and read as `options` on the product itself — there is
no separate endpoint, and no table.

> **A variation is a thing with a SKU and stock. An option is a modifier with
> neither.** If a choice needs its own stock count, it is a variation (§47).

```json
"options": { "groups": [
  { "id": "wrap", "type": "choice", "label": "Gift wrap",
    "required": false, "min": 0, "max": 1,
    "choices": [ { "id": "gold", "label": "Or", "price_delta": "250", "image_id": 0 },
                 { "id": "none", "label": "Sans coffret", "price_delta": "-100", "image_id": 0 } ] },
  { "id": "engraving", "type": "text", "label": "Gravure",
    "required": false, "max_length": 20, "price_delta": "500" },
  { "id": "contents", "type": "bundle", "label": "Contenu",
    "items": [ { "product_id": 12, "quantity": 2 } ] }
] }
```

| Group type | Keys | Notes |
|---|---|---|
| `choice` | `required` `min` `max` `choices[]` | `price_delta` may be **negative**; `image_id` must already exist |
| `text` | `required` `max_length` `price_delta` | `max_length` 1–500 |
| `bundle` | `items[]` | components this product draws stock from |

Caps: 20 groups, 50 choices per group, 20 bundle components. `required: true`
with `min: 0` is refused as contradictory rather than normalised. Send
`"options": null` to clear. Errors name the position —
`options.groups[0].choices[2].price_delta`.

Two **read-only** fields come back beside it and are ignored on write, so a
whole GET body PATCHes back unchanged:

- `bundle` — `{ items, available }`. `available` is **computed on every read**
  as the minimum its components allow; it is never stored.
- `options_problems` — groups the stored document could not be read. Present
  only when something is wrong with it.

### Choosing options on a cart line

`POST /cart/items` accepts `options` — **identifiers only**:

```json
{ "product_id": 42, "quantity": 2, "options": { "wrap": "gold", "engraving": "AB" } }
```

**The client sends the choice; the server reads the price.** `price`,
`option_price`, `options_price`, `surcharge`, `option_total` and the rest are
refused **by name** with the reason. The surcharge is re-read from the product's
stored definition on every cart mutation and again at checkout.

Each configured line comes back with `options` (the priced selection) and
`options_surcharge`; `price` already includes it. **Two configurations of one
product are two lines** — one mug engraved "AB" and one "CD" cannot be a
quantity of two — so a line is addressed by its key, as it always was.

`meta.problems` appears when a line's stored options can no longer be priced,
which happens if the shop edits the definition while the cart is live. The line
keeps its catalogue price and **`POST /checkout` refuses** until the shopper
chooses again.

On the order, chosen options land on the line item twice: as visible meta
(`"Gift wrap": "Or"`), which packing slips and WooCommerce's own emails already
render, and as `_ac_options`, frozen at the time of sale.

Facets are **opt-in** and arrive under `meta.facets`, never in `data`:

```json
"meta": {
  "total": 2, "page": 1, "per_page": 20, "total_pages": 1,
  "facets": {
    "scope": "publish",
    "scope_note": "Counts cover published products; drafts and pending products are not counted.",
    "price": { "min": "100.00", "max": "590.00", "currency": "DZD" },
    "stock_status": [ { "value": "instock", "count": 5 } ],
    "category": { "values": [ { "term_id": 21, "slug": "tapis", "name": "Tapis", "count": 3 } ],
                  "total_values": 1, "truncated": false },
    "attributes": {
      "facetable": ["pa_size"],
      "note": "Only global attributes can be filtered or counted. …",
      "groups": [ { "taxonomy": "pa_size", "label": "Size", "total_values": 3, "truncated": false,
                    "values": [ { "term_id": 33, "slug": "m", "name": "M", "count": 2 } ] } ]
    }
  }
}
```

Three things to know before building against it:

- **A facet's counts are computed against every filter except its own.** With
  `attributes[pa_size]=m` selected, the size facet still reports how many
  products exist in `l` and `xl`; every other facet narrows by `size=m`. That is
  what stops a selection turning every sibling into a dead end.
- **Counts cover published products only**, which is what `scope` says. `GET
  /products` lists drafts as well, so an admin listing can legitimately show
  more rows than the counts beside it.
- **Every group is capped** at 50 values, ordered by count. `truncated` and
  `total_values` say when the list was cut — a bounded list that does not say so
  reads as a complete one.

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
| GET | `/orders/track` | public — tracking token |

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

**`marketing_consent` is refused by name too**, and it is the only one of them a client reaches without
typing it: the field *is* emitted on read, so a GET → edit → PATCH arrives there. It used to answer
`"Unknown field."`, the same message a misspelling gets, which reads as "no such field" when the truth is
"it exists and it is not yours". Refused rather than silently dropped, unlike `role`: a shop that thinks
it ticked a consent box has no consent record.

Two read-only fields sit beside the flag, both `null` on a customer who has never decided:

| Field | Meaning |
|---|---|
| `marketing_consent_at` | ISO 8601, when they last decided — **present on a withdrawal as well as a grant** |
| `marketing_consent_source` | `registration`, `account`, `unsubscribe_link`, or `null` if it predates the record |

A bare boolean cannot distinguish *declined* from *never asked*, and an admin screen showing one is
answering a question it has not been given the data for. The date carries a UTC offset even though the
meta behind it does not: `notes[].created_at` and `movements[].created_at` both hand a client a naive
instant that `new Date()` shifts silently, and a consent date off by an hour is a date in the wrong day.

Staff set none of this. `POST /account/marketing-consent` is the customer's own route.

## Shopper accounts (storefront)

| Method | Route | Guard |
|---|---|---|
| POST | `/account/register` | public |
| POST | `/account/login` | public |
| POST | `/account/logout` | customer token |
| GET, PATCH | `/account` | customer token |
| POST | `/account/password` | customer token |
| POST | `/account/password/reset` | public |
| POST | `/account/password/reset/confirm` | public |
| POST | `/account/marketing-consent` | customer token |
| GET | `/account/orders` | customer token |
| GET | `/account/orders/{id}` | customer token |

`/account/orders` has no `customer_id` parameter — the identity comes from the session and cannot be
redirected. `/account/orders/{id}` checks ownership in the service layer: customer A asking for customer
B's order gets **403**, and is served their own order normally. Both halves are tested.

**Guest orders are reachable by nobody here.** An order placed without an account has no owner to match.
They are reachable through [order tracking](#order-tracking-storefront), which is keyed on a token rather
than on an identity.

`GET /account/orders/{id}` carries a `shipment` block — the parcel, its status and its history — or
`shipment: null` when nothing has shipped yet, which is the ordinary state of a `pending` order. It never
carries the courier's provider metadata; see the tracking section for why.

`POST /account/register` accepts an optional `marketing_consent` boolean, **default false**.
`POST /account/marketing-consent` with `{ "consent": true|false }` is how a shopper changes it later; it
answers `{ marketing_consent, changed }` and is idempotent. **No staff route can set this flag** — see
[Email marketing](#email-marketing-campaigns). Send an unticked checkbox, never a pre-ticked one, and
never infer it from a purchase.

### Password reset

Two public calls, because a shopper who cannot sign in holds no session.

```
POST /account/password/reset          { email }
POST /account/password/reset/confirm  { login, key, password }
```

**Both answer 503 if the shop cannot actually send email**, with `error.code` naming which half is
missing — `mail_not_configured` (no `SMTP_HOST`) or `storefront_url_not_set` (no `store.storefront_url`
in `/settings`, so no link can be built). That is deliberate: a reset that mints a token and silently
fails to send looks like a feature that works.

**The request call always answers the same thing**, whether or not the address exists, whether or not it
belongs to a staff account. Do not build UI that distinguishes them — there is nothing to distinguish.
Show "if that address has an account, check your email" and move on.

The link you send points at `{storefront_url}/account/reset?key=…&login=…`. **That path is yours to
build** — it should collect a new password and POST it, with `key` and `login`, to the confirm endpoint.
The destination host comes from configuration and never from the request, because a `redirect_to`
parameter here is how reset-link poisoning works.

Tokens expire in 24 hours and are single-use. Passwords must be at least 12 characters.

**A successful reset does not sign the shopper in** — it returns `{ reset: true, sessions_revoked: true }`
and no token. Send them to your login page. Every existing session is invalidated, which is the property
that makes a reset useful after an account is stolen.

Both calls share the failed-login rate limiter: 10 attempts per 15 minutes per IP, then 429.

Operators check the mail path with `wp algerian-commerce mail-check --to=you@example.com`.

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
    },
    "tracking": {
      "token": "4211.9f3c1a4e07b52d6890fa1c3b4d5e6f70",
      "endpoint": "/orders/track?token=4211.9f3c1a4e07b52d6890fa1c3b4d5e6f70",
      "url": "https://shop.example.dz/orders/track?token=4211.9f3c1a4e07b52d6890fa1c3b4d5e6f70"
    }
  }
}
```

Call that endpoint next. A payment that fails must not orphan an order that succeeded.

**Keep `tracking.token`.** It is the only key a guest order will ever have, and this is the one moment the
caller is provably the buyer. `url` is present only when `store.storefront_url` is set in `/settings` —
this backend will not guess a storefront address.

## Order tracking (storefront)

| Method | Route | Guard |
|---|---|---|
| GET | `/orders/track` | public — tracking token |

```
GET /orders/track?token=4211.9f3c1a…
GET /orders/track            X-Tracking-Token: 4211.9f3c1a…
```

The header takes precedence, and is worth preferring: a query string lands in access logs and in
`Referer` on every outbound link from your tracking page.

**This route exists for guest orders.** A registered shopper can use `/account/orders/{id}` instead, and
should. A guest has no account to sign in to and this is their only door.

The token is minted at checkout and is stable — the same order always yields the same token, so the
confirmation response and the "your parcel is on its way" email carry the same link.

**What it returns, and this list is exhaustive:**

```json
{
  "success": true,
  "data": {
    "order_number": "4211",
    "order_status": "processing",
    "destination": { "wilaya_id": 16, "wilaya_code": "16", "wilaya": "Alger", "wilaya_ar": "الجزائر" },
    "shipment": {
      "courier": "yalidine",
      "tracking_number": "yal-16-ABCDEF",
      "status": "in_transit",
      "is_live": true,
      "estimated_delivery": null,
      "created_at": "2026-08-01T09:00:00+00:00",
      "updated_at": "2026-08-03T14:30:00+00:00"
    },
    "history": [
      { "status": "created",    "at": "2026-08-01T09:00:00+00:00" },
      { "status": "in_transit", "at": "2026-08-03T14:30:00+00:00" }
    ]
  }
}
```

**What it will never return**: the delivery address, the commune, the phone, the email, the customer's
name, line items, quantities, totals, the payment method, order notes, or the courier's label URL. A
tracking link is forwarded, pasted into chats and screenshotted; a page that echoed the address would turn
one leaked link into a doxxing tool. The wilaya is enough to recognise your own parcel and nowhere near
enough to find anybody. See `docs/SECURITY.md` → "Order tracking" for the rule.

**`order_status` and `shipment.status` are two different things and are never merged.** A parcel's
progress does not move the order — that has been true since §55 — so an order can be `processing` while
its parcel is `delivered`, and the shop decides when to complete it.

`shipment` is `null` and `history` is `[]` until something ships. `destination` is `null` for an order
that did not go through this API's checkout and whose parcel recorded no wilaya — never guessed from the
address.

**Statuses**: `pending created picked_up in_transit out_for_delivery delivered returning returned
cancelled failed`. `is_live` is false for the last four.

**Errors:**

| Status | Code | Means |
|---|---|---|
| 404 | `not_found` | no token, malformed, wrong MAC, unknown order, or a link that was revoked |
| 410 | `tracking_link_expired` | a genuine token, more than 90 days after the parcel finished |
| 429 | `too_many_requests` | 20/min per IP; `Retry-After` in seconds |

The 404 is deliberately one answer for several causes — telling them apart tells somebody guessing which
half of the guess was right. The 410 is separate because reaching it requires a valid MAC, so the holder
learns nothing they did not already have, and a customer with a three-month-old email deserves a reason.

`estimated_delivery` is present in the contract and is **null today**: neither Yalidine nor ZR Express
publishes one in the responses this project has measured. It will fill in when an adapter supplies it; do
not build UI that requires it.

## Coupons

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/coupons` | `ac_manage_coupons` |
| GET | `/coupons/eligible-products` | `ac_manage_coupons` |
| GET | `/coupons/eligible-categories` | `ac_manage_coupons` |
| GET, PATCH, DELETE | `/coupons/{id}` | `ac_manage_coupons` |

Types: `percent`, `fixed_cart`, `fixed_product`. Codes are lowercased on save.
`maximum_discount` is **refused by name** — WooCommerce has no such field, and `maximum_amount` caps the
*cart*, not the discount.

### Restrictions are ids, and they are checked

`product_ids`, `excluded_product_ids`, `product_categories` and `excluded_product_categories` are arrays
of ids. **An id that names nothing is a 400**, per field, with the offending ids in the message.
WooCommerce stores these without checking them, so before this they went straight to the database:
`{"product_ids": [999999]}` answered 200, and the coupon then applied to nothing while looking, in every
response, exactly like a coupon that worked.

Reads stay tolerant. A single coupon — `GET`, `POST` and `PATCH`, never the list — carries a
`restrictions` block with the same ids resolved to names:

```json
"restrictions": {
  "product_categories": [{ "id": 16, "name": "Tapis et Textiles", "slug": "tapis", "missing": false }],
  "product_ids": [{ "id": 8842, "name": null, "missing": true }]
}
```

**`missing: true` is emitted, never filtered out.** A product deleted after the coupon was written leaves
a real, stale id — validating a write cannot make a read total — and a client that dropped the row would
silently delete the restriction the next time it saved. The key is present on every row, including the
good ones, because a key that appears only in the failure case is one clients forget to check.

`restrictions` is dropped on write, like every other read-only field, so the whole GET body PATCHes back.

### The two picker sources

`/coupons/eligible-products` and `/coupons/eligible-categories` exist because a restriction picker could
not otherwise be built. Turning `[16]` into *Tapis et Textiles* needs `/products` and
`/product-categories`, and both are `ac_manage_products` — **which Marketing Manager does not hold**,
though it holds `ac_manage_coupons`. One of the three roles that can manage coupons, and the role whose
job coupons are, could not see what a coupon applied to.

These are not `/products` behind a second capability. A product row is `id`, `name`, `sku` and `status`
and nothing else: no price, no stock, no cost, no attributes, no facets, no write. That is strictly less
than the catalogue discloses, which is the point — widening `ac_manage_products` would have handed this
role the catalogue in order to give it a label.

Both take `page`, `per_page` (capped at 100) and `search`, plus `include=12,16` to resolve a known set of
ids in one request. Products search by **name or SKU** — WordPress's own search reads the title and the
content, so a shop that knows a product by its SKU would otherwise get an empty picker. Categories are
listed with `hide_empty=false`: a shop creates the category, then the coupon, then the products.

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
| GET, PUT | `/cms/homepage` | `ac_manage_content` |
| GET, POST | `/cms/pages` | `ac_manage_content` |
| GET, PATCH, DELETE | `/cms/pages/{path}` | `ac_manage_content` |
| GET, POST | `/cms/banners` | `ac_manage_content` |
| PATCH, DELETE | `/cms/banners/{id}` | `ac_manage_content` |
| GET, POST | `/cms/faqs` | `ac_manage_content` |
| PATCH, DELETE | `/cms/faqs/{id}` | `ac_manage_content` |
| GET, POST | `/cms/faq-categories` | `ac_manage_content` |
| PATCH, DELETE | `/cms/faq-categories/{id}` | `ac_manage_content` |
| GET, PUT | `/cms/menus/{location}` | `ac_manage_content` |

One capability for reading and writing. `ac_manage_content` already governs the WordPress editor screens
these post types register, so a person's access to a banner does not depend on which door they came
through — and note the gap it implies, which is the same one media has: a **Product Manager cannot edit
content**.

`DELETE` trashes; `?force=true` removes permanently. Deleting a page with children is a **409** — WordPress
promotes them to the root, which changes the path of every one of them and reports nothing.

### Pages are addressed by path — §89

`{path}` takes a **full path** — `legal/terms`, not `terms` — because that is how WordPress addresses a
hierarchical page, and a bare slug cannot choose between two children both called `terms`.

**The URL is the address and the body is the content**, and the two fields that move a page are separate
from it: `slug` renames (one segment, never a path) and `parent_path` moves. `POST /cms/pages` takes
`parent_path` too, so a client that has only ever seen paths never has to learn ids. `path` and `parent_id`
are emitted on read and dropped on write — the URL wins for the first, and `parent_path` is the field for
the second. A rename answers `meta.path_changed: true` with the new path; WordPress leaves no redirect
behind, so every storefront link built on the old one is now a 404.

A page's `seo` block is read and written here, and SEO errors land in the same `details.fields` list as the
rest of the write. There is no SEO endpoint.

### The page index, and the two kinds of page it treats differently

`GET /cms/pages` lists pages with `?status=`, `?search=`, `?page=` and `?per_page=`, ordered by title.
A row carries `id`, `path`, `slug`, `parent_path`, `status`, `title`, `menu_order` and the two dates —
and deliberately **not** `content`, `seo` or `excerpt`. The first is a whole page body per row and the
second is a `SeoResolver` pass per row; an index that carried them would cost what opening every page at
once costs.

**`?search=` matches the title and the body, never the path.** `WP_Query`'s `s` does not search
`post_name`, so on the one resource whose address *is* its path, searching for `legal/terms` finds
nothing. Asserted in `tests/Api/cms.php` rather than worked around, and a client is expected to say what
the field matches — the same treatment `/customers` gets, where `?search=` matches a login and never a
name.

A WordPress install running WooCommerce contains pages nobody wrote, and they are not one kind of thing.
`SystemPages` splits them by the only distinction that matters, and derives both sets from options
WordPress and WooCommerce already store rather than from a list of paths:

- **Functional** — `page_on_front`, `page_for_posts` and the WooCommerce `shop`, `cart`, `checkout`,
  `myaccount`, `view_order`, `edit_address` and `lost_password` page ids. Their body is a block, a
  shortcode, or nothing at all. **Omitted from the index**, with `meta.excluded_system` reporting how many,
  so a count that is short of what wp-admin shows is explicable. They remain readable and writable by
  path: the reason they are hidden is that nobody edits them from a CMS screen, not that they are secret.
- **Referenced** — the above plus `wp_page_for_privacy_policy` and `woocommerce_terms_page_id`. These two
  are prose somebody has to be able to edit, so they stay in the index and stay writable. **`DELETE` is a
  409 on any referenced page**, naming the option in `details.option`, and **`?force=true` does not
  override it** — unlike the children guard, where force is exactly what the flag is for. Reparenting
  children is recoverable; leaving `woocommerce_checkout_page_id` pointing at nothing makes WooCommerce
  report a missing page rather than a broken setting, so the person fixing it starts from the wrong
  symptom.

A page referenced by no option — `refund_returns`, which WooCommerce creates and then forgets — is an
ordinary page: listed, editable and deletable like any other.

### Drafts

Writes accept `status`: `draft` or `publish`, defaulting to `publish`. The four read routes take
`?status=` — `publish` (the default, so nothing that worked before changes), `draft`, or `any`. **`any` is
publish plus draft and never the trash**: `DELETE` is what puts something there.

### The homepage is replaced whole

`PUT /cms/homepage` takes `{ "sections": [ {type, data}, … ] }` and replaces the document. There is no
section-level route — sections are ordered, and two clients inserting at index 2 concurrently is a merge
problem the shop does not have.

**A malformed section is a 400 naming its index** — `details.fields["sections[2].type"]` — where the *read*
drops it and reports it in `meta.problems`. The asymmetry is deliberate: an option edited by hand with
`wp option update` must degrade rather than break a storefront, and a person with a form in front of them
must not lose their work quietly. Fifty sections maximum, and the type vocabulary is PLAN §23's.

### Menus

`PUT /cms/menus/{location}` replaces `primary` or `footer` with an ordered tree, and **creates and assigns
the menu when the location has none** — which is the state a fresh install is in.

```json
{ "items": [
  { "label": "Tapis", "type": "category", "object_id": 21, "children": [] },
  { "label": "Conditions", "type": "page", "path": "legal/terms", "children": [] },
  { "label": "Instagram", "type": "url", "url": "https://…", "children": [] }
] }
```

Two levels deep, **50 items across both**. `type` is `page`, `category`, `product` or `url`; a `page` takes
a `path` or an `object_id`, the other two an `object_id`, and a `url` must be `http`, `https` or a path
beginning with `/` — `javascript:` is a valid URL and this is where that matters. A reference that names
nothing is a 400, and nothing is written: every reference is resolved before the old menu is touched.

**A read body PUTs back.** The read publishes WordPress's own vocabulary (`type: post_type` with
`object: page`, and `title` rather than `label`) because that was §61's contract, so the writer accepts
that form as well as the one above. `target` and `classes` are read-only — a menu written through this API
is described by label, destination and order, which is a named limitation rather than an oversight.

An unknown location is a **400** listing the registered ones, while `GET` on the same location is a 404.
The read merges "no menu here" with "no such location" because both mean the same thing to a storefront;
the write must not, because one of them is a typo.

### HTML is sanitised on the way in

Page content, page excerpts, banner captions, FAQ answers and category descriptions go through an
allowlist **on save**: no `<script>`, `<iframe>`, `<style>`, `<form>`, no `on*` handler, and no
`javascript:` or `data:` in any `href` or `src`. Tables, figures, headings, lists and inline styles
survive. A sanitiser on the way out is one that a second reader — the storefront, an export, a search
indexer — does not run.

This is not redundant with WordPress's own filtering. An administrator holds `unfiltered_html`, so core
removes its filters for exactly the caller most able to do damage; measured on 2026-08-17,
`wp_insert_post()` as an administrator stores `<script>alert(1)</script>` byte for byte. Content that
predates §89, or that was written in wp-admin, carries no such guarantee.

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
`mode` is `create` or `update` (products) — neither does both, and it defaults to `create`.

**Every export names its columns on the first line.** `/export/products` did not until
`fix/product-export-header`: `ProductCsvExporter::toCsv()` called `get_csv_data()`, which is the rows,
where `WC_CSV_Exporter::export()` sends `export_column_headers() . get_csv_data()`. So a 48-column file
began `10,simple,AC-TAP-001,…` and `POST /import/products` read the first product's own values as the
header, answering *"The file is missing required columns. Missing: sku."* with `columns_found` listing
a product name as a column.

**A product export still does not round-trip through `POST /import/products` unedited**, and that is a
WooCommerce fact rather than a bug here. The header carries WooCommerce's **display labels** — `ID`,
`SKU`, `GTIN, UPC, EAN, or ISBN` — because that is the file every other WooCommerce tool reads, and the
table mapping those labels onto field names lives in `includes/admin/importers/mappings/`, inside
`admin/`, which `WooCsv` deliberately does not load: the whole argument of that class is that only the
*loader* is admin-gated and the engine is not. Measured 2026-08-21, a re-import parses every row and
resolves an empty `sku` on each; the same file with a lowercased header resolves every SKU and previews
`updated: 2`. Both directions are asserted in `tests/Api/import-export.php`. The inventory export, which
uses our own writer and our own field names, round-trips as it stands.

Exports return the file, not the envelope: `Content-Type: text/csv; charset=utf-8`, a
`Content-Disposition` filename that is the API's, `Cache-Control: no-store`, and a body that **begins
with a UTF-8 byte-order mark** (`EF BB BF`) so Excel does not read it in the system codepage and break
every Arabic name. An export *error* is still the envelope with its 4xx, so a client never saves an
error message as `products.csv`. Every imported stock change writes a ledger movement.

**Fixed on `fix/export-download`, measured 2026-08-21 on WordPress 7.0.4:** the body was arriving
JSON-encoded — one quoted line per export, with the BOM as the six characters `﻿`, every accent as
`è` and every newline as the two characters `\r\n`. `API\FileDownload` marked its own responses
with `set_matched_route()`, and `WP_REST_Server::respond_to_request()` overwrites that *after* the
callback returns, so `rest_pre_serve_request` declined every download and WordPress encoded the string
it was handed. The marker is a response **subclass** now, which `rest_ensure_response()` leaves alone.
The two HTTP assertions watching this passed over the broken body — "not JSON" looked for a `success`
key a JSON-encoded string does not have, and "names its columns" grepped a first line that was the
whole file — and now assert the BOM bytes and the presence of a second line instead.

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

## Email marketing campaigns

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/campaigns` | `ac_manage_marketing` |
| GET, PATCH, DELETE | `/campaigns/{id}` | `ac_manage_marketing` |
| GET | `/campaigns/{id}/preview` | `ac_manage_marketing` |
| POST | `/campaigns/{id}/test` | `ac_manage_marketing` |
| POST | `/campaigns/{id}/cancel` | `ac_manage_marketing` |
| POST | `/campaigns/{id}/send` | `ac_manage_marketing` **+ `ac_manage_customers`** |
| GET | `/campaigns/{id}/recipients` | `ac_manage_marketing` **+ `ac_manage_customers`** |
| GET, POST | `/segments` | `ac_manage_marketing` |
| GET, PATCH, DELETE | `/segments/{id}` | `ac_manage_marketing` |
| GET | `/segments/{id}/preview` | `ac_manage_marketing` **+ `ac_manage_customers`** |
| GET | `/email-templates` | `ac_manage_marketing` |
| GET | `/email-templates/{id}` | `ac_manage_marketing` |
| GET, POST | `/marketing/unsubscribe` | public — signed token |

**Sending needs a second capability, and a Marketing Manager does not have it.** A campaign discloses
nothing, but it *reaches* every customer record in the shop, so the person permitted to mail the customer
list is the person already trusted with it. Drafting, previewing and test-sending need only
`ac_manage_marketing`; `send`, the recipient list and a segment count also need `ac_manage_customers`. A
403 from `send` with a 201 from `POST /campaigns` is not a bug.

### Consent, which is not optional

**Only customers who have given marketing consent are ever in an audience** — including when you name them
by id. The filter is in the resolver, not in the caller, so there is no argument that turns it off. The flag
is default-false, set by the customer at registration or through `POST /account/marketing-consent`, and
cleared by the unsubscribe link. Transactional mail is **not** gated on it: an unsubscribed customer still
gets order confirmations.

`GET /customers/{id}` reports `marketing_consent`; `PATCH /customers/{id}` refuses it by name.

### A campaign

```json
POST /campaigns
{
  "name": "August rugs",
  "subject": "{{shop_name}} — something for you, {{first_name}}",
  "body_html": "<p>Hello {{first_name}}</p>",
  "body_text": "Hello {{first_name}}",
  "audience_type": "segment",
  "segment_id": 4
}
```

`audience_type` is `ids` (with `customer_ids`, at most 1,000), `segment` (with `segment_id`) or `all`
(everyone eligible). A campaign may instead name a `template_id`; its own body wins where both exist.

**Refused by name, with a reason**: `status`, `recipients_total`, `recipients_sent`, `recipients_failed`,
`recipients`, `emails`, `to`, `bcc`, `from`, `tracking_pixel`, `open_tracking`, `claimed_at`,
`completed_at`, `created_by`, `id`. An audience is a *definition* and never a list of addresses — that
would bypass the consent filter and make this an open relay.

**Statuses**: `draft → sending → sent`, with `cancelled` reachable from the first two. Only a draft can be
edited or deleted. A sent campaign is kept — it is the record of mail that left the building.

### Templates

`ac_email_template` is a WordPress post type: author them in wp-admin, where revisions and the media
library already are. The API reads them. **Every save runs `wp_kses` with an email-safe allowlist** — no
`<script>`, no `<iframe>`, no `on*`, no `javascript:` — because a template is stored and re-rendered, so a
stored XSS fires in your own preview.

**Placeholders, not code.** `{{customer_name}}`, `{{first_name}}`, `{{shop_name}}`, `{{order_number}}`,
`{{unsubscribe_url}}`. An unknown token renders **empty** and is listed in `unknown_tokens` on the preview
and on the template — check it. `{{unsubscribe_url}}` is **appended automatically when absent** rather than
rejected.

Every campaign sends an HTML part *and* a text part. The text part is authored, not stripped from the HTML.

### Sending

```
GET  /campaigns/{id}/preview           → rendered HTML and text for a sample recipient
POST /campaigns/{id}/test  { to }      → one copy, sent synchronously, writes no recipient row
POST /campaigns/{id}/send              → 202, freezes the audience, returns a count
```

**`send` sends nothing.** It resolves the audience, writes one row per recipient, marks the campaign
`sending` and returns `202` with `recipients` and a `next` block. The drain is
`wp algerian-commerce send-campaigns [--limit=50] [--campaign=<id>] [--rate=<per-minute>]`, and a
deployment schedules it — a five-minute WP-Cron is a convenience, not the mechanism.

A second `send` is a **409** and changes nothing: the claim is one `UPDATE … WHERE status = 'draft'`.

`send` answers **503 `mail_not_configured`** when the shop has no `SMTP_HOST`, before writing any rows, and
**409** when the audience currently matches nobody — which is almost always a segment that is wrong or a
customer list with no consent yet, and is never silently reported as "sent to 0 people".

`GET /campaigns/{id}/recipients` answers "who got this?", with `meta.purged` once the addresses are gone.
**Recipient addresses are purged 30 days after a campaign completes**; the counts on the campaign survive.

It takes `?status=` — `pending`, `sent` or `failed` — and **`meta.total` follows the filter**, which it
did not before `feat/campaign-recipient-counts`: measured 2026-08-21, `?status=failed` answered 0 rows
with `meta.total: 9`, because the rows were filtered and the total was not. It is the filter the drain
itself points at when a run ends with *"see GET /campaigns/{id}/recipients?status=failed"*.

### Unsubscribe

```
GET  /marketing/unsubscribe?token=…
POST /marketing/unsubscribe   { token }
```

Public, one click, no login, idempotent. **A forged token answers identically to a real one**, because a
404 here would answer "is this a customer id" for anybody who asked. Build your storefront page at
`{storefront_url}/marketing/unsubscribe` if you want one; with no `store.storefront_url` set, the link in
the email points at this API's own route instead — a mandatory link that is sometimes absent is worse than
one on an unlovely domain.

### Segments

```json
POST /segments
{ "name": "Alger regulars", "criteria": { "wilaya_id": 16, "ordered_after": "2026-05-01", "min_orders": 2 } }
```

**A segment is a stored query, not a stored membership list** — edit it and every campaign that names it
follows. Criteria: `min_spent`, `max_spent`, `min_orders`, `max_orders`, `ordered_after`,
`ordered_before`, `registered_after`, `registered_before`, `wilaya_id`, `bought_product_id`,
`not_bought_product_id`. Money is a decimal string, dates are `Y-m-d`.

**Empty criteria are refused**: they would match every customer, and "everyone eligible" already has its
own `audience_type`. `consent`, `email`, `email_contains`, `role`, `commune_id`, `limit` and `sql` are
refused by name with the reason.

`wilaya_id` comes off the **shipment**, never the address, so an order nobody has shipped has no wilaya and
cannot match. `GET /segments/{id}/preview` gives a live count. A segment a campaign uses cannot be deleted.

### Not in this version

No open or click tracking. No drip sequences or abandoned-cart flows. No SMS or WhatsApp campaigns. No
third-party ESP integration.

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

## Settings

| Method | Route | Guard |
|---|---|---|
| GET, PATCH | `/settings` | `ac_manage_settings` — **Super Admin only** |

The client configuration in one document: store identity, contact details, the
Algerian trade-register block (`rc`, `nif`, `nis`, `ai`), social links, the feature flags, and which
providers actually registered. This is what an admin panel's settings screen reads, and what a storefront
footer gets its contact and legal details from.

**It is assembled from whoever owns each value, not copied from them.** The store name is WordPress's,
the currency WooCommerce's, the flags the environment's, the provider lists the live registries'. Change
one at its source and this document follows on the next request.

`PATCH` writes four blocks — `store`, `contact`, `legal`, `social` — and refuses everything else **by
name, with the reason**:

| Refused | Why |
|---|---|
| `currency` | WooCommerce records it *per order*, so changing it splits the order book instead of converting it. Set once at provisioning. |
| `features` | `ENABLE_*` are environment variables read once at bootstrap to decide which providers register. Set them in `.env` and restart. |
| `providers` | Read-only. It reports what registered, which follows from flags *and* credentials. |
| `api_key`, `webhook_secret`, … | Secrets are environment variables and never the options table. |

`features` reports all nine declared flags. Four of them — `blog`, `reviews`, `sms`, `whatsapp` — gate
nothing yet and are reported as declared so nobody has to grep `.env.example` to learn a flag exists.

A partial write updates only what it names. `null` or `""` clears a field. URLs must be `http` or
`https` — `javascript:` is a valid URL and is cross-site scripting the moment a storefront renders it as
a link.

Writes are audited **by field name, never by value**: the shop's trade-register numbers do not belong in
a table nobody cleans.

## Staff users and roles

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/users` | `ac_manage_users` — **Super Admin only** |
| GET, PATCH, DELETE | `/users/{id}` | `ac_manage_users` |
| GET, POST | `/users/{id}/application-passwords` | `ac_manage_users` |
| DELETE | `/users/{id}/application-passwords/{uuid}` | `ac_manage_users` |
| GET | `/roles` | `ac_manage_users` |

**`/users` is staff and `/customers` is shoppers, and no account is in both.** An account is staff
when it holds one of the seven roles or is a WordPress administrator; everything else is a customer.
`GET /users/{id}` on a shopper is a 404, and so is `GET /customers/{id}` on a staff account.

A role is **required** on create — an account with no role is a customer created through the wrong
door. `GET /roles` publishes §45's matrix (role, display name, capabilities, `assignable`) and is the
role picker's source. **There is no way to create a role**: the matrix is code, it is unit-tested, and
a role invented at runtime is a capability set nothing has reviewed.

**Two roles are assignable; all seven are published.** Staff access is a two-tier model — **Super
Admin** and **Manager** — and the other five are *retired*: still defined, still held by existing
accounts, still returned by `GET /roles` and `?role=`, but no longer granted. `assignable` on each row
is the difference, so a picker filters on the flag while a label can still resolve the role an account
visibly has.

Assigning a retired role is a **400 naming it as retired**, not as unknown — it exists, it is
published on this very route, and accounts hold it, so "Unknown role" would send an operator looking
for a typo. Nothing was removed from the matrix and no account was stranded: a role definition deleted
out from under a live account leaves it authenticating normally and answering 403 everywhere, because
WordPress resolves an unknown role to no capabilities at all.

**Refused by name, with the reason**: `password` and `user_pass` (a password set by somebody else is
one its owner cannot trust), `capabilities` (they come from the role), `roles` (an account holds
exactly one — use `role`), `user_login` (a login is an identity, not a field).

### The five refusals that make this safe

| Refused | Status | Why |
|---|---|---|
| `administrator`, `editor`, `shop_manager`, `customer`, … | 400 | This API grants commerce roles. A WordPress role carries platform access — installing plugins, editing files — that no capability in the matrix models. |
| A role holding capabilities the caller lacks | 403 | Naming the missing capabilities. Otherwise `ac_manage_users` is a one-step path to every other capability. |
| Changing your own role | 403 | A demotion you can perform on yourself is one you cannot undo. |
| Deleting or suspending yourself | 403 | A shop with no Super Admin has no route back except wp-admin. |
| Deleting an account that owns orders | 409 | With the count. `wp_delete_user()` reassigns posts and knows nothing about HPOS, so the orders would become rows no report can attribute. Suspend instead. |

**Promoting a customer to staff is allowed**, and it is the only case where `PATCH /users/{id}`
accepts an id that `GET /users/{id}` would 404. It needs a `role` in the payload; anything else
against a non-staff id is the 404 it would have been. The response reports it in
`meta.promoted_from_customer`, and the audit row carries the same flag.

### Suspension

`PATCH /users/{id}` with `{"status": "suspended"}`. A suspended account's credentials answer **401
`account_suspended` at every route in this namespace**, including `/auth/me` and `/health`. Reactivate
with `{"status": "active"}`.

This is what to do when somebody leaves, because deletion is refused for any account that owns orders.
It does **not** revoke access to `/wp/v2` or wp-admin — that is WordPress's own to grant, and this
plugin does not decide who may open a dashboard it does not own. Revoke the account's application
passwords to close that door.

### Application passwords

The onboarding step, and the reason these routes exist. WordPress shows an application password
exactly once, at creation, in wp-admin — the dashboard PLAN §52 says routine administration must not
require.

```
POST /users/{id}/application-passwords   { "name": "Admin panel — Karim's iPhone" }
→ 201 { "uuid": "…", "name": "…", "created": "…", "last_used": null,
        "password": "abcd EFGH ijkl MNOP qrst UVWX" }
```

**`password` appears in that one response and nowhere else** — not on the collection, not on
`GET /users/{id}`, not in the audit event, not in a log. Show it once and do not store it; if it is
lost, revoke and mint another. That is why the name identifies a device.

A duplicate name is a **409**: this list is a "revoke this device" screen, and two entries called
"iPhone" are two entries nobody can tell apart. A suspended account is a **409** too — the credential
would not work. `last_ip` is deliberately not published; it describes a person rather than a
credential.

**503 `application_passwords_unavailable`** means the install cannot issue them at all: WordPress
requires HTTPS unless `WP_ENVIRONMENT_TYPE` is `local`. That is a deployment fact, not a request
problem — see `docs/DEPLOYMENT.md`.

## Notifications — §90

| Method | Route | Guard |
|---|---|---|
| GET | `/notifications` | `ac_manage_customers` |
| GET | `/notifications/{id}` | `ac_manage_customers` |
| POST | `/notifications/{id}/retry` | `ac_manage_customers` |

The transactional queue §59d built, read back. It exists to answer one question — *did the customer get
their confirmation?* — which had no answer outside `wp eval`.

**The capability is `ac_manage_customers` and no new one is invented.** A row holds a customer's address
and, on the single read, the frozen body of their order confirmation: their name and what they bought.
§63's rule applies — reporting may not disclose in aggregate what the caller cannot already read in
detail — so the gate is the capability that already reads a customer's record. A Product Manager holding
ten other management capabilities is refused.

**Nothing here sends.** That was §59d's whole design and it is stronger at this door: an SMTP server that
hangs would hang the admin panel, and the operator most likely to press retry is the one whose mail server
is already misbehaving.

`GET /notifications` filters by `channel`, `status` (`pending`, `sent`, `failed`), `dedupe_key`,
`recipient`, `subject_id` and `date_from` / `date_to` (`Y-m-d`, **UTC**, both ends covering the whole
day). Newest first — the opposite of the drain, which sends oldest first so a customer is not told
"delivered" before "shipped".

**`dedupe_key` is the filter that answers the question.** It is `event:subject_id` by construction, so
`?dedupe_key=order.placed:1234` finds the confirmation for order 1234 in one request.

**`recipient` and `subject_id` answer the two questions `dedupe_key` cannot**, and they were added on
`feat/notification-filters` because the admin panel could not be built without them. `dedupe_key` is
exact and names one event about one subject; nothing named *everything sent to this person* or
*everything about this order*. Measured 2026-08-21 before they existed, `?recipient=`, `?subject_id=`,
`?event=` and `?audience=` were all **accepted and silently ignored** — so a customer's own
notifications cost one request per order per event name, on event names the caller had to hard-code.

```
?recipient=amina@example.test    every channel, every event, one customer
?subject_id=4529                 every notification about order 4529
```

Neither discloses anything new: both columns are already on every list row and the route is
`ac_manage_customers` throughout. `recipient` is **not** validated as an email — it is whatever the
channel addresses, and §29's other four would put a phone number there.

**`event` and `audience` remain unfilterable, and that is deliberate.** An `event` filter overlaps
`dedupe_key`, whose left half *is* the event; `audience` has two values that `recipient` already
separates. **`orderby` and `order` are not parameters at all** — measured, they are accepted and
ignored, including `?orderby=nonsense`, because the ordering is `created_at DESC, id DESC` and the
screen this exists for has no reason to reverse it.

**The list omits the message; the single read carries it.** `GET /notifications/{id}` adds a `message`
block — `subject`, `body`, `context` — read out of the stored payload by allowlist, so a key a future
channel adds is not published by accident. A payload that will not decode reports `readable: false`
rather than an empty message: that is the row the drain marks permanently failed, and an operator needs
to see what the drain saw.

```
POST /notifications/{id}/retry
→ 202 { … status: "pending", attempts: 0, last_error: null }
     meta: { queued: true, already_pending: false, drain: "wp algerian-commerce send-notifications" }
```

**202, not 200**, because nothing has been sent when it returns — a row has gone back in a queue that
something else drains, and the response names the command rather than leaving anybody to wonder. Retry
clears `status`, `attempts` and `last_error`. A row that is already `pending` answers 202 with
`already_pending: true`.

**A `sent` row is a 409**, naming `sent_at`. It is a record of something that left the building, and
re-queueing it would deliver a body frozen weeks ago — `Notification` freezes the message at queue time
on purpose, so an order refunded since would still send the confirmation that was true when it was
placed.

## Audit

| Method | Route | Guard |
|---|---|---|
| GET | `/audit-logs` | `ac_view_audit_logs` |

Filters by `actor_id`, `action`, `resource_type`, `resource_id` and `date_from` / `date_to` (`Y-m-d`,
**UTC**, both ends covering the whole day — `AuditEvent` stamps `gmdate()` and nothing else writes the
table). Newest first, and `orderby` / `order` are **not parameters**: the table is append-only, so id
order is time order and there is no second ordering worth offering. An unknown `action` is a 200 with
no rows rather than a 400 — the vocabulary is open by design, since every subsystem adds to it.

**`resource_id` is a string, not an integer**, because the things this trail records are not all
numbered: a page is audited by path, a FAQ category by slug, a menu by location. It is only meaningful
beside `resource_type` — ids are unique per type, not globally.

**`resource_id` and the date range were added on `feat/audit-filters`.** Measured 2026-08-21 against
16 632 rows, both were **accepted and silently ignored** — §65's failure mode, where a filter that does
not filter is indistinguishable from a collection that all matches. The clause for `resource_id` had
been in `AuditRepository::buildWhere()` since the table existed; the route simply never declared the
argument, so `WP_REST_Request` dropped it before the controller looked. The date range had neither.
Both are named in the admin panel's specification as though they worked, and without them 16 632 rows
at 20 a page is 832 pages with no way to reach yesterday and no way to get from an audited object to
its own history. `tests/Api/audit.php` is the route's first suite, **35 assertions**, and its floor is
that a filtered set is *strictly smaller* than the whole rather than merely a 200.

**`search` is accepted and ignored, and stays that way.** Writes are audited by field *name*, never by
value, so there is no column holding what a free-text box would be searching for.

§87's writes record `user.created`, `user.role_changed`, `user.suspended`, `user.reactivated`,
`user.updated`, `user.deleted`, `user.app_password_created` and `user.app_password_revoked`. A role
change names both roles, because "somebody's role changed" answers none of the questions the trail is
read to answer — the one place the field-names-never-values rule gives way, since here the value *is*
the security fact.

§89 records `cms.page_created`, `cms.page_updated`, `cms.page_deleted` and the same three for banners,
FAQs and FAQ categories, plus `cms.homepage_updated` and `cms.menu_updated`. A page's **path**, a
**status transition** and a **category slug** are recorded by value for the same reason a role is;
content never is, so a page edit records `fields: ["content"]` and the homepage records its section types
and their count. §90 records `notification.retried` with the channel, event and dedupe key — never the
recipient.

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
                                                          → keep data.tracking.token
POST /orders/{id}/payments                                → checkout_url, redirect the shopper
GET  /orders/track?token=…                                → afterwards, for as long as they keep the link
```

Cash on delivery ends at `/checkout`; there is no redirect.

After the order exists, `POST /marketing/events/purchase` returns the `event_id` your browser pixel must
reuse.

---

# Things that will bite you

- **Send tokens back.** Cart and customer tokens are the whole identity. Drop one and the cart is empty.
- **Keep the tracking token from the checkout response.** For a guest order it is the only way back to it,
  and nothing on this API will hand it out a second time to a caller who cannot already prove the order.
- **Never expose the Application Password.** Browser → your server → this API. Always.
- **You cannot set prices.** Anything resembling money in a cart payload is refused by name.
- **`GET` then `PATCH` the whole object works** — read-only fields are dropped, not rejected. The
  **URL decides which resource is written**, always: an `id` in the body is ignored, never followed.
- **A 400 lists every bad field**, so render `details.fields` rather than the top-level message.
- **A 404 can mean "not configured"** on webhook routes, "not yours" on `/account/orders/{id}`, and
  "that is a customer, not staff" on `/users/{id}`.
- **A 401 can mean "suspended".** Read `error.code`: `account_suspended` is not a signed-out session
  and signing in again will not fix it.
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
