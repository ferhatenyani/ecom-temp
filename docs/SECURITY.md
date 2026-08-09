# Security Requirements

Read this before implementing authentication, authorization, payments, webhooks, file uploads, or any
third-party integration. Source roadmap §21, §44, §64.

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
  or full customer PII beyond what an audit record needs.

## Payments

> Never trust the frontend to tell the backend that a payment succeeded.

- Payment status is confirmed by a server-side call to the provider, or by a signature-verified webhook.
- Every transaction attempt is recorded with its provider reference and verification result.
- Amount and currency are re-checked server-side against the order before marking it paid.

## Webhooks

Each webhook endpoint follows this exact sequence:

```
receive → verify signature/auth → validate payload → identify event
       → idempotency check → process → store event → respond
```

- Signature verification uses a constant-time comparison against the environment's webhook secret.
- Reject events whose timestamp is outside a short tolerance window (replay protection).
- The event id is stored; a repeat delivery is acknowledged and dropped. Duplicate delivery must never
  duplicate a payment, shipment, order transition, or notification.
- Respond quickly; do slow work asynchronously.

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

## Security tests to write

`SQL injection`, `XSS`, `CSRF`, `IDOR`, `privilege escalation`, `rate limits`, `file upload abuse`,
`webhook forgery`, `replay`.

## Backups

Maintain backups of database and uploads, and **test restores** — an untested backup is not a backup.
