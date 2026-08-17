# Deployment

Per-client deployment steps for this template.

> **This document is incomplete.** Email, TLS and DDoS are written. **Off-site encrypted
> backups is the remaining section**, and `docs/SECURITY_AUDIT.md` → "What to do about the
> gaps" is the list it comes from. Roadmap §74–§76 owns it.

**Order matters.** TLS first — everything else assumes it, and §44's Application Passwords are
refused over plain HTTP outside a `local` environment. Then email, then Cloudflare.

---

## TLS and the reverse proxy

`docker compose --profile proxy up -d` adds Caddy in front of WordPress. Without the profile the
stack is exactly what it was: plain HTTP on `WP_PORT`, nothing changed for development.

### Why a proxy at all, when the app could serve HTTPS

Certificates. Caddy gets one from Let's Encrypt on first request and renews it without a cron job,
and an expired certificate on a shop is an outage that looks to the customer like a compromise. It
also gives one place for HSTS and the other response headers, and one place to put Cloudflare's
ranges later.

### 1. Point DNS at the VPS

An `A` record for the domain at the VPS's address. Do this **before** starting the proxy: Caddy
requests a certificate on first use, and Let's Encrypt rate-limits failures.

### 2. Set the four variables

```
SITE_DOMAIN=boutique.dz
ACME_EMAIL=ops@boutique.dz
WP_BIND=127.0.0.1
AC_TRUSTED_PROXIES=172.16.0.0/12
```

**`WP_BIND=127.0.0.1` is a security control, not tidiness.** It stops the WordPress container
publishing to the internet; Caddy reaches it over the compose network instead. A container that
anyone can reach directly is one whose forwarded headers mean nothing, because anyone can write
them — see "What a client may not tell you" below.

`AC_TRUSTED_PROXIES` is the compose network, which is where Caddy sits. **One hop, never a CDN's
ranges**: Cloudflare's list belongs in `docker/Caddyfile`, because Caddy is what talks to
Cloudflare. Each layer knows only its own neighbour, so no layer holds a list that belongs to
another and goes stale unnoticed.

Also set `WP_ENVIRONMENT_TYPE=production`. It is what `scripts/reset.sh` refuses to run against.

### 3. Start it

```bash
docker compose --profile proxy up -d
curl -sI https://boutique.dz/wp-json/algerian-commerce/v1/health | head -1
```

Then tell WordPress its own address, or it will keep emitting `http://` links:

```bash
docker compose run --rm wpcli wp option update home    https://boutique.dz
docker compose run --rm wpcli wp option update siteurl https://boutique.dz
```

### What a client may not tell you

Everything a request claims about itself is attacker-controlled until a trusted proxy vouches for
it. Three layers had to agree, and two of them were wrong by default:

- **`Security\ClientIp`** reads `X-Forwarded-For` only when the TCP peer is in
  `AC_TRUSTED_PROXIES`, and walks the list **right to left** — each proxy appends what it saw, so
  the rightmost untrusted entry is the real client and everything left of it is whatever the
  caller chose to send.
- **`ClientIp::applyForwardedScheme()`**, called from the plugin bootstrap, is what makes
  `is_ssl()` true behind the proxy. It is in the plugin and not in `wp-config.php` because the
  `wordpress` image writes `WORDPRESS_CONFIG_EXTRA` only when it *creates* `wp-config.php` — on
  an install that already exists the setting is accepted and does nothing.
- **Apache's `mod_remoteip` is disabled** by `docker/apache-remoteip-off.conf`. The image ships it
  trusting every RFC1918 range, and in Docker every peer is RFC1918 — so Apache rewrote
  `REMOTE_ADDR` from the header before PHP ran, and no application-level check could tell.
  Measured 2026-08-17: a plain `curl -H 'X-Forwarded-For: 9.9.9.9'` to the published port had its
  failed login counted against `9.9.9.9`.

Without those, rate limiting is opt-out — rotate one header, get a fresh allowance — and the
append-only audit trail records whatever the caller typed. `scripts/test-api.sh` →
"forwarded headers" asserts all of it, with a positive control, because a stack that ignored the
header entirely would pass the negative half.

