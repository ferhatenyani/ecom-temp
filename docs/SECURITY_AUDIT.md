# Security Audit

Status of every security layer in this repository, audited against the code rather than against the
design documents. Where the two disagree, the disagreement is the finding — see
[Discrepancies found](#discrepancies-found).

| | |
|---|---|
| **Audited** | 2026-08-17, against `f9693da` |
| **Revised** | 2026-08-17, after §86 fixed TLS, SSRF and client-IP attribution. Rows changed by that work say so and carry the date; everything else is as first audited. |
| **Scope** | `wp-content/plugins/algerian-commerce-core/` (`src/`, `integrations/`, `migrations/`), `compose.yaml`, `docker/`, `scripts/` |
| **Method** | Read the code and cited it. A ✅ means the mechanism was located in a file, not that a document claims it. |
| **Not covered** | The Next.js admin and storefront (separate repositories), the production host, and anything a live penetration test would find. This is a code audit. |

**Status legend.** The brief asked for ✅ / ❌. A third marker was necessary: several layers are
genuinely implemented in part, and recording those as ✅ would overstate them while ❌ would deny working
code. They are called out rather than averaged away.

| Marker | Meaning |
|---|---|
| ✅ | Implemented and located in the code |
| ⚠️ | Partial — implemented for the threat this project faces, with a named limit |
| ❌ | Not implemented |

---

## Authentication & authorization

| Security Layer | Status | Notes |
|---|---|---|
| Staff authentication | ✅ | WordPress Application Passwords over HTTP Basic, held by the Next.js server and never by browser JavaScript. `src/Auth/AuthService.php` reports the resolved method. Roadmap §44 forbids issuing one to a customer, and `tests/Api/account.php` asserts it. |
| Shopper authentication | ✅ | `src/Account/AccountSession.php:109` — the session token is a WordPress auth-cookie string from `wp_generate_auth_cookie()`, validated by `wp_validate_auth_cookie()`. No bespoke crypto. Returned in the response body, never as a cookie, so the storefront holds it server-side. |
| Staff/shopper separation | ✅ | `AccountSession::isShopper()` (`:195`) refuses any account holding an `ac_*` capability at `/account/login`, checked against the capability vocabulary rather than a role name. Without it the customer door would mint bearer tokens for administrators. |
| Role-based authorization | ✅ | `src/Permissions/` — named capabilities (`Capabilities.php`), never role-name comparisons. Every route declares a real `permission_callback`; `tests/Api/security.php` reads the router and fails on any route that does not, with `/health`, `/locations/*` and `/webhooks/*` as an explicit allowlist. |
| Object-level authorization (IDOR) | ✅ | Enforced in the service layer, not the controller: `src/Account/AccountService.php:378` calls `Permissions::assertOwnsOr()`. `/account/orders` takes no `customer_id` parameter. Proven as the pair the house rule demands — A is refused B's order **and** served their own. Guest orders (`customer_id` 0) are reachable by nobody. |
| Privilege escalation | ✅ | One Support Agent credential is swept across every GET route in `tests/Api/security.php`; where an administrator gets 200 the sweep requires **403**, so a route added later with the wrong guard fails the suite. Each negative carries a positive control. |
| Two-factor authentication | ❌ | **Deliberate, with an architectural reason** — roadmap §26 "On 2FA". 2FA protects interactive human logins; privileged API access is an Application Password held by a server, which bypasses 2FA by design because there is nobody to prompt. The surface it would protect is `wp-admin`, a WordPress-level concern that §54's approved plugin baseline covers with **Wordfence**. **Timeline:** revisit when a human-facing admin login exists that does not go through `wp-admin` (roadmap §70). Configure Wordfence at deployment rather than writing a TOTP implementation. |

## Transport & data protection

| Security Layer | Status | Notes |
|---|---|---|
| Encryption in transit — inbound | ✅ | **Built 2026-08-17 (§86).** Caddy (`caddy:2.10.2-alpine`) terminates TLS, behind a compose `proxy` profile so development is untouched — `docker/Caddyfile`, with automatic Let's Encrypt and no renewal cron to forget. HSTS, `nosniff`, `X-Frame-Options: DENY` and a `Referrer-Policy` are set there, and `Server`/`X-Powered-By` removed. `ClientIp::applyForwardedScheme()` makes `is_ssl()` true behind the proxy, which also restores §44 — `wp_is_application_passwords_supported()` checks it. Verified end to end on 2026-08-17 against a running Caddy. The certificate itself is per client: `docs/DEPLOYMENT.md` → "TLS and the reverse proxy". |
| Encryption in transit — outbound | ✅ | `src/Http/WpHttpClient.php:47` sets `sslverify => true` on every provider call. Chargily and Meta settings reject any base URL not beginning `https://` (`integrations/Chargily/ChargilySettings.php:105`, `integrations/Meta/MetaSettings.php:75`). Chargily's `checkout_url` is upgraded to HTTPS on arrival because the provider returns `http://` — that URL is where a shopper types card details. |
| Encryption at rest | ❌ | No application-level encryption: no `openssl_encrypt`, no `sodium_*`, no encrypted columns. MySQL runs with default settings and no InnoDB tablespace encryption. Sensitive rows sit in cleartext — `ac_shipments` (customer name, phone, full address, and Yalidine label URLs which are bearer credentials), `ac_campaign_recipients` (real email addresses), `ac_payment_transactions`. **Timeline: no timeline recorded.** The repository names the consequence but not a date; `docs/SECURITY.md` → "Backups" requires production backups be encrypted at rest off-site, which is the nearest committed statement. |
| Secrets management | ✅ | `.env` only, read through `Config::secret()` (`src/Core/Config.php:153`) and handed to providers by the plugin bootstrap — one place per credential. `.env` is gitignored, `.env.example` mirrors keys with blank values. Never an option, never a constant, never a query parameter. `CHARGILY_WEBHOOK_SECRET` was **deleted** rather than left as a slot that could only be filled in wrongly. |
| Secret leakage into logs | ✅ | `src/Core/Logger.php:32` redacts on substring (`card`, `cvv`, `pan`, `cookie`, …) and `:51` on exact field name — `SENSITIVE_EXACT = ['label', 'labels', 'signature_url']`, because a Yalidine label URL is a credential to one customer's name, phone and address, not a link. `GET /marketing/config` is asserted not to contain `META_CAPI_ACCESS_TOKEN`. |

## API security

| Security Layer | Status | Notes |
|---|---|---|
| Rate limiting | ✅ | `src/Security/RateLimiter.php` — per route group, not per verb: reads 600/min, writes 120/min, uploads 30/min, tracking 20/min, auth failures 10 per 15 min. All overridable via `AC_RATE_LIMIT_*`, which `compose.yaml` forwards into both containers (a variable the compose file does not pass through reaches the plugin as nothing). Unauthenticated routes count for themselves, because `RateLimitGuard` hooks WordPress's admin auth path and never sees them. |
| CORS | ✅ | `src/API/Cors.php:48` **removes** core's `rest_send_cors_headers`, which reflects any origin it is given, and replaces it with an environment-specific allowlist on this namespace only. `scripts/test-api.sh` asserts the contrast against `/wp/v2`, so the replacement is proven installed rather than merely written. Never `*` on a private route. |
| CSRF | ✅ | Ruled out architecturally and then measured, not assumed — `docs/SECURITY.md` → "CSRF", verified over real HTTP 2026-08-16. There is no ambient credential: the credential is an `Authorization` header a cross-origin page cannot set, and core forces `wp_set_current_user(0)` on any REST request carrying cookies without `X-WP-Nonce`, so a valid session cookie and a forged one are refused identically. No state-changing `GET` routes exist. Nonces would be theatre and are deliberately absent. |
| Input validation | ✅ | Every route declares an args schema; the house rule that a `sanitize_callback` must be paired with `'validate_callback' => 'rest_validate_request_arg'` exists because a custom sanitizer otherwise displaces the default and silently voids `minimum`, `maximum`, `enum` and `pattern`. Write payloads refuse unknown fields **by name with a reason** — the `CustomerInput` device, extended by `Cart\LineInput` to `price`, `line_total`, `subtotal`, `total`, `discount`, `currency`, and by §83 to `option_price`, `options_price`, `surcharge`, `option_total`. |
| Server-side pricing | ✅ | The client sends the option *choice*; the server reads the *price* from the product's stored definition on every cart mutation and again at checkout (`Products\OptionSelection`). Shipping comes from `RateResolver` against the destination, and the free-shipping threshold is compared against the **cart's** subtotal, never a caller-stated one. |
| SQL injection | ✅ | 72 `->prepare()` call sites across `src/`. `tests/Unit/SqlSafetyTest.php` walks `src/`, `integrations/` and `migrations/` statically, **asserts its own reach first** (a floor count, so a regex matching nothing cannot pass silently) and proves it can still fail against a hostile fixture. `tests/Api/security.php` asserts the property a concatenated `WHERE` actually breaks — **a payload must not widen a result set** — because "200, no crash" is exactly what a working injection returns. |
| Filter injection (§82) | ✅ | A taxonomy name from a request is resolved against registered taxonomies before reaching a query and never interpolated into one; an unresolved name is a **400**, never a dropped clause, because a dropped clause is how a filter widens a result set. Term lists are refused rather than coerced — `(int) "1 OR 1=1"` is `1`, which would filter on the wrong category and report success. |
| XSS | ✅ | Escape on output; stored data is never trusted. Campaign templates are the sharp case: `wp_kses` runs **on save** via `wp_insert_post_data` (`src/Campaigns/EmailHtml.php:130`), not on send, so hostile markup never reaches the database where a later reader might not sanitise. The allowlist is email-safe rather than `wp_kses_post()`'s — no `<script>`, `<iframe>`, `<form>`, `on*` attribute or `javascript:` URL. |
| Template injection / RCE | ✅ | `Campaigns\TemplateRenderer` is pure and does one thing: an allowlist of token names replaced by values. No `eval`, no `do_shortcode()`, no `do_blocks()`, no callable in a token map — rendering a user-authored template as code would be RCE granted to whoever holds `ac_manage_marketing`. An unknown token renders empty and is reported. |
| Email header injection | ✅ | A rendered subject is stripped of CR, LF and tab before `wp_mail()` writes it into a `Subject:` header, where a newline in a merge value could add a `Bcc:`. Merge values are escaped with `htmlspecialchars(ENT_QUOTES)` for the HTML part — `ENT_COMPAT` would let `x' onclick='…` break out of `href="{{unsubscribe_url}}"`. |
| SSRF | ✅ | **Fixed 2026-08-17 (§86).** `WpHttpClient` now calls `wp_safe_remote_request()`, which sets `reject_unsafe_urls` and runs `wp_http_validate_url()` — loopback, link-local and private ranges are refused, so a provider URL cannot reach the VPS's own services or `169.254.169.254`. The audit found the unsafe spelling against a rule `docs/SECURITY.md:53` had stated since it was written. `tests/Unit/OutboundHttpSafetyTest` scans `src/` and `integrations/` for the unsafe wrappers and asserts its own reach first, so the next call site cannot quietly reintroduce it. |
| Client IP attribution | ✅ | **A layer that was wrong by default until §86 found it.** Every rate limit and every audit row keys on the caller's address, and the `wordpress` image ships `mod_remoteip` enabled and trusting all of `10/8`, `172.16/12`, `192.168/16`, `169.254/16` and `127/8` — in Docker every peer is RFC1918, so Apache overwrote `REMOTE_ADDR` from a client-written header before PHP ran. Measured 2026-08-17: `curl -H 'X-Forwarded-For: 9.9.9.9, 8.8.8.8'` to the published port arrived as `8.8.8.8`, and the failed login it carried was counted against that address. `docker/apache-remoteip-off.conf` disables it, and `Security\ClientIp` owns the decision instead — the header is read only from a peer in `AC_TRUSTED_PROXIES`, walking right to left. Asserted over real HTTP with a positive control. |
| Explicit timeouts | ✅ | `WpHttpClient` sets an explicit 15s timeout rather than inheriting WordPress's 5s default. `YalidineSettings::MAX_TIMEOUT` and `ZRExpressSettings::MAX_TIMEOUT` **cap** a client's configured value at 60s — a setting raisable to ten minutes removes the explicit timeout as surely as never setting one, and would hold a PHP worker on the checkout path. |
| Error handling & information disclosure | ✅ | `src/API/AbstractController.php:97` — an `ApiException` returns its declared code; **anything else is logged with its real message and returned as a generic internal error**. Clients never receive a stack trace, an exception class or a file path. `GET /health` returns check names and statuses only, no versions or paths. A webhook 401 says nothing about which check failed, because a verifier that distinguishes "bad timestamp" from "bad signature" is an oracle for building a valid one. |
| Enumeration resistance | ✅ | Password reset returns a byte-identical response for a known, unknown and staff address, asserted as identity rather than matching wording. `/orders/track` answers **404** for anything that does not verify and **410** only for a valid-but-expired MAC. A forged unsubscribe token answers identically to a real one. |

## Payments

| Security Layer | Status | Notes |
|---|---|---|
| Server-side payment verification | ✅ | Status is never believed from a client callback. `PaymentStatus::accepts()` carries the rule that protects money: from `paid`, **only `refunded`** — providers send late `pending` events and webhooks arrive out of order, and without it one of them un-pays a settled order. |
| Amount & currency re-check | ✅ | Re-checked server-side against the order before marking paid. A report stating **no** currency now fails that check rather than skipping it — Chargily's webhook payload carries an amount and no currency, and the lenient reading answered "matches" on a comparison that had never run. The handler re-fetches: a signature proves who sent a message, not the money. |
| Transaction ledger | ✅ | `ac_payment_transactions` (migration 007). The row is written **before** the gateway is called and closed as `failed` if that call never returns — the attempt worth having is the one where a gateway may have taken money on a request this side then dropped. `amount` is `decimal(12,2)`, not minor units, because Chargily quotes in dinars. |
| PCI DSS compliance | ⚠️ | **No cardholder data enters this system** — grep finds no card number, CVV, PAN or expiry field anywhere in `src/` or `integrations/`. Chargily is a hosted checkout: the server creates a checkout and hands back `checkout_url`, and the shopper types card details on the provider's domain. That places the merchant in the narrowest scope (SAQ-A shape). What is **not** done is the formal part — no attestation, no ASV scanning, no documented cardholder-data-environment boundary, and the TLS row above is a prerequisite. **Timeline: no timeline recorded**; this is a merchant-and-acquirer obligation per client rather than a code change, and belongs with deployment. |
| Cash on delivery | ✅ | COD state is order meta plus audit events, never new order statuses and never its own table. A COD order is **not** a paid order — the payment notification is gated on `$order->is_paid()`, or every COD customer is told their money arrived. |

## Webhooks

| Security Layer | Status | Notes |
|---|---|---|
| Signature verification | ✅ | One sequence, implemented once in `src/API/AbstractWebhookController.php`: receive → verify → validate → identify → claim → re-fetch → respond. Verification runs on the **raw body** before any JSON decode, with `hash_equals()`. Svix (ZR Express) and Chargily are real signatures; Yalidine's `security_token` binds to nothing and is treated as a **hint to re-fetch, never a source of truth**. |
| Unconfigured secret = closed door | ✅ | A webhook route is registered only when its provider is. Missing credentials mean the endpoint 404s rather than accepting what it cannot check. |
| Replay protection | ✅ | 5-minute timestamp tolerance where the timestamp is inside the signed material; Yalidine gets **no** timestamp check, because nothing is signed and checking an attacker-controlled `date` would be theatre. |
| Idempotency | ✅ | `ac_webhook_events` (migration 008), `UNIQUE (provider, event_id)`. The claim is a write-once insert **whose duplicate-key failure is the answer** — `WebhookEventRepository` deliberately has no `has()`, because a read-then-write races precisely when a provider retries in parallel. |
| Inbound verification proven live | ❌ | **No courier webhook has ever been received.** `ac_webhook_events` holds rows for `chargily` and the test double only; neither `YALIDINE_WEBHOOK_SECRET` nor `ZR_EXPRESS_WEBHOOK_SECRET` is set, so both routes 404 today. Every test payload is *constructed* from published documentation — the suites prove each verifier matches the scheme **as written** and cannot prove the sender implements it. Both failure modes are quiet 401s that look like silence. Not an outage: a verified courier event is only ever a hint, and the hourly poller keeps parcels current either way. **Timeline:** resolves itself when a real event arrives — the ledger is the standing check, and the `ASSUMPTION` markers come out then and not before. |

## File handling

| Security Layer | Status | Notes |
|---|---|---|
| Upload validation | ✅ | `POST /media` is the only endpoint that writes a file a web server might execute. Four independent checks in a load-bearing order — size, filename, contents (`finfo` **and** `getimagesize()`, which must agree), extension — plus `wp_handle_upload()`'s own from an allowlist generated from ours so the two cannot drift. All of it in `Media\UploadPolicy`, which is **pure**, so every abuse case is a unit test. |
| Stored file ≠ sent file | ✅ | The name is rewritten and the extension taken from the **sniffed** type, so a double extension cannot survive. Every accepted image is decoded and re-encoded, which strips EXIF/GPS and makes a polyglot inert. `ImageSanitizer` **pins the editor to GD** because `WP_Image_Editor_Imagick::save()` keeps EXIF, and the two containers here disagreed about which editor WordPress picks — a security property that depends on which process ran is not a property. |
| Upload directory non-execution | ✅ | The layer that does not depend on the allowlist being right: `docker/apache-wordpress.conf` denies PHP, CGI and `.htaccess` under `wp-content/uploads` by `FilesMatch`, using pure core Apache so it holds whether or not mod_php is loaded. `scripts/test-api.sh` asserts it, because a vhost edit could silently drop it. |
| File permissions | ✅ | `wp-content` is `755`/`644`, never `777`. It **was** found at `777` during the stack audit as a workaround for the two images disagreeing about `www-data`'s uid (33 vs 82); the fix was pinning `user: "33:33"` on `wpcli`, not widening the mode. |
| CSV formula injection | ✅ | A cell beginning `=`, `+`, `-`, `@`, tab or CR is a formula that reaches the shell, and the attacker needs one product name. `ImportExport\CsvWriter` prefixes a single quote exactly as `WC_CSV_Exporter::escape_data()` does, and the two are asserted to agree so the duplication cannot drift after a WooCommerce upgrade. |
| Import safety | ✅ | The CSV arrives as the request body, not a multipart upload, so `move_uploaded_file()` into a web-reachable path is simply absent. Nothing is retained between requests. `dry_run` defaults to **true** — a client that forgets the flag previews, never writes. Stock changes go through `InventoryService`, so an import cannot be a back door around `ac_inventory_movements`. |
| Download response | ✅ | Serving a file is the one exception to the response envelope, and it is bounded: 2xx bodies only, opt-in routes only, `Content-Disposition` filename generated rather than taken from input — a filename from input is header injection and path traversal looking for somewhere to happen. `Cache-Control: no-store, private` keeps one shop's order book out of a shared cache. |

## Privacy & data governance

| Security Layer | Status | Notes |
|---|---|---|
| Audit logging | ✅ | `ac_audit_logs` (migration 001) records actor, action, target, before/after, timestamp and source IP. **Append-only in code**: `src/Audit/AuditRepository.php` exposes `insert()`, `paginate()` and `count()` and no update or delete path exists. Every campaign send logs the recipient **count**, never the list — an append-only table is the one place PII cannot later be purged. |
| Marketing consent | ✅ | `Campaigns\Consent` — **default false**, stored as user meta whose *absence* is a no, so customers predating the feature are not silently opted in. Withdrawal **deletes** the meta rather than writing `'0'`, because a stored no invites a later query written `!= '0'` that reads every unanswered customer as a yes. Only the customer can set it; `CustomerInput` refuses it and no staff route can. The filter lives in `AudienceResolver::candidates()` so **every** path goes through it, including an explicit id list an admin typed, and consent is re-checked at send time. |
| Unsubscribe | ✅ | Mandatory, one click, no login. Public route, signed `{customer id}.{128-bit HMAC}` token, idempotent. Keyed on the **customer**, not the recipient row, because those rows are purged — a token bound to a row would stop working exactly when somebody clicked it. Deliberately **not** rate-limited beyond the namespace counters: throttling it produces a customer who cannot unsubscribe, which is how a sending domain gets blocklisted. |
| PII to third parties | ✅ | `Marketing\UserData` has a private constructor and hashes on the way in, so no object — and no queue row, log line or `var_dump` — holds a raw email en route to an ad network. Only what the server witnessed is sent; `PageView`, `Search` and `ViewContent` are browser facts a backend would be guessing at. The Algerian trap is handled: `0551020304` becomes `213551020304`, replacing the trunk prefix rather than stripping the zero. |
| Data retention | ⚠️ | Implemented in exactly one place: `Campaigns\RecipientRepository::purge()` drops recipient addresses 30 days after a campaign completes, keeping aggregate counts as columns so a shop can still say a campaign reached 4,812 people and can no longer say who they were. Everywhere else retention is named as an operational concern and **not built** — `AuditRepository`, `MovementRepository` and `PaymentController` each say pruning is separate. **Timeline: no timeline recorded.** |
| Data subject rights (access, erasure, portability) | ❌ | Not implemented. No integration with WordPress's `wp_privacy_*` personal-data export or erasure hooks, and no endpoint for a customer to request their data or its deletion. Algeria's **Law 18-07** governs here, and `docs/SECURITY.md` is explicit that §54's rule applies to law as much as to APIs: the implementer reads the current text or has the client confirm with counsel, and nothing in the repository is a legal opinion. **Timeline: no timeline recorded.** The engineering hooks exist in WordPress and would be a small module; the blocker is the legal determination, which is per client. |
| Privacy notice / lawful basis | ❌ | Out of scope for a backend, and named rather than assumed: hashing is **not** anonymisation — a SHA-256 of an email is a stable identifier for one person, which is the entire point of sending it. The shop needs a lawful basis and a privacy notice. **Timeline: client obligation, no timeline recorded.** |

## Operations & infrastructure

| Security Layer | Status | Notes |
|---|---|---|
| Backups | ✅ | `scripts/backup.sh` dumps from inside the `db` container (never `wp db export`, broken here for the `caching_sha2_password` reason), takes uploads out of the volume, and writes `.env` at `0600` into a `0700` directory under a `backups/.gitignore` that ignores everything but itself. A backup is a copy of every secret and every customer. |
| Tested restore | ✅ | An untested backup is not a backup, so the rule is a second script: `scripts/restore.sh --verify` starts a throwaway MySQL of the pinned version, restores into it and compares every table's `COUNT(*)` against the manifest — the running stack untouched, which is what makes the drill get done. Verified 2026-08-16: **62 tables, every count matching**. It refuses to pass when it compared nothing, because `docker exec -i` once ate the manifest from stdin and reported success in green. |
| Off-site encrypted backups | ❌ | `backups/` is local development only. `docs/SECURITY.md` → "Backups" states production backups belong off-site and encrypted at rest, because the threat model of a dump on someone's laptop is not that of the running database. No off-site target, schedule or encryption step exists in `scripts/`. **Timeline:** `docs/DEPLOYMENT.md` (roadmap §74–§76), not yet written. |
| DDoS protection | ⚠️ | **The application side is ready and the runbook is written; the account is the client's.** Application rate limiting bounds a brute-force or forgery loop but does not absorb a volumetric attack — every refused request still costs a PHP worker and a database round trip. §86 added what this repository can own: `WP_BIND=127.0.0.1` keeps the container off the internet, Caddy is the single ingress, and `AC_TRUSTED_PROXIES` plus Caddy's own `trusted_proxies` resolve the real client through a CDN. `docs/DEPLOYMENT.md` → "DDoS and Cloudflare" is the procedure, including the step that makes the rest real — firewalling the origin to Cloudflare's ranges, without which the VPS still answers on its own address. **Remaining, per client:** a Cloudflare account, the DNS move and the firewall rules. |
| Database exposure | ✅ | The `db` service publishes no host port — reachable only on the compose network. MySQL 8.4 LTS, supported to 2032-04-30, authenticating with `caching_sha2_password`. |
| Version pinning | ✅ | Every image tag in `compose.yaml` is an exact build, and WooCommerce — which installs into a volume and so is not pinned by a tag — is declared under `x-tested-versions`. The record is a **check, not a table**: `scripts/test.sh versions` reads the pins and compares them against the running stack, because a table in a document is a second copy of numbers that drifts. |
| Bundled plugin removal | ✅ | Akismet and Hello Dolly are **deleted**, not deactivated — neither does anything headless and unused code still has to be patched. They live in the volume, so `setup.sh` re-deletes them after a fresh install. |
| Mail transport | ⚠️ | `Notifications\MailTransport` configures SMTP via one `phpmailer_init` hook; an unrecognised encryption value falls back to **`tls`, never `none`**, so a typo cannot send credentials in the clear. `wp algerian-commerce mail-check` reports whether the password is set, never what it is. **Updated 2026-08-17:** SPF/DKIM/DMARC now have a documented per-client procedure (`docs/DEPLOYMENT.md` → "Email", Brevo) and `mail-check` resolves all three for the `AC_MAIL_FROM` domain, reporting missing, misconfigured (two SPF records; a revoked DKIM key) and `p=none`-only. It stays ⚠️ because **the records are DNS on each client's domain and cannot be shipped from a template** — the command turns "did we remember?" into one check, it does not publish anything. A failed lookup reports `unknown`, never `missing`. |
| Security test coverage | ✅ | All nine categories in `docs/SECURITY.md` → "Security tests to write" are answered, mapped in `docs/TESTING.md`. Two are answered by argument rather than test (CSRF; the owner half of IDOR before §59c) and both arguments are written down. Discipline worth keeping: **every negative test needs a positive control**, since a refusal and an unreachable route look identical from outside. Latest run — 1,683 unit tests / 4,019 assertions, 27 `tests/Api` suites, 124 HTTP checks. |

## Feature-specific controls

| Security Layer | Status | Notes |
|---|---|---|
| Order tracking token (§84) | ✅ | The obvious key — order number plus phone — is enumerable: an Algerian mobile is ten digits behind a known operator prefix, so the whole order book sits a few million requests behind a form. `TrackingToken` is `{order id}.{128-bit HMAC}` over the id and a **per-order nonce**, keyed on `wp_salt('auth')`, with `hash_equals()` throughout. The nonce makes links revocable with one write and means an order never issued a link has no valid token — without it, one database dump would expose tracking for every order. Expires 90 days after a terminal status. |
| Tracking disclosure limits (§84) | ✅ | A tracking page that echoed the delivery address would turn a leaked link into a doxxing tool, and these links get forwarded and screenshotted. Allowed: order number, statuses, history, courier, tracking number, destination **wilaya only**. Refused: full address, commune, phone, email, customer name, line items, totals. `TrackingPresenter::publicView()` filters **what it is handed, not what it is promised** — it takes a whole shipment row and reads an allowlist out of it, so a future caller passing `Shipment::toArray()` still cannot publish a label URL. Asserted by key **and** by value. |
| Campaign send authorization (§85) | ✅ | Two capabilities, and the second is on the send: `ac_manage_marketing` covers drafting; **sending, reading the recipient list and counting a segment additionally require `ac_manage_customers`**, because a campaign reaches every customer record in the shop. A Marketing Manager holds the first and not the second, so it is a live restriction. No new capability was invented. |
| Send-path safety (§85) | ✅ | Nothing is sent on a request path — `POST /campaigns/{id}/send` resolves, freezes and returns; a CLI command drains. A send is claimed with one `UPDATE … WHERE status = 'draft'`, so a second request changes no rows and is told so, with `UNIQUE (campaign_id, customer_id)` as the second guarantee. Refuses with 503 when the shop has no mail transport and 409 when the audience matches nobody, rather than marking a campaign `sent`. |
| Analytics disclosure | ✅ | `ac_view_analytics` is held by every role including Support Agent, so wiring revenue to it alone would hand the shop's turnover to an account that cannot open one order. Money additionally requires `ac_manage_orders`; counts and rates do not. The cache key carries the money flag — a response cache keyed without the caller's privileges serves the privileged payload to whoever asks next. |
| Row-level security | ❌ | **Correctly not implemented, and it is not available.** RLS is a PostgreSQL feature; MySQL 8.4 has none and `CREATE POLICY` is a syntax error. Even on a database that had it, WordPress opens **one** connection as **one** database user for every request, so admin, shopper and cron are indistinguishable to the server and there is no identity for a policy to key on. `SQL SECURITY DEFINER` views have the same problem. Object-level authorization belongs in `Permissions::assertOwnsOr()`, at the only layer that knows who is asking. **Timeline: not planned, by design.** |

---

## Discrepancies found

Three places where the code and `docs/SECURITY.md` did not agree, or where a control was thinner than the
document implied. Listed because an audit that only confirms the documentation is not an audit. **Two were
fixed on 2026-08-17 (§86); the notes are kept rather than deleted, because what was wrong and why is the
part worth reading.**

**1. SSRF — `wp_remote_request()` where the rule says `wp_safe_remote_*`. — RESOLVED 2026-08-17.**
`docs/SECURITY.md:53` had required `wp_safe_remote_*` since it was written; the single outbound call site
spelled it the unsafe way, with `reject_unsafe_urls` set nowhere. Fixed in §86, and the fix is guarded by
`tests/Unit/OutboundHttpSafetyTest` rather than by remembering — it scans `src/` and `integrations/` for the
unsafe wrappers, proves it can still fail against a hostile fixture, and asserts it found the safe call sites
it is guarding so a regex matching nothing cannot report success.

**2. TLS had no implementation and no deployment document. — LARGELY RESOLVED 2026-08-17.**
"HTTPS in production" is the first line of `docs/SECURITY.md` → "Baseline requirements" and nothing satisfied
it. §86 added the Caddy `proxy` profile, the forwarded-header handling underneath it, and
`docs/DEPLOYMENT.md` → "TLS and the reverse proxy" and "DDoS and Cloudflare". **Still outstanding in that
file: off-site encrypted backups.** Getting TLS working also turned up the `mod_remoteip` defect above, which
no amount of reading would have shown — the row that says a deployment step is done should be one somebody ran.

**3. Retention is a rule in three files and a purge in one.**
`AuditRepository`, `MovementRepository` and `PaymentController` each name retention pruning as a separate
operational concern; none implements it, and no script does either. Only `Campaigns\RecipientRepository::purge()`
exists. The audit log is the sharp edge: it is deliberately append-only, so anything written there cannot
later be purged — which is exactly why campaign sends log a recipient count rather than the list, and why an
unbounded audit table is a growing cleartext PII store that no erasure request could satisfy.

## What this audit did not check

- **The Next.js admin and storefront**, which hold the Application Password and the shopper session and are
  separate repositories. The property that keeps that credential out of browser JavaScript is enforced there,
  not here.
- **The production host** — TLS configuration, firewall, patch level, container escape surface, log shipping.
  No production environment exists yet.
- **Live webhook verification**, which cannot be checked until a courier sends one (see the ❌ row above).
- **Runtime behaviour under attack.** This is a code and configuration audit; no penetration test, fuzzing or
  load test was performed.

---

## What to do about the gaps

Plain-language triage of the thirteen open items. **Effort** is rough developer time, not calendar time.

| Priority | Meaning |
|---|---|
| 🔴 **Do before launch** | A real shop with real customers should not go live without it |
| 🟠 **Do soon after** | Not worth blocking launch, but it gets more expensive the longer it waits |
| 🟡 **Do when triggered** | Pointless today; a specific event makes it necessary |
| ⚪ **Don't build** | Buy it, configure it, or it is already solved — writing code here is the wrong move |

### 🔴 Do before launch

| Layer | Effort | Why |
|---|---|---|
| ~~**Encryption in transit (TLS)**~~ | **Done 2026-08-17** | Was the single item to do first, because without HTTPS every admin password, API credential, session token and customer address crosses the network readable by anyone on the path. §86 added the Caddy `proxy` profile and `docs/DEPLOYMENT.md` → "TLS and the reverse proxy". Per client, all that remains is a DNS record and four variables. |
| **Off-site encrypted backups** | A day | Backups exist and restores are tested, but they sit on the same machine. One dead server, one ransomware event, one bad `docker compose down -v` and the shop is gone. Encrypted, because the backup contains every customer's address and every provider key. |
| **SPF / DKIM / DMARC** | Hours, per client | §85 can now mail thousands of customers. Without these records that mail lands in spam, and anyone can send email pretending to be the shop. You already built the campaign engine; this is what makes it work. **The template half is done** — `docs/DEPLOYMENT.md` → "Email" has the Brevo setup and the exact records, and `wp algerian-commerce mail-check` verifies them. What remains is per client and unavoidable: publish three DNS records on their domain, then run the command. |
| **Privacy notice + lawful basis** | Writing, not code | Algeria's Law 18-07 applies the moment you hold customer data, and you already send hashed emails to Meta. This is a page on the storefront and a decision about consent — cheap now, and awkward to retrofit after complaints. Have counsel or the client confirm the wording. |

### 🟠 Do soon after

| Layer | Effort | Why |
|---|---|---|
| ~~**SSRF (finish it)**~~ | **Done 2026-08-17** | One call changed to `wp_safe_remote_request()`, plus a scan that stops the next call site reintroducing it. |
| **DDoS protection** | Hours, per client | Put Cloudflare in front. Rate limiting protects the *data*; it does not stop the site falling over, because every blocked request still costs a PHP worker. **The repository side is done** — `docs/DEPLOYMENT.md` → "DDoS and Cloudflare", `WP_BIND`, and the client-IP handling that keeps rate limits correct behind a CDN. What remains is the account, the DNS move, and firewalling the origin to Cloudflare's ranges — skip that last step and the VPS still answers on its own address. |
| **Data retention** | Days | Audit logs, inventory movements and payment rows grow forever and hold customer data. Two problems later: the database gets slow, and you cannot honour a deletion request for data you never planned to delete. Write the pruning commands while the tables are still small. |

### 🟡 Do when triggered

| Layer | Effort | Why |
|---|---|---|
| **Data subject rights** | Days | Legally you owe customers access and deletion. Practically, a new shop gets very few such requests, and you can handle the first ones by hand. Wire WordPress's built-in export/erase hooks once requests become regular — or sooner if the client's counsel says so. Do **not** ship a self-service delete button before retention above is settled, or it will miss half the data. |
| **Live webhook verification** | An afternoon | Nothing to build — the verifiers exist. The trigger is a real Yalidine or ZR Express account: set the secret, send one real event, confirm a row lands in `ac_webhook_events`. Until a courier is actually configured there is nothing to verify against, and parcel status stays correct via the poller regardless. |
| **Encryption at rest** | Days–weeks | The honest version: enable full-disk or database-volume encryption on the production host — that is an hour and covers the realistic threat (a stolen disk, a discarded VM). Encrypting individual columns is the expensive version, it breaks searching and sorting on those columns, and it does not protect you from the attack you actually face, which is someone with a valid database login. Do the cheap version at deployment; skip the expensive one unless a client contract demands it. |
| **PCI DSS** | Paperwork | Card details never touch this server — Chargily's hosted page takes them. Your obligation is a short self-assessment (SAQ-A shape) if your acquirer asks, and it requires TLS to be in place first. There is no code to write. Ask the payment provider what they need rather than starting a compliance project. |

### ⚪ Don't build

| Layer | Why not |
|---|---|
| **Two-factor authentication** | The decision already made is the right one. 2FA protects humans typing passwords; your API is a server holding an Application Password, which bypasses 2FA by design. The only surface it helps is `wp-admin`, and **Wordfence** does it properly for free. Configure the plugin at deployment; writing TOTP code here would be effort spent on the wrong door. |
| **Row-level security** | Not possible and not needed. MySQL has no such feature, and even if it did, WordPress talks to the database as one user for every request — so the database cannot tell an admin from a shopper. The check has to live in the application, and it already does (`Permissions::assertOwnsOr()`), tested both ways. |

### If you only do four things

~~1. **TLS**~~ — done 2026-08-17 (§86).
~~4. **The one-line SSRF fix**~~ — done 2026-08-17 (§86).

Two left, and they are the two that can still hurt:

1. **Off-site encrypted backups** — the only gap on this list that can end the business outright. Backups
   and tested restores exist; they sit on the same machine as the thing they protect.
2. **SPF/DKIM/DMARC** — per client, and without them the email feature does not really work. The procedure
   and the `mail-check` verification are written; publishing three DNS records is the client-side step.

Both belong in `docs/DEPLOYMENT.md`, which now exists and has the second.
