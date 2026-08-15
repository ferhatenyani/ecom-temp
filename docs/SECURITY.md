# Security Requirements

Read this before implementing authentication, authorization, payments, webhooks, file uploads, or any
third-party integration. Source roadmap §26, §42, §55.

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
- Capability checks use named capabilities, not role-name comparisons.
- Never rely on the frontend hiding a control.
- Privileged users require 2FA.

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
- Every transaction attempt is recorded with its provider reference and verification result.
- Amount and currency are re-checked server-side against the order before marking it paid.

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
  `ZR_EXPRESS_WEBHOOK_SECRET`, `CHARGILY_WEBHOOK_SECRET`. Read through `Config::secret()` and handed to the
  verifier by the plugin bootstrap — the same path `Plugin::shippingProviders()` already uses for API
  credentials, and for the same reason: one place in the codebase reads a provider's credentials.
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
  body-secret shape may not be acted on directly.
- **The event id is claimed, not checked.** A repeat delivery is acknowledged with 200 and dropped. The claim
  is a write-once insert whose duplicate-key failure *is* the idempotency answer — a read-then-write races
  precisely when a provider retries in parallel, which is the case it exists for.
- Where a provider sends no event id, the id is the SHA-256 of the signed material (`{id}.{timestamp}.{body}`,
  or the body alone), which is stable across a genuine retransmission and distinct between real events.
- Duplicate delivery must never duplicate a payment, shipment, order transition, or notification.

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

## Rate limiting

Apply to authentication, password reset, checkout, order creation, COD confirmation, search, and
import endpoints. Limit per identity and per IP, and log rejections.

## File uploads

Validate real MIME type and extension against an allowlist, cap size, strip metadata, store outside
web-executable paths where possible, and never trust the client-supplied filename.

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

## Backups

Maintain backups of database and uploads, and **test restores** — an untested backup is not a backup.
