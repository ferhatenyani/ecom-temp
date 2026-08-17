# Deployment

Per-client deployment steps for this template.

> **This document is incomplete.** Only the email section below is written. TLS termination,
> off-site encrypted backups and upstream DDoS filtering are the remaining sections, and
> `docs/SECURITY_AUDIT.md` → "What to do about the gaps" is the list they come from. Roadmap
> §74–§76 is the section that owns them.

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
