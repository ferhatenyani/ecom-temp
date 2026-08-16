# Security Requirements

Read this before implementing authentication, authorization, payments, webhooks, file uploads, or any
third-party integration. Source roadmap §26, §42, §55, §61.

Two sections here are **rules** rather than reminders, each settled before the code they govern was
written: "Webhooks" (§55) for anything inbound, and "File uploads" (§61) for anything that writes a file.

## Baseline requirements

- HTTPS in production
- Never commit secrets
- Validate all input
- Authorize all private routes
- Restrict CORS
- Rate-limit sensitive endpoints
- Use prepared SQL
- Escape output
- Verify payment server-side
- Verify webhook signatures
- Make webhook processing idempotent
- Protect file uploads
- Test IDOR
- Test privilege escalation
- Require 2FA for privileged users
- Maintain backups
- Test restores

---

## Implementation order

Build these **with** the features, not at the end:

```
authentication → authorization → validation → sanitization
→ rate limiting → CORS → audit logging → webhook verification → secret management
```

Harden afterwards: file uploads, IDOR, privilege escalation, XSS, CSRF, SQL injection, SSRF, replay attacks.

Use WordPress's own security APIs (nonces, capabilities, `sanitize_*`, `esc_*`, `$wpdb->prepare()`,
`wp_safe_remote_*`) rather than hand-rolled equivalents.

## Input and output

- Every REST route declares an args schema with types, ranges, enums, and `sanitize_callback` /
  `validate_callback`. Validation runs before controller logic.
- Reject unknown or unexpected fields on write endpoints rather than passing them through.
- All SQL goes through `$wpdb->prepare()`. No string-concatenated queries, ever — including in migrations,
  WP-CLI commands, and analytics aggregation.
- Escape on output; never trust stored data to be safe.
- Outbound HTTP uses `wp_safe_remote_*` with an explicit timeout — SSRF protection matters for any
  URL that came from user input.

## Authorization

- Every route has a real `permission_callback`. `__return_true` is only acceptable on genuinely public
  endpoints and must be justified in a comment.
- Authorization is enforced again in the service layer for object-level access — a valid capability does not
  imply access to *that* order or *that* customer. This is the IDOR defence.
- **The database cannot help with this, and database row-level security is not an option.** RLS is a
  PostgreSQL feature; MySQL has none, and 8.4 did not add one — `CREATE POLICY` is a syntax error. Even on a
  database that had it, it would be inert here: WordPress opens **one** connection as **one** database user
  for every request, so admin, shopper and cron are indistinguishable to the server and there is no identity
  for a policy to key on. Emulating it with `SQL SECURITY DEFINER` views has the same problem. Object-level
  authorization belongs in `Permissions::assertOwnsOr()`, at the only layer that knows who is asking.
