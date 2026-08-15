# Algerian Headless E-Commerce Backend

## Docker + Git + Claude Code Implementation Roadmap

**Target:** Windows 11 + Docker Desktop + WSL 2\
**Production:** undecided and kept portable\
**Backend:** WordPress + WooCommerce + custom plugin\
**Frontend:** independent React/Next.js applications\
**Goal:** turn the previous Algerian e-commerce backend specification
into a reproducible, versioned, AI-assisted development workflow.

------------------------------------------------------------------------

## How to read this document

Sections are ordered as you build them. Work top to bottom: each
section's prerequisites are behind it. The parts below map onto the
milestones in §3 and the master sequence in §4.

Reference material sits immediately before the step that needs it —
the plugin structure and API contract before the plugin is written,
the third-party integration rules before the first provider adapter.

## Contents

**Part I — Orientation**

- [1. The Target Architecture](#1-the-target-architecture)
- [2. Why This Workflow](#2-why-this-workflow)
- [3. Milestone Roadmap](#3-milestone-roadmap)
- [4. Exact Implementation Order](#4-exact-implementation-order)
- [5. Do Not Build Everything at Once](#5-do-not-build-everything-at-once)
- [6. Definition of Done](#6-definition-of-done)

**Part II — Development Environment (Milestone 1–2)**

- [7. Windows 11 Setup Strategy](#7-windows-11-setup-strategy)
- [8. Install and Verify WSL 2](#8-install-and-verify-wsl-2)
- [9. Configure Docker Desktop](#9-configure-docker-desktop)
- [10. Create the Project Inside WSL](#10-create-the-project-inside-wsl)
- [11. Recommended Repository Structure](#11-recommended-repository-structure)
- [12. Git Configuration](#12-git-configuration)
- [13. Docker Compose](#13-docker-compose)
- [14. Environment Variables](#14-environment-variables)
- [15. Start WordPress](#15-start-wordpress)
- [16. WP-CLI](#16-wp-cli)
- [17. Recommended Free Plugin Baseline](#17-recommended-free-plugin-baseline)
- [18. First-Day Checklist](#18-first-day-checklist)

**Part III — AI Project Context (Milestone 3)**

- [19. Claude Code Access Model](#19-claude-code-access-model)
- [20. Why Filesystem + CLI Is Better Than Dashboard Automation](#20-why-filesystem-cli-is-better-than-dashboard-automation)
- [21. Install Claude Code](#21-install-claude-code)
- [22. CLAUDE.md](#22-claudemd)
- [23. The Three Documentation Layers](#23-the-three-documentation-layers)
- [24. Import the Previous Plan](#24-import-the-previous-plan)
- [25. Create ARCHITECTURE.md](#25-create-architecturemd)
- [26. Create SECURITY.md](#26-create-securitymd)
- [27. Claude's Permission Strategy](#27-claudes-permission-strategy)
- [28. First Claude Session Checklist](#28-first-claude-session-checklist)

**Part IV — How You and Claude Work**

- [29. The Standard Claude Implementation Loop](#29-the-standard-claude-implementation-loop)
- [30. Claude's Standard Feature Prompt](#30-claudes-standard-feature-prompt)
- [31. Claude Review Prompt](#31-claude-review-prompt)
- [32. Development Workflow for Each Phase](#32-development-workflow-for-each-phase)
- [33. Branch Strategy](#33-branch-strategy)
- [34. What You Should Do Manually](#34-what-you-should-do-manually)
- [35. What Claude Should Do](#35-what-claude-should-do)

**Part V — Core Plugin (Milestone 4)**

- [36. First Claude Task: Inspect, Don't Build](#36-first-claude-task-inspect-dont-build)
- [37. Recommended Custom Plugin Structure](#37-recommended-custom-plugin-structure)
- [38. Composer](#38-composer)
- [39. API Contract](#39-api-contract)
- [40. Second Claude Task: Plugin Foundation](#40-second-claude-task-plugin-foundation)
- [41. First Git Commit](#41-first-git-commit)

**Part VI — Security Foundation (Milestone 5)**

- [42. Security Implementation Order](#42-security-implementation-order)
- [43. Database Migrations](#43-database-migrations)
- [44. Authentication Architecture](#44-authentication-architecture)
- [45. RBAC and Audit](#45-rbac-and-audit)
- [46. CORS](#46-cors)

**Part VII — Commerce (Milestone 6)**

- [47. Product Implementation](#47-product-implementation)
- [48. First Successful Backend Milestone](#48-first-successful-backend-milestone)
- [49. Inventory](#49-inventory)
- [50. Orders and Customers](#50-orders-and-customers)

**Part VIII — Algeria (Milestone 7)**

- [51. Algerian Geographic Data](#51-algerian-geographic-data)
- [52. COD](#52-cod)

**Part IX — Shipping (Milestone 8)**

- [53. Shipping Abstraction](#53-shipping-abstraction)
- [54. Third-Party Integration Rule](#54-third-party-integration-rule)
- [55. Security Review Before Each Integration](#55-security-review-before-each-integration)
- [56. Yalidine](#56-yalidine)
- [57. ZR Express (was "Zedair")](#57-zr-express-was-zedair)

**Part X — Payments (Milestone 9)**

- [58. Payment Abstraction](#58-payment-abstraction)
- [59. Chargily](#59-chargily)
- [60. Webhooks](#60-webhooks)

**Part XI — CMS and Marketing (Milestone 10)**

- [61. CMS](#61-cms)
- [62. SEO and Marketing](#62-seo-and-marketing)
- [63. Analytics](#63-analytics)

**Part XII — Operations (Milestone 11)**

- [64. Import/Export](#64-importexport)
- [65. Testing Strategy](#65-testing-strategy)
- [66. Automation Scripts](#66-automation-scripts)
- [67. Seed Data](#67-seed-data)
- [68. Version Pinning](#68-version-pinning)

**Part XIII — Frontends (Milestone 12)**

- [69. API Testing Before Next.js](#69-api-testing-before-nextjs)
- [70. Next.js Integration](#70-nextjs-integration)

**Part XIV — Reuse, Deployment and Reference (Milestone 13)**

- [71. Client Configuration](#71-client-configuration)
- [72. Feature Flags](#72-feature-flags)
- [73. New Client Provisioning](#73-new-client-provisioning)
- [74. Production Separation](#74-production-separation)
- [75. Production Strategy](#75-production-strategy)
- [76. Important Production Rule](#76-important-production-rule)
- [77. Long-Term Reusable Template](#77-long-term-reusable-template)
- [78. Final Workflow](#78-final-workflow)
- [79. Golden Rules](#79-golden-rules)
- [80. Final Target](#80-final-target)
- [81. Official References](#81-official-references)

------------------------------------------------------------------------

# 1. The Target Architecture


The project should stop being treated as a manually configured WordPress
website.

Treat WordPress/WooCommerce as the commerce platform and your custom
plugin as the application/business/API layer.

``` text
                         Git Repository
                              |
              +---------------+---------------+
              |                               |
          docs/PLAN.md                    CLAUDE.md
              |                               |
              +---------------+---------------+
                              |
                         Claude Code
                              |
              +---------------+---------------+
              |               |               |
             Git           Docker          WP-CLI
              |               |               |
              |          WordPress            |
              |               |               |
              |          WooCommerce           |
              |               |               |
              +---------------+---------------+
                              |
                 algerian-commerce-core
                              |
                         Custom REST API
                         /algerian-commerce/v1
                              |
                  +-----------+-----------+
                  |                       |
                  v                       v
            Next.js Store           Next.js Admin
```

The core rule is:

> Never modify WordPress core or WooCommerce core to implement your
> business features. Put your reusable logic in
> `algerian-commerce-core`.

------------------------------------------------------------------------

# 2. Why This Workflow


The manual approach is useful for learning WordPress, but your project
contains too much custom engineering to be efficient as a dashboard-only
workflow.

You need:

-   Product CRUD
-   Orders
-   Customers
-   Inventory
-   RBAC
-   Analytics
-   COD
-   Wilaya/commune logic
-   Yalidine
-   Zedair
-   Chargily
-   Webhooks
-   CMS
-   Pixels
-   Security
-   Audit logs
-   Import/export
-   Database migrations
-   Testing

These are software-development tasks.

The target workflow is therefore:

``` text
You:
architecture + requirements + review + testing + business decisions

Claude:
implementation + repetitive coding + tests + debugging + automation

Docker:
reproducible environment

Git:
versioning + rollback + collaboration

WP-CLI:
programmatic WordPress administration
```

Claude Code is powerful enough to implement a large portion of this
project, but it should not be treated as an autonomous production
engineer. You remain responsible for reviewing security-sensitive code,
validating third-party integrations, approving destructive operations,
and deciding when a phase is actually complete.

------------------------------------------------------------------------

# 3. Milestone Roadmap


## Milestone 1 --- Development Environment

``` text
WSL 2
Docker Desktop
Ubuntu
Git
VS Code WSL
Claude Code
```

## Milestone 2 --- Platform

``` text
Docker Compose
WordPress
MySQL
WP-CLI
WooCommerce
```

## Milestone 3 --- AI Project Context

``` text
Git
CLAUDE.md
PLAN.md
ARCHITECTURE.md
SECURITY.md
```

## Milestone 4 --- Core Plugin

``` text
plugin bootstrap
autoloading
REST namespace
error format
health endpoint
```

## Milestone 5 --- Security Foundation

``` text
authentication
RBAC
capabilities
audit logs
validation
```

## Milestone 6 --- Commerce

``` text
products
variations
categories
inventory
orders
customers
coupons
```

## Milestone 7 --- Algeria

``` text
wilayas
communes
COD
shipping rules
```

## Milestone 8 --- Shipping

``` text
provider abstraction
Yalidine
Zedair
tracking
status synchronization
```

## Milestone 9 --- Payments

``` text
payment abstraction
Chargily
webhooks
transactions
```

## Milestone 10 --- CMS/Marketing

``` text
CMS
SEO
pixels
Meta Pixel + Conversions API
analytics
notifications
```

## Milestone 11 --- Operations

``` text
import/export
backups
monitoring
security hardening
testing
```

## Milestone 12 --- Frontends

``` text
Next.js admin
Next.js storefront
```

## Milestone 13 --- Deployment

``` text
staging
production
backup
rollback
monitoring
```

------------------------------------------------------------------------

# 4. Exact Implementation Order


**This list is the authoritative build sequence, and the rest of this
document is now ordered to match it.** Sections 5 onward follow these
steps, so working top to bottom through the document works through this
list.

Use this as the master sequence.

**28b was added during §53 and is numbered rather than renumbering the list**,
which the rest of this document — and the plugin README, which cites "§4 items
17–21" — is ordered to match. It is a step the original list missed: PLAN.md §14
requires shipping zones, wilaya and commune pricing and free-shipping
thresholds, and none of it belongs to a provider. §53 built the abstraction and
§56/§57 map couriers onto it, but nothing in either decides *what the shop
charges a customer for delivery*, which is a thing a store cannot open without.
It needs no provider documentation, so it can be built at any point after §53
and before a storefront quotes a price.

**37b is the same device.** Step 37 is the marketing event layer; 37b is the
first provider mapped onto it, standing to 37 as Yalidine stands to the
shipping abstraction. It is split out because the server half is a real
third-party integration — a token, a hashed-PII payload and a deduplication
contract with the storefront — and §62b says so where §62 only lists event
names.

``` text
01. WSL 2
02. Docker Desktop
03. Git
04. VS Code WSL
05. Claude Code
06. Repository
07. Docker Compose
08. WordPress
09. MySQL
10. WP-CLI
11. WooCommerce
12. Approved free plugins
13. CLAUDE.md
14. PLAN.md
15. ARCHITECTURE.md
16. SECURITY.md
17. Custom plugin skeleton
18. REST API foundation
19. Database migrations
20. Authentication/RBAC
21. Audit logs
22. Product CRUD
23. Inventory
24. Orders
25. Customers
26. Algeria geographic data
27. COD
28. Shipping abstraction
28b. Shipping rules — zones, wilaya and commune pricing,
     free-shipping thresholds, provider selection (PLAN §14)  [built]
29. Yalidine
30. ZR Express (was "Zedair")
31. Payment abstraction
32. Chargily
33. Coupons
34. Notifications
35. CMS
36. SEO
37. Marketing/pixels
37b. Meta Pixel + Conversions API — the first concrete provider behind
     the marketing event layer (PLAN §26, roadmap §62b)
38. Analytics
39. Import/export
40. Security hardening
41. Automated tests
42. Backup/recovery
43. Next.js admin
44. Next.js storefront
45. Staging
46. Production
```

------------------------------------------------------------------------

# 5. Do Not Build Everything at Once


Do not send:

``` text
"Implement PLAN.md completely."
```

Instead:

``` text
Phase 1
 ↓
test
 ↓
review
 ↓
commit

Phase 2
 ↓
test
 ↓
review
 ↓
commit
```

Claude can handle large codebases, but incremental implementation
dramatically reduces architectural drift and makes errors easier to
identify.

------------------------------------------------------------------------

# 6. Definition of Done


A phase is not complete because Claude says:

``` text
Implemented successfully.
```

Mark it complete only when:

``` text
[ ] Code exists
[ ] Architecture is respected
[ ] Validation exists
[ ] Authorization exists
[ ] Tests exist
[ ] Tests pass
[ ] API tested
[ ] Error handling tested
[ ] Security reviewed
[ ] Git diff reviewed
[ ] No secrets committed
[ ] Documentation updated
[ ] Git commit created
```

------------------------------------------------------------------------

# 7. Windows 11 Setup Strategy


For Windows 11, use:

``` text
Windows 11
   |
Docker Desktop
   |
WSL 2
   |
Ubuntu
   |
Git + Claude Code + Docker CLI
```

Docker documents WSL 2 as a recommended Windows development path. It
also recommends storing source code inside the WSL/Linux filesystem
rather than under `/mnt/c` for better bind-mount performance and
file-change behavior.

Therefore prefer:

``` text
~/projects/algerian-commerce-backend
```

over:

``` text
C:\Users\...\algerian-commerce-backend
```

You can still use VS Code's Windows UI through its WSL extension.

------------------------------------------------------------------------

# 8. Install and Verify WSL 2


Open PowerShell as Administrator:

``` powershell
wsl --install
```

Restart if Windows asks you to.

Then:

``` powershell
wsl --status
wsl -l -v
wsl --update
```

You want your Ubuntu distribution to show:

``` text
VERSION 2
```

If required:

``` powershell
wsl --set-version Ubuntu 2
wsl --set-default Ubuntu
```

Keep WSL updated.

------------------------------------------------------------------------

# 9. Configure Docker Desktop


Open Docker Desktop:

``` text
Settings
  -> General
     -> Use WSL 2 based engine
```

Then:

``` text
Settings
  -> Resources
     -> WSL Integration
        -> Enable Ubuntu
```

Verify:

``` powershell
docker version
docker compose version
```

Docker Desktop already includes Docker Compose, so you do not need a
separate Compose installation.

------------------------------------------------------------------------

# 10. Create the Project Inside WSL


Open Ubuntu:

``` powershell
wsl
```

Then:

``` bash
mkdir -p ~/projects
cd ~/projects

mkdir algerian-commerce-backend
cd algerian-commerce-backend
```

Initialize Git:

``` bash
git init
git branch -M main
```

Create the initial directories:

``` bash
mkdir -p docs
mkdir -p scripts
mkdir -p wp-content/plugins/algerian-commerce-core
mkdir -p backups
```

Create:

``` text
CLAUDE.md
README.md
.env.example
.gitignore
compose.yaml
```

------------------------------------------------------------------------

# 11. Recommended Repository Structure


Use:

``` text
algerian-commerce-backend/
│
├── docs/
│   ├── PLAN.md
│   ├── ARCHITECTURE.md
│   ├── API.md
│   ├── SECURITY.md
│   ├── DEPLOYMENT.md
│   └── CHANGELOG.md
│
├── scripts/
│   ├── setup.sh
│   ├── reset.sh
│   ├── seed.sh
│   ├── health.sh
│   ├── test.sh
│   └── backup.sh
│
├── wp-content/
│   ├── plugins/
│   │   └── algerian-commerce-core/   <- src/, tests/ and phpunit.xml.dist
│   └── mu-plugins/                      live inside the plugin (§37)
│
├── backups/
├── compose.yaml
├── .env.example
├── .gitignore
├── CLAUDE.md
└── README.md
```

Do not put the whole generated WordPress installation under Git unless
you have a specific reason.

Version-control:

-   Custom plugin
-   Docker configuration
-   scripts
-   documentation
-   tests
-   configuration templates

Let Docker provide WordPress itself.

------------------------------------------------------------------------

# 12. Git Configuration


If needed:

``` bash
git config --global user.name "Your Name"
git config --global user.email "your@email.com"
```

Create `.gitignore`:

``` gitignore
.env
.env.*
!.env.example

*.log

wp-content/uploads/

vendor/
node_modules/

.phpunit.cache/
.phpunit.result.cache/

.vscode/
.idea/

docker-data/
```

Never commit:

``` text
.env
API keys
payment secrets
shipping credentials
SMTP passwords
database passwords
webhook secrets
real customer data
```

------------------------------------------------------------------------

# 13. Docker Compose


Start with only:

``` text
WordPress
MySQL
WP-CLI
```

Add Redis/Mailpit/other services only when they become useful.

Example `compose.yaml`:

``` yaml
services:

  db:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql

  wordpress:
    image: wordpress:latest
    restart: unless-stopped
    depends_on:
      - db
    ports:
      - "8090:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD}
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wordpress_data:/var/www/html
      - ./wp-content/plugins/algerian-commerce-core:/var/www/html/wp-content/plugins/algerian-commerce-core

  wpcli:
    image: wordpress:cli
    depends_on:
      - db
      - wordpress
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD}
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - wordpress_data:/var/www/html
      - ./wp-content/plugins/algerian-commerce-core:/var/www/html/wp-content/plugins/algerian-commerce-core

volumes:
  db_data:
  wordpress_data:
```

This is a development starting point, not a production configuration.

The official WordPress Docker image documents the standard WordPress +
MySQL Compose approach. Docker Compose is designed to define and run
multi-container applications from a Compose file.

Once the environment works, pin tested image versions instead of relying
indefinitely on `latest`.

------------------------------------------------------------------------

# 14. Environment Variables


Create `.env` locally:

``` env
DB_PASSWORD=local_dev_password
DB_ROOT_PASSWORD=local_root_password

WP_PORT=8090

YALIDINE_API_KEY=
YALIDINE_API_SECRET=

ZEDAIR_API_KEY=
ZEDAIR_API_SECRET=

CHARGILY_SECRET_KEY=
CHARGILY_WEBHOOK_SECRET=

SMTP_HOST=
SMTP_USERNAME=
SMTP_PASSWORD=
```

Never commit `.env`.

Commit `.env.example` with empty placeholders.

Two notes on the values above:

-   `local_dev_password` is a **placeholder printed in this public
    document**. Do not keep it as your actual password — replace it, and
    never reuse a development password in staging or production (§76).
-   `WP_PORT` is not yet consumed by `compose.yaml`, which publishes
    `8090:80` directly. Either wire it up as `"${WP_PORT}:80"` or treat
    the variable as documentation until you do.

------------------------------------------------------------------------

# 15. Start WordPress


Run:

``` bash
docker compose up -d
```

Check:

``` bash
docker compose ps
```

Logs:

``` bash
docker compose logs -f wordpress
```

Open:

``` text
http://localhost:8090
```

Complete the initial WordPress installation.

Then install WooCommerce.

For the reusable template, prefer installing/configuring WooCommerce
through automation rather than relying on manual dashboard clicks.

## Pretty permalinks and /wp-json/

The custom REST API is reached at:

``` text
http://localhost:8090/wp-json/algerian-commerce/v1/...
```

That path only works with pretty permalinks. Two things must be true, and
neither is true out of the box:

``` bash
docker compose run --rm wpcli wp option get permalink_structure
```

1.  A permalink structure must be set. If the command above prints
    nothing, run:

    ``` bash
    docker compose run --rm wpcli wp rewrite structure '/%postname%/'
    ```

2.  Apache must honour the rewrite. The official `wordpress` image ships
    a vhost with `AllowOverride None`, so the `.htaccess` WordPress
    generates is **ignored** and every `/wp-json/` request returns 404 —
    even after step 1 succeeds.

    Fix it in the repository rather than inside the container, so it
    survives a fresh volume. Create `docker/apache-wordpress.conf`:

    ``` apache
    <Directory /var/www/html>
        AllowOverride All
        Require all granted

        RewriteEngine On
        RewriteBase /
        RewriteRule ^index\.php$ - [L]
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule . /index.php [L]
    </Directory>
    ```

    and mount it in `compose.yaml`:

    ``` yaml
    - ./docker/apache-wordpress.conf:/etc/apache2/conf-enabled/zz-wordpress.conf:ro
    ```

Verify:

``` bash
curl -o /dev/null -w '%{http_code}\n' http://localhost:8090/wp-json/
```

A 200 means the REST layer is reachable. Without this, `?rest_route=/`
still works and can mislead you into thinking REST is fine.

------------------------------------------------------------------------

# 16. WP-CLI


WP-CLI is the official WordPress command-line interface and is extremely
important for this project.

It lets you automate administration such as:

``` text
plugin installation
plugin activation
user management
options
content
database operations
development setup
```

Example:

``` bash
docker compose run --rm wpcli wp plugin list
```

Install WooCommerce:

``` bash
docker compose run --rm wpcli wp plugin install woocommerce --activate
```

Install Wordfence:

``` bash
docker compose run --rm wpcli wp plugin install wordfence --activate
```

Install Rank Math:

``` bash
docker compose run --rm wpcli wp plugin install seo-by-rank-math --activate
```

Install WP Mail SMTP:

``` bash
docker compose run --rm wpcli wp plugin install wp-mail-smtp --activate
```

The exact plugin list should be kept small and reviewed before
standardizing it.

------------------------------------------------------------------------

# 17. Recommended Free Plugin Baseline


Core:

``` text
WooCommerce
```

Security:

``` text
Wordfence Security
```

Email:

``` text
WP Mail SMTP
```

or:

``` text
FluentSMTP
```

SEO:

``` text
Rank Math SEO
```

Marketing:

``` text
Meta for WooCommerce
```

Optional:

``` text
Advanced Custom Fields
UpdraftPlus
```

Do not install multiple plugins that perform the same job.

Do not make the custom backend dependent on unnecessary plugins.

The custom plugin should own business logic.

------------------------------------------------------------------------

# 18. First-Day Checklist


``` text
[ ] WSL 2 works
[ ] Ubuntu works
[ ] Docker Desktop works
[ ] Docker WSL integration works
[ ] Git works
[ ] VS Code WSL works
[ ] Repository created
[ ] compose.yaml created
[ ] WordPress starts
[ ] MySQL starts
[ ] WP-CLI works
[ ] WooCommerce installed
[ ] Custom plugin directory mounted
```

------------------------------------------------------------------------

# 19. Claude Code Access Model


The recommended model is:

``` text
Claude Code
     |
     v
WSL repository
     |
     +-- Git
     +-- Docker CLI
     +-- WP-CLI
     |
     v
Docker
     |
     v
WordPress + WooCommerce
```

Run Claude Code from:

``` bash
cd ~/projects/algerian-commerce-backend
```

This gives Claude access to:

``` text
CLAUDE.md
docs/
compose.yaml
scripts/
wp-content/
tests/
```

Claude should not need a WordPress admin login to perform most
development work.

------------------------------------------------------------------------

# 20. Why Filesystem + CLI Is Better Than Dashboard Automation


Instead of:

``` text
click Plugins
click Add New
search plugin
install
activate
```

Claude can run:

``` bash
wp plugin install <plugin> --activate
```

Instead of creating PHP files manually:

``` text
Claude edits the plugin directly.
```

Instead of configuring dozens of settings manually:

``` text
Claude creates WP-CLI/setup scripts.
```

Instead of losing changes in a server:

``` text
Git records them.
```

This is the main productivity advantage.

------------------------------------------------------------------------

# 21. Install Claude Code


Install Claude Code using Anthropic's current official installation
instructions.

Then start it from the project root:

``` bash
cd ~/projects/algerian-commerce-backend
```

Do not start Claude from an unrelated directory.

The project root should contain:

``` text
CLAUDE.md
compose.yaml
docs/
scripts/
wp-content/
```

------------------------------------------------------------------------

# 22. CLAUDE.md


Create a permanent project instruction file:

``` md
# Algerian Commerce Backend

## Project

Reusable headless WordPress/WooCommerce backend for Algerian e-commerce.

## Architecture

WordPress + WooCommerce + algerian-commerce-core + REST API.

Next.js applications are separate clients.

## Source of truth

Read:
- docs/PLAN.md
- docs/ARCHITECTURE.md
- docs/SECURITY.md

before implementing major features.

## Rules

1. Never modify WordPress core.
2. Never modify WooCommerce core.
3. Business logic belongs in algerian-commerce-core.
4. Prefer official/supported WordPress and WooCommerce APIs.
5. Validate every external input.
6. Authorize every private API route.
7. Never expose secrets.
8. Never commit .env.
9. Payment status must be verified server-side.
10. Webhooks must be authenticated and idempotent.
11. Shipping providers must use adapters.
12. Write tests for business-critical functionality.
13. Do not implement future phases without explicit instruction.
14. Inspect existing code before changing it.
15. Avoid unnecessary rewrites.
16. Run relevant tests after implementation.
17. Report files changed, tests run and remaining issues.
18. Ask before destructive operations.
19. Never force-push or rewrite main history.
20. Never deploy production without explicit approval.
```

------------------------------------------------------------------------

# 23. The Three Documentation Layers


Use three different documents.

## PLAN.md

Answers:

> What are we building?

Contains the complete functional specification from the previous plan.

## ARCHITECTURE.md

Answers:

> How is it structured?

Contains:

``` text
modules
dependencies
data flow
provider abstraction
API architecture
database architecture
```

## CLAUDE.md

Answers:

> How should Claude work on it?

Contains:

``` text
rules
constraints
coding conventions
safety rules
workflow
```

This separation makes Claude much more reliable.

------------------------------------------------------------------------

# 24. Import the Previous Plan


Save the previous file as:

``` text
docs/PLAN.md
```

Do not turn the entire plan into one huge Claude prompt.

Claude should read it as project documentation.

The implementation should be incremental.

------------------------------------------------------------------------

# 25. Create ARCHITECTURE.md


Document:

``` text
REST API
    ↓
Controllers
    ↓
Services
    ↓
Domain/business logic
    ↓
WooCommerce adapters
    ↓
WooCommerce/WordPress
```

For integrations:

``` text
ShippingService
    |
    +-- YalidineProvider
    +-- ZedairProvider

PaymentService
    |
    +-- ChargilyProvider
```

Avoid provider-specific logic inside order controllers.

------------------------------------------------------------------------

# 26. Create SECURITY.md


Use:

``` md
# Security Requirements

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
```

Claude must read this before implementing authentication, payments,
webhooks or integrations.

------------------------------------------------------------------------

# 27. Claude's Permission Strategy


During development, allow Claude to:

``` text
read files
edit files
run tests
run Docker commands
run WP-CLI
run Git commands
```

Be cautious with:

``` text
docker compose down -v
git reset --hard
git clean -fd
DROP DATABASE
force push
production deployment
```

Require confirmation for destructive operations.

------------------------------------------------------------------------

# 28. First Claude Session Checklist


``` text
[ ] Claude launched from repository root
[ ] CLAUDE.md exists
[ ] PLAN.md exists
[ ] ARCHITECTURE.md exists
[ ] SECURITY.md exists
[ ] Claude can read files
[ ] Claude can run Docker
[ ] Claude can run WP-CLI
[ ] Claude can run tests
[ ] Claude can run Git status
```

------------------------------------------------------------------------

# 29. The Standard Claude Implementation Loop


For every feature:

``` text
1. Read specification
2. Inspect existing code
3. Plan
4. Implement
5. Run tests
6. Test API
7. Inspect logs
8. Fix failures
9. Review diff
10. Update documentation
11. Commit
```

Never let Claude silently implement five unrelated phases at once.

------------------------------------------------------------------------

# 30. Claude's Standard Feature Prompt


Use this pattern:

``` text
Read:
- CLAUDE.md
- docs/PLAN.md
- docs/ARCHITECTURE.md
- docs/SECURITY.md

Implement only [FEATURE/PHASE].

Before editing:
1. Inspect existing code.
2. Identify relevant WordPress/WooCommerce supported APIs.
3. Describe the implementation plan.

Implement:
- required functionality
- validation
- authorization
- tests
- error handling

Do not implement future phases.

After implementation:
1. Run tests.
2. Run relevant API checks.
3. Inspect logs.
4. Fix failures.
5. Review the diff.
6. Report changed files.
7. Report tests and results.
8. Report known limitations.
```

------------------------------------------------------------------------

# 31. Claude Review Prompt


After a significant implementation:

``` text
Review the current implementation without modifying it.

Look specifically for:
- security issues
- authorization problems
- IDOR
- WordPress API misuse
- WooCommerce compatibility problems
- unnecessary database queries
- duplicated business logic
- error handling
- missing tests
- secrets
- race conditions
- webhook idempotency

Return findings grouped by severity.

Do not change code yet.
```

Then decide which findings to fix.

------------------------------------------------------------------------

# 32. Development Workflow for Each Phase


Use:

``` text
Issue/task
   ↓
Claude reads docs
   ↓
Claude inspects code
   ↓
Claude proposes implementation
   ↓
Claude implements
   ↓
Tests
   ↓
API verification
   ↓
Security review
   ↓
git diff
   ↓
commit
```

Example:

``` bash
git checkout -b feat/products
```

Then after completion:

``` bash
git add .
git commit -m "feat: add product CRUD"
```

Merge only after review.

------------------------------------------------------------------------

# 33. Branch Strategy


Stable:

``` text
main
```

Features:

``` text
feat/core-plugin
feat/rbac
feat/products
feat/inventory
feat/orders
feat/customers
feat/algeria-data
feat/cod
feat/shipping
feat/yalidine
feat/zedair
feat/payments
feat/chargily
feat/analytics
feat/cms
feat/security
```

Keep `main` deployable.

------------------------------------------------------------------------

# 34. What You Should Do Manually


You still need to personally handle:

### One-time setup

-   WSL
-   Docker Desktop
-   Git
-   VS Code
-   Claude Code
-   GitHub repository

### Ongoing

-   Architecture decisions
-   Business rules
-   Reviewing important diffs
-   Approving destructive operations
-   Validating third-party API documentation
-   Testing real provider behavior
-   Security review
-   Final acceptance testing

### Production

-   Secret management
-   Backup policy
-   Deployment approval
-   Monitoring
-   Rollback decisions

------------------------------------------------------------------------

# 35. What Claude Should Do


Automate as much as possible:

``` text
plugin files
PHP classes
REST controllers
services
repositories
migrations
tests
WP-CLI commands
setup scripts
seed scripts
health scripts
API documentation
refactoring
debugging
log inspection
test execution
```

This is where most of your time savings will come from.

------------------------------------------------------------------------

# 36. First Claude Task: Inspect, Don't Build


Your first Claude prompt should be:

``` text
Read:
- CLAUDE.md
- docs/PLAN.md if present
- README.md

Do not implement business features yet.

Inspect the repository and development environment.

Verify:
1. Docker Compose configuration
2. WordPress
3. MySQL
4. WooCommerce
5. WP-CLI
6. custom plugin mount
7. Git

Identify what works and what is missing.

If something is broken, explain the root cause and propose the smallest fix.

Do not perform destructive operations.
```

The objective is to make the environment healthy first.

------------------------------------------------------------------------

# 37. Recommended Custom Plugin Structure


``` text
algerian-commerce-core/
│
├── algerian-commerce-core.php
├── composer.json
│
├── src/
│   ├── Core/
│   ├── API/
│   ├── Auth/
│   ├── Permissions/
│   ├── Products/
│   ├── Orders/
│   ├── Customers/
│   ├── Inventory/
│   ├── Analytics/
│   ├── Shipping/
│   ├── Payments/
│   ├── COD/
│   ├── CMS/
│   ├── Marketing/
│   ├── Notifications/
│   ├── Settings/
│   ├── Audit/
│   ├── Security/
│   └── ImportExport/
│
├── integrations/
│   ├── Yalidine/
│   ├── Zedair/
│   └── Chargily/
│
├── migrations/
├── tests/
└── README.md
```

Do not create custom copies of WooCommerce's product/order/customer
databases without a strong reason.

Use WooCommerce's supported APIs and data models.

Use custom tables for genuinely custom/high-volume domains such as:

``` text
audit events
shipment records
payment transactions
notification events
analytics aggregates
```

------------------------------------------------------------------------

# 38. Composer


Use Composer for PHP dependency management/autoloading if appropriate.

Example:

``` json
{
  "autoload": {
    "psr-4": {
      "AlgerianCommerce\\": "src/"
    }
  }
}
```

Then:

``` bash
composer dump-autoload
```

Do not add packages simply because Claude can.

Every dependency should have a reason.

------------------------------------------------------------------------

# 39. API Contract


Before building the full Next.js applications, stabilize the API.

Example:

``` text
GET    /products
POST   /products
GET    /products/{id}
PATCH  /products/{id}
DELETE /products/{id}

GET    /orders
GET    /orders/{id}

GET    /customers
GET    /customers/{id}

GET    /analytics/overview

POST   /shipping/shipments

POST   /payments/checkout
```

Standard response:

``` json
{
  "success": true,
  "data": {}
}
```

Error:

``` json
{
  "success": false,
  "error": {
    "code": "invalid_product",
    "message": "The product is invalid.",
    "details": {}
  }
}
```

Use pagination consistently.

------------------------------------------------------------------------

# 40. Second Claude Task: Plugin Foundation


Once the environment works:

``` text
Read:
- CLAUDE.md
- docs/PLAN.md
- docs/ARCHITECTURE.md
- docs/SECURITY.md

Implement the custom plugin foundation only.

Create:
- plugin bootstrap
- namespaces
- autoloading
- configuration
- REST API namespace
- standardized response format
- error handling
- health endpoint
- basic logging foundation

Do not implement products, orders, shipping or payments.

Add tests.

Run the tests.

Verify:
GET /wp-json/algerian-commerce/v1/health
```

Expected:

``` json
{
  "success": true,
  "status": "ok"
}
```

------------------------------------------------------------------------

# 41. First Git Commit


Review:

``` bash
git status
git diff
```

Then:

``` bash
git add .
git commit -m "feat: initialize backend platform"
```

Create your GitHub repository and push:

``` bash
git remote add origin <repository>
git push -u origin main
```

Never commit secrets.

------------------------------------------------------------------------

# 42. Security Implementation Order


Do not leave security until the final week.

Implement early:

``` text
authentication
authorization
validation
sanitization
rate limiting
CORS
audit logging
webhook verification
secret management
```

Later harden:

``` text
file uploads
IDOR
privilege escalation
XSS
CSRF
SQL injection
SSRF
replay attacks
privileged-user 2FA
```

Use WordPress's security APIs and supported authentication mechanisms.

## On 2FA (PLAN §3, SECURITY.md)

Deliberately in the "later" list rather than the early one, for an
architectural reason and not just scheduling.

2FA protects **interactive human logins**. Privileged API access uses an
Application Password held by the Next.js server, and those bypass 2FA by
design — there is nobody to prompt for a code. Adding 2FA changes nothing
about how the API is reached.

The surface it does protect is `wp-admin`, where humans sign in with real
passwords to accounts holding `ac_super_admin`. That is a WordPress-level
concern, and §54 already names **Wordfence** in the approved plugin baseline,
which provides it. Prefer configuring that to writing a TOTP implementation.

Revisit when a human-facing admin login exists that does not go through
`wp-admin` — see §70.

------------------------------------------------------------------------

# 43. Database Migrations


The custom plugin needs a schema version.

Example:

``` text
AC_DB_VERSION=1
```

When a new custom table is introduced:

``` text
AC_DB_VERSION=2
```

Create versioned migrations:

``` text
migrations/
  001_create_audit_logs.php
  002_create_shipments.php
  003_create_payment_transactions.php
```

Never make a production migration depend on deleting existing data.

------------------------------------------------------------------------

# 44. Authentication Architecture


For privileged admin operations:

``` text
Browser
   ↓
Next.js server
   ↓
WordPress API
```

Do not expose privileged WordPress credentials to browser JavaScript.

For customer authentication, design a dedicated customer/session
strategy.

Do not use an administrator credential in the browser.

For browser authentication, secure HTTP-only sessions/cookies are often
preferable to long-lived privileged tokens stored in browser storage.

## Status

**Admin authentication is implemented.** WordPress Application Passwords,
verified by core on `determine_current_user`, held by a dedicated service
account, with `GET /auth/me` for capability introspection. No login endpoint
and no token store of our own — core already owns credential storage,
rotation and revocation. Requires HTTPS, or `WP_ENVIRONMENT_TYPE=local` in
development.

**Customer authentication is not, and is deliberately blocked.** A session
strategy cannot be designed before cart and checkout exist, and PLAN §53 says
that architecture is finalized during implementation. It belongs with the
storefront work, not here.

Two rules to carry into it:

``` text
customers never receive an Application Password  (server-to-server only)
customer sessions are HTTP-only cookies          (SECURITY.md)
```

------------------------------------------------------------------------

# 45. RBAC and Audit


Before exposing serious admin functionality, implement:

``` text
roles
capabilities
permissions
audit logs
```

Suggested roles:

``` text
Super Admin
Admin
Manager
Order Manager
Product Manager
Marketing
Support
```

Test:

``` text
allowed
forbidden
privilege escalation
IDOR
```

Do not rely on the frontend hiding buttons.

Authorization belongs on the backend.

------------------------------------------------------------------------

# 46. CORS


Development origins may include:

``` text
http://localhost:3000
http://localhost:3001
```

Production origins will eventually be:

``` text
https://store.example.dz
https://admin.example.dz
```

Do not use:

``` text
Access-Control-Allow-Origin: *
```

for private APIs.

Keep the allowlist environment-specific.

------------------------------------------------------------------------

# 47. Product Implementation


> **Prerequisite.** Part VI must be complete first — §43 (migrations),
> §45 (RBAC and audit), §46 (CORS). Products introduce the first write
> endpoints, and §42 is explicit that authorization is built early rather
> than retrofitted into working code. The `unauthorized` test below cannot
> be written until capabilities exist.

First major feature:

``` text
Product CRUD
```

Implement:

``` text
GET    /products
POST   /products
GET    /products/{id}
PATCH  /products/{id}
DELETE /products/{id}
```

Test:

``` text
create
read
update
delete
unauthorized
invalid input
missing product
duplicate SKU
```

## Then, in slices

This section is too large for one branch. Build it in the order below —
each slice is independently reviewable, and each one's rules are inherited
by the slices after it (bulk operations in particular should come late, so
they wrap single-item behaviour that is already correct).

``` text
47a. CRUD + pagination + search + filtering   DONE  feat/products
47b. attributes + variations                  DONE  feat/products
47c. images (featured + gallery, by id)       DONE  feat/products
47d. bulk operations                          DONE  feat/products
47e. duplicate, sorting, category endpoint    DONE  feat/products
```

**47c — images.** Assigning existing media-library attachment ids belongs
here, and ids are verified to be image attachments before they are stored.
*Uploading* does not belong here: file handling brings the MIME/extension
allowlists, size caps, metadata stripping and non-executable storage that
`docs/PLAN.md` §24 (Media) specifies, and that work carries its own security
review rather than riding along inside product CRUD. It is scheduled in
**§61, "Media and uploads"** — until then, attachments must be created
through the WordPress dashboard.

**47d — bulk operations.** Each item goes through the single-item service, so
validation, authorization and audit are inherited rather than reimplemented.
Two decisions worth keeping: a per-item result list rather than
all-or-nothing (partial success is the expected outcome, so the response is
200 and the caller reads the results), and a hard cap on batch size.

**47e — sorting and categories.** Sorting is a list-endpoint argument
constrained to an enum. Category and tag *assignment* already existed through
`category_ids`; what was missing was a read endpoint for populating pickers.
Full taxonomy CRUD is `docs/PLAN.md` §5, not this section — deleting a
category silently detaches every product on it and deserves its own phase.

Section 47 is complete apart from image upload (§24). Continue to inventory.

------------------------------------------------------------------------

# 48. First Successful Backend Milestone


Do not define the first milestone as "WooCommerce is installed."

Define it as:

``` text
Next.js / curl
      |
      v
Custom REST API
      |
      v
Custom Plugin
      |
      v
WooCommerce
      |
      v
Product JSON
```

Then:

``` text
POST product
      |
      v
WooCommerce product created
```

Once that works, you have proven the fundamental architecture.

------------------------------------------------------------------------

# 49. Inventory


Implement:

``` text
stock quantity
stock status
low-stock threshold
manual adjustment
inventory history
bulk update
```

Every manual adjustment should record:

``` text
user
product
old quantity
new quantity
reason
timestamp
```

Use WooCommerce's supported mechanisms rather than direct database
manipulation unless there is a documented reason.

------------------------------------------------------------------------

# 50. Orders and Customers


Orders:

``` text
GET /orders
GET /orders/{id}
POST /orders
PATCH /orders/{id}
POST /orders/{id}/cancel
POST /orders/{id}/notes
```

Customers:

``` text
GET /customers
GET /customers/{id}
PATCH /customers/{id}
GET /customers/{id}/orders
```

Add:

``` text
search
filters
pagination
order history
timeline
statistics
```

Use WooCommerce's native commerce model where appropriate.

## Status

**Done.**

``` text
GET   /orders                  search, status/customer/date filters, sorting
POST  /orders                  catalogue-priced lines; no caller-set totals
GET   /orders/{id}
PATCH /orders/{id}             fields and status, through a transition matrix
POST  /orders/{id}/cancel      with a reason, recorded in the audit trail
GET   /orders/{id}/notes
POST  /orders/{id}/notes       customer_note defaults to false and is not coerced
GET   /orders/{id}/timeline    notes + audit + ledger, merged newest-first
GET   /customers               role-scoped; guests are not customer records
GET   /customers/{id}          profile and lifetime statistics
PATCH /customers/{id}          name, email, addresses — never roles or credentials
GET   /customers/{id}/orders
```

Order-driven stock movements reach the §49 ledger through WooCommerce's own
hooks, so they are recorded no matter what moved them — this API, wp-admin,
WP-CLI, cron or a payment gateway.

Amending the lines of an order that already holds stock is **implemented**, not
refused: the units are returned, the lines replaced, and the units re-taken, so
the ledger records all three moves and nets to what is actually held. Skipping
that reconciliation destroys WooCommerce's `_reduced_stock` markers and strands
the units silently.

There is deliberately **no `DELETE /orders/{id}`** — an order is cancelled, never
removed — and no `POST`/`DELETE` on customers, since account creation and
erasure belong to `ac_manage_users`.

The operational states PLAN §8 lists — COD Pending Confirmation, Shipping
Prepared, Shipped, Delivered, Returned — were **not** added as statuses. They
arrive as metadata and events in §52 and §53, which is what "avoid creating
redundant statuses when metadata/events are sufficient" asks for.

### Deferred, with their reasons

``` text
refunds
    — PATCH to status "refunded" works. Creating an actual
      WC_Order_Refund with amounts, and verifying it against the
      provider, is §57 payments.

COD history on a customer
    — §52 defines the confirmation states this would summarise.
      Nothing to count yet.

customer notes, account status / banning
    — PLAN §9 lists both; §50's endpoint list does not, and §52 says
      not to ban on a single weak signal, so the flag belongs with
      the risk signals rather than ahead of them. Needs storage
      (a table or user meta) — a decision, not an oversight.

customer-facing order access
    — a shopper reading their own order needs the session strategy
      deferred in §44. Permissions::assertOwnsOr() is already there
      for it. Every route here is administrative today.
```

------------------------------------------------------------------------

# 51. Algerian Geographic Data


Create importable data:

``` text
data/algeria/wilayas.json
data/algeria/communes.json
```

Do not bury thousands of geographic records inside arbitrary PHP files.

Create a WP-CLI command such as:

``` bash
wp algerian-commerce import-algeria
```

Store mappings required by shipping providers separately.

Example:

``` text
wilaya
commune
postal code
provider destination ID
```

Keep the dataset updateable.

## Status

**Done, data included.**

``` text
data/algeria/wilayas.json                 69 wilayas + Arabic names
data/algeria/communes.json                1,541 communes + Arabic names,
                                          daira, national code, coordinates
data/algeria/sources/                     the CSV they are generated from
data/algeria/provider-destinations.json   empty until §53

scripts/build-algeria-dataset.php         source CSV -> the two JSON files
wp algerian-commerce import-algeria [--dry-run]

GET /locations/wilayas
GET /locations/wilayas/{id}
GET /locations/wilayas/{id}/communes
GET /locations/communes/{id}
GET /locations/coverage
```

Algeria has **69 wilayas**: the 58 of the 2019 reform plus the eleven former
circonscriptions administratives — Aflou, Barika, El Kantara, Bir El Ater,
El Aricha, Ksar Chellala, Ain Oussera, Messaad, Ksar El Boukhari, Boussaâda
and El Abiodh Sidi Cheikh — since promoted in full.

Nothing was written from memory. Codes 01-58 come from WooCommerce's own
`i18n/states.php` `DZ` block; codes 59-69 and every Arabic name from a supplied
CSV, converted by a committed build script so the dataset is a diff rather than
an origin story.

Two corrections, printed with their evidence on every build:

``` text
11 rows carry Ouargla's code 30 while being named Touggourt (55)
    the 2019 split was half-applied; the name is followed
    Touggourt ends with its 13 communes instead of 2

daira Bousaada was still filed under M'Sila (28)
    a wilaya is named after its chef-lieu, and 10 of the 11 new
    wilayas contain their namesake commune — Boussaâda (68) was
    the only one that did not
    Bou Saada, El Hamel and Oulteme move to 68
```

A chef-lieu check now runs on every build and prints any wilaya holding no
commune of its own name, so that class of misfiling is caught rather than
noticed. Seven wilayas stay on the list permanently (Algiers/Alger Centre,
Tipasa/Tipaza, In Salah/Ain Salah …) — spelling, not misfiling — which is why
it reports and never enforces.

Result: 69 wilayas, 1,541 communes — Algeria's exact count — every wilaya
non-empty, no slug collisions.

**Postal codes are still absent**, because the source has none. `code_commune`
is the national commune code (3-4 digits) and not a postal code (5), so it is
stored as `national_code` and `postal_code` stays empty. Mapping one onto the
other would have put a wrong postal code on every address in the country. If
postal codes matter to a client, they are a second dataset merged into
`communes.json` and re-imported.

Decisions worth keeping:

``` text
wilaya PK  = the official code 1-69, a real natural key
commune PK = auto-increment, natural key (wilaya_id, slug)
slugs      = accent-folded, so Bejaia and Béjaïa are one commune
import     = all-or-nothing, idempotent, never deletes
provider   = its own table; ids are opaque strings, never parsed
/locations = the only public routes in the plugin
coordinates = carried for §53, not used yet
```

------------------------------------------------------------------------

# 52. COD


Implement:

``` text
cod_enabled
confirmation status
confirmation attempts
confirmed_at
cancelled_at
cancellation reason
```

Statuses:

``` text
PENDING
CONFIRMED
REJECTED
UNREACHABLE
CANCELLED
```

Track:

``` text
confirmation rate
cancellation rate
delivery rate
return rate
```

Do not automatically ban customers based on a single weak signal.

## What was built

Order meta plus audit events — **no new order statuses and no table of its
own**, which is what PLAN.md §8's "avoid creating redundant statuses when
metadata/events are sufficient" asks for. `Schema::VERSION` was unchanged.

``` text
GET   /orders/{id}/cod            the state
PATCH /orders/{id}/cod            enable or disable COD for that order
POST  /orders/{id}/cod/attempts   record a confirmation call → 201
GET   /cod/statistics             the funnel (customer_id, date_from, date_to)
```

One endpoint records a call, carrying its outcome, rather than three verb
endpoints: one state machine, one audit path, and a new outcome later is an enum
value rather than a fourth endpoint and a fourth set of tests.

Decisions worth keeping:

``` text
history      = ac_audit_logs, already append-only and already merged
               into the order timeline; a cod_attempts table would be a
               third copy of what two stores hold
state machine = pending → confirmed | rejected | unreachable | cancelled,
               every legal move listed including unreachable → unreachable
               (a second failed call). No blanket self-transition rule:
               recording an outcome is an event, not an idempotent PATCH
confirmed → rejected is absent; confirmed → cancelled is present.
               A customer who says yes and later changes their mind has
               cancelled, and folding the two together makes the
               confirmation rate count one event two ways
no auto-transition = a COD outcome never changes the order's status
CodSubscriber = woocommerce_order_status_cancelled closes the COD state
               whatever cancelled the order — wp-admin, WP-CLI, cron, a
               gateway. This direction is what keeps Orders/ unaware of COD/
untouched orders = an order paid `cod` with no COD meta reads as enabled
               and pending, and the funnel counts it, so the state
               endpoint and the statistics cannot disagree
rates        = one denominator, every COD order in scope. Confirmation
               counts orders *ever* confirmed, from the confirmed_at
               stamp, so one confirmed then cancelled is in both rates
ENABLE_COD   = deliberately not read. It gates what checkout offers,
               which is §58; freezing these endpoints when a shop stops
               taking new COD would strand every order already in flight
```

### Deferred, with their reasons

``` text
cancellation reason on the COD state
    — the WooCommerce hook carries none, and the reason an operator
      typed is already on the order.cancelled audit row with its actor.
      `reason` holds the most recent *outcome's* reason instead

delivery and return rates from a courier
    — derived from order status (completed / refunded) because that
      was the only outcome record that existed. See §56
```

------------------------------------------------------------------------

# 53. Shipping Abstraction


Before implementing Yalidine or Zedair, create:

``` php
interface ShippingProviderInterface
{
    public function createShipment(array $order): ShipmentResult;

    public function cancelShipment(string $trackingId): bool;

    public function getShipmentStatus(string $trackingId): ShipmentStatus;

    public function getShippingRates(array $destination): array;
}
```

Then:

``` text
ShippingService
      |
      +-- YalidineProvider
      +-- ZedairProvider
```

The order service should say:

``` text
create shipment
```

not:

``` text
call Yalidine endpoint
```

This keeps the core reusable.

## What was built

The abstraction, the shipment records, and **one working provider** — an
abstraction with no implementation cannot be exercised, and `ac_shipments` is
what §56 writes into anyway.

``` php
interface ShippingProviderInterface
{
    public function name(): string;
    public function label(): string;
    public function createShipment(ShipmentRequest $request): ShipmentResult;
    public function cancelShipment(string $providerShipmentId): bool;
    public function getShipmentStatus(string $providerShipmentId): StatusReport;
    /** @return list<RateQuote> */
    public function getShippingRates(Destination $destination): array;
}
```

**Two deliberate deviations from the sketch above.** The shapes are value
objects rather than bare arrays: with an array every adapter re-derives what a
valid request looks like and the third gets it subtly wrong. And a provider is
addressed by its own shipment id rather than the tracking number — at some
couriers those are the same string and at others they are not, and assuming they
are is a bug that only appears at the one that disagrees. ARCHITECTURE.md §4 was
updated to match the code.

``` text
GET   /shipping/providers        the couriers this shop has
GET   /shipping/rates            quotes for a destination
GET   /orders/{id}/shipments     the order's parcels
POST  /orders/{id}/shipments     hand a parcel to a courier → 201
GET   /shipments                 list, filterable by tracking number
GET   /shipments/{id}            read
PATCH /shipments/{id}            move it on by hand
POST  /shipments/{id}/cancel     call it off at the provider
POST  /shipments/{id}/sync       ask the courier where it is
```

Decisions worth keeping:

``` text
ManualProvider = in-house delivery, and not a stub: many Algerian shops
               deliver inside their own wilaya and hand only the distant
               orders to a courier. It refuses getShipmentStatus() with a
               409 rather than answering, because inventing "created"
               would look like a successful sync and would walk a parcel
               a person had already advanced back to the beginning
no transition matrix = a courier owns reality and reports late and out of
               order. Refusing a status for not following our sequence
               would mean the record disagreeing with the parcel. The one
               rule enforced: a finished shipment stays finished
provider_status = each adapter maps a courier's states onto ours AND
               stores the courier's own spelling. A mapping that silently
               falls through to a default is the most likely defect in a
               courier integration, and this makes it a query
no auto-transition = a parcel's status never moves the order (PLAN §8)
one live shipment per order = enforced in the service, not by a unique
               key: an unaccepted shipment has an empty provider id and
               MySQL treats '' as a value. A finished shipment does not
               block a re-send
destination  = wilaya + commune ids from §51, validated as a pair before
               a provider sees them. Never derived from the order's
               free-text city — Algeria has same-named communes in
               different wilayas
provider called last = after everything refusable has been refused, so a
               400 never arrives once a van already has the parcel. If
               the row still fails to write, shipment.record_failed goes
               to the audit trail with the tracking number on it
reference    = "42-2", the second parcel for order 42. Couriers accept a
               merchant reference and most treat it as an idempotency
               key, so a retried create returns the existing parcel
```

### Deferred, with their reasons

``` text
Yalidine and Zedair adapters
    — §54: an adapter is never written from memory. §56 and §57, with
      each provider's current official documentation

shipping rules and pricing
    — PLAN §14, and a step the build sequence was missing entirely.
      Added as §4 step 28b and built straight after this section:
      ac_shipping_rates (migration 005), /shipping/rules CRUD, and
      RateResolver picking the narrowest matching rule. Deliberately
      not WooCommerce shipping zones — they key on postcodes, which
      §51's dataset does not have, and pricing here is per commune

a delivered shipment completing the order
    — an automatic order transition driven by a third party's webhook
      needs the idempotency and replay design §55 exists for. See §56

parcel weight, dimensions and contents on ShipmentRequest
    — every courier wants them in a different shape. Inventing the
      fields now, with no provider documentation in front of us, is
      exactly what §54 forbids. They arrive with the first adapter whose
      docs say what it actually requires
```

------------------------------------------------------------------------

# 54. Third-Party Integration Rule


Never tell Claude:

``` text
"Just figure out Yalidine."
```

Instead give it:

``` text
current official API documentation
authentication requirements
request/response examples
webhook documentation
sandbox credentials
```

Then ask it to create:

``` text
adapter
tests
error mapping
logging
idempotency
```

The same applies to Zedair and Chargily.

Third-party APIs can change and this document should not be treated as
an API specification for those providers.

------------------------------------------------------------------------

# 55. Security Review Before Each Integration


Before activating a provider, verify:

``` text
credentials stored securely
TLS
signature verification
request validation
timeouts
retry behavior
idempotency
logging
sensitive-data handling
failure behavior
```

Do not log:

``` text
API secrets
payment secrets
full authentication headers
unnecessary card/payment information
```

## Done — the review, and what it changed

Walked against everything that ships: `Auth/`, `Security/`, `Permissions/`, `Http/`, `COD/`, `Shipping/`,
`integrations/Yalidine/`, `integrations/ZRExpress/`.

Clean against the list: credentials are `.env`-only and read in one place; TLS is not switchable off and a
non-`https://` base URL is refused; every REST arg pairs `sanitize_callback` with `validate_callback`; the
one retry is 429-only and bounded, and `POST parcels/` is never retried blind; the provider is called last
in a create, and every SQL statement is prepared.

Four things it found, all fixed here:

``` text
label URLs      Yalidine's `label`/`labels` are URLs carrying an access
                token — a credential to one customer's PII. Now masked by
                Logger::SENSITIVE_EXACT and documented in SECURITY.md.
timeout ceiling a per-client `timeout` had a floor and no cap, so an option
                could hold a PHP worker for ten minutes. Capped at 60s in
                both settings classes, clamped and reported.
masked logs     Logger::redact() masks any key containing "key", which was
                redacting the hashed rate-limit bucket and Yalidine's
                "which parcel did you answer about" list into uselessness.
                Renamed to `bucket` and `answered_for`.
retry-after     RateLimitGuard read an absent array key on every 429 from
                the WP_Error path.
```

**The webhook rule is the deliverable**, and it lives in `docs/SECURITY.md` under "Webhooks": where the
secret lives, verification on the raw body with `hash_equals()`, the split between a real signature (Svix,
Chargily) and a body secret that binds to nothing (Yalidine's `security_token`, which is a hint to re-fetch
and never a source of truth), a 5-minute timestamp tolerance, an event id *claimed* by a write-once insert
rather than checked, and 401 `webhook_unverified` saying nothing about which check failed. §56 and §57's
webhooks are unblocked, and so is §58.

------------------------------------------------------------------------

# 56. Yalidine


Implement the adapter from Yalidine's **current official API
documentation** at the time of implementation.

Do not invent endpoints or payloads.

## Sources — read these before writing anything

There is **no merchant account and no sandbox**, so nothing here can be
confirmed against the live API. §54 forbids writing an adapter from memory; it
does not forbid writing one from working code. Three independent implementations
agree on everything below, which is stronger evidence than a single document:

``` text
1. /mnt/c/Users/MyHomehP/Desktop/work/EL/api        (primary)
   Spring Boot, in production against the live API.
   service/shipping/YalidineService.java            42 KB, the whole flow
   service/dto/shipping/Yalidine*.java              request/response shapes
   web/rest/ShippingWebhookResource.java            the webhook endpoint
   Its admin client is at ../el-admin-app (5 order statuses, provider
   enum YALIDINE|ZR) — useful for the operator's view, not the API.

2. Createk Delivery for Yalidine 1.2.2              (GPL, WordPress.org)
   https://fr.wordpress.org/plugins/createk-delivery-for-yalidine/
   includes/class-yalidine-api.php is a clean 211-line client.
   READ FOR FACTS ONLY — it is GPL and this plugin is proprietary.
   Endpoints, field names and HTTP behaviour are facts; its code is not.

3. CourierDZ (MIT) and dzship docs/statuses.md      (cross-check)
   https://github.com/PiteurStudio/CourierDZ
   https://github.com/DZBuild-com/dzship
```

## The API, as all three sources agree

``` text
base     https://api.yalidine.app/v1/
auth     X-API-ID, X-API-TOKEN            (headers, both required)
quota    429 + Retry-After header         (obey the header; the limit
                                           itself is not published)
paging   ?page_size=1000                  on list endpoints
verify   GET wilayas/                     cheap credential test — this is
                                           what a client's setup screen calls
                                           to prove their keys work

GET  wilayas/                             destination sync source
GET  communes/                            destination sync source
GET  centers/                             stop desks
GET  fees/?from_wilaya_id=&to_wilaya_id=  rates
POST parcels/                             array in; object out, keyed by
                                           OUR order_id
GET  parcels/{tracking}                   status poll
```

**`POST parcels/` takes an array and returns an object keyed by `order_id`.**
Send `ShipmentRequest::$reference` as `order_id` and the response can be found
by it. An empty array `[]` in response means **rejected**, almost always because
`to_commune_name` did not match a Yalidine commune exactly — which is precisely
what the destination sync exists to prevent. Map it to a named error, never to
"unexpected response".

Parcel payload, exact field names (sources 1 and 2 agree):

``` text
order_id  from_wilaya_name  firstname  familyname  contact_phone
address   to_commune_name   to_wilaya_name  product_list  price
do_insurance  declared_value  length  width  height  weight
freeshipping  is_stopdesk  stopdesk_id  has_exchange
```

`from_wilaya_name` and the insurance/dimension defaults are **per-client
settings**, not template constants — see the settings note below.

`GET fees/` returns per-commune `express_home`, `express_desk`,
`economic_home`, `economic_desk`, plus `zone`, `return_fee`, `cod_percentage`,
`insurance_percentage`, `oversize_fee`. Four RateQuote services per commune.

Webhook payload, from `YalidineWebhookPayload.java` (source 1):

``` text
event  tracking_number  order_id  status_code  status  sub_status
date   updated_at  comment  wilaya  commune  attempts
amount_collected  amount_due  receiver_name  phone
signature_url  fail_cause  security_token
```

`security_token` is a **shared secret in the body** — not a signature. Compare
it in constant time, and treat the payload as a *hint*: re-fetch
`GET parcels/{tracking}` and trust that, the same way CLAUDE.md requires payment
status to be verified server-side rather than believed from a callback.
`amount_collected` / `amount_due` are what COD reconciliation will need later.

## The 36 statuses, and the one gap they exposed

The complete `last_status` vocabulary, from the merchant dashboard's filter:

``` text
created     Pas encore expédié · A vérifier · En préparation ·
            Pas encore ramassé · Prêt à expédier · En passation
picked_up   Ramassé
in_transit  Transfert · Expédié · Centre · En localisation ·
            Vers Wilaya · En transit · Reçu à Wilaya ·
            Prêt pour livreur · En attente · En attente du client ·
            Bloqué · Débloqué · En alerte · Alerte résolue
out_for_delivery  Sorti en livraison · Tentative échouée
returning   Retour vers centre · Retourné au centre ·
            Retour transfert · Retour groupé · Retour à retirer ·
            Retour non retiré · Echèc livraison
delivered   Livré
returned    Retourné au vendeur
cancelled   Annulé
failed      Colis abandonné · Echange échoué
```

**`returning` does not exist yet and must be added to
`Shipping\ShipmentStatus`** — a non-terminal state, with
`returning → returned | failed` legal. Seven statuses say the parcel is coming
back but has not arrived, and neither existing value can carry them: `returned`
is terminal, so tracking would stop while the parcel is still moving and a COD
shop could not tell *on its way back* from *back in my hands*; `in_transit`
reads to an operator as heading to the customer. The column is `varchar(30)`
and `TERMINAL` is unchanged, so this is additive — no migration.

Two traps in the same table, both of which the reference implementation falls
into by matching substrings: **Tentative échouée** is a retried attempt and
**Bloqué** is a hold. Neither is terminal, and both contain words that a naive
keyword match reads as failure.

Match accent- and case-insensitively — these are dashboard labels and the API
may differ in accent or case (note *Echèc livraison* as the dashboard spells
it) — and always store the raw word in `StatusReport::$providerStatus`.

## Per-client settings versus template constants

This plugin is cloned per client, so **nothing courier-specific may be
hard-coded**. Credentials come from `.env` (`YALIDINE_API_ID`,
`YALIDINE_API_TOKEN`, `YALIDINE_WEBHOOK_SECRET`, `ENABLE_YALIDINE`), read only
in `Plugin::shippingProviders()`. Everything else a client configures — origin
wilaya, whether they have stop desk / exchange / insurance, default parcel
dimensions and weight — belongs in settings, not in code and not in `.env`.

The reference implementation hard-codes a 58-case wilaya-name→id `switch` and an
`UNSUPPORTED_WILAYAS` set in Java. **Do neither.** Coverage is data: the
destination sync writes it, and the sync report names the wilayas this client's
account cannot reach.

## What is still unconfirmed

``` text
the published quota numbers   handled by obeying Retry-After
the create error catalogue    passed through raw; grows with real failures
API vs dashboard spelling     mitigated by accent-insensitive matching
                              plus the stored raw value
```

Mark each of these in the adapter where it is assumed, so the first live call
proves or disproves it visibly.

## Everything else is already waiting

``` text
ShippingProviderInterface   implement it; nothing above it changes
Plugin::shippingProviders() the one place a courier is switched on,
                            behind ENABLE_YALIDINE and its credentials
ShipmentRequest::$reference "42-2" — send as the merchant reference,
                            which most couriers treat as an idempotency
                            key, so a retried create returns the parcel
                            it already made instead of a second one
ac_geo_provider_destinations  §51's table, still empty. Populate it from
                            Yalidine's own destination list — never from
                            memory, and never by parsing their ids
StatusReport::$providerStatus  store their word next to our mapped one
```

Three things this section should also settle, each recorded here when it was
deferred rather than discovered later:

``` text
webhooks
    — §55's security review comes first: signature verification and
      idempotency, since replaying an event must not re-apply it

should a delivered parcel complete the order?
    — deferred from §53 deliberately. It is an automatic order
      transition triggered by a third party, so it needs the replay and
      idempotency design of §55 before it is wired to anything

COD delivery and return rates
    — §52 derives them from order status because, with only in-house
      delivery, a shipment status is hand-entered and no more
      authoritative. Once a real courier reports its own, re-derive both
      from ac_shipments
```

Cover as applicable:

``` text
authentication
destination mapping
rates
shipment creation
cancellation
tracking
status synchronization
labels
webhooks
errors
timeouts
```

Test:

``` text
successful shipment
invalid destination
duplicate shipment
provider timeout
authentication failure
provider API failure
```

## What was built

Five slices, in this order, each one usable before the next began.

``` text
1  Shipping\ShipmentStatus gains `returning`  — live, not terminal, no
   migration. Seven of Yalidine's states say a parcel is on its way back and
   has not arrived
2  wp algerian-commerce sync-destinations     — the courier's own wilaya,
   commune and centre lists into ac_geo_provider_destinations, with the gaps
   reported in both directions
3  integrations/Yalidine/                     — the adapter, behind
   ShippingProviderInterface, tested against recorded responses
4  wp algerian-commerce sync-shipments        — poll-based status sync, plus
   an hourly cron event
5  docs                                       — README, ARCHITECTURE §3/§4,
   CLAUDE.md, this section
```

Decisions worth keeping:

``` text
ASSUMPTION markers   every point the three sources are silent on is marked
                 `ASSUMPTION (unverified)` where it is assumed, and listed
                 in the README: the `page` parameter, the wilaya/commune row
                 shapes, Retry-After being seconds, the single-parcel
                 endpoint returning a bare object, order_id being
                 idempotent, and freeshipping meaning "collect exactly
                 price". grep -rn ASSUMPTION integrations/Yalidine
coverage is data the sync stores Yalidine's *own spelling* of every wilaya
                 and commune, and the adapter quotes it back to them. That
                 is what removes the reference implementation's 58-case
                 name switch and its UNSUPPORTED_WILAYAS set — both
                 per-account facts pretending to be constants
gaps, not guesses  a name that will not match stays unmatched and is named
                 in the report. The reference implementation falls back to
                 substring matching at creation time, which is how a parcel
                 gets addressed to a place nobody chose
whole labels     the 36 statuses are matched entire, accent- and
                 case-folded through GeoSlug, never by substring: Tentative
                 échouée is a retry and Bloqué is a hold. An unmapped label
                 throws rather than defaulting
cancellation refused  no source documents a cancel endpoint, so there is
                 none. cancelShipment() answers 409 with "cancel it in the
                 dashboard, then mark this shipment cancelled" rather than
                 returning false, which would put words in Yalidine's mouth
                 about a call nobody made
Http\HttpClientInterface  the transport is injected, so authentication, the
                 429/Retry-After path, timeouts and every payload field are
                 unit-tested against fixtures. With no sandbox, that is the
                 only evidence that exists before the first live call
settings vs .env  credentials in .env, read only in
                 Plugin::shippingProviders(); origin wilaya, insurance,
                 exchange, freeshipping and parcel defaults in the
                 ac_yalidine_settings option. A bad option value falls back
                 and is reported, never fatal
shipping-check   one command answering "can this store ship anything" —
                 credentials, geography, destinations, origin — because
                 those four otherwise fail one 409 at a time, in front of a
                 customer
poll before webhook  §55's review comes first, and the poll is what a
                 webhook payload will be verified against anyway
```

## Verified against the live API, 2026-08-14

The adapter shipped with its guesses marked. They were then tested for real,
using the merchant credentials of the primary source project with its owner's
permission — read-only calls, then two test parcels created and deleted again.

``` text
confirmed   page= paging; the wilaya row {id,name,zone,is_deliverable}, whose
            flag is 1/0 rather than a JSON boolean; the commune row, plus
            delivery_time_parcel / delivery_time_payment; optional dimensions;
            fees keyed by commune id with retour_fee — not return_fee, as this
            section had it; the status vocabulary, spelled exactly as the
            dashboard spells it

wrong       GET parcels/{tracking} is wrapped in {data:[…]}, and a forgotten
            parcel is a 200 with total_data 0, not a 404
wrong       order_id is NOT an idempotency key — the same one posted twice
            produced two parcels. §53's claim that "most couriers treat it as
            one" is now known false for this courier. GET parcels/?order_id=
            does work, so the adapter looks before it creates
wrong       DELETE parcels/{tracking} exists. Cancellation was refused on the
            grounds that no source documented it; a delete aimed at an
            impossible tracking number settled the question at no risk

better      the quota is published on every response — second/minute/hour/day
            -quota-left, at 5/50/1000/10000 — rather than only at a 429. The
            client waits out a spent second instead of earning the refusal
still open  Retry-After's format, since provoking a 429 means exhausting a
            live merchant's quota; and who absorbs the fee under
            freeshipping:true, which is visible only in a payout
```

**The one rule this evidence changed.** This section said never to parse a
provider's ids, written when nobody could check them. Checked: every wilaya
matched by name carried an id identical to the official Algerian code — 54
agreements, no disagreement — while four failed on spelling alone (*Alger*
against our *Algiers*) and took 96 communes with them. The code now breaks a tie
the name could not, for a wilaya only, never over a name, never for a place
another already claimed, and every such row is recorded and reported. The rule
was right about guessing and wrong about ids; the fix keeps the first half.

Two gaps a live account made visible, neither of them an adapter bug:

``` text
~338 communes  transliteration variance between two romanisations — "In Zghmir"
               against our "Ain Zghmir". The report now names the nearest
               candidate and its edit distance; closing them means an alias in
               §51's dataset, reviewed by someone who knows the country. Not a
               fuzzy match: at three edits "Bitam" and "Batna" are neighbours
95 communes    the 11 wilayas promoted after 2019. We model 69, Yalidine still
               models 58 and files those communes under the old parent. A
               disagreement about Algeria's map, not about the API — and §57
               will meet it too
```

### Deferred, with their reasons

``` text
the webhook
    — §55's security review first. `security_token` is a shared secret in
      the body, not a signature, so verification is a constant-time compare
      plus a re-fetch of the parcel; replay protection is the rest of it
a delivered parcel completing the order
    — still an automatic order transition driven by a third party. It wants
      the replay design the webhook slice brings
COD delivery and return rates from ac_shipments
    — now possible, since a real courier reports its own statuses. §52
      still derives both from the order status
choosing a specific stop desk
    — a collected parcel goes to the first desk the sync found in that
      commune. Letting a customer pick one needs a stopdesk id on
      ShipmentRequest and a storefront that can list desks, which is §58
parcel weight and dimensions per order
    — per-client settings for now, which is what Yalidine's payload
      actually needs. A per-order weight is a product-data question
```

------------------------------------------------------------------------

# 57. ZR Express (was "Zedair")


**The section title was wrong.** No Algerian courier called "Zedair" exists —
repeated searches find nothing, and the provider actually integrated everywhere
in this market is **ZR Express**. Treat every earlier mention of Zedair,
including `ENABLE_ZEDAIR` and `ZEDAIR_*` in `.env.example`, as meaning ZR
Express, and rename them when this section is built.

Use exactly the same provider abstraction. If adding this adapter requires a
change *above* `ShippingProviderInterface`, that is a defect in the abstraction
and should be fixed there rather than worked around here — §53 exists to make
this section additive.

## Sources

``` text
1. /mnt/c/Users/MyHomehP/Desktop/work/EL/api        (primary)
   service/shipping/ZrExpressService.java           64 KB, in production
   service/dto/shipping/ZrExpress*.java             request/response shapes

2. https://docs.zrexpress.app/llms.txt              (official endpoint index)
   The docs site is an SPA; llms.txt is the only crawlable artefact and it
   lists endpoints without schemas.

3. https://api.zrexpress.app/swagger/index.html     (the real specification)
   The UI is public; the spec at /swagger/docs/v1.0 returns 401, so it needs
   an authenticated session. If an account ever exists, fetch it — it would
   close both gaps below at once.
```

## The API

``` text
base     https://api.zrexpress.app/api/v1.0
auth     X-Tenant, X-Api-Key              (headers; Bearer JWT also accepted)
errors   RFC 7231 problem+json — {type,title,status,detail,traceId}

GET  territories/search                   destination sync source
GET  hubs/search                          stop desks / pickup points
GET  rates                                all territories
GET  rates/{territory}                    one territory
POST customers  ·  GET customers/search   see the customer step below
POST parcels    ·  POST parcels/bulk      (bulk max 100)
GET  parcels/{id}  ·  GET parcels/tracking/{trackingNumber}
GET  parcels/search  ·  GET parcels/{id}/state-history
PUT  parcels/{id}/state  ·  DELETE parcels/{id}
POST parcels/labels/generate-multiple     (max 250, HTML)
GET  webhooks/endpoints/{id}/secret       signing secret — see gaps
```

**Destinations are GUIDs, not names.** A parcel needs a `cityTerritoryId`
(wilaya level) and a `districtTerritoryId` (commune level), both UUIDs from
`territories/search`. The reference implementation resolves them from names at
request time through in-memory caches with partial-match fallbacks; that is what
`ac_geo_provider_destinations` replaces. Store the district UUID as
`destination_id` and the city UUID in `metadata` — never parse either.

**A parcel needs a customer first.** `customers/search` by phone, then
`customers/individual` to create if absent, then the parcel carries the customer
UUID. Two API calls before the parcel exists; both must be idempotent on retry.

Parcel payload, from `ZrExpressParcelRequest.java`:

``` text
customer{customerId|name,phone{number1,number2,number3}}
deliveryAddress{cityTerritoryId, districtTerritoryId, street}
hubId  deliveryType("home"|"pickup-point")  amount  weight{weight}
externalId  description
orderedProducts[{productName, quantity, unitPrice, stockType}]
```

`externalId` is where `ShipmentRequest::$reference` goes — ZR's merchant
reference, and the idempotency key.

`GET rates/{territory}` returns `toTerritoryId`, `toTerritoryName`,
`toTerritoryLevel` and `deliveryPrices[{deliveryType, price, discountedPrice}]`.
**Rates are restricted by the supplier's origin wilaya**: a real 400 from
production reads *"Suppliers can only request rates for all wilayas or communes
of their origin wilaya"*. Model that as coverage rather than rediscovering it —
quote what the account may quote, and say so plainly when it may not.

## Two gaps, and how to work without them

``` text
the parcel state enumeration
    Not in llms.txt, and the reference implementation guesses it with
    substring matching on state.name — contains("livre"), contains("retour")
    — falling through to "Unknown ZR Express status". That is the same trap
    §56 documents: "échoué" appears in states that are not failures.
    Until the list is known: map only what is certain, treat everything
    else as unmapped rather than guessing, and always store the raw
    state.name in StatusReport::$providerStatus. An unmapped status must be
    visible, never silently wrong.

the webhook signature scheme
    docs confirm GET webhooks/endpoints/{id}/secret exists but not the
    header name, the algorithm, or what is signed. Do not invent an HMAC.
    Ship status sync by POLLING parcels/tracking/{trackingNumber}, which
    needs no signature; add webhooks when the scheme is known.
    The reference implementation has no ZR webhook handling at all — its
    validateWebhook() takes a *Yalidine* payload type, which is the
    abstraction leaking. Do not copy that shape.
```

`UNSUPPORTED_WILAYAS` is hard-coded in the reference as Illizi, Tindouf, Djanet
and Bordj Badji Mokhtar. **Do not hard-code it** — the destination sync finds
which territories exist for this client's account and records the gaps as data.

The core application should not need to know whether the shipment is
handled by:

``` text
Yalidine
ZR Express
future provider
```

Only the adapter should know provider-specific details.

## What was built

`integrations/ZRExpress/`, and **nothing above ShippingProviderInterface
changed** — which is what this section was written to test. The destination
sync, the status poller, `shipping-check` and the CLI were all built for
Yalidine without knowing this provider existed, and took it unmodified. Two
couriers that disagree about almost everything fit the same interface:

``` text
                    Yalidine              ZR Express
destination         wilaya + commune      cityTerritoryId +
                    NAMES, exact-matched  districtTerritoryId, UUIDs
recipient           inline on the parcel  a customer record, searched by
                                          phone and created if absent
identifiers         tracking only         parcel id AND tracking number,
                                          different strings
merchant reference  NOT idempotent        idempotent — a repeat is a 409
states              36 French labels      12 snake_case identifiers
```

`ENABLE_ZEDAIR` and `ZEDAIR_*` are renamed to `ENABLE_ZR_EXPRESS`,
`ZR_EXPRESS_TENANT_ID`, `ZR_EXPRESS_API_KEY` and `ZR_EXPRESS_WEBHOOK_SECRET`.

### Both gaps closed, from the provider's own documentation

``` text
the parcel states   state.name is a stable snake_case identifier with a
                    separate human description — not the French label the
                    reference implementation substring-matches on. Twelve
                    were observed across real parcels' state histories and
                    are mapped; anything else raises. An identifier does
                    not change because somebody fixed an accent
the webhook scheme  Svix — svix-id, svix-timestamp, svix-signature,
                    HMAC-SHA256 over id.timestamp.body. A published
                    standard, so there is nothing to invent. The webhook
                    still waits for §55, as Yalidine's does
```

ZR Express publishes an **OpenAPI definition per endpoint** at
`docs.zrexpress.app/reference/*.md`, which is where the payload shapes here
come from. That is also where `rates` turned out to be
`delivery-pricing/rates/{toTerritoryId}` — the paths this section listed from
llms.txt are 404s. The swagger UI's spec is still closed: an API key gets 403.

### Two defects in the reference implementation, found by testing live

``` text
filters are ignored   parcels/search accepts a `filters` object and pays no
                      attention to it: filtering by externalId returned all
                      706 parcels on the account. The reference recovers
                      duplicate parcels that way, so it adopts whichever
                      parcel is first and calls it the customer's. Ours
                      searches by keyword AND verifies externalId on the
                      row before accepting it — and reuses a customer only
                      on an exact phone match, since a near miss puts a
                      stranger's name on a delivery
a read-back can lose  POST parcels answers with an id alone, so the
a parcel              tracking number needs a second call — which timed out
                      on the first live run, and the adapter reported the
                      whole create as unreachable while a real parcel sat
                      at the courier. Nothing after the parcel exists may
                      throw now: an id with no tracking number is
                      recoverable, neither is a parcel nobody knows about
```

### Verified against the live API, 2026-08-15

A merchant account's credentials, with its owner's permission: read-only
calls, then one test parcel created, retried, polled, cancelled and confirmed
gone.

``` text
1,485 destinations mapped   54 wilayas, 1,531 communes, 77 pickup points
coverage                    delivery.canSend per territory: 11 wilayas this
                            account may not send to, and four not listed at
                            all — Illizi, Tindouf, Bordj Badji Mokhtar and
                            Djanet, which is exactly the UNSUPPORTED_WILAYAS
                            set the reference hard-codes. Data, not code
4 wilayas by code           MSila/M'Sila, Alger/Algiers, Tipaza/Tipasa,
                            El Menia/El Meniaa — the same name drift §56 met
~100 communes unmatched     transliteration variance, reported with the
                            nearest candidate rather than fuzzy-matched
rates                       600 DZD home / 470 pickup to Alger, 500 to a
                            commune of the origin wilaya. Restricted to the
                            origin wilaya, which comes back as no quote and
                            a logged sentence rather than an error
```

------------------------------------------------------------------------

# 58. Payment Abstraction


Create:

``` php
interface PaymentProviderInterface
{
    public function createPayment(array $order): PaymentResult;

    public function verifyPayment(string $paymentId): PaymentStatus;

    public function handleWebhook(
        array $payload,
        array $headers
    ): WebhookResult;
}
```

Then:

``` text
PaymentService
      |
      +-- ChargilyProvider
```

## What §55 already decided for this section

`handleWebhook()` is in the interface, so **`docs/SECURITY.md` → "Webhooks"
applies from the first line of §58** — it is not a §60 concern to be bolted on
later. Chargily signs its webhooks, which is the shape that may be acted on
directly, unlike Yalidine's body secret.

Credentials come from `.env` through `Config::secret()` and are read in **one
place**, the way `Plugin::shippingProviders()` reads a courier's — never an
option, never a constant. `CHARGILY_SECRET_KEY` and `CHARGILY_WEBHOOK_SECRET`
already exist in `.env.example` and in `Config::FLAGS`' key list.

Keep the boundary the shipping abstraction proved: **an adapter never sees a
`WC_Order`.** Everything crossing `PaymentProviderInterface` is one of our value
objects, which is what made adding a second courier change nothing above the
interface.

**This is where `ENABLE_COD` finally does something.** CLAUDE.md records that the
COD module deliberately does not read that flag — COD state is order meta and
audit events, and the flag gates *what checkout offers*. Offering payment
methods is this section.

A payment's status is **never** taken from a client callback, and the amount and
currency are re-checked server-side against the order before anything is marked
paid (§59, SECURITY.md → Payments).

------------------------------------------------------------------------

# 59. Chargily


Implement from Chargily's current official API documentation.

Cover:

``` text
payment creation
checkout/redirect
verification
status
webhooks
failed payments
expired payments
refunds if supported
transaction storage
```

Critical rule:

> Never trust the frontend to tell the backend that a payment succeeded.

The backend verifies the transaction.

## What was built

`integrations/Chargily/` — credentials, settings, client, status map, provider —
plus `Payments/Transaction`, `TransactionRepository`, `WebhookEventRepository`,
`PaymentPoller`, `PaymentController`, `PaymentWebhookController`,
`CLI/SyncPaymentsCommand`, and migrations 007 and 008 (`Schema::VERSION` 8).
Nothing above `PaymentProviderInterface` changed, which was the §58 standard.

## Verified against the live test API, 2026-08-15

Chargily issues test keys to anyone with an email address, so §54's "do not work
from memory" could be satisfied all the way to a real call. There are **no
`ASSUMPTION (unverified)` markers** in this integration. Four things the run
settled that the reference does not state:

``` text
expired        a real status; the documented enum lists only five
1500.50        a fractional amount is accepted, despite type `integer`
checkout_url   returned as http:// though the docs write https://
account{…}     every response embeds the merchant's own record —
                 trade register, NIS, NIF, satim_credentials
```

## Three things the documentation got wrong about our own assumptions

``` text
§58 wrote that Chargily quotes in centimes. It quotes in dinars, which
  is why the transaction amount is decimal(12,2) and not an integer.
SECURITY.md named a CHARGILY_WEBHOOK_SECRET. There is none: Chargily
  signs with the API secret key, so the variable was deleted rather
  than left as a slot only fillable wrongly.
PaymentReport::matches() skipped the currency check when a report did
  not state one — and Chargily's webhook payload does not. Tightened.
```

## The webhook is a trigger, never evidence

The checkout object inside a Chargily event carries no `currency`, so
docs/SECURITY.md's "amount **and** currency are re-checked" cannot be satisfied
from the payload at all. Every path — the verify endpoint, the webhook, the
poller — ends in `verifyPayment()` against the gateway. The signature proves who
sent the message; the re-fetch proves the money.

`wp algerian-commerce sync-payments` exists because of the five-minute replay
window: Chargily publishes no retry schedule, so a late retry is refused, and a
strictness with no backstop is a strictness somebody eventually removes.

## Refunds

Chargily's API has none — balance, customers, products, prices, checkouts and
payment links are the whole surface. §59's "refunds if supported" therefore
resolves to *not supported*, and `PaymentProviderInterface` gained no method for
it. `PaymentStatus::REFUNDED` stays in the vocabulary for the provider that has
one.

------------------------------------------------------------------------

# 60. Webhooks


**`docs/SECURITY.md` → "Webhooks" is the binding rule, settled by §55 before any
inbound endpoint existed so the three providers are not each argued out
separately.** Read it first; what follows is the shape, not the specification.

Use:

``` text
/wp-json/algerian-commerce/v1/webhooks/chargily
/wp-json/algerian-commerce/v1/webhooks/yalidine
/wp-json/algerian-commerce/v1/webhooks/zr-express
```

(The courier is **ZR Express** — §57. Earlier drafts of this file called it
"zedair"; the adapter, the provider name and the route all use `zr-express`.)

Each webhook:

``` text
receive
  ↓
verify signature/authentication   ← raw body, before any JSON decode,
  ↓                                 with hash_equals()
validate payload
  ↓
identify event
  ↓
claim idempotency                 ← NOT "check": a write-once insert whose
  ↓                                 duplicate-key failure IS the answer
process
  ↓
respond
```

**"Claim", not "check", is load-bearing.** A read-then-write idempotency test
races exactly when a provider retries in parallel, which is the one case it
exists for — the same defect migration 006 fixed for shipments. Let the unique
key refuse the duplicate and treat that refusal as "already handled".

Three more things §55 settled, all of them in SECURITY.md with the reasoning:

``` text
route exists only when its provider is registered — an unconfigured
  secret is a 404, never an endpoint that accepts what it cannot check
a signature (Svix, Chargily) may be acted on directly
a body secret that binds to nothing (Yalidine's security_token) is a
  hint to re-fetch and NEVER a source of truth
an unverified request gets 401 webhook_unverified and is told nothing
  about which check failed — a specific error is an oracle
```

§65's security tests for **webhook forgery** and **replay** land with this
section, not after it: they are the only proof the rule above is implemented
rather than merely written down.

Duplicate webhook delivery must not duplicate:

``` text
payment
shipment
order transition
notification
```

## What was built

Chargily's endpoint landed with §59. This section is the two couriers, which
§56 and §57 deferred until §55 had produced a written rule.

``` text
ShippingProviderInterface::handleWebhook()  + ShipmentWebhookResult
ShippingService::handleWebhook()            verify → claim → re-fetch → act
ShippingWebhookController                   one route per registered courier
API/AbstractWebhookController               everything a webhook route does,
                                              extracted from §59's copy
Commerce/WebhookEventRepository             moved out of Payments/ once a
                                              second domain needed the claim
```

**The route for ZR Express is `/webhooks/zrexpress`**, not the `zr-express`
written above. `ZRExpressProvider::NAME` is `zrexpress` and that string is
already in every `ac_shipments` row this plugin has written; a route spelled
differently would be a second name for one thing, with the mapping between them
living wherever somebody remembered to put it.

## The two shapes, and why both are re-fetched

``` text
ZR Express   Svix, from their published docs: HMAC-SHA256 over
               {svix-id}.{svix-timestamp}.{body}, base64, against the
               base64 key behind `whsec_`; several space-separated
               "v1,<sig>" values may arrive and any one matching passes.
               The timestamp is signed material, so the tolerance binds.
Yalidine     `security_token` in the body, hash_equals(), and no
               timestamp check at all — nothing is signed, so a date
               field is attacker-controlled and checking it would be
               theatre that reads like security.
```

docs/SECURITY.md permits acting on a real signature directly, and this section
still does not — for a reason found by reading rather than by caution. ZR
Express's webhook reference documents `state.name` as a display string ("Out for
Delivery"); the live API returns the stable snake_case identifiers
`ZRExpressStateMap` maps and the poller has read since §57. Two documented
shapes for the field that decides a parcel's status is exactly where believing a
payload writes a state nothing else can reason about. So every verified event
ends in `getShipmentStatus()` — the poller's own path — and a parcel's status
still never moves the order.

## §65's two security tests, as promised

Forgery and replay, at both levels: `tests/Unit/CourierWebhookTest` covers
tampering, a wrong key, a swapped `svix-id`, a stale timestamp, an unconfigured
secret and that every rejection is byte-identical across both couriers;
`tests/Api/shipping-webhooks.php` covers the same through the route and the
service against a real database, including that a replayed delivery does not
ask the courier a second time.

## Two things this section found

``` text
signature_url   Yalidine's webhook links to the customer's handwritten
                  signature. Now in Logger::SENSITIVE_EXACT beside the
                  label URLs, under the rule CLAUDE.md already stated.
the autoloader  `optimize-autoloader` dumps a classmap, so moving one
                  class between directories under src/ made every request
                  fatal. The bundled PSR-4 autoloader is now registered
                  behind Composer unconditionally — the reasoning the
                  bootstrap already carried for integrations/, never
                  applied to src/ where a classmap needs it more.
```

------------------------------------------------------------------------

# 61. CMS


Expose:

``` text
GET /cms/homepage
GET /cms/pages/{slug}
GET /cms/banners
GET /cms/faqs
GET /cms/menus/{location}
```

The Next.js application renders the frontend.

WordPress stores content.

Example homepage model:

``` json
{
  "sections": [
    {
      "type": "hero",
      "data": {}
    },
    {
      "type": "featured_products",
      "data": {}
    }
  ]
}
```

## Media and uploads

`docs/PLAN.md` §24 (Media) belongs here, and nowhere earlier. §47c assigns
product images by attachment id and validates them, but nothing in this
roadmap ever creates an attachment — without this step the media library can
only be filled through the WordPress dashboard, which contradicts §20.

Implement:

``` text
POST   /media          multipart upload
GET    /media          list, search, filter by type
GET    /media/{id}
PATCH  /media/{id}     alt text, title, caption
DELETE /media/{id}
```

Upload is the highest-risk endpoint in the whole API, because it is the only
one that writes a file a web server might later execute. Before it ships:

``` text
MIME type validated from file contents, not the client-supplied header
extension allowlist, checked independently of MIME
size cap
metadata stripped
filenames sanitized and never trusted
stored outside any web-executable path where the host allows it
authorization on every route
rate limiting
```

Prefer WordPress's own `wp_handle_upload()` and `wp_insert_attachment()`
over hand-rolled file handling: they already apply the core allowlist and
the uploads-directory rules.

Test the abuse cases from §65 — a PHP file renamed to `.jpg`, a polyglot
image, a double extension, a path-traversal filename, an oversized file —
and treat §55's checklist as applying to this endpoint too.

## What was built

``` text
src/CMS/     ContentTypes, CmsController, CmsService, CmsRepository,
               CmsPresenter, HomepageSections
src/Media/   MediaController, MediaService, MediaRepository, MediaPresenter,
               MediaInput, UploadedFile, UploadPolicy, ImageSanitizer
```

**No migration, and no custom table.** WordPress stores content, which was the
instruction: banners and FAQs are post types, menus are nav menus, pages are
pages. `Schema::VERSION` is still 8.

The homepage is the one thing that is not a post. It is a single document — the
`ac_cms_homepage` option, holding §23's `{type, data}` sections — because it is
edited as a whole rather than an item at a time, and splitting it across eleven
post rows would make "what does the homepage look like" a query instead of a
value. It is written the way §20 prefers:

``` bash
wp option update ac_cms_homepage --format=json '{"sections":[{"type":"hero","data":{}}]}'
```

A malformed section is dropped **and reported** in the response `meta`, never
silently. An option is edited by hand; a section that vanishes without a word is
the one failure a content manager cannot diagnose.

CMS is **read-only**, as this section specifies. Authoring is the WordPress
editor and WP-CLI; a CMS write surface belongs to PLAN §52's admin coverage.

## The upload checklist, line by line

``` text
MIME from contents      finfo magic bytes AND getimagesize(), which must agree
extension allowlist     checked independently, jpg/jpeg/png/webp only
size cap                8 MiB, clamped to PHP's own upload_max_filesize
metadata stripped       every image re-encoded from decoded pixels
filenames               rewritten: our stem, and the extension the bytes proved
outside web-executable  cannot be — the storefront serves these URLs — so the
                          uploads directory is made non-executable instead
authorization           ac_manage_content on all five routes
rate limiting           its own 30/minute, above the namespace write limit
```

`UploadPolicy` is pure, so every §65 abuse case is a unit test rather than a
live experiment, and `docs/SECURITY.md` → "File uploads" is now the rule the way
"Webhooks" is.

## Three things this section found

``` text
map_meta_cap    Registering a post type with 'edit_post' => 'ac_manage_content'
                  writes that name into WordPress's global $post_type_meta_caps,
                  after which *every* check of `ac_manage_content` maps to
                  `delete_post` with no post id and resolves to do_not_allow.
                  Every CMS and media route answered 403 to the exact capability
                  being asked about — administrators included. Map the primitive
                  capabilities only; map_meta_cap derives the other three.

by reference    wp_handle_upload() takes its first argument by reference, so
                  passing an array literal is a fatal TypeError. Only a
                  legitimate upload reaches that line, which is precisely the
                  case no refusal test exercises.

Imagick keeps   WP_Image_Editor_Imagick::save() preserves EXIF and JPEG
  metadata        comments — strip_meta() is only reached through the resize
                  path, and a same-size resize early-returns first. GD strips
                  everything. The two containers in this stack also disagreed
                  about which editor WordPress picks, so the sanitiser pins it.
```

The first two were found by `tests/Api/`, the third by measuring rather than
believing. §65's file-upload-abuse tests land with this section, not after it:
the refusals run in `tests/Api/media.php`, and the parts only a real multipart
POST can reach — the file being written, the metadata actually gone, the
polyglot's payload actually gone — run in `scripts/test-api.sh`.

------------------------------------------------------------------------

# 62. SEO and Marketing


Use one SEO plugin.

Expose SEO data:

``` json
{
  "seo": {
    "title": "...",
    "description": "...",
    "canonical": "...",
    "image": "..."
  }
}
```

For marketing, avoid installing multiple plugins that emit the same
events.

Track:

``` text
PageView
ViewContent
Search
AddToCart
InitiateCheckout
Purchase
```

Use unique purchase/event IDs to prevent duplicate conversions.

------------------------------------------------------------------------

# 62b. Meta Pixel and Conversions API


**62b is numbered rather than renumbering the list**, for the reason §4 gives
for 28b. §62 names the events and stops; PLAN.md §26 names Meta Pixel as the
first integration. This step decides who sends an event and from where, which
is the part a headless install gets wrong by default.

## The pixel has two halves and this repository owns one of them

The browser half — `fbevents.js` and the `fbq()` calls — belongs to the Next.js
storefront, because WordPress renders no page here. **Do not solve it with a
WooCommerce pixel plugin** (PLAN §54 lists "Meta for WooCommerce" as a
candidate, not a decision): those plugins inject their script through
WooCommerce's template hooks, and in a headless install those templates never
run, so the plugin is inert and looks installed.

The server half — the Conversions API — belongs here, because it is an outbound
HTTP call carrying order data and a long-lived token. It is a third-party
integration like any other: §54 and §55 apply to it in full.

Build it as an abstraction with one provider, the way §58 and §59 did payments:

``` text
Marketing/MarketingProviderInterface
Marketing/MarketingEvent            value object crossing the boundary
Marketing/MarketingService
Plugin::marketingProviders()        the only place a token or flag is read
integrations/Meta/MetaProvider      Conversions API adapter
```

An adapter never sees a `WC_Order`. TikTok and Google Ads (PLAN §26) are the
second provider, and adding one must change nothing above the interface.

## Deduplication is the whole problem

The same Purchase reaches Meta twice — once from the browser, once from the
server — and Meta discards the copy only when both carry the same `event_name`
*and* the same `event_id`. Two systems cannot each invent that id. **The backend
mints it and the storefront is told what it is**, never the reverse:

``` text
GET  /marketing/config              public ids only, for the storefront
POST /marketing/events/purchase     → { event_id, event_name, ... }
```

The storefront passes that value as `fbq('track', 'Purchase', {...},
{eventID})` and the adapter sends the same one server-side. Derive it from the
order rather than from randomness, so a retry, a refresh and a second tab all
produce one conversion.

Send server-side only what the server actually witnessed. `Purchase` — and
`InitiateCheckout` if checkout is what creates the order — are facts the backend
holds. `PageView`, `Search` and `ViewContent` are browser facts; a server that
reports them is guessing, and a guessed event is worse than a missing one
because it silently reprices somebody's ad spend.

Fire Purchase **once**, and claim it the way §60 claims a webhook event: a
write-once insert into `ac_marketing_events` (migration 009, `Schema::VERSION`
moves with it) whose duplicate-key failure *is* the answer, never a read.

## Rules that are not negotiable

``` text
META_PIXEL_ID is public — it ships in browser JS, so /marketing/config may serve it
META_CAPI_ACCESS_TOKEN is a credential — .env only, reaches the adapter through the
  bootstrap as courier and gateway credentials do, never in any response, and it
  joins Logger::SENSITIVE_EXACT
user data is hashed SHA-256 over trimmed lowercase values; the raw email or phone
  never leaves. Hashing is not anonymisation — this is still customer PII going to a
  third party, so §55's review runs before the first call
never in the checkout request path — queue the call and drain it on cron; a Meta
  outage must never fail or delay an order, exactly as §57 refuses to let a
  read-back fail a parcel that already exists
pin the Graph API version per §68, and verify against the live API per §54 —
  test_event_code exercises it without polluting the dataset, so nothing here
  needs to stay an ASSUMPTION
```

Gate it on `ENABLE_MARKETING_PIXELS` (§72). No flag or no token means no
provider registered and no outbound call at all, and `/marketing/config` says
the pixel is off rather than erroring — a client without an ad account is the
normal case, not a misconfiguration.

------------------------------------------------------------------------

# 63. Analytics


Build aggregation endpoints such as:

``` text
GET /analytics/overview
GET /analytics/revenue
GET /analytics/products
GET /analytics/orders
GET /analytics/shipping
GET /analytics/cod
```

Metrics:

``` text
revenue
orders
customers
best sellers
low stock
COD confirmation
delivery success
returns
cancellations
revenue by wilaya
shipping cost
provider performance
```

Do not repeatedly scan every order on every dashboard request once the
data becomes large.

Use indexes and aggregation strategies.

------------------------------------------------------------------------

# 64. Import/Export


Automate:

``` text
product CSV import
product CSV export
inventory import
inventory export
orders export
customers export
```

Imports need:

``` text
validation
dry run
preview
error report
duplicate handling
safe update behavior
```

Example:

``` text
CSV
 ↓
validate
 ↓
preview
 ↓
confirm
 ↓
process
 ↓
report
```

------------------------------------------------------------------------

# 65. Testing Strategy


Claude should write tests as features are built.

## Unit

Test:

``` text
price calculations
shipping calculations
COD risk
provider mapping
validation
permissions
```

## Integration

Test:

``` text
WordPress
WooCommerce
custom plugin
database
```

## API

Test:

``` text
success
bad input
unauthenticated
unauthorized
not found
duplicate
pagination
boundary values
```

## Provider

Test:

``` text
Yalidine
Zedair
Chargily
```

using sandbox/test systems where available.

## Security

Test:

``` text
SQL injection
XSS
CSRF
IDOR
privilege escalation
rate limits
file upload abuse
webhook forgery
replay
```

------------------------------------------------------------------------

# 66. Automation Scripts


Eventually implement:

``` text
scripts/setup.sh
scripts/reset.sh
scripts/seed.sh
scripts/health.sh
scripts/test.sh
scripts/backup.sh
```

## setup.sh

Should eventually:

``` text
check Docker
start containers
wait for services
install WooCommerce
install approved plugins
activate plugins
activate custom plugin
create roles
run migrations
import Algeria data
seed development data
run health check
```

## reset.sh

Development only:

``` text
destroy development data
recreate environment
run setup
```

Make it explicitly destructive.

## health.sh

Check:

``` text
Docker
WordPress
database
WooCommerce
custom plugin
REST API
health endpoint
```

## test.sh

Run:

``` text
syntax checks
unit tests
integration tests
API tests
```

## backup.sh

Back up (§44):

``` text
database
wp-content/uploads
configuration
custom plugin code
```

**Do not use `wp db export` or `wp db query`.** Both are broken in this
stack and always will be while the images stay as they are: the
`wordpress:cli` image ships a MariaDB client, MySQL 8 authenticates with
`caching_sha2_password`, and the plugin that implements it is not in
`/usr/lib/mariadb/plugin/`. The connection fails before any SQL runs:

``` text
mariadb-dump: Got error: 1045: "Plugin caching_sha2_password could not be
loaded ... No such file or directory" when trying to connect
```

Dump from the `db` container instead, which has MySQL's own client:

``` bash
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump \
     --single-transaction --quick --no-tablespaces \
     -u"$MYSQL_USER" "$MYSQL_DATABASE"' > backups/db-$(date +%F-%H%M).sql
```

Why those flags, and why it reads the password from the container's own
environment:

``` text
MYSQL_PWD           keeps the password out of the process list and out of shell history
--single-transaction consistent InnoDB snapshot without locking the site
--quick              streams rows instead of buffering the table in memory
--no-tablespaces     avoids the PROCESS privilege, which the wordpress user does not have
```

Uploads live in the `wordpress_data` volume, not the repository, so they
need `docker compose cp` or a volume mount — copying the repo is not a
backup of the media library.

`backups/` is for local development only. Production backups belong
off-site (§44), and **a backup is not valid until a restore has been
tested** — script the restore too, and run it.

------------------------------------------------------------------------

# 67. Seed Data


Create fake development data:

``` text
categories
products
variations
customers
orders
coupons
```

Never use real client/customer data locally unless you have a legitimate
protected workflow.

This allows Claude to test against realistic data.

------------------------------------------------------------------------

# 68. Version Pinning


Once the initial environment works, record tested versions for:

``` text
WordPress
WooCommerce
PHP
MySQL
plugins
Docker images
```

Avoid depending indefinitely on:

``` text
latest
```

When upgrading:

``` text
branch
upgrade
run tests
inspect logs
test integrations
review
merge
```

Do not upgrade WordPress, WooCommerce and every plugin simultaneously
without a reason.

------------------------------------------------------------------------

# 69. API Testing Before Next.js


Before building the admin panel, test the API with:

``` text
curl
Postman
Insomnia
Bruno
```

Example:

``` bash
curl http://localhost:8090/wp-json/algerian-commerce/v1/health
```

Then:

``` text
product CRUD
order CRUD
customer queries
authentication
permissions
```

Only after the API works should the Next.js admin become the main
testing interface.

------------------------------------------------------------------------

# 70. Next.js Integration


Eventually:

``` text
Next.js Admin
       |
       v
Custom REST API
       |
       v
WordPress/WooCommerce
```

and:

``` text
Next.js Storefront
       |
       v
Custom REST API
       |
       v
WordPress/WooCommerce
```

The frontend should not be tightly coupled to:

``` text
/wp-json/wp/v2
/wp-json/wc/v3
random plugin endpoints
```

unless there is a deliberate reason.

Prefer your stable:

``` text
/algerian-commerce/v1
```

contract.

------------------------------------------------------------------------

# 71. Client Configuration


Use configuration/feature flags rather than forks.

Example:

``` json
{
  "store_name": "Example Store",
  "currency": "DZD",
  "cod_enabled": true,
  "yalidine_enabled": true,
  "zedair_enabled": false,
  "chargily_enabled": true,
  "blog_enabled": true
}
```

Secrets remain environment variables.

------------------------------------------------------------------------

# 72. Feature Flags


Possible flags:

``` text
ENABLE_COD
ENABLE_CHARGILY
ENABLE_YALIDINE
ENABLE_ZEDAIR
ENABLE_BLOG
ENABLE_REVIEWS
ENABLE_MARKETING_PIXELS
ENABLE_SMS
ENABLE_WHATSAPP
```

This lets one backend template serve different clients.

------------------------------------------------------------------------

# 73. New Client Provisioning


The long-term goal is:

``` text
New Client
   ↓
clone backend template
   ↓
copy .env.example → .env
   ↓
set client configuration
   ↓
docker compose up
   ↓
./scripts/setup.sh
   ↓
configure integrations
   ↓
deploy
```

Then build the client's custom Next.js frontend.

------------------------------------------------------------------------

# 74. Production Separation


The local Docker environment is not automatically your production
environment.

Eventually use:

``` text
local
  ↓
GitHub
  ↓
staging
  ↓
production
```

Possible production options:

``` text
managed WordPress hosting
VPS
Docker on VPS
cloud VM
managed database + application server
```

The backend should remain portable.

Docker's production guidance recommends changing development volume
patterns and configuration before production, rather than blindly using
the development Compose file as-is.

------------------------------------------------------------------------

# 75. Production Strategy


Production is intentionally not selected yet.

When choosing later, evaluate:

``` text
PHP support
WordPress support
WooCommerce support
MySQL/MariaDB
HTTPS
SSH
cron
backups
database performance
Redis/object cache
firewall
monitoring
logs
deployment
restore process
```

The development architecture should not force a specific provider.

------------------------------------------------------------------------

# 76. Important Production Rule


Do not expose the development Docker stack directly to the internet.

Development:

``` text
localhost
```

Staging:

``` text
staging API
```

Production:

``` text
production API
```

Each environment should have separate:

``` text
database
credentials
API keys
webhook secrets
domains
```

------------------------------------------------------------------------

# 77. Long-Term Reusable Template


The finished template should support:

``` text
                    CORE TEMPLATE
                         |
            +------------+------------+
            |            |            |
            v            v            v
         Client A     Client B     Client C
            |            |            |
         config        config       config
            |            |            |
            v            v            v
       Next.js A     Next.js B    Next.js C
```

The backend stays standardized.

The frontend is completely custom.

------------------------------------------------------------------------

# 78. Final Workflow


Your ideal daily workflow becomes:

``` text
1. Create/choose a feature
2. Create Git branch
3. Tell Claude which phase to implement
4. Claude reads PLAN + architecture + security
5. Claude inspects existing code
6. Claude implements
7. Claude runs tests
8. You review
9. Claude fixes review findings
10. You test API
11. Commit
12. Push
```

Instead of:

``` text
open WordPress
click settings
install plugin
configure
edit PHP
upload
refresh
repeat
```

------------------------------------------------------------------------

# 79. Golden Rules


1.  WordPress is the platform, not your frontend.
2.  WooCommerce is the commerce engine.
3.  Your custom plugin is the reusable application layer.
4.  Claude works from the Git repository.
5.  Docker provides a reproducible environment.
6.  WSL 2 is the preferred Windows development filesystem/workflow.
7.  WP-CLI automates WordPress administration.
8.  Git is the source-control system.
9.  `PLAN.md` describes what to build.
10. `CLAUDE.md` describes how Claude should work.
11. Never modify WordPress core.
12. Never modify WooCommerce core.
13. Prefer supported APIs.
14. Never expose privileged credentials to the browser.
15. Verify payments server-side.
16. Verify webhooks.
17. Make webhooks idempotent.
18. Keep shipping providers behind an abstraction.
19. Keep payment providers behind an abstraction.
20. Automate repetitive setup.
21. Test every important feature.
22. Review Claude's security-sensitive code.
23. Never commit secrets.
24. Never give Claude unrestricted production access.
25. Build one phase at a time.
26. Keep `main` stable.
27. Make client configuration separate from core business logic.
28. Keep production deployment portable.
29. Treat third-party API documentation as the source of truth.
30. Optimize for a backend that can be cloned and reused for the next
    client.

------------------------------------------------------------------------

# 80. Final Target


The completed development platform should make this possible:

``` bash
git clone <backend-template>
cd algerian-commerce-backend

cp .env.example .env

./scripts/setup.sh
```

Then:

``` text
Docker
  ↓
WordPress
  ↓
WooCommerce
  ↓
Custom plugins
  ↓
Database migrations
  ↓
Algerian data
  ↓
Development seed
  ↓
Health checks
  ↓
Tests
```

And Claude Code can immediately continue from the same repository.

The final product is therefore not merely a WordPress installation.

It is:

``` text
REUSABLE
+
VERSIONED
+
AUTOMATABLE
+
TESTABLE
+
SECURE
+
HEADLESS
+
ALGERIA-SPECIFIC
+
CLIENT-REUSABLE
```

The purpose of Docker + Git + Claude Code is not to eliminate your
involvement. It is to remove repetitive mechanical work so your time is
spent on architecture, business rules, review, security, integrations
and client-specific decisions.

------------------------------------------------------------------------

# 81. Official References


Use current official documentation as the source of truth when
implementation details change.

## Docker

-   Docker Desktop on Windows
-   Docker Desktop + WSL 2
-   Docker Compose
-   Compose specification
-   Compose production guidance

## WordPress

-   WordPress Developer Handbook
-   REST API Handbook
-   REST API Authentication
-   Security Hardening

## WooCommerce

-   WooCommerce documentation
-   WooCommerce REST API documentation
-   WooCommerce developer documentation
-   HPOS documentation

## WP-CLI

-   WP-CLI Handbook
-   WP-CLI Quick Start
-   WP-CLI Docker usage

## Claude Code

-   Anthropic's current Claude Code documentation
-   Current Claude Code installation documentation
-   Current Claude Code permissions/security documentation
-   Current project instruction documentation

For Yalidine, Zedair and Chargily, always implement against their
current official API documentation and current authentication/webhook
requirements. This roadmap is an architecture and workflow guide, not a
frozen specification of third-party endpoints.

------------------------------------------------------------------------

## Appendix: renumbering map

Section numbers changed when this document was reordered into build
order. Older notes, commits and code comments may cite the previous
numbering.

| Old | New | Section |
| --- | --- | --- |
| 1 | 1 | The Target Architecture |
| 2 | 2 | Why This Workflow |
| 3 | 7 | Windows 11 Setup Strategy |
| 4 | 8 | Install and Verify WSL 2 |
| 5 | 9 | Configure Docker Desktop |
| 6 | 10 | Create the Project Inside WSL |
| 7 | 11 | Recommended Repository Structure |
| 8 | 12 | Git Configuration |
| 9 | 13 | Docker Compose |
| 10 | 14 | Environment Variables |
| 11 | 15 | Start WordPress |
| 12 | 16 | WP-CLI |
| 13 | 17 | Recommended Free Plugin Baseline |
| 14 | 19 | Claude Code Access Model |
| 15 | 20 | Why Filesystem + CLI Is Better Than Dashboard Automation |
| 16 | 21 | Install Claude Code |
| 17 | 22 | CLAUDE.md |
| 18 | 23 | The Three Documentation Layers |
| 19 | 24 | Import the Previous Plan |
| 20 | 25 | Create ARCHITECTURE.md |
| 21 | 26 | Create SECURITY.md |
| 22 | 27 | Claude's Permission Strategy |
| 23 | 36 | First Claude Task: Inspect, Don't Build |
| 24 | 40 | Second Claude Task: Plugin Foundation |
| 25 | 41 | First Git Commit |
| 26 | 33 | Branch Strategy |
| 27 | 29 | The Standard Claude Implementation Loop |
| 28 | 47 | Product Implementation |
| 29 | 49 | Inventory |
| 30 | 45 | RBAC and Audit |
| 31 | 50 | Orders and Customers |
| 32 | 51 | Algerian Geographic Data |
| 33 | 52 | COD |
| 34 | 53 | Shipping Abstraction |
| 35 | 56 | Yalidine |
| 36 | 57 | Zedair |
| 37 | 58 | Payment Abstraction |
| 38 | 59 | Chargily |
| 39 | 60 | Webhooks |
| 40 | 63 | Analytics |
| 41 | 61 | CMS |
| 42 | 62 | SEO and Marketing |
| 43 | 64 | Import/Export |
| 44 | 42 | Security Implementation Order |
| 45 | 65 | Testing Strategy |
| 46 | 66 | Automation Scripts |
| 47 | 67 | Seed Data |
| 48 | 68 | Version Pinning |
| 49 | 43 | Database Migrations |
| 50 | 37 | Recommended Custom Plugin Structure |
| 51 | 38 | Composer |
| 52 | 39 | API Contract |
| 53 | 69 | API Testing Before Next.js |
| 54 | 70 | Next.js Integration |
| 55 | 44 | Authentication Architecture |
| 56 | 46 | CORS |
| 57 | 74 | Production Separation |
| 58 | 73 | New Client Provisioning |
| 59 | 71 | Client Configuration |
| 60 | 72 | Feature Flags |
| 61 | 30 | Claude's Standard Feature Prompt |
| 62 | 31 | Claude Review Prompt |
| 63 | 54 | Third-Party Integration Rule |
| 64 | 55 | Security Review Before Each Integration |
| 65 | 32 | Development Workflow for Each Phase |
| 66 | 34 | What You Should Do Manually |
| 67 | 35 | What Claude Should Do |
| 68 | 5 | Do Not Build Everything at Once |
| 69 | 3 | Milestone Roadmap |
| 70 | 4 | Exact Implementation Order |
| 71 | 6 | Definition of Done |
| 72 | 18 | First-Day Checklist |
| 73 | 28 | First Claude Session Checklist |
| 74 | 48 | First Successful Backend Milestone |
| 75 | 77 | Long-Term Reusable Template |
| 76 | 75 | Production Strategy |
| 77 | 76 | Important Production Rule |
| 78 | 78 | Final Workflow |
| 79 | 79 | Golden Rules |
| 80 | 80 | Final Target |
| 81 | 81 | Official References |