### Verify

```bash
./scripts/test-api.sh https://boutique.dz
```

---

## DDoS and Cloudflare

Application rate limiting bounds a brute-force or forgery loop. It does **not** absorb a
volumetric attack: every refused request still costs a PHP worker and a database round trip. That
needs something upstream, and Cloudflare's free plan is enough.

This is account and DNS configuration. There is no code, and nothing in this repository changes.

### 1. Move DNS to Cloudflare

Add the domain, point the registrar at Cloudflare's nameservers, and set the `A` record to
**Proxied** (orange cloud). Set SSL/TLS mode to **Full (strict)** — Caddy has a real certificate,
and anything less either breaks or leaves the origin hop unencrypted.

### 2. Let Caddy see through Cloudflare

With Cloudflare proxying, Caddy's peer is Cloudflare, so the address it forwards is Cloudflare's.
Uncomment the `trusted_proxies` block at the end of `docker/Caddyfile` and fill it with the current
list from <https://www.cloudflare.com/ips/>.

### 3. Firewall the origin

**This is the step that makes the rest real.** Until it is done, the VPS still answers on its own
IP address and an attacker who finds it bypasses Cloudflare entirely — and the IP is not a secret,
since it is in DNS history.

```bash
# allow only Cloudflare to 80/443; adjust for the VPS provider's firewall
ufw allow from 173.245.48.0/20 to any port 443 proto tcp
# … one line per range, then:
ufw deny 80/tcp
ufw deny 443/tcp
```

Keep SSH on a separate rule scoped to your own address, and confirm you can still reach it in a
second terminal **before** closing the first.

### 4. Turn on the free protections

Under **Security**: Bot Fight Mode on, and a rate-limiting rule on `/wp-login.php` and
`/xmlrpc.php` — neither is used by this headless backend, and both are the internet's favourite
WordPress targets.

**Do not put Cloudflare's cache in front of `/wp-json/algerian-commerce/v1/`.** The API serves
per-customer data with `Cache-Control: no-store, private` on exports; a shared cache in front of an
authenticated API is how one shopper is served another's order. Add a cache rule that bypasses the
whole namespace.

---

## Email

Every client needs this, and it is the step most likely to be skipped, because skipping it produces
a shop that appears to work. `wp_mail()` returns true whether or not anyone accepts the message.

### Why it cannot be templated away

The credentials and the DNS records both belong to the client, not to this repository:

- **SMTP credentials** are per account — five values in `.env`, no code.
- **SPF, DKIM and DMARC are DNS records on the client's own domain.** This repository cannot publish
  them, and neither can any amount of PHP.

What *is* templated is the shape: the same provider every time, the same five variables, the same
three records, and one command that checks all of it.

### 1. Choose the sending address first

Set `AC_MAIL_FROM` to an address on **the client's own domain**:

```
AC_MAIL_FROM=commandes@boutique.dz
```

This decides everything below, because SPF and DKIM authenticate the From domain and nothing else.
That is also why `Campaigns\CampaignInput` refuses a per-campaign `from` — a campaign that could pick
its own sender could pick one the DNS does not cover.

**Never use a gmail.com, yahoo.com or outlook.com address here.** You cannot publish DNS for a domain
you do not control, so mail from it fails DMARC at every major receiver — and Gmail and Yahoo both
reject unauthenticated bulk mail outright now, so §85's campaigns would not arrive at all.

### 2. Create the Brevo account and get SMTP credentials

Brevo is the provider this template is set up for: a free tier that covers transactional mail, one
account that also sends §85's campaigns, and plain SMTP, so nothing here needs an adapter.

In Brevo, go to **SMTP & API → SMTP** and copy the login and the SMTP key. Then in the client's `.env`:

```
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_USERNAME=<the login shown on that page>
SMTP_PASSWORD=<the SMTP key from that page>
```

Two traps, both of which produce an authentication failure that reads like a wrong password:

- The **username is not the account email address** — Brevo shows a separate login on that page.
- The **password is the SMTP key, not the account password**.