- Capability checks use named capabilities, not role-name comparisons.
- Never rely on the frontend hiding a control.
- Privileged users require 2FA.
- **Reporting may not disclose in aggregate what the caller cannot already read in detail.** A capability
  that grants a *summary* of a resource is not thereby a capability to read the resource, and the reverse
  trap is the one that bites: `ac_view_analytics` is held by every role in PLAN §3, Support Agent included,
  so wiring revenue to it alone would hand the shop's turnover to an account that cannot open a single
  order. §63 resolves it by requiring `ac_manage_orders` for every money figure — the capability that
  already reads an order's total through `GET /orders`, so summing those totals for a caller who holds it
  discloses nothing new. Counts and rates need only `ac_view_analytics`. **No new capability was invented**
  to draw the line; where PLAN §3's vocabulary genuinely has no answer, name the gap rather than widen the
  list (§61's media capability is the worked example). A shop that wants a wider audience for revenue grants
  that account `ac_manage_orders`, which is a deliberate act with a visible consequence.
- **A response cache keyed without the caller's privileges is an authorization bug.** The same request from
  two callers can legitimately produce two payloads; if the key does not distinguish them, the cache serves
  the privileged one to whoever asks next. `Analytics\AnalyticsCache::key()` takes the money flag for exactly
  this reason and is pure so the property is a unit test.

## Secrets

- Credentials live in environment variables, never in code, options tables, logs, or Git.
- `.env` is gitignored; `.env.example` carries key names with empty values.
- Each environment (local / staging / production) has its own database, credentials, API keys, and webhook
  secrets.
- **Never log:** API secrets, payment secrets, full authentication headers, card or payment instrument data,
  full customer PII beyond what an audit record needs, or a URL that carries an access token — a courier's
  label URL is a credential, not a link (see "A Yalidine label URL is a credential" below).

## Payments

> Never trust the frontend to tell the backend that a payment succeeded.

- Payment status is confirmed by a server-side call to the provider, or by a signature-verified webhook.
- Every transaction attempt is recorded with its provider reference and verification result. The row is
  written **before** the gateway is called and closed as `failed` if that call never returns — the attempt
  worth having is the one where a gateway may have taken money on a request this side then dropped.
- Amount and currency are re-checked server-side against the order before marking it paid. **A report that
  states no currency fails that check rather than skipping it** (§59): Chargily's webhook payload carries an
  amount and no currency, and the lenient reading answered "matches" on a payload where the comparison had
  never run.
- Where a provider's webhook cannot carry what the re-check needs, the handler **re-fetches** and acts on
  that. A signature proves who sent a message; it does not prove the money.

## Webhooks

**This section is the rule.** It was settled by the §55 review, before the first webhook was written, so
that Yalidine, ZR Express and Chargily are not each argued out separately. A new inbound endpoint follows
it or does not ship.

Each webhook endpoint follows this exact sequence, and nothing is done out of order:

```
receive → verify signature/auth → validate payload → identify event
       → idempotency claim → re-fetch and act → respond
```

### Where the secret lives

- One secret per provider **per environment**, in `.env` only: `YALIDINE_WEBHOOK_SECRET`,
  `ZR_EXPRESS_WEBHOOK_SECRET`, `CHARGILY_SECRET_KEY`. Read through `Config::secret()` and handed to the
  verifier by the plugin bootstrap — the same path `Plugin::shippingProviders()` and
  `Plugin::paymentProviders()` already use for API credentials, and for the same reason: one place in the
  codebase reads a provider's credentials.
- **Chargily has no separate webhook secret, and §59 removed the variable that pretended it did.** It signs
  with the API secret key itself — its documentation, its own WooCommerce plugin and its PHP SDK all agree —
  so `CHARGILY_WEBHOOK_SECRET` had nothing that could correctly go in it, and anything anyone put there would
  have failed every signature check in silence. A slot that can only be filled in wrongly is worse than no
  slot. The rule this section states is "one secret per provider per environment"; for Chargily that secret
  happens to be the API key, which is a fact about the provider rather than an exception to the rule.
- Never an option, never a constant, never a query parameter, never part of the route. A webhook URL is not
  a secret — it appears in the provider's dashboard, in their logs and in ours — so a "secret path" is not
  authentication and must never be treated as any.
- **A webhook route is registered only when its provider is.** If the feature flag is off or the credentials
  are missing, the provider is absent from `Plugin::shippingProviders()` and the endpoint does not exist —
  a 404, not an endpoint that accepts what it cannot check. An unconfigured secret is a closed door.

### Verification, before anything else

Verification runs on the **raw body** (`WP_REST_Request::get_body()`), byte for byte, before any JSON
decode. Decoding and re-encoding changes bytes and breaks every signature scheme there is.

Two shapes exist among the providers this plugin has, and they are not equally trustworthy:

**A signature (ZR Express via Svix; Chargily).** Recompute the HMAC over the provider's documented signing
string and compare with `hash_equals()` — never `==`, never `===`. For Svix that is HMAC-SHA256 over
`{svix-id}.{svix-timestamp}.{body}` against the base64 secret behind the `whsec_` prefix, and the
`svix-signature` header may carry several space-separated `v1,<sig>` values of which any one matching is a
pass (that is how they rotate keys). A signature binds the secret to *these bytes*, so a verified payload
may be acted on directly.

**A shared secret in the body (Yalidine's `security_token`).** Compare with `hash_equals()` against the
configured value. It is **not a signature**: it binds to nothing, so it proves only that the sender once saw
the token, and anyone who has ever seen one — a proxy log, a support ticket, a misrouted delivery — can forge
any event with it. Therefore a body-secret webhook is a **hint and never a source of truth**: it tells us
*when* to look, and the handler then re-fetches `GET parcels/{tracking}` and acts on that. This is the same
rule as "payment status is verified server-side, never believed from a callback", applied to parcels.

### Replay

- **Timestamp tolerance: 5 minutes**, in either direction — clock skew is not one-sided. This is only
  meaningful where the timestamp is *inside* the signed material, as Svix's is. Where it is not, there is
  nothing binding a timestamp to the payload and the check is theatre; that is one more reason the
  body-secret shape may not be acted on directly. Chargily puts no timestamp in a header at all, but its
  event's `created_at` is in the body, which is what the HMAC covers — so the tolerance binds there too, and
  is enforced.
- **The tolerance's cost is paid by a reconciliation poll, not by relaxing it.** Chargily does not document
  how long it retries, so a genuine retry arriving after five minutes is refused — correctly, since the
  alternative is accepting an event captured off the wire last week. `wp algerian-commerce sync-payments`
  (hourly cron, or a real scheduler) asks the gateway about every payment still `pending`, so a refused or
  never-delivered event costs minutes of delay instead of a payment nobody recorded. A webhook endpoint whose
  strictness has no such backstop will eventually be loosened by whoever is on call.
- **The event id is claimed, not checked.** A repeat delivery is acknowledged with 200 and dropped. The claim
  is a write-once insert whose duplicate-key failure *is* the idempotency answer — a read-then-write races
  precisely when a provider retries in parallel, which is the case it exists for. `ac_webhook_events`
  (migration 008) holds it, `UNIQUE (provider, event_id)`; `WebhookEventRepository` has one method and
  deliberately no `has()`.
- The claim is taken **before** processing, which means a retry after a failed process finds it already taken
  and is answered as a duplicate. The 500 such a failure returns is honest — it puts the failure in the
  provider's delivery log as well as ours — but it is not what recovers the event. The reconciliation poll is.
- Where a provider sends no event id, the id is the SHA-256 of the signed material (`{id}.{timestamp}.{body}`,
  or the body alone), which is stable across a genuine retransmission and distinct between real events.
- Duplicate delivery must never duplicate a payment, shipment, order transition, or notification.

### A verifier written from a specification is not a verified verifier

**No courier webhook has ever been received.** `ac_webhook_events` — the idempotency ledger every verified
event writes to — holds rows for `chargily` and the test double only, and neither `YALIDINE_WEBHOOK_SECRET`
nor `ZR_EXPRESS_WEBHOOK_SECRET` is set, so both routes currently 404 by the rule above. Every payload in
`tests/Unit/CourierWebhookTest` and `tests/Api/shipping-webhooks.php` is *constructed* from published
documentation: the suites prove each verifier matches the scheme as written, and cannot prove the sender
implements that scheme. The gap is marked at both call sites —
`grep -rn ASSUMPTION integrations/Yalidine integrations/ZRExpress`.

Do not read a provider's "verified against the live API on <date>" note as covering its webhooks; those runs
exercised the outbound API. The two failure modes differ and both are quiet:

- A **wrong signature scheme** (ZR Express) refuses genuine events with 401 — the shop sees silence, not an
  error, because a courier retrying into a 401 reports nothing to the merchant.
- A **wrong field name or location** (Yalidine's `security_token`, documented as a body field) does the same.

Neither is an outage here, because a verified courier event is only ever a hint to re-fetch and the hourly
poller keeps parcels current regardless — which is the design decision that makes this a documented gap
rather than a blocker. **The ledger is the standing check:** a row whose `provider` is `yalidine` or
`zr-express` is proof one has genuinely arrived, and until then the markers stay.

### What an unverified request gets

- **401 `webhook_unverified`**, in the standard envelope, saying nothing about *which* check failed. A
  verifier that distinguishes "bad timestamp" from "bad signature" is an oracle for building a valid one. No
  `WWW-Authenticate` header, and the received body is never echoed back.
- Logged at warning with the provider, the route and the source IP — never the body, never the headers,
  never the secret, and never the event id, which at that point is unverified attacker input.
- Rate limiting applies as it does to every other route in the namespace, so a forgery loop is bounded.
- A **verified** event for something we do not recognise — an unknown type, a parcel this shop has no record
  of — is **200 and dropped**. The provider must not retry forever over a gap on our side.
- A **verified** event that we failed to process is **500**, so the provider retries.

### The route itself

- `'permission_callback' => '__return_true'` is correct here and must carry a comment saying why: the
  signature *is* the authentication, and a capability check would reject the provider. Besides `/health`,
  this is the only place in the plugin where that is allowed.
- Respond quickly; do slow work asynchronously.
- A webhook may not do anything the rest of the system would not. A parcel status never moves the order
  (CLAUDE.md), and a payment is marked paid only after a server-side verification against the provider.

## CSRF

**This API is not exposed to CSRF, and the reason is architectural rather than a control we added.** The
§65 audit checked the claim against `Auth/` and against the running stack rather than asserting it; what
follows is what was measured on 2026-08-16.

CSRF requires an **ambient credential** — something a browser attaches to a cross-site request without
the attacking page having to know it. This API has none:

- **The intended credential is a WordPress Application Password sent as HTTP Basic auth.** A browser
  never attaches it on its own. A cross-origin page cannot set an `Authorization` header on a form post,
  an image or a script tag, and setting one through `fetch` makes the request non-simple, so it is
  preflighted — and `API/Cors` answers a preflight from an origin outside the allowlist with no
  `Access-Control-Allow-Origin` at all.
- **A cookie is never sufficient.** Cookie authentication *is* reachable — `AuthService` reports
  `auth_method: cookie` — but WordPress core's `rest_cookie_check_errors()` calls
  `wp_set_current_user(0)` on any REST request that arrives with no `X-WP-Nonce`, whatever the cookies
  said, so the request reaches our `permission_callback` as an anonymous one and gets 401. A nonce that
  does not verify gets 403. The nonce lives in a same-origin page's JavaScript, which is exactly what a
  cross-site attacker cannot read.
- **There are no state-changing `GET` routes.** Every write is POST, PATCH or DELETE.

Note what the second point does *not* say. The cookie's validity is not what is being tested and does not
need to be: core forces the anonymous path **before** the cookie is trusted, so a valid session cookie
and a forged one produce the same refusal for the same reason. That is the property worth having, and it
is asserted over real HTTP in `scripts/test-api.sh` → "CSRF", with a control proving the same route
answers a real credential — otherwise the refusals would be indistinguishable from an unreachable route.

**What would reinstate the risk**, and what to re-check if any of it changes: a plugin or a change here
that authenticates a request from cookies alone; a state-changing `GET`; a wildcard or reflected CORS
origin; or `Access-Control-Allow-Credentials: true` reaching an origin that is not on the allowlist.
WordPress core's own REST CORS handler reflects **any** origin it is given, which is why `API/Cors`
removes it on this namespace — `scripts/test-api.sh` asserts the contrast against `wp/v2` so that the
replacement is proven to be installed and not merely written.

Nonces are therefore **not** used on this API's routes, and adding them would be theatre: a client that
can obtain a nonce is a same-origin client that already has a session, and the Next.js server that holds
the real credential is not a browser at all.

## Rate limiting

Apply to authentication, password reset, checkout, order creation, COD confirmation, search, and
import endpoints. Limit per identity and per IP, and log rejections.

## File permissions

WordPress must not be able to write its own code. `wp-content` and everything under it is `755`/`644`
owned by the web server user — never `777`, and never group- or world-writable.

This is not theoretical here: `wp-content` was found at `777` during the stack audit, put there as a
workaround for the `wordpress` and `wordpress:cli` images disagreeing about `www-data`'s uid (33 versus 82).
A world-writable plugin directory turns any file-write bug, any compromised container process and any
mis-scoped mount into arbitrary PHP execution inside the commerce backend. The correct fix is to align the
uid — `compose.yaml` pins `user: "33:33"` on the `wpcli` service — not to loosen the permissions.

When a permission error appears, fix who is running the command. Do not widen the mode.

One trap when repairing this: **`wp-content` contains a bind mount of the plugin source**, so a recursive
`chown` inside the container reaches out of the container and rewrites ownership in the Git working tree —
which then refuses to let the host user edit its own files. Scope the repair, or repair the host side after.
The plugin directory needs to be *readable* by the web server and nothing more; nothing in it is written at
runtime.

## File uploads

**This section is the rule**, as "Webhooks" is for inbound requests. It was settled by §61, which built
`POST /media` — the only endpoint in this API that writes a file a web server might later execute.
A route that accepts a file follows it or does not ship.

The short form: validate the real MIME type and the extension against an allowlist, independently of each
other and of the client's `Content-Type`; cap the size; strip metadata; never trust the client-supplied
filename; and make sure the directory it lands in cannot execute anything.

### Four independent checks, in this order

```
size        cheapest, and refuses before anything reads the file
filename    hostile shapes rejected, not silently repaired
contents    finfo magic bytes AND getimagesize(), which must agree
extension   the allowlist, compared against what the contents proved
```

`wp_handle_upload()`'s own `wp_check_filetype_and_ext()` then runs as a fifth, from an allowlist generated
from ours so the two cannot drift. Any one of them passing is not enough, and the order is load-bearing: a
size check that ran after the sniff would read a 200 MB file to find out it was too big.

`UploadPolicy` holds all of it and is **pure** — no WordPress, no globals — so every abuse case is a unit
test rather than a live experiment. The client's `Content-Type` is recorded in the audit entry and never
used to decide anything.

### The stored file is not the file that was sent

- **The name is rewritten.** The readable part of the client's stem survives, folded to
  `[a-z0-9-]`; the extension comes from the **sniffed type**, never from the name. A double extension
  cannot survive that function even if the filename check were one day loosened.
- **The bytes are re-encoded.** Every accepted image is decoded and written back out, so what is stored is
  pixels and nothing else. This is what strips EXIF, GPS coordinates and JPEG comments — and what makes a
  polyglot inert, since anything appended after the end-of-image marker is simply not part of the picture.

  Measured on 2026-08-15, and the reason `ImageSanitizer` pins the editor to GD with the `wp_image_editors`
  filter: **`WP_Image_Editor_Imagick::save()` keeps EXIF and comments.** Its `strip_meta()` is only reached
  through the resize path, and a same-size resize early-returns before it. Worse, the two containers in this
  stack disagreed about which editor WordPress picks. A security property that depends on which PHP process
  handled the request is not a property.
- **An image that cannot be re-encoded is refused and unlinked**, never stored as it arrived.

### The allowlist is short, and each exclusion has a reason

JPEG, PNG and WebP. `svg` is XML and carries `<script>`; `pdf` has its own scripting engine; `gif` is only
wanted for animation, which cannot survive the strip; `avif` decoding is a GD build option, so a file
accepted on one host would be unsanitisable on another. Adding a type means adding it to the allowlist
**and** confirming the sanitiser can re-encode it.

### The directory must not execute

The allowlist and the re-encode are application-layer defences. The layer that does not depend on either
being right is the web server: `wp-content/uploads` must refuse to run anything.
`docker/apache-wordpress.conf` denies PHP, CGI and `.htaccess` by `FilesMatch` — pure core Apache, so it is
valid whether or not mod_php is loaded — and turns the interpreter off as well where it is. `scripts/test-api.sh`
asserts it, because a compose or vhost edit could silently drop it. **On nginx the equivalent is a
`location ^~ /wp-content/uploads/ { location ~ \.php$ { deny all; } }`, and it is not optional.**

Uploads cannot be moved off the web root here: the storefront serves these URLs and WordPress generates
them. "Store outside web-executable paths where possible" is satisfied by making the path non-executable
rather than by moving it.

### Authorization and rate limiting

- Every media route requires `ac_manage_content`. There is deliberately no weaker second capability: a
  Product Manager can attach an image that exists and cannot create one, which is a named gap rather than a
  reason to widen anything. Writing files to the server is the privilege to be strictest about.
- `POST /media` carries its **own** rate limit (`AC_RATE_LIMIT_UPLOADS`, 30/minute) on top of the
  namespace-wide write limit. That limit was sized for endpoints that insert a row; an upload moves a file
  and re-encodes an image.
- The size cap is the **lower** of `AC_MEDIA_MAX_BYTES` and PHP's `upload_max_filesize`. A cap the web
  server refuses first is a number that lies — PHP discards an oversized body before any application code
  runs, so the two must be raised together (`docker/php-uploads.ini`).
- Deletion is permanent, files included. An attachment in the trash still answers at the same URL, so
  "deleted" would not be true of the only thing anyone can reach — and these files are customer-supplied
  photographs as often as they are product shots.

## CSV import and export

- **A CSV is a document a spreadsheet will execute.** A cell beginning `=`, `+`, `-`, `@`, a tab or a
  carriage return is a formula to Excel, LibreOffice and Google Sheets, and formulas reach the shell and the
  network. The attacker needs no access to the shop — one product name, one customer's first name, one order
  note is enough, and the shop owner runs it by opening their own export. **Every exported field is
  neutralised** by prefixing a single quote (`ImportExport\CsvWriter`), which is what
  `WC_CSV_Exporter::escape_data()` does; where both are in play they are asserted to agree, so the
  duplication cannot drift after a WooCommerce upgrade.
- **An export carries the capability of the thing it exports** — orders need `ac_manage_orders`, customers
  need `ac_manage_customers`. This is §63's rule at its strongest: not a summary but the records themselves,
  in a file that leaves the building. There is deliberately no separate "export" capability, which would let
  an account read in bulk what it cannot read one at a time.
- **Exports are bounded and uncacheable.** A row cap keeps one request's cost finite; `Cache-Control:
  no-store, private` keeps one shop's order book out of a shared cache.
- **An import writes no file anywhere a web server can reach.** The CSV arrives as the request body, not as
  a multipart upload, so `move_uploaded_file()` into `wp-content/uploads` — the step "File uploads" below
  spends four checks and a re-encode making safe — is simply absent. Where a third-party engine needs a path
  (WooCommerce's product importer does), the file goes to `get_temp_dir()` under a random name and is
  unlinked in a `finally`. Nothing is retained between requests.
- **A dry run defaults to on.** A client that omits the flag gets a preview, never a write. The reverse
  default means one malformed integration overwrites a catalogue on its first request.
- **An import must not be a back door around the ledger.** Stock changes go through the domain service, so
  every imported row leaves an `ac_inventory_movements` entry and an audit record with an actor — "a
  spreadsheet said so" is a reason like any other, and an import that wrote quantities directly would defeat
  the table's whole purpose.
- **Serving a file is the one exception to the response envelope**, and it is bounded: only a 2xx body is
  raw, only on routes that opt in, and the `Content-Disposition` filename is generated rather than taken
  from input — a filename from input is header injection and path traversal looking for somewhere to happen.
  Errors always come back in the envelope, so a client never saves an error message as `products.csv`.

## Customer data sent to third parties

Settled by §62b, which is the first integration that sends **customer data outward** rather than fetching
or receiving something. Everything before it — couriers, gateways — sends what a parcel or a payment
needs. An advertising integration sends who the customer *is*.

- **Hash before the boundary, not inside the adapter.** `Marketing\UserData` has a private constructor and
  hashes on the way in, so no object in the system holds a raw email on its way to an ad network. An
  adapter cannot leak what it was never given, and neither can a queue table, a log line or a `var_dump`.
- **Hashing is not anonymisation, and nothing here may pretend otherwise.** A SHA-256 of an email address
  is a stable identifier for one person — that is the entire point of sending it — and the space of real
  email addresses is small enough to brute force. Personal data sent this way is still personal data: the
  shop needs a lawful basis and a privacy notice, and this section is not one.
- **Send only what the server witnessed.** `Purchase` is a fact this database holds. `PageView`, `Search`
  and `ViewContent` are browser facts, and a backend reporting them is guessing — a guessed conversion
  event is worse than a missing one, because it silently reprices somebody's ad spend.
- **A public id and a credential are not the same variable.** `META_PIXEL_ID` ships inside the
  storefront's JavaScript and is served by `GET /marketing/config`; `META_CAPI_ACCESS_TOKEN` authorises
  writing conversions into an ad account, lives in `.env` only, and appears in no response ever. A test
  asserts the config endpoint's body does not contain it, because the compiler cannot.
- **Never on the request path.** The outbound call is queued and drained on cron. A third party being
  down must never fail or delay a customer's order.
- **Fire once.** A conversion reported twice is a real financial distortion, not a duplicate log line.
  The event id is derived from the order rather than from randomness, and claimed with a write-once
  insert whose duplicate-key failure is the answer — the same mechanism as the webhook ledger.

## Audit logging

Record actor, action, target, before/after where meaningful, timestamp, and source IP for every privileged
or state-changing operation. Audit records are append-only.

## Before activating any provider integration

Verify each of these:

```
[ ] credentials stored securely (env only)
[ ] TLS enforced
[ ] signature verification implemented and tested
[ ] request payload validation
[ ] explicit timeouts
[ ] defined retry behaviour (and no retry storms)
[ ] idempotency keys
[ ] logging that excludes secrets
[ ] sensitive-data handling reviewed
[ ] failure behaviour defined (what the order does when the provider is down)
```

Two of those lines have a specific answer in this codebase, arrived at by walking the list against the
shipping modules (roadmap §55, on the branch `feat/security-review`).

### Explicit timeouts have a ceiling, not just a floor

`YalidineSettings::MAX_TIMEOUT` and `ZRExpressSettings::MAX_TIMEOUT` cap a client's configured `timeout` at
**60 seconds**, clamping and reporting through `problems()` like every other corrected value. A per-client
setting that could be raised to ten minutes removes the explicit timeout this list asks for as surely as
never setting one: a hung courier would then hold a PHP worker for ten minutes per request, on the checkout
path included. Any future provider setting that reaches a transport gets the same treatment.

### A Yalidine label URL is a credential

Yalidine's parcel response carries `label` (and `labels`), and **those URLs carry an access token**. Anyone
holding one can fetch the shipping label — the customer's name, phone number and full address — with no
credential of their own, for as long as the courier honours it. We cannot expire or revoke it.

How that is handled here, and why:

- **It is stored** in `ac_shipments.metadata` and **returned** by the shipment endpoints. That is deliberate:
  an operator has to be able to print a label, and `ac_manage_shipping` is the boundary that decides who may.
- **It is never logged.** `label` and `labels` are in `Logger::SENSITIVE_EXACT`, so `Logger::redact()` masks
  them in any log or audit context. A log line outlives the parcel; the URL should not outlive it anywhere
  we control.
- **Treat it downstream as you would a password.** It must not be pasted into a ticket, a chat message, a
  client-side console log, or anything cached by a CDN, and a database dump containing `ac_shipments` is a
  dump containing live credentials to customer PII. Anything holding these rows is in scope for the same
  handling as the credential store.

Redaction is keyed on the name of the field, which is a defence that only works while names stay honest. A
provider field holding a tokenised URL is added to `SENSITIVE_EXACT` when the adapter is written, not after
the first leak.

## Security tests to write

`SQL injection`, `XSS`, `CSRF`, `IDOR`, `privilege escalation`, `rate limits`, `file upload abuse`,
`webhook forgery`, `replay`.

**All nine are now answered, and `docs/TESTING.md` is the map** — which test covers each, which were
already covered under another name, and which do not apply here. Two are answered by an argument rather
than a test, and both arguments are in this document: CSRF above, and the owner half of IDOR, which
cannot be tested because no route in this API is owner-scoped yet — every one carries a management
capability, and `Permissions::assertOwnsOr()` is written, unused, and waiting for a customer session.

**That wait now has a step: roadmap 32c, written up as §59c.** Read it before building customer accounts.
The short form, because it is the one authorization mistake this project is most likely to make: a shopper
opens their order history, edits `/orders/123` to `/orders/124`, and reads a stranger's name, phone and
address. Nothing errors, because the request is valid and only the authorization is absent. Three things
must all hold, and the third is the one that gets skipped — the storefront asks for "my orders" rather
than "orders for customer 5"; the Next.js server never forwards a browser-supplied id under its own
privileged credential; and **this API checks ownership itself, in the service layer**, because a check
that lives only in the storefront is a check the second client removes. Owner-scoped tests are part of
that step, not a follow-up, and the shape is "customer A is refused customer B's order **and** served
their own" — a refusal alone proves only that the route is broken.

The same question applies to every shopper-reachable resource taking an id: an order's notes, timeline,
shipments and payments, a customer record, a saved address. Enumerate them when the step is built.

Two properties of this list are worth keeping when adding to it. A refusal and an unreachable route look
identical from outside, so **every negative test needs a positive control**. And an injection test that
asserts only "no crash" passes against a concatenated query, so **assert that a payload does not widen a
result set**, which is the thing a vulnerable query actually fails.

## Backups

Maintain backups of database and uploads, and **test restores** — an untested backup is not a backup.
