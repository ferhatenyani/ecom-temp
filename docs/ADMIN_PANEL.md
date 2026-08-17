# Admin Panel — Build Specification

Roadmap §4 step 43, PLAN §52. This is the document to read before writing a line of the admin
panel, and the one to read **after** [docs/API.md](API.md), never instead of it.

The panel lives in its own repository — `ecom-admin`, a sibling of this one, on the WSL ext4
filesystem. This document is the specification it is built from. Everything in Part I is work that
happens **here**, in this repository, before the panel repository is created.

---

## What this document is not

**It never restates a field list.** `docs/API.md` is the contract and `scripts/test-api.sh` verifies
it against the live router; a second copy of `product.regular_price` in this file would be a copy
that goes stale, and §68's argument is that the copy is what drifts. Where a screen needs a shape,
this document names the route and the section of `docs/API.md` that owns it.

What this document owns is everything the API does not decide: how a staff member signs in, what the
panel is allowed to render, how it behaves in Arabic, on a phone, on a dying connection — and the
design rules that stop it looking like every other generated dashboard.

---

## Decisions already made

Six, each settled before writing. They are not open for re-litigation mid-build; changing one means
changing this document first.

| Decision | Choice | Because |
|---|---|---|
| Staff sign-in | **Per-user WordPress Application Password** | The audit trail names a real person and `/auth/me` drives what each role sees. A shared service credential collapses §45's whole matrix into one account. |
| Languages | **French and Arabic, full RTL** | The staff who use this are Algerian. Retrofitting RTL means touching every component, so it is direction-agnostic from line one. |
| Missing routes | **Built in this repository first** | PLAN §52 says routine administration must not require wp-admin. Four of its areas have no API; Part I is that work. |
| Styling | **Tailwind v4 with a token-only theme** | Fast to write, and the arbitrary-value escape hatch is closed by a CI check rather than by good intentions. |
| Typeface | **IBM Plex Sans + IBM Plex Sans Arabic** | One designed system across both scripts. Open licence, self-hostable, variable. SF Pro cannot be self-hosted for the web outside Apple platforms, and the system stack is generic by definition. |
| Offline | **Installable PWA, read-cached** | Staff use this on mobile data in warehouses and vans. Writes still need a connection and say so. No offline write queue — see [Part VI](#part-vi--pwa-and-offline). |

---

## The non-negotiables

These come from the person who commissioned the panel and they are constraints on every screen, not
preferences to weigh against others.

1. **No AI slop.** No component library whose look is the house style of generated UI. No
   `shadcn/ui`, no MUI, no Chakra, no Ant. Primitives are written here. Radix or Ark is permitted for
   *behaviour only* — focus traps, dismissal, ARIA wiring — with every visual property ours.
2. **The `impeccable` skill is invoked before any UI is written**, and the Apple/iOS design skill
   supplies the platform grammar. This document supplies the constraints those skills work inside; it
   does not replace them.
3. **No gradients.** Not on buttons, not on cards, not on charts, not as a "subtle" background wash.
   `backdrop-filter: blur()` on a navigation material is **not** a gradient and is permitted — it is
   how iOS actually renders those surfaces. The distinction is that a blur samples what is behind it;
   a gradient invents a colour ramp.
4. **No accent bars.** No 3px coloured left border to mark a status, a category or a selection.
   [Part III](#status-without-an-accent-bar) specifies what to do instead, because a banned pattern
   with no replacement gets reinvented.
5. **No generic fonts.** IBM Plex Sans and IBM Plex Sans Arabic, self-hosted, subset, variable. The
   fallback stack exists only to cover the milliseconds before the font loads, and it is
   metric-adjusted so nothing reflows.
6. **Mobile first.** Every screen is designed at 390 × 844 and then allowed to grow. A layout that
   only works once there is a sidebar is not finished. The primary navigation is a bottom tab bar
   within thumb reach, not a hamburger drawer.
7. **iOS-like.** Grouped inset lists, large titles that collapse, sheets with detents, segmented
   controls, action sheets for destructive choices, 44 pt targets, spring easing. Detail in
   [Part III](#part-iii--the-design-system).

A rule nobody enforces is a preference. [Part VII](#part-vii--testing-and-checks) specifies
`scripts/check-design.sh`, which fails the build on 1, 3, 4, 5 and on physical-direction properties.

---

# Part I — Backend prerequisites

Four route groups PLAN §52 requires and this API does not have. They are built **here**, on their own
branches, before the panel repository exists — the panel is then written against a complete API
rather than around holes in one.

Numbering continues the roadmap. `Schema::VERSION` does not move: none of these needs a migration.

Every one of them follows the rules already in force. Input objects refuse unknown fields **by name
with the reason** — the `CustomerInput` device. Writes are audited. Nothing new is invented where an
existing capability fits, per §61's media precedent. Each gets a `tests/Api/` suite with a positive
control beside every refusal, per §65.

---

## §87 — Staff users and roles

`ac_manage_users` has been declared in `Permissions\Capabilities` since §45 and has **zero call
sites**. It is the only capability in the vocabulary that gates nothing, which means Super Admin's
defining privilege — the one that makes it different from Admin — is currently unexercisable through
the API.

### Routes

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/users` | `ac_manage_users` |
| GET, PATCH, DELETE | `/users/{id}` | `ac_manage_users` |
| GET, POST | `/users/{id}/application-passwords` | `ac_manage_users` |
| DELETE | `/users/{id}/application-passwords/{uuid}` | `ac_manage_users` |
| GET | `/roles` | `ac_manage_users` |

> **Built.** `src/Users/` — ten classes, no migration, no table, no new capability.
> `docs/API.md` → "Staff users and roles" is the contract; `tests/Api/users.php` is 89 assertions.
> What follows is the design; where it and the code disagree, the code is right and the note says so.

### `/roles` is GET-only, and that is the design

No route creates a role. `Capabilities::roles()` is pure data, unit-tested, and the single source of
the matrix; a role invented at runtime and stored in the options table is a capability set nobody
tested and that no test can enumerate. `GET /roles` returns the seven roles with their capabilities
and their display names — enough for a role picker, and nothing that can drift.

The same argument that kept `ac_analytics_aggregates` out of §63 and a version table out of §68.

### `/users` manages staff, and only staff

A user holding no `ac_*` capability is a **customer** and belongs to `/customers`, which already
exists and already refuses `roles`, `capabilities` and `user_pass` by name. `GET /users` therefore
filters to staff accounts, plus WordPress administrators.

> **Corrected in the build: staff is defined by *role*, not by capability.** This section first said
> "at least one capability from `Capabilities::ALL`", and the two rules give different answers. A
> capability granted directly to one account — by another plugin, or by hand — makes it staff under
> the capability test while remaining unfindable by `WP_User_Query`, which filters on roles. One rule
> the list and the single read can both honour is worth more than a wider rule the list cannot, so an
> account is staff when it holds one of §45's seven roles or `administrator`. `UserRoles::staff()`.

That split has to be enforced on the way in as well, or the panel gains a second door onto customer
records with a different permission model. Two rules:

- **`POST /users` requires a role**, and it must be one of the seven. An account created with no role
  is a customer created through the wrong endpoint.
- **`PATCH /users/{id}` refuses to remove the last role**, because the result would be a customer that
  `/users` can no longer see and `/customers` never expected to receive.

**Promoting an existing customer to staff is permitted and audited loudly.** It is a real operation —
the shop owner's own account is often a customer first — but it changes what §44 says about that
account: a customer receives no Application Password, and a staff member does. The audit event names
both the old and the new role, and the response says so in `meta.promoted_from_customer`.

### Privilege escalation, which is the whole risk of this endpoint

§45's test list names it explicitly. Five refusals, each with its reason in the error body:

| Refused | Why |
|---|---|
| Assigning `administrator` or any core WordPress role | A core administrator installs plugins and edits files. This API manages commerce roles; it does not hand out the platform. |
| Granting a role holding capabilities the caller lacks | Trivially true for Super Admin today, and the rule is what stops a future eighth role from being an escalation path. |
| Changing your own role | The only way to demote yourself out of the capability you need to undo it. |
| Deleting yourself | Same shape, worse outcome: a shop with no Super Admin has no route back except wp-admin. |
| `user_pass`, `capabilities`, `user_login` | A password set by somebody else is a password the account holder cannot trust. Capabilities come from the role. A login is an identity, not a field. |

`DELETE /users/{id}` additionally refuses when the account owns orders — `wp_delete_user()` reassigns
posts, and HPOS orders keyed to a deleted `customer_id` become orphans no report can attribute.
Deactivation is the operation people actually want: `PATCH` the role to none is refused above, so
provide `status: active|suspended` as user meta, and a suspended account's credentials answer **401**
at every route.

> **Corrected in the build: the check is a `rest_pre_dispatch` guard, not `AuthService`.** That
> service only answers `/auth/me`, so a check there would leave every other route open. The obvious
> alternative, `rest_authentication_errors`, is where core reports a bad credential — but
> `rest_do_request()` does not fire it, so every in-process suite in `tests/Api` would be blind to
> the guard and a security property only the HTTP stage can see is one that gets verified once.
> `rest_pre_dispatch` fires for both, which is why `RateLimitGuard` already uses it.
> `Users\SuspensionGuard` runs there at priority 9, ahead of the rate limiter at 10, so a refused
> account does not spend anyone's allowance.

### Minting an Application Password is the onboarding step

This is why the endpoint exists at all. A WordPress Application Password is displayed exactly once,
at creation, in wp-admin — which is the dashboard PLAN §52 says staff should not need. So:

```
POST /users/{id}/application-passwords   { "name": "Admin panel — Karim's iPhone" }
→ 201 { "uuid": "…", "name": "…", "password": "abcd EFGH ijkl MNOP qrst UVWX", "created": "…" }
```

**The password appears in that one response and nowhere else, ever.** Not in the audit event, not in
a log, not on `GET`. The `GET` collection returns name, uuid, created and last-used, which is what a
"revoke this device" screen needs. `last_ip` is stored by WordPress and deliberately not published:
it is the one field here describing a person rather than a credential.

> **Corrected in the build: `Logger` needed no change.** This section said
> `Logger::SENSITIVE_EXACT` gains the field name. It does not — `password` is already in
> `Logger::SENSITIVE`, the *substring* list, so any key containing it is masked and has been since
> §41. The exact list exists for words like `label` that are ordinary English and cannot be matched
> as substrings. The service still never puts a plaintext password in an audit payload, because
> being correct only by virtue of a redactor elsewhere is how the redactor's next edit becomes a
> leak — and `tests/Api/users.php` asserts the outcome (the minted string appears nowhere in the
> trail, under any key) rather than the mechanism.

`DELETE` revokes one. A revoked password stops working immediately, which is the panel's real logout
for a lost phone — a sealed cookie the panel cannot reach is not something the panel can invalidate.

Rate limiting already covers the abuse case: `RateLimitGuard` hooks
`application_password_failed_authentication`, so brute-forcing a minted password hits
`AC_RATE_LIMIT_AUTH_FAILURES` at 10 per 15 minutes per IP.

### Audit

Every write here is audited, and the resource is the *target* user, not the caller: `user.created`,
`user.role_changed`, `user.suspended`, `user.reactivated`, `user.updated`, `user.deleted`,
`user.app_password_created`, `user.app_password_revoked`.

One PATCH can write up to three of them, because they are three separately queryable facts: a single
`user.updated` carrying "role and status both changed" is a row nobody can filter for, and those are
the two questions an audit trail is opened to answer.

> **Corrected in the build: a role change records values, not just field names.** This section said
> "field names, never values — §71's rule". That rule exists so a shop's trade-register numbers stay
> out of a table nobody cleans, and it still governs everything else here — an email change records
> `fields: ["email"]` and no address. But for a role the value *is* the security fact, and "somebody's
> role changed" answers none of the questions the trail is read to answer. `user.role_changed` carries
> `from` and `to`.

---

## §88 — Product attributes

`GET /product-categories` exists; nothing manages global attributes. §82 made that gap load-bearing:
**only a global attribute can be filtered or counted**, so a shop with no global attributes has a
faceted search that can never return a facet, and the API's own advice — a 400 listing
`details.facetable_attributes` — currently lists what the shop happens to have, with no way to add to
it outside wp-admin.

### Routes

| Method | Route | Guard |
|---|---|---|
| GET, POST | `/attributes` | `ac_manage_products` |
| GET, PATCH, DELETE | `/attributes/{id}` | `ac_manage_products` |
| GET, POST | `/attributes/{id}/terms` | `ac_manage_products` |
| PATCH, DELETE | `/attributes/{id}/terms/{term_id}` | `ac_manage_products` |

> **Built.** `src/Products/` — six classes, no migration, no table, no new capability.
> `docs/API.md` → "Global attributes" is the contract; `tests/Api/attributes.php` is 59 assertions.

No new capability. An attribute is part of the catalogue and `ac_manage_products` already writes
products, variations and the attribute *assignments* on them.

### The trap this section must not fall into, which is already written down

CLAUDE.md records it for §82's fixtures and it applies verbatim here:

> **An attribute created in the same process cannot be counted.** `wc_create_attribute()` writes the
> row, but the taxonomy is registered on `init` from the `$wc_product_attributes` global, so
> `taxonomy_is_product_attribute()` is false for the rest of the request. The attribute is
> registered, queryable and invisible to the facet counter, answering 200 with an empty list.

So `POST /attributes` must:

1. call `wc_create_attribute()`, then `register_taxonomy()` for the new name in the same request, so
   terms can be added immediately;
2. bust `AttributeCatalogue`'s memoised `facetable()` map, or the next call in the same request
   reports the attribute as unfacetable;
3. report what is actually true about facets, so the panel never shows an empty facet the developer
   blames the facet code for.

A live shop meets this once, at setup. A test fixture meets it every run.

> **Corrected in the build: the trap closes completely, and step 3 was pessimistic.** This section
> expected `meta.facets_available: false`, assuming a fresh attribute could not be counted.
> Registering the taxonomy *and* setting the `$wc_product_attributes` global —
> `taxonomy_is_product_attribute()` checks both — makes it filterable and countable in the same
> request. Verified in one process: create → term created 201 → the attribute appears in
> `meta.facets.attributes.facetable`. The response carries `meta.filterable: true` and a note that
> counts cover published products, which is the honest remaining caveat.
>
> **WooCommerce's own REST controller does not do this** (read at 11.0.1), so `wc/v3` still cannot
> take a term on a just-created attribute. Its registration could not be reused either —
> `WC_Post_Types::register_taxonomies()` returns early on `taxonomy_exists('product_type')` — so the
> taxonomy is registered with the minimum the write path needs, for one request, rather than by
> copying sixty lines of labels and rewrite rules that would drift on the next upgrade.

### Deleting

`DELETE /attributes/{id}` is refused with **409** while any product uses the attribute, listing a
count and the first few product ids. WooCommerce's own delete removes the taxonomy and orphans every
variation that resolved through it, which surfaces later as variations that cannot be matched — a
failure with no error and a long delay between cause and symptom.

`?force=true` overrides, as it does elsewhere in this API, and the audit event records the count that
was overridden.

### Terms

Term slugs are what `GET /products?attributes[pa_size]=m` matches, so renaming a slug breaks every
saved filter and every storefront link. `PATCH` on a term accepts `name` and `description` freely and
treats `slug` as a **separate, explicit** field whose change is audited and whose response carries
`meta.slug_changed: true`. Not refused — sometimes a slug is genuinely wrong — but never incidental.

**Deleting a term is guarded the same way as deleting the attribute**, which this section did not
say: it detaches every product on that term and breaks any variation that resolved through it, so it
is a 409 with the count and `?force=true` overrides. The argument does not get weaker one level down,
and the term case is the likelier of the two to be hit.

> **What §88 found, and it was not in this section: the URL was decorative on every write route.**
> `WP_REST_Request::get_param()` reads the JSON body before the URL, so `PATCH /products/1801` with
> `{"id": 1802}` edited product 1802, and no sub-resource's read body could be PATCHed back. Fixed
> centrally in `AbstractController::pinRouteParams()`; asserted in `tests/Api/security.php`. The
> panel is unaffected in behaviour — it never sends a conflicting id — but Part V's "GET then PATCH
> the whole object" advice depended on it.

> **§89 must not name its page-rename field `slug`.** Pinning makes the URL authoritative for every
> param the route captured, and `/cms/pages/(?P<slug>…)` captures `slug` — so a write payload
> carrying `slug` would have it silently overwritten by the path and answer 200 having renamed
> nothing. Use `path` (the parameter already takes a full path, so the name is more honest anyway) or
> a distinct `new_path`. `tests/Api/security.php` fails the build on any write route addressed by a
> name that is not an id and not listed with its reason, so this decision is forced at the moment the
> route is registered rather than discovered afterwards. Verified against a route of exactly that
> shape.

---

## §89 — CMS writes

§61 built the read half and named the reason the write half was missing: *"a write surface is PLAN
§52's admin coverage, not this."* This is that.

### Routes

| Method | Route | Guard |
|---|---|---|
| PUT | `/cms/homepage` | `ac_manage_content` |
| POST | `/cms/pages` | `ac_manage_content` |
| PATCH, DELETE | `/cms/pages/{slug}` | `ac_manage_content` |
| POST | `/cms/banners` | `ac_manage_content` |
| PATCH, DELETE | `/cms/banners/{id}` | `ac_manage_content` |
| POST | `/cms/faqs` | `ac_manage_content` |
| PATCH, DELETE | `/cms/faqs/{id}` | `ac_manage_content` |
| GET, POST | `/cms/faq-categories` | `ac_manage_content` |
| PATCH, DELETE | `/cms/faq-categories/{id}` | `ac_manage_content` |
| PUT | `/cms/menus/{location}` | `ac_manage_content` |

> **Built.** `src/CMS/` — seven new classes, no migration, no table, no new capability.
> `docs/API.md` → "CMS" is the contract; `tests/Api/cms.php` is 155 assertions.
> What follows is the design; where it and the code disagree, the code is right and the note says so.

`{slug}` keeps taking a **full path** — `legal/terms` — for the reason §61 gives, and `POST
/cms/pages` therefore takes `parent_path` rather than `parent_id`, so a client that only ever saw
paths never has to learn ids.

> **Corrected in the build: the capture is `{path}`, and the routes are ten rather than nine.** The
> table above still writes `{slug}`, which is the collision §88 predicted three paragraphs earlier:
> `pinRouteParams()` would overwrite a body's `slug` with the path and answer 200 to a rename that
> renamed nothing. So the capture took the name it always deserved — it has held a full path since
> §61 — and the body renames with `slug` and moves with `parent_path`. `GET /cms/faq-categories` was
> also missing from the table: `POST` is listed, so a panel could create a category it had no way to
> list, and `FaqInput` refuses a category that does not exist.
>
> **`status` is accepted on write and every read takes `?status=`.** This section does not mention
> drafts, and without them `POST /cms/pages` with no way to stage a page answers 201 for a resource
> whose `GET` is a 404 — the silent-failure shape the rest of Part I is written against. The default
> is `publish`, so §61's read contract and every existing caller are unchanged, and `any` means
> publish plus draft and never the trash.

### `wp_kses` runs on save, not on read

§85 settled this for email templates and the argument is identical: stored content is re-rendered,
and the first place a stored XSS fires is the content manager's own preview. Every HTML field —
page content, banner copy, FAQ answers — goes through `wp_kses` with an allowlist on the way in. A
sanitiser on the way out is one that a second reader (the storefront, an export, a search indexer)
does not run.

The allowlist here is wider than §85's email-safe one — a page may carry a table and a figure — and
narrower than `wp_kses_post`: no `<script>`, no `<iframe>`, no `on*`, no `javascript:` or `data:` in
any href or src, and no `<style>`.

> **Corrected in the build: this is not belt-and-braces, and the reason is a clause in §61.** That
> section recorded that WordPress "already runs `wp_kses_post` over anything saved by a user without
> `unfiltered_html`" — which is true, and an **administrator holds `unfiltered_html`**, so
> `kses_init()` removes every filter for exactly the caller most able to do damage. Measured
> 2026-08-17: `wp_insert_post()` as an administrator stored `<script>alert(1)</script>` and an
> `onclick` byte for byte; the same call as a Marketing Manager did not. `tests/Api/cms.php` therefore
> runs its XSS assertions **as an administrator**, against the stored row rather than the response,
> with that measurement as the control beside them.
>
> **The homepage document is sanitised too, which this section does not ask for.** It names page
> content, banner copy and FAQ answers — all fields. The homepage has none: a section's `data` is
> free-form, so there is nothing to point an allowlist at, and a `<script>` in a `text` section would
> be stored verbatim. Running `wp_kses` over every string leaf was the obvious fix and is wrong:
> measured the same day, it rewrites `Tapis & Kilims` to `Tapis &amp; Kilims` and `?a=1&b=2` to
> `?a=1&amp;b=2`. So `ContentHtml::looksLikeMarkup()` routes a leaf to the allowlist only when it
> carries something that will parse as a tag. The named cost is that `a <b` in prose is treated as
> markup.

### The homepage is edited whole, and the drop report becomes a refusal

`GET /cms/homepage` already reports malformed sections it had to drop, in `meta`, because an option
edited by hand fails silently otherwise. On **write**, a malformed section is a **400** naming its
index and its problem — `sections[2].type` — because at that point there is a human with a form who
can fix it, and dropping their work quietly is the one failure a content manager cannot diagnose.

`PUT` replaces the document. There is no section-level route: sections are ordered, and an API that
lets two clients insert at index 2 concurrently has invented a merge problem the shop does not have.
The panel sends the whole array; the response carries the stored document back.

### Menus

`PUT /cms/menus/{location}` replaces the whole menu for `primary` or `footer` with an ordered tree:

```json
{ "items": [
  { "label": "Tapis", "type": "category", "object_id": 21, "children": [] },
  { "label": "Conditions", "type": "page", "path": "legal/terms", "children": [] },
  { "label": "Instagram", "type": "url", "url": "https://…", "children": [] }
] }
```

Two levels deep, maximum 50 items. WordPress nav-menu items are posts with meta and an ordering
field, and exposing that shape would make the panel implement WordPress's data model instead of the
shop's. `type` is `page`, `category`, `product` or `url`; a `url` must be `http` or `https`, per
§71's rule about `javascript:` being a valid URL.

> **Corrected in the build: the writer also accepts what the reader returns, and a refused write
> touches nothing.** `CmsPresenter::menu()` has published WordPress's vocabulary since §61 — `type` is
> `post_type` with `object: page`, and the label is `title` — and "GET the menu, drag one item, PUT it
> back" is the only interaction a menu screen has. Accepting only the shape above would have made
> `docs/API.md`'s round-trip promise have an exception in it, and changing the read shape would have
> broken every existing caller. So `MenuInput` normalises both. A root-relative `/soldes` is accepted
> alongside `http`/`https`, because a storefront's own routes carry no scheme; `//host` is not.
>
> **A missing menu is created and assigned**, which this section leaves unsaid: `get_nav_menu_locations()`
> on this install returned `primary` and no `footer`, and a PUT that 404ed until somebody opened
> Appearance → Menus would be useless for the case it exists for.
>
> **The first version emptied the menu before it validated the tree** — it deleted the existing items,
> then resolved each page path as it wrote, so a payload naming one page that did not exist destroyed
> a shop's navigation and answered 400. Every reference is now resolved before anything is deleted.
> Two more of the same shape were found with it (an FAQ created and *then* refused for an unknown
> category; a page created and then refused for a bad `seo.image_id`), which is what made it a rule:
> **resolve every reference before the first write.**

### SEO writes through the resource

Unchanged from §62. A page's `seo` block is written by `PATCH /cms/pages/{slug}`, and SEO errors land
in the same `details.fields` list as the rest of the write. There is no SEO endpoint and this section
does not add one.

---

## §90 — The notification queue, read and retry

PLAN §52 lists Notifications. §59d deliberately built none: *"§29 asks for an abstraction, not an
endpoint."* That was right for sending, and it leaves an operator with no way to answer "did the
customer get their confirmation?" without `wp eval`.

### Routes

| Method | Route | Guard |
|---|---|---|
| GET | `/notifications` | `ac_manage_customers` |
| GET | `/notifications/{id}` | `ac_manage_customers` |
| POST | `/notifications/{id}/retry` | `ac_manage_customers` |

### The capability is `ac_manage_customers`, and no new one is invented

A notification row holds a customer's email address and the frozen message body, which on an order
confirmation contains their name and what they bought. §63's rule applies — *reporting may not
disclose in aggregate what the caller cannot already read in detail* — and the capability that
already reads a customer's record is the honest gate. §61's media gap set the precedent for not
inventing a capability to close a hole.

This is read-and-retry only. **Nothing sends on a request path**, which was §59d's whole design and is
stronger here than anywhere: an SMTP server that hangs would hang the panel. `retry` clears the row's
`status` and `attempts` so the next drain picks it up, and answers `202` with the drain command name.
It never mails.

`GET /notifications` filters by `channel`, `status`, `dedupe_key` and date range, and orders newest
first. The list omits the message body; `GET /notifications/{id}` carries it, so a support agent
scanning a queue does not pull five hundred customers' order contents into one response.

---

## Part I build order

Four branches, in this order, each with its `tests/Api` suite before the merge:

```
feat/admin-users        §87   users, roles, application passwords
feat/attributes         §88   global attributes and terms
feat/cms-writes         §89   pages, banners, FAQs, menus, homepage
feat/notification-queue §90   read and retry
```

`docs/API.md` is updated in the same branch as each — `scripts/test-api.sh` → "documented contract"
fails the build otherwise, which is the point of it.

---

# Part II — Panel architecture

## The stack

| Concern | Choice | Note |
|---|---|---|
| Framework | Next.js 15+, App Router, TypeScript `strict` | Server Components for first paint, Route Handlers as the credential boundary. |
| Styling | Tailwind CSS v4, `@theme` tokens only | [Part III](#part-iii--the-design-system). Arbitrary values fail CI. |
| i18n | `next-intl` | ICU messages, `/[locale]/…` routing, `dir` from the locale. |
| Server state | TanStack Query v5 | For lists, filters and mutations. RSC renders the first screen; the client owns everything after it. |
| Forms | React Hook Form + Zod | Zod schemas are also what parse API responses at the boundary. |
| Behaviour primitives | Radix (or Ark) — unstyled only | Dialog, popover, select, tabs. Every visual property ours. |
| Charts | `visx` or hand-rolled SVG, per the `dataviz` skill | Flat fills only. No gradient area charts. |
| Icons | One set, outline, 1.5 px stroke, self-hosted SVG sprite | Never an icon font. |
| Session sealing | `jose` (JWE, A256GCM) | [below](#the-session-is-a-sealed-cookie). |
| Tests | Vitest + Testing Library, Playwright | Plus `scripts/check-design.sh`. |

No CSS-in-JS runtime. No component library. No `next/font/google` — the fonts are in the repository,
because a build that fetches a font from a third party at deploy time is a build that fails when that
third party does.

## Repository layout

```
ecom-admin/
  app/
    [locale]/
      (auth)/login/
      (panel)/
        dashboard/  orders/  products/  inventory/  customers/
        coupons/    shipping/ payments/ analytics/  content/
        media/      marketing/ campaigns/ notifications/
        settings/   users/    audit/    import-export/
      layout.tsx
    api/
      ac/[...path]/route.ts     ← the only place a credential is attached
      session/route.ts          ← login, logout
  lib/
    api/        client.ts, envelope.ts, errors.ts, schemas/
    session/    seal.ts, read.ts
    capabilities.ts
    format/     money.ts, date.ts, number.ts
  components/
    primitives/ Button, Field, Sheet, List, Segmented, Toast, …
    patterns/   DataList, DetailScaffold, FilterBar, EmptyState, …
  messages/     fr.json, ar.json
  styles/       tokens.css, globals.css
  public/       fonts/, icons/, manifest.webmanifest
  scripts/      check-design.sh
```

`components/primitives` holds things with no knowledge of commerce. `components/patterns` holds the
five or six compositions every screen reuses. A component that knows what an order is lives beside
the route that renders it, not in `components/`.

## The credential boundary

```
browser  →  Next.js Route Handler  →  /wp-json/algerian-commerce/v1
            (attaches Authorization: Basic)
```

The browser never holds an Application Password, never sees one in a payload, and never talks to the
WordPress host directly. This is roadmap §19 and §44 and it is the rule the whole architecture rests
on: a leaked Application Password is full admin access to the shop.

Consequences worth stating because each has been got wrong somewhere before:

- **`AC_CORS_ORIGINS` is irrelevant to the panel in production.** Requests come from the Next.js
  server, which is not a browser and sends no `Origin`. If you find yourself adding the panel's
  origin to the allowlist to make something work, something is calling the API from the browser and
  that is the bug.
- **No `NEXT_PUBLIC_*` variable ever holds a credential.** The only public variables are the locale
  list and the panel's own base URL.
- **The proxy is an allowlist, not a pass-through.** `app/api/ac/[...path]/route.ts` matches the
  incoming path against a list of permitted route patterns before forwarding. A generic proxy that
  forwards anything under `/wp-json/` is an open relay to `/wp/v2/users` with an admin credential
  attached — the exact thing `docs/API.md` opens by telling you not to touch.
- **The proxy strips response headers it does not need** and never forwards `Set-Cookie` from
  WordPress in either direction.

## The session is a sealed cookie

Login collects a WordPress **username** and that user's **Application Password**.

```
POST /api/session      { username, password }
  → server calls GET /auth/me with Authorization: Basic
  → 200: seal { username, password, userId } as JWE, set cookie, return the identity
  → 401: return 401. Do not say whether the username exists.
  → 429: return 429 with Retry-After — this is the API's failed-login bucket and it is real
```

The cookie:

| Attribute | Value | Why |
|---|---|---|
| name | `ac_admin_session` | |
| `httpOnly` | true | The panel's own JavaScript must not be able to read a credential either. |
| `secure` | true outside development | |
| `sameSite` | `lax` | `strict` breaks returning from an external payment provider's dashboard; `lax` is sufficient because every mutation is a POST from same-origin script. |
| `path` | `/` | |
| max age | 12 hours, rolling | A warehouse phone left on a bench is the threat model. |
| payload | JWE, `A256GCM`, key from `SESSION_SECRET` | Encrypted, not signed — the payload *is* the credential. A signed-but-readable cookie hands the password to anyone who reads a proxy log. |

Rotate the seal on every response so the 12 hours are since last use. Clear on logout, and clear on
any **401** from the API — the Application Password was revoked or the account was suspended, and
holding a dead credential produces a panel that renders and then fails on every action.

A **403** is not a logout. It means this role cannot do this thing, and the correct response is the
forbidden state described in [Part V](#every-screen-has-five-states), not a bounce to the login page.

### What the panel cannot do about a lost device

Nothing, directly — and it should say so. Revoking a session means revoking the Application Password,
which is §87's `DELETE /users/{id}/application-passwords/{uuid}`. The account screen lists a user's
passwords by name and last-used date and offers exactly that. This is why §87 mints them with a
device name in the first place.

## Capabilities are for rendering, never for access

`GET /auth/me` returns the caller's `ac_*` capabilities, filtered to this plugin's vocabulary. The
panel uses it to decide which tabs exist, which buttons render and which routes redirect.

`AuthController` says it in its own docblock and it bears repeating here: **a client that hides a
button is a convenience, not a security boundary.** Every route enforces its own
`permission_callback`; the panel must be built so that a user who types a URL they cannot use gets a
clean forbidden screen rather than a broken one.

Two capability rules are compound and get missed:

- **Money in analytics needs `ac_view_analytics` *and* `ac_manage_orders`.** Without the second,
  `/analytics/revenue` is 403 and every other analytics response omits its money block and says so in
  `meta.money_visible`. The dashboard must render a coherent screen in that state — a Support Agent
  sees counts and rates, and no empty currency-shaped holes.
- **Sending a campaign needs `ac_manage_marketing` *and* `ac_manage_customers`.** A Marketing Manager
  can draft, preview and test-send, and gets a 403 from `send`. `docs/API.md` says it outright: *a
  403 from `send` with a 201 from `POST /campaigns` is not a bug.* The composer renders the send
  button disabled with the reason, not hidden — a hidden button makes a Marketing Manager think the
  feature is broken.

Encode this once, in `lib/capabilities.ts`, as named predicates — `canSeeMoney(me)`,
`canSendCampaigns(me)` — never as capability strings compared inline in a component. There are two
compound rules today and the third will be added in one place.

## The API client

One typed client in `lib/api/`. Three jobs:

**Unwrap the envelope.** Every response is `{ success, data, meta? }` or
`{ success: false, error: { code, message, details } }`. Callers receive `data` or a thrown typed
error; no component ever writes `response.data.data`.

**Map errors to behaviour**, once, by `error.code` and status:

| Status | Behaviour |
|---|---|
| 400 | Field errors from `details.fields` onto the form. **Render the field list, not the top-level message** — `docs/API.md` says a 400 lists *every* bad field, and a toast saying "The product data is invalid" throws that away. |
| 401 | Clear the session, redirect to login with a "signed out" notice. |
| 403 | Forbidden state on the screen. Never a logout, never a toast that disappears. |
| 404 | Not-found state. On `/account/orders/{id}` it means "not yours"; in the panel it means gone. |
| 409 | **Read the body.** Status transitions return what moves are legal; a duplicate SKU names the SKU. This is the one error class where the API's message is worth surfacing verbatim. |
| 413 / 415 | Upload-specific, handled in the media picker. |
| 429 | Back off by `Retry-After`, show a countdown, retry once automatically. Never retry a POST. |
| 5xx | Generic failure state with a retry. Never surface diagnostics; the API sends none. |

**Parse, don't trust.** Zod schemas at the boundary for every resource the panel renders. When the
API changes shape, the panel fails at the boundary with a legible message instead of rendering
`undefined` three components deep. The schemas are `.passthrough()` so an added field is not a
breaking change — only a *missing* or *retyped* one is.

## Data fetching

**Server Components render the first screen.** A list route's page component fetches page one on the
server with the sealed credential and streams it. First paint carries data; there is no spinner on
navigation.

**TanStack Query owns everything after.** Filters, pagination, sorting, mutations, refetching. Query
keys mirror the URL so the two never disagree.

**Filter state lives in the URL.** `?status=processing&wilaya=16&page=2`. A support agent shares a
link to the orders they are looking at; a refresh does not lose the filter; the back button works.
This is also what makes the RSC/Query split coherent — the server reads the same search params the
client writes.

### Polling, and the rate limit it must respect

Reads are 600/min **per credential**, and one staff member with the orders board open in two tabs is
one credential. An orders list polling every 5 seconds is 24/min for one tab; four staff with two
tabs each is 192/min before anyone clicks anything. It does not break, and it is a quarter of the
budget spent on nothing.

Rules: no polling interval below **30 seconds**; polling pauses when the document is hidden
(`refetchIntervalInBackground: false`); only the orders list and the campaign-send progress poll at
all. Everything else refetches on window focus and on mutation.

## Routing and navigation

`/[locale]/(panel)/…`, with `fr` and `ar` the only locales. The locale segment is present always —
no implicit default — because a shared link must render in the language it was shared in.

Navigation is a **bottom tab bar on mobile** with five destinations, chosen by what the role can do:

```
Orders   Products   Dashboard   Customers   More
```

`Dashboard` sits centre. `More` opens a grouped inset list of everything else — Inventory, Shipping,
Payments, Coupons, Content, Media, Marketing, Campaigns, Notifications, Import/Export, Users,
Settings, Audit — filtered by capability, in a full-height sheet.

A role holding fewer than five of the primary destinations gets its tabs backfilled from `More` in a
fixed order, so a Marketing Manager's tab bar is coherent rather than three tabs and two gaps.

At `md` and up the tab bar becomes a fixed sidebar with the same order and the same grouping. It is
the same tree, not a second navigation model.

---

# Part III — The design system

Invoke the `impeccable` skill and the Apple/iOS skill before writing components. What follows is the
constraint set they work inside, plus the decisions that are specific to this shop.

## Tokens are the only source of values

`styles/tokens.css` defines everything as CSS custom properties inside Tailwind v4's `@theme`, which
publishes them as both variables and utilities. **No component declares a colour, a radius, a
duration or a shadow that is not a token.** `scripts/check-design.sh` greps for hex literals,
`rgb(`, `oklch(` and arbitrary-value brackets in `app/` and `components/` and fails on any hit.

### Colour

Apple's system palette, because "iOS-like" is the brief and an approximation of it reads as a
knock-off. Light and dark are both first-class; dark is not an afterthought filter.

**`@theme` is declared once, at the top level.** Tailwind v4's `@theme` is not a nestable rule — it
cannot go inside `@media`, and a build that tries silently drops the block. Theme variants are plain
custom-property redefinitions on `:root`, which work because `@theme` emits its values as variables
that the generated utilities reference.

```css
@import "tailwindcss";

@theme {
  /* surfaces — elevation is a surface step, never a shadow */
  --color-bg:            #ffffff;
  --color-bg-grouped:    #f2f2f7;   /* the ground behind inset lists */
  --color-surface:       #ffffff;
  --color-surface-2:     #f2f2f7;
  --color-surface-3:     #e5e5ea;

  /* text */
  --color-label:           rgb(0 0 0 / 1);
  --color-label-secondary: rgb(60 60 67 / 0.60);
  --color-label-tertiary:  rgb(60 60 67 / 0.30);

  /* separators — the hairline is structural, not decorative */
  --color-separator:       rgb(60 60 67 / 0.29);
  --color-separator-opaque:#c6c6c8;

  /* semantics */
  --color-accent:  #007aff;   /* the one token a client rebrands */
  --color-success: #34c759;
  --color-warning: #ff9500;
  --color-danger:  #ff3b30;
  --color-info:    #5856d6;
}

/* one dark palette, applied by the system default and by the explicit toggle */
@layer theme {
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) { /* …the dark values… */ }
  }
  :root[data-theme="dark"] {
    --color-bg:            #000000;
    --color-bg-grouped:    #000000;
    --color-surface:       #1c1c1e;
    --color-surface-2:     #2c2c2e;
    --color-surface-3:     #3a3a3c;
    --color-label:           rgb(255 255 255 / 1);
    --color-label-secondary: rgb(235 235 245 / 0.60);
    --color-label-tertiary:  rgb(235 235 245 / 0.30);
    --color-separator:       rgb(84 84 88 / 0.60);
    --color-separator-opaque:#38383a;
    --color-accent:  #0a84ff;
    --color-success: #30d158;
    --color-warning: #ff9f0a;
    --color-danger:  #ff453a;
    --color-info:    #5e5ce6;
  }
}
```

Write the dark values **once**, as a custom-property group the two selectors both apply, rather than
maintaining two copies that drift. There are three theme states, not two: an explicit `light`, an
explicit `dark`, and the default where nothing is stamped and only `prefers-color-scheme` decides. A
colour whose only definition lives inside the media query disappears the moment someone picks the
other theme manually, so **every token has a value on bare `:root`**.

**Semantic colour is never the only signal.** Red for a failed payment carries an icon and a word;
7 % of male users cannot separate it from green, and a warehouse phone in sunlight flattens both.

### Type

IBM Plex Sans and IBM Plex Sans Arabic, variable, self-hosted from `public/fonts`, subset to
Latin + Latin-1 + Arabic and preloaded. `font-display: swap` with a metric-adjusted fallback so the
swap does not reflow the list under the reader's thumb.

The scale is iOS's, which is why it will not look like a web dashboard. Note the body size: **17 px**,
not 16.

| Token | Size / line | Weight | Used for |
|---|---|---|---|
| `--text-large-title` | 34 / 41 | 700 | The collapsing screen title |
| `--text-title-1` | 28 / 34 | 700 | Section heads |
| `--text-title-2` | 22 / 28 | 600 | Card heads, sheet titles |
| `--text-title-3` | 20 / 25 | 600 | Grouped-list group titles |
| `--text-headline` | 17 / 22 | 600 | The primary line of a list row |
| `--text-body` | 17 / 22 | 400 | Body, form values |
| `--text-callout` | 16 / 21 | 400 | Dense tables at `md`+ |
| `--text-subhead` | 15 / 20 | 400 | The secondary line of a list row |
| `--text-footnote` | 13 / 18 | 400 | Timestamps, helper text |
| `--text-caption` | 12 / 16 | 400 | Table column heads, badges |

Numbers use `font-variant-numeric: tabular-nums` **everywhere they appear in a column** — money,
stock counts, order ids, dates. A right-aligned price column with proportional figures is the single
most common tell of an interface nobody tuned.

**Arabic is set slightly larger.** Plex Sans Arabic's x-height sits lower than the Latin's, so at 17 px
it reads smaller. `[dir="rtl"]` scales the root by `1.0625` and adds `0.08em` of line-height. This is a
typographic fact, not a preference.

### Space, radius, hairline

An 8 pt grid with a 4 pt half-step: `4 8 12 16 20 24 32 40 48 64`. Nothing between.

Radii: `--radius-sm: 8px` (badges, inputs), `--radius-md: 12px` (buttons), `--radius-lg: 14px`
(cards, grouped list containers), `--radius-xl: 20px` (sheets, top corners only), `--radius-full`.
iOS radii are larger than web defaults, and this is most of why a screenshot reads as iOS.

The hairline is a real token because it is a real thing:

```css
--hairline: 1px;
@media (min-resolution: 2dppx) { :root { --hairline: 0.5px; } }
```

A 1 px separator on a phone is a rule; a 0.5 px separator is a hairline. Every grouped list, nav bar
and tab bar uses it.

### Elevation without gradients or shadows

iOS builds depth from **surface lightness and hairlines**, not from drop shadows. So:

- A card is `--color-surface` on `--color-bg-grouped`, with `--radius-lg`. No border, no shadow.
- Nested surfaces step up: `surface` → `surface-2` → `surface-3`. Three steps is the whole vocabulary.
- Shadow exists for exactly two things — a **sheet** and a **popover** — because those float over
  content and need to say so. `--shadow-overlay: 0 8px 32px rgb(0 0 0 / 0.16)`. Nothing else in the
  panel casts a shadow.
- Navigation and tab bars use a **material**: `background: color-mix(in srgb, var(--color-bg) 72%, transparent)`
  plus `backdrop-filter: blur(20px) saturate(180%)`, with a hairline on the content edge. Provide a
  `@supports not (backdrop-filter: blur(1px))` fallback to the opaque surface — never to a gradient.

### Status without an accent bar

The banned pattern needs a named replacement or it comes back. Status is communicated three ways,
chosen by density:

1. **A tonal badge** — the semantic colour at low alpha for the fill, the same colour at full
   strength for the label, `--radius-full`, `--text-caption`, uppercase off. This is the default in
   lists and on detail headers.
   `background: color-mix(in srgb, var(--color-warning) 14%, transparent); color: var(--color-warning);`
2. **A leading dot** — 8 px, semantic colour, when the row is already dense and a badge would crowd
   it. Always beside a text label; never the only signal.
3. **Typographic weight** — an unread or action-needed row sets its primary line to 600. Used for
   "needs attention", never for status itself.

No coloured left border. No coloured full-width strip above a card. No coloured card background.

### Motion

One easing curve, iOS's: `--ease-ios: cubic-bezier(0.32, 0.72, 0, 1)`. Three durations: `--dur-fast:
180ms` (state changes, toggles), `--dur-base: 280ms` (sheets, navigation), `--dur-slow: 420ms` (the
large-title collapse, pull-to-refresh release).

Transforms and opacity only. Never animate `width`, `height`, `top` or `background-color` — a list
that janks while it filters is worse than one that snaps.

`@media (prefers-reduced-motion: reduce)` collapses every transform animation to a 120 ms opacity
fade and disables the parallax on the large title. This is a correctness requirement, not a courtesy;
vestibular disorders are common and the panel is used on a phone in motion.

### Touch

- **44 × 44 CSS px minimum** for anything tappable. A 20 px icon gets a 44 px hit area via padding or
  a pseudo-element, not by growing the icon.
- 8 px minimum between adjacent targets.
- Destructive actions are never adjacent to their non-destructive neighbour in a list. `?force=true`
  deletes get their own confirmation step regardless.
- `:active` states are visible and immediate — `scale(0.97)` with `--dur-fast` — because a phone has
  no hover and a tap with no feedback gets tapped again.
- `touch-action: manipulation` on every control to kill the 300 ms double-tap delay.
- `overscroll-behavior: contain` on every scroll container, so pulling a sheet's list does not drag
  the page behind it.

### Safe areas

`viewport-fit=cover` in the viewport meta, then `env(safe-area-inset-*)` on the tab bar, the nav bar
and every sheet. A tab bar that sits under the iPhone home indicator is unusable and looks like a
website pretending to be an app — which is precisely the failure mode this brief exists to avoid.

## The iOS patterns this panel uses

Each of these is a primitive in `components/primitives`, built once.

**Grouped inset list.** The workhorse. `--color-surface` rounded block on `--color-bg-grouped`, rows
separated by hairlines that inset from the leading edge to the text (not the icon), a group title in
`--text-title-3` above and an optional footnote below. Every detail screen, every settings screen and
every form in this panel is a grouped inset list. This single pattern carries most of the iOS
character and most of the mobile ergonomics.

**Large title that collapses.** The screen title starts at `--text-large-title`, left-aligned (
leading-aligned — see [Part IV](#part-iv--arabic-and-rtl)), below the nav bar. On scroll it shrinks
and moves into the nav bar as `--text-headline`, and the nav bar's material becomes opaque with its
hairline. Driven by a scroll listener with `IntersectionObserver`, not by a scroll handler that runs
on every frame.

**Sheet with detents.** Forms and filters open as bottom sheets, not as page navigations. Detents at
`medium` (≈ 55 vh) and `large`; a grabber; dismiss by drag or backdrop tap; `--radius-xl` on the top
corners only. On `md` and up the same component renders as a centred modal — one component, two
presentations.

**Segmented control.** For 2–4 mutually exclusive filters (order status, date range, product status).
Full width on mobile, `--radius-md`, the selected segment on `--color-surface` inside a
`--color-surface-2` track, animated with a transform.

**Action sheet.** Destructive and multi-choice actions. Bottom-anchored on mobile, the destructive
option in `--color-danger`, `Annuler` / `إلغاء` in its own group beneath. Never a browser `confirm()`.

**Swipe actions on list rows.** Leading swipe for the common non-destructive action (mark processing,
adjust stock), trailing swipe for destructive with a confirm step. Every swipe action also exists in
the row's overflow menu, because swipe is not discoverable and is not available to a keyboard.

**Pull to refresh** on every list. Spring-eased, respects reduced motion, and calls the same refetch
the focus listener does.

**Toast.** Bottom-anchored above the tab bar, respecting safe area, one at a time, 4 seconds,
dismissible. Toasts confirm; they never carry error detail that the user needs to act on — that goes
on the screen.

---

# Part IV — Arabic and RTL

Both directions are first-class from the first component. Retrofitting this is a rewrite.

## Physical properties are banned

Not discouraged — banned, and checked. `scripts/check-design.sh` fails on `ml-`, `mr-`, `pl-`, `pr-`,
`left-`, `right-`, `text-left`, `text-right`, `border-l`, `border-r`, `rounded-l`, `rounded-r` in
`app/` and `components/`, and on `margin-left`, `padding-right`, `left:`, `right:` in CSS.

Use `ms-` / `me-` / `ps-` / `pe-` / `start-` / `end-` / `text-start` / `text-end` / `border-s` /
`border-e` / `rounded-s` / `rounded-e`. Tailwind v4 ships all of them.

Flexbox and grid are direction-aware already — `flex-row` follows `dir`, so most layout needs nothing.
The places that break are absolute positioning, transforms and box-shadow offsets, and those are the
ones the check catches.

## What mirrors and what does not

| Mirrors | Does not mirror |
|---|---|
| Layout, navigation, list disclosure chevrons | Numbers — always LTR, in every locale |
| Back and forward arrows | Phone numbers, SKUs, tracking numbers, order ids |
| Progress and timeline direction | Media playback controls |
| Chart axis order and bar direction | Logos, product images, the courier's brand marks |
| Text alignment | Clock icons, checkmarks, most pictograms |

A tracking number or a SKU inside Arabic text needs `dir="ltr"` and `unicode-bidi: isolate` on its
own element, or the bidi algorithm reorders it and a customer reads back a number that does not
exist. Ship a `<Ltr>` primitive and use it for every identifier. This is the single most common bug
in bilingual admin tools and it is silent — nothing errors, the number is just wrong on screen.

## Fonts across scripts

Both faces load, and `unicode-range` routes each codepoint to the right one automatically:

```css
@font-face { font-family: "Plex"; src: url("/fonts/plex-sans-var.woff2") format("woff2-variations");
             unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+2000-206F, U+2074, U+20AC; }
@font-face { font-family: "Plex"; src: url("/fonts/plex-sans-arabic-var.woff2") format("woff2-variations");
             unicode-range: U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF; }
```

One family name, two files. An Arabic wilaya name inside a French sentence — `Alger / الجزائر` — sets
correctly with no per-element font switching, which matters because §51's geography data carries both
names on every row and they appear side by side throughout the panel.

## Numbers, dates and money

**Western digits in both locales.** Algeria uses `0123456789` in Arabic text; Eastern Arabic numerals
would be wrong here and unreadable to the staff using this. Pin it — do not rely on the locale
default:

```ts
new Intl.NumberFormat("ar-DZ-u-nu-latn", { … })
```

**Money is formatted, never computed.** The API returns decimal strings and has already done the
arithmetic; the panel's job is to render `26350.00` as `26 350,00 DA`. There is no client-side
subtotalling, no "estimated total", no tax calculation. `docs/API.md` puts it plainly for the
storefront — *you cannot set prices* — and the same discipline applies here for a different reason:
a panel that computes its own total will eventually disagree with the order, and the order is right.

Never parse money into a JavaScript number for anything but display width. `Intl.NumberFormat` takes
the string.

**Dates** come back as ISO 8601 with an offset. Render in the shop's timezone, which the panel reads
once from `/settings` and holds — not the browser's, or a manager in France sees yesterday's orders
dated today. Relative time ("il y a 2 h" / "منذ ساعتين") for anything under 24 hours, absolute after.

## Copy

ICU messages in `messages/fr.json` and `messages/ar.json`. No string concatenation, ever — Arabic
word order is not French word order and `"Commande " + id + " expédiée"` cannot be translated.

**Error messages from the API are French-only**, because the API emits one language. Two rules: map
every `error.code` the panel can provoke to a localised message of its own, and fall back to the
API's `message` only for codes the panel does not know. The 409 bodies are the exception — the list
of legal status moves is data, and the panel renders it through its own status labels.

---

# Part V — The screens

Every area of PLAN §52, with what it reads, what it writes and what will bite.

## Every screen has five states

Not three. Build all five as part of the screen, not afterwards:

1. **Loading** — skeleton rows matching the real row height, never a centred spinner. A spinner
   reflows the page when data lands; a skeleton does not.
2. **Empty** — distinguishes *no data yet* from *no results for this filter*, and the second offers
   to clear the filter. One illustration-free line of text and one action.
3. **Forbidden (403)** — names the capability required and who to ask. A Support Agent hitting
   `/products` should read "Cette section demande la permission Produits" and not a blank page.
4. **Error** — one line, a retry, and the API's `message` only where it is actionable.
5. **Offline / stale** — see [Part VI](#part-vi--pwa-and-offline). A visible marker with the age of
   the data, not silent staleness.

## Dashboard

`GET /analytics/overview`, `/orders`, `/products`, `/customers`, `/cod`, `/shipping`.
`ac_view_analytics`.

Mobile is a single column of grouped cards: today's orders, pending fulfilment, low stock, COD
success rate, revenue (when visible). Each card is a tap target that navigates to the filtered list
behind it — a dashboard number that cannot be drilled into is decoration.

**Money gating is the whole trap.** `meta.money_visible` is false for a role without
`ac_manage_orders`, and the screen must be complete without it — not a layout with holes. Two card
sets, chosen by `canSeeMoney(me)`.

The 366-day window cap and `range` / `date_from` / `date_to` belong to a shared date-range control
used by every analytics surface. Responses are cached server-side for 60 s
(`AC_ANALYTICS_CACHE_TTL`); do not add a shorter client refetch on top of it.

Charts follow the `dataviz` skill. **Flat fills, no gradient areas, no drop shadows on bars.** Axis
direction mirrors in RTL; the numbers on it do not.

## Orders

`GET/POST /orders`, `GET/PATCH /orders/{id}`, `/orders/{id}/cancel`, `/notes`, `/timeline`.
`ac_manage_orders`.

The most-used screen in the panel. Design it for a phone held in one hand in a stockroom.

**List.** Rows: order number and status badge on the primary line; customer name, wilaya and total on
the secondary. Segmented control for the common statuses, sheet for everything else. Swipe leading to
advance status, trailing to open the action sheet. This is the one list that polls — 30 s, paused
when hidden.

**Detail.** A stack of grouped inset sections: summary, line items, customer, shipping, COD,
payments, notes, timeline. Each section pulls from its own route and renders independently, so a
missing shipment does not block the order.

**Status transitions are the trap.** `pending processing on-hold completed cancelled refunded failed`,
and **not every move is legal**: `cancelled` and `refunded` are terminal, `refunded` is reachable only
from `processing` or `completed`, and a new order may only be created in five of the seven states. Do
not hard-code the transition table in the panel — offer the moves, and when a 409 comes back, render
the allowed list from the response body. The API is the authority and it tells you.

**Line items may only be rewritten while the order holds no stock.** The editor is disabled with that
reason showing, rather than absent, once stock has moved.

**A parcel's status never moves the order.** The shipping section shows the parcel's own status
beside the order's and never merges them. §55 has been explicit about this since it was written, and
a panel that showed one number for both would be the first thing to break it.

## Products

`GET/POST /products`, `GET/PATCH/DELETE /products/{id}`, `/duplicate`, `/bulk`, `/variations`,
`/product-categories`, and §88's `/attributes`. `ac_manage_products`.

**List with §82's filters.** Nine filters plus facets. On mobile the filter bar is a horizontally
scrolling row of pills that opens the filter sheet; selected filters render as removable chips above
the list.

Facets are **opt-in** (`?facets=…`) and arrive in `meta.facets`. Three things to build correctly:

- **A facet's counts exclude its own filter.** With `pa_size=m` selected, the size facet still reports
  `l` and `xl` counts. Render every value in a group, including zero-count ones, so a selection never
  turns its siblings into dead ends.
- **Counts are published-only; the list includes drafts.** The panel will legitimately show seven rows
  beside a count of six. Render `scope_note` beside the facet block. Without it this reads as a bug
  and gets "fixed" by someone into something wrong.
- **Groups are capped at 50** with `truncated` and `total_values` beside them. Show "50 sur 128" when
  truncated; a bounded list that does not say so reads as complete.

**Detail.** Grouped sections: identity and pricing, inventory, images, categories and attributes,
variations, options (§83), SEO (§62).

**The `options` editor is the hardest component in the panel.** Three group types — `choice`, `text`,
`bundle` — with caps (20 groups, 50 choices, 20 components), negative `price_delta` allowed,
`image_id` that must already exist, and errors that name a position:
`options.groups[0].choices[2].price_delta`. Map those paths onto the form's field paths exactly, or a
validation error lands nowhere. The line above the editor is the rule that stops the whole
misunderstanding: **a variation has a SKU and stock; an option is a modifier with neither.**

`bundle.available` and `options_problems` are **read-only** and come back on GET. `options_problems`
appearing at all means the stored document has a group the API could not read — surface it as a
warning on the product, because carts holding that product are already refusing to check out.

**GET then PATCH the whole object works.** Read-only fields are dropped, not rejected. Build the form
around the full object rather than diffing — it is what the API is designed for and it removes a
whole class of partial-update bug.

`DELETE` trashes; `?force=true` is permanent and gets its own confirmation with different wording.

## Categories and attributes

`GET /product-categories`; §88's `/attributes` and `/attributes/{id}/terms`.
`ac_manage_products`.

A tree for categories, a flat list for attributes with a drill-in for terms. The two §88 warnings
belong on screen: a newly created attribute's facet counts are zero until the next request, and
changing a term slug breaks saved filters and storefront links — the confirm dialog says so.

## Inventory

`GET /inventory`, `/inventory/{id}`, `/lookup?sku=`, `/low-stock`, `POST /adjust`, `/bulk`,
`GET /movements`, `/movements/summary`. `ac_manage_inventory`.

Built for a phone in a warehouse. The default screen is **low stock**, not the full list.

`/inventory/lookup?sku=` behind a search field is the fastest path from a barcode to an adjustment —
give it a large input, `inputmode="text"`, autofocus off, and no debounce below 300 ms.

**Every stock change writes a movement.** There is no path that changes stock without one, and the
movements ledger is the screen that proves it. Adjustments take a reason; the ledger shows who, when,
how much and why. An imported change writes a movement too — two thousand rows write two thousand
movements, and the ledger's pagination has to expect that.

## Customers

`GET /customers`, `GET/PATCH /customers/{id}`, `GET /customers/{id}/orders`. `ac_manage_customers`.

`roles`, `capabilities` and `user_pass` are **refused by name**. So is `marketing_consent`: it is
reported on GET and refused on PATCH, because consent is the customer's to give. Render it as a
read-only row with the date and the reason it cannot be changed here — a disabled toggle with no
explanation gets raised as a bug every few months.

Staff accounts do not appear here; they are §87's `/users`.

## Coupons

`GET/POST /coupons`, `GET/PATCH/DELETE /coupons/{id}`. `ac_manage_coupons`.

Three types: `percent`, `fixed_cart`, `fixed_product`. Codes lowercase on save — lowercase them in
the field as the user types, so what they see is what is stored.

`maximum_discount` does not exist and is refused by name. If a client asks for it, the answer is that
WooCommerce has no such field and `maximum_amount` caps the cart, not the discount.

Absent thresholds come back as `null`, not `"0.00"` — an empty field, not a zero.

## Shipping

`/shipping/providers`, `/shipping/rates`, `/shipping/rules`, `/shipments`, `/orders/{id}/shipments`,
`/shipments/{id}`, `/cancel`, `/sync`. `ac_manage_shipping`.

Two things that are separate and must look separate: **what the shop charges** (`/shipping/rules`,
the tariff) and **what a courier quotes** (`/shipping/rates`). `GET /shipping/rates` returns both,
labelled; the screen keeps the labels.

The rules editor's whole logic is that **the narrowest match wins** — commune beats wilaya beats the
national fallback — and **rules are never added together**. Show which rule would win for a chosen
destination, live, as the user edits. A rules table that does not show its own resolution is a table
people misconfigure.

**One live shipment per order**, enforced by the database. The create button is absent, with the
reason, while one is live.

**Shipment `label` URLs are credentials.** They carry an access token to one customer's name, phone
and address. Never render one in a client component, never put one in a `href` the browser can
prefetch, never log it, and never let the service worker cache a response containing one. The panel
opens a label through a server-side handler that fetches it with the staff credential and streams the
PDF back — the URL never reaches the browser at all.

## Payments

`GET /payments`, `/payments/{id}`, `/payments/methods`, `GET/POST /orders/{id}/payments`,
`POST /payments/{id}/verify`. `ac_manage_payments`.

**Status is verified server-side, never trusted from a callback**, and from `paid` the only permitted
move is `refunded`. The verify button exists because a webhook can arrive late or out of order; it
calls the provider, and the answer it returns is the truth.

Several transactions per order is the design, not a duplicate — a duplicated checkout link is a link
nobody clicks. Do not deduplicate them in the UI.

## Cash on delivery

`GET/PATCH /orders/{id}/cod`, `POST /orders/{id}/cod/attempts`, `GET /cod/statistics`.

COD lives inside the order detail as its own grouped section: attempts, outcomes, the next call. It is
order metadata and audit events, **never a status** — a COD outcome does not move the order, and the
section must not offer anything that looks like it does.

`/cod/statistics` needs only `ac_view_analytics` and belongs on the dashboard as a success-rate card.

## Analytics

Seven endpoints: `overview`, `revenue`, `orders`, `products`, `customers`, `shipping`, `cod`.

`/analytics/revenue` is **403** without `ac_manage_orders`; the others omit money and say so. Build
the money gate once, in the shared analytics layout.

Three figures are reported as **unavailable rather than zero** — shipping cost, payment fees, margin.
Render them as "non disponible" with the reason on tap, never as `0,00 DA`. A zero that means "we
cannot know" is a number someone will put in a report.

A wilaya comes off the **shipment**, never the address, so unshipped orders arrive as `unattributed`
with a reason attached. Show the reason; an "unattributed" slice with no explanation reads as a bug.

Counts cover every order, sums only those in the shop's currency, with `excluded_currencies` naming
the rest. On an install carrying pre-`DZD` orders, "22 commandes" beside a COD funnel of 615 is
correct and needs the note beside it.

## Content

§89's routes. `ac_manage_content`.

Pages addressed by **full path**. Banners and FAQs as post types. Menus as a two-level ordered tree.
The homepage as one document of `{type, data}` sections, edited whole with drag-ordering and a live
per-section validation that mirrors the API's `sections[2].type` error paths.

The `seo` block is edited inside the page's own form, because it is written through the page's PATCH.

A rich-text field is the risk here. Whatever editor is chosen, the panel does **not** sanitise — the
API does, on save, with `wp_kses`. The panel's job is to render the sanitised result back so the
author sees what was actually stored rather than what they typed. An editor that shows the unstored
version is one where a stripped `<iframe>` silently comes back on the next save attempt.

## Media

`GET /media`, `POST /media`, `GET/PATCH/DELETE /media/{id}`. `ac_manage_content`.

**jpg, jpeg, png, webp only.** Over the cap is **413**, wrong type is **415**, and both need distinct
messages — "trop volumineux" and "format non accepté" are different problems with different fixes.
Check size and type client-side before uploading so a phone on mobile data does not spend 40 seconds
uploading a file that will be rejected, but treat the server's answer as the authority.

The stored filename is generated server-side and the extension comes from the sniffed type, so the
name the user uploaded is not the name that comes back. Show the returned name.

`multipart/form-data`, field name `file`, plus optional `alt`, `title`, `caption`. Upload progress is
required — this is the one screen where a spinner without a percentage is unacceptable on a 3G
connection.

**A Product Manager cannot upload.** That is a documented gap in the capability matrix, not an
oversight. The product image picker must handle a role that can select existing media and cannot add
any — the "téléverser" affordance renders disabled with the reason, and the library still works.

## Marketing

`GET /marketing/config`, `POST /marketing/events/purchase`. `ac_manage_marketing`.

A small screen: which pixel is configured, which provider registered, and the note that the
Conversions API token appears in no response, ever. The purchase-event route is the storefront's, not
the panel's; the panel shows configuration and recent event counts.

## Campaigns

`/campaigns`, `/campaigns/{id}`, `/preview`, `/test`, `/cancel`, `/send`, `/recipients`, `/segments`,
`/segments/{id}/preview`, `/email-templates`. `ac_manage_marketing`, and **`ac_manage_customers` for
send, recipients and segment counts**.

The composer is the second-hardest screen. Sequence: audience → content → preview → test → send.

- **`send` sends nothing.** It returns **202**, freezes the audience and hands back a count. The drain
  is `wp algerian-commerce send-campaigns`, scheduled by the deployment. The confirmation screen must
  say this — a progress bar implying live sending is a lie the operator will act on.
- **A second `send` is a 409** and changes nothing. Do not retry it automatically.
- **503 `mail_not_configured`** before any row is written, and **409** when the audience matches
  nobody. Both are common and both need their own screen: the first points at
  `wp algerian-commerce mail-check`, the second at the segment.
- **Consent is not optional and not visible as a toggle.** Every audience is filtered to consenting
  customers, including an explicit id list. Show the eligible count beside the raw count so
  "1 000 clients sélectionnés → 412 destinataires" is legible rather than alarming.
- **Only a draft can be edited or deleted.** A sent campaign is the record of mail that left the
  building. Render it read-only with its counts.
- **Recipient addresses are purged 30 days after completion**, and `meta.purged` says so. The counts
  survive. Show "4 812 destinataires — adresses purgées" rather than an empty table.
- Placeholders are `{{…}}` tokens, not code. An unknown token renders **empty** and is listed in
  `unknown_tokens` on both the preview and the template — surface that list prominently, because an
  empty `{{firstname}}` (the real token is `{{first_name}}`) is invisible in a preview that has a
  name in it.
- `{{unsubscribe_url}}` is appended automatically when absent. Say so; do not add it a second time.

Segments are stored queries with eleven criteria. `consent`, `email`, `email_contains`, `role`,
`commune_id`, `limit` and `sql` are refused by name. **Empty criteria are refused** — that is what
`audience_type: all` is for. `wilaya_id` comes off the shipment, so an unshipped order has no wilaya
and cannot match; say it in the criteria form. A segment in use cannot be deleted.

## Notifications

§90's routes. `ac_manage_customers`.

The operator's answer to "did it send?". List by channel, status and date; detail carries the frozen
message. Retry is a **202** that clears the row for the next drain and mails nothing.

## Import and export

`POST /import/products`, `/import/inventory`; `GET /export/{products,inventory,orders,customers}`.
Capability follows the resource.

**Imports take the CSV as the raw request body** with `Content-Type: text/csv` — not JSON, not
multipart. This differs from `/media` and will be got wrong once.

**`dry_run` defaults to true**, which is the safety property: a client that forgets the flag previews
and never writes. The panel makes that visible — the preview is a screen, and applying is a separate,
explicit action showing the counts the preview reported.

`mode` is `create` or `update` and **neither does both**. The word "mode" was chosen because
`update_existing: false` reads like a modifier and behaves like a switch. Label the two clearly:
"Créer les nouveaux SKU (ignore les existants)" / "Mettre à jour les SKU existants (ignore les
nouveaux)".

A product dry run is a parse and a lookup, not a rehearsal — `preview_only` says so. Do not present
it as a guarantee.

**Exports are files, not JSON.** `Content-Type: text/csv`, a `Content-Disposition` filename that is
the API's. An export *error* still arrives in the envelope, so a client never saves an error as
`products.csv`. Download through the server proxy, streaming, so the credential stays server-side —
this is the one place the envelope-unwrapping client must be bypassed deliberately.

## Settings

`GET/PATCH /settings`. `ac_manage_settings` — **Super Admin only**.

Four writable blocks: `store`, `contact`, `legal` (the trade register — `rc`, `nif`, `nis`, `ai`),
`social`. Everything else is read-only **with its reason on screen**, because a greyed field with no
explanation is a support ticket:

| Read-only | The reason to render |
|---|---|
| `currency` | WooCommerce records it per order; changing it splits the order book instead of converting it. Set once at provisioning. |
| `features` | `ENABLE_*` are environment variables read once at bootstrap. Change `.env` and restart. |
| `providers` | Reports what actually registered, which follows from flags *and* credentials — a flag on with no key is a provider that never loaded, and this is the only place that gap shows. |
| secrets | Environment variables, never the options table. |

`store.storefront_url` earns its own emphasis: without it, password reset answers **503
`storefront_url_not_set`**, tracking links carry no URL, and the unsubscribe link points at the API's
own domain. Render it with that consequence attached.

An Admin holding the other ten management capabilities is refused here, and that is the boundary that
stops an Admin escalating. The forbidden state should name Super Admin rather than the capability
string.

## Users and roles

§87's routes. `ac_manage_users` — Super Admin only.

A staff list, a role picker fed by `GET /roles`, an application-password manager per user, and a
suspend action. The five escalation refusals each render as a disabled control with the reason, not
as a hidden one — the refusals are the security model, and a Super Admin should be able to see it.

The minted password appears **once**, in a sheet, with a copy button and a warning that it will not
be shown again. No "reveal" affordance elsewhere in the panel, because there is nothing to reveal.

## Audit

`GET /audit-logs`. `ac_view_audit_logs`.

Filter by actor, action, resource type, resource id and date. This is the screen that makes the
per-user credential decision worth its cost — every row names a person because every staff member
authenticates as themselves.

Writes are audited **by field name, never by value**, so the log says a trade-register field changed
and not what it changed to. Render it that way; a reader expecting values needs to know they were
never stored.

---

# Part VI — PWA and offline

Installable, read-cached. No offline write queue: the API has strict status transitions, 409s and
money, and a replay engine that resolves those conflicts is a larger and riskier project than the
panel itself.

## Install

`manifest.webmanifest` with maskable icons at 192/512, `display: standalone`, `theme_color` following
the active theme, `orientation: portrait` on phones. Apple touch icons and
`apple-mobile-web-app-status-bar-style: black-translucent` so the safe-area work shows.

Prompt to install after the second successful session, never on first load.

## What the service worker caches

| Cached | Strategy |
|---|---|
| App shell, JS, CSS, fonts, icons | Cache-first, versioned by build |
| Reference data — `/locations/*`, `/product-categories`, `/attributes`, `/settings` | Stale-while-revalidate, 24 h |
| Nothing else | — |

## What it must never cache

This is a security boundary, not an optimisation choice:

- Anything under `/orders`, `/customers`, `/shipments`, `/payments`, `/audit-logs`,
  `/campaigns/*/recipients`, `/notifications`. These are customer PII and money.
- Any response containing a shipment `label` URL, which is a credential.
- Any response to a POST, PATCH, PUT or DELETE.

Working data — the orders list the user just looked at — lives in **TanStack Query's in-memory cache
only**, persisted nowhere. It survives a navigation and dies with the tab, which is the correct
lifetime for a stranger's phone number on a shared warehouse phone.

## Two rules that make the cache safe

1. **Wipe every cache on logout and on any 401.** `caches.keys()` → delete all, plus the Query cache.
   A shared device otherwise serves the previous user's shell state to the next one.
2. **Key the runtime cache by user id.** Two staff on one phone must not share a cache entry, even
   for reference data — a suspended account's cached `/settings` is a small leak but it is one.

## Behaviour offline

The shell loads. Every list shows its last in-memory data if the tab is still alive, with a persistent
banner naming the age — "Données de 14:32 — hors ligne" — never silent staleness. Every write control
is disabled with the same message. `navigator.onLine` is a hint, not a truth; treat a failed fetch as
the real signal and recover on the next success.

---

# Part VII — Testing and checks

`scripts/test.sh` in the panel repository, with stages, mirroring this repository's convention.

| Stage | What it runs | Catches |
|---|---|---|
| `types` | `tsc --noEmit` | |
| `design` | `scripts/check-design.sh` | The non-negotiables |
| `unit` | Vitest — formatters, capability predicates, error mapping, envelope parsing | |
| `component` | Testing Library — the five states of every pattern component | |
| `e2e` | Playwright — mobile viewport, both locales, both directions | |

## `scripts/check-design.sh`

A rule nobody enforces is a preference. This is the enforcement, and it carries a floor the way
`test-api.sh` does — a grep that matches nothing must not report success.

Fails on any hit in `app/` and `components/`:

| Pattern | Rule |
|---|---|
| `bg-gradient`, `from-\[`, `via-`, `linear-gradient`, `radial-gradient` | No gradients |
| `border-[lrse]-[248]` on a status or card element, `border-inline-start-width` | No accent bars |
| `-\[[0-9]`, `-\[#`, `#[0-9a-fA-F]{3,8}` in `tsx`, `rgb(`, `oklch(` outside `tokens.css` | Tokens only |
| `\bm[lr]-`, `\bp[lr]-`, `\bleft-`, `\bright-`, `text-left`, `text-right`, `rounded-[lr]` | Logical properties only |
| `font-family` naming `sans-serif`, `system-ui`, `Inter`, `Arial`, `Helvetica` outside the fallback stack | No generic fonts |
| `shadow-` outside `Sheet` and `Popover` | Elevation is surface, not shadow |
| `from "@mui`, `from "antd`, `shadcn` | No component library |

And asserts a floor: the script must have scanned at least 40 files, or it exits non-zero. A rename
that empties the glob otherwise reports a perfectly compliant codebase.

## What the e2e suite must cover

Each of these is a real failure mode, not a checklist item:

- Login with a valid Application Password; login with a bad one **twice more than the limit** and
  assert the 429 with its `Retry-After` rendered.
- A Support Agent's session renders a coherent dashboard with **no money** and no empty
  currency-shaped elements.
- A Marketing Manager can create a campaign (201) and is **refused** at send (403), with the reason
  visible.
- A 409 on an order status transition renders the allowed moves from the response body.
- A 400 renders **every** field error, not the first — assert two simultaneously bad fields.
- The Arabic locale renders `dir="rtl"`, and a tracking number inside Arabic text keeps its digit
  order. Assert the rendered string, not the DOM attribute; the attribute half cannot catch a bidi bug.
- A shipment label opens without its URL ever appearing in the client bundle or in a DOM attribute.
- Logout clears every Cache Storage entry.

Every negative test carries a positive control, per §65: *a refusal and an unreachable route look
identical from outside.* A test asserting a Support Agent gets 403 proves nothing unless an
administrator gets 200 from the same URL in the same run.

---

# Part VIII — Accessibility

Not a separate pass. Staff use this eight hours a day, some of them on a cracked phone in a
badly-lit stockroom.

- **Contrast**: 4.5:1 for text, 3:1 for UI boundaries and icons, in **both** themes. The tonal status
  badges are the risk — `color-mix` at 14 % leaves the label at full strength, which passes; a badge
  whose text is also tinted does not.
- **Focus**: a visible ring on every interactive element, `:focus-visible`, using `--color-accent` at
  full strength and 2 px. Never `outline: none` without a replacement.
- **Keyboard**: every swipe action also in the overflow menu; every sheet trap-focused and
  Escape-dismissible; skip-to-content on every page.
- **Screen readers**: `aria-live="polite"` for toasts and for list-count changes after a filter;
  `aria-busy` during refetch; every icon-only button labelled in both locales.
- **Reduced motion** and **reduced transparency** (`prefers-reduced-transparency`) both honoured — the
  second replaces the nav material's blur with an opaque surface.
- **Text scaling**: the layout survives 200 % text zoom and iOS Dynamic Type. Nothing is sized in `px`
  that holds text; `rem` throughout.

---

# Part IX — Performance budgets

On a mid-range Android over Algerian 3G, which is the honest test case:

| Metric | Budget |
|---|---|
| First Contentful Paint | < 1.8 s |
| Largest Contentful Paint | < 2.5 s |
| Interaction to Next Paint | < 200 ms |
| Cumulative Layout Shift | < 0.05 |
| JS shipped to the browser, first load | < 180 KB gzipped |
| Fonts | 2 variable files, subset, < 90 KB total, preloaded |

Skeletons must match real row heights exactly — a skeleton of the wrong height is a layout shift with
extra steps. Images through `next/image` with explicit dimensions. No chart library that ships a
plotting engine to render six bars.

---

# Part X — Build order

Fourteen branches. Each is independently reviewable and each inherits the rules of the ones before it,
mirroring §47's slicing.

**In this repository first:**

```
 1. feat/admin-users          §87   DONE
 2. feat/attributes           §88   DONE
 3. feat/cms-writes           §89
 4. feat/notification-queue   §90
```

**Then in `ecom-admin`:**

```
 5. feat/shell          Next.js, tokens, fonts, both locales, both directions,
                        check-design.sh, the five states as components
 6. feat/session        login, the sealed cookie, the proxy allowlist, /auth/me,
                        capability predicates, the forbidden state
 7. feat/patterns       grouped list, large title, sheet, segmented control,
                        action sheet, swipe row, toast, filter bar, date range
 8. feat/orders         the hardest real screen, and the one that proves the patterns
 9. feat/products       list with facets, detail, variations, the options editor
10. feat/inventory      low stock, adjust, the movements ledger
11. feat/customers      + coupons
12. feat/shipping       + payments + COD
13. feat/analytics      dashboard, the seven reports, the money gate
14. feat/content        CMS, media, marketing, campaigns, notifications
15. feat/admin          settings, users, audit, import/export
16. feat/pwa            manifest, service worker, offline states, cache wiping
```

Step 8 comes before step 7 is finished being pretty. Orders is the screen that will reveal which
patterns are wrong, and finding that out on the fourth screen instead of the first costs three
rewrites.

---

# Things that will bite you

The panel-side counterpart to `docs/API.md`'s own list.

- **A 403 is not a logout.** Only a 401 clears the session. Getting this wrong makes a Support Agent
  unable to stay signed in.
- **Render `details.fields`, not `error.message`.** The API lists every bad field on purpose.
- **Read the 409 body.** It names the legal status moves. Do not hard-code the transition table.
- **Facet counts are published-only while the list shows drafts.** Seven rows beside a count of six is
  correct.
- **`meta.money_visible` is a layout decision**, not a conditional on one number.
- **Campaign `send` needs a second capability.** 403 on send with 201 on create is not a bug.
- **`marketing_consent` is read-only to staff.** Show why.
- **Shipment `label` URLs are credentials.** Server-proxied, never in the DOM, never cached.
- **Exports are files.** Do not send them through the envelope client.
- **Imports are raw CSV bodies**, not multipart — unlike `/media`, which is.
- **`dry_run` defaults to true.** Applying is a separate action.
- **`?force=true` is permanent.** Different confirmation wording from a trash.
- **Reads are 600/min per credential**, shared across every tab that person has open.
- **Analytics windows cap at 366 days**, and `per_page` caps at 100.
- **Money is formatted, never computed.** The order is right; the panel is a renderer.
- **Numbers inside Arabic text need `dir="ltr"` isolation** or the digits reorder silently.
- **Application Passwords need HTTPS** outside a `local` environment. Staging without TLS is a panel
  that cannot sign anybody in.
- **The panel cannot revoke its own session remotely.** Revoking means deleting the Application
  Password, which is why §87 mints them per device with a name.

---

# Reading order for whoever builds this

1. [docs/API.md](API.md) — the contract. All of it.
2. [docs/SECURITY.md](SECURITY.md) — "Authorization", "Secrets", "File uploads", "CSRF".
3. This document, Part I, and build those four route groups.
4. This document, Parts II–IV, and build the shell.
5. The `impeccable` skill and the Apple/iOS design skill, before the first component.
6. [docs/TESTING.md](TESTING.md) — the conventions the panel's own suites mirror.

`CLAUDE.md` summarises this repository and lags it slightly; where the two disagree, the code is
right. Where this document and `docs/API.md` disagree, `docs/API.md` is right — and where
`docs/API.md` and `scripts/test-api.sh` disagree, the script is right.