Restart the stack afterwards. A value in `.env` reaches the plugin only through `compose.yaml`, and
the containers read their environment at start.

> **The free tier is sized for transactional mail, not campaigns.** It covers order confirmations and
> password resets comfortably. A campaign to a few thousand customers will exceed a daily cap in one
> send, so a client who starts sending campaigns needs a paid tier — or Amazon SES, which is far
> cheaper at volume. `AudienceResolver::MAX_AUDIENCE` already bounds an audience, so this shows up as
> a refusal rather than a half-sent campaign.

### 3. Publish the three DNS records

On the client's DNS, for the domain in `AC_MAIL_FROM`. Brevo shows the exact values under
**Senders, Domains & Dedicated IPs → Domains** after you add the domain — use those, not these,
since the DKIM key is generated per domain.

| Record | Host | Value | Who gives it to you |
|---|---|---|---|
| **SPF** | `boutique.dz` (the apex) | `v=spf1 include:spf.brevo.com ~all` | Brevo |
| **DKIM** | `brevo._domainkey.boutique.dz` | the long `v=DKIM1; k=rsa; p=…` string | Brevo, per domain |
| **DMARC** | `_dmarc.boutique.dz` | `v=DMARC1; p=none; rua=mailto:dmarc@boutique.dz` | **You write this one** |

**If the domain already has an SPF record, merge into it — do not add a second.** RFC 7208 makes more
than one `v=spf1` record a permanent error, so adding one breaks the SPF that was already working:

```
v=spf1 include:spf.brevo.com include:_spf.google.com ~all
```

### 4. Roll DMARC forward, in that order

Start at `p=none` and do not skip ahead. `p=none` enforces nothing — it only asks receivers to send
reports — which is exactly what you want while you find out whether anything else sends mail as this
domain (an invoicing tool, a contact form, the client's own Outlook).

```
p=none          → collect reports for a week or two
p=quarantine    → failures go to spam
p=reject        → failures are refused outright
```

Going straight to `p=reject` is how a shop's own order confirmations disappear on day one, with no
error on this side.

### 5. Verify

```bash
docker compose run --rm wpcli wp algerian-commerce mail-check
```

It prints the SMTP settings, then the sending domain and its three records:

```
Sending domain: boutique.dz
+--------+---------------------------------+--------+-----------------------------------------------+
| record | host                            | status | detail                                        |
+--------+---------------------------------+--------+-----------------------------------------------+
| SPF    | boutique.dz                     | ok     | v=spf1 include:spf.brevo.com ~all             |
| DKIM   | brevo._domainkey.boutique.dz    | ok     | Public key published.                         |
| DMARC  | _dmarc.boutique.dz              | ok     | p=none — monitoring only. Move to quarantine… |
+--------+---------------------------------+--------+-----------------------------------------------+
```

Then send a real message, which is the only check that fails for the real reason:

```bash
docker compose run --rm wpcli wp algerian-commerce mail-check --to=you@example.com
```

Useful flags:

- `--dkim-selector=<name>` — for a provider that is not Brevo. The report always names the host it
  queried, so a wrong selector is visible rather than looking like a missing record.
- `--skip-dns` — skip the lookups entirely.

**What the statuses mean.** `missing` and `problem` are worth acting on. `unknown` means the container
could not resolve DNS at all — it is not evidence that a record is absent, and the command says so
rather than sending you to fix DNS that is already correct.

**`mail-check` warns and never fails the deploy.** A shop mid-setup is a normal state, and mail is not
required to take orders. It exits non-zero only when `--to` was given and the send was refused.

### The failure this section exists to prevent

Working SMTP credentials with no DNS is the expensive state, and nothing on this side reports it:
`wp_mail()` returns true, the notification queue drains, `ac_campaigns` records a successful send, and
the mail goes to spam. Before §85 that cost one password reset. Now it costs a campaign to the entire
customer list, and repeated spam complaints damage the domain's reputation for months afterwards —
which is also why the unsubscribe link in every campaign is mandatory and unthrottled.
