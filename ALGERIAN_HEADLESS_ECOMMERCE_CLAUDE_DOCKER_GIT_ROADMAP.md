# Algerian Headless E-Commerce Backend

## Docker + Git + Claude Code Implementation Roadmap

**Target:** Windows 11 + Docker Desktop + WSL 2\
**Production:** undecided and kept portable\
**Backend:** WordPress + WooCommerce + custom plugin\
**Frontend:** independent React/Next.js applications\
**Goal:** turn the previous Algerian e-commerce backend specification
into a reproducible, versioned, AI-assisted development workflow.

------------------------------------------------------------------------

## Reading order

Sections are grouped by topic, **not** by build order. Where a section's
position in this document implies a different sequence than
[§70 Exact Implementation Order](#70-exact-implementation-order), §70 wins.

In particular: §28 Product Implementation appears before §30 RBAC and Audit
and §49 Database Migrations, but the security foundation is built **first**
(§44, §69 Milestone 5, §70 items 19–21). See the ordering note in §28.

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

# 3. Windows 11 Setup Strategy

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

# 4. Install and Verify WSL 2

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

# 5. Configure Docker Desktop

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

# 6. Create the Project Inside WSL

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

# 7. Recommended Repository Structure

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
│   └── mu-plugins/                      live inside the plugin (§50)
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

# 8. Git Configuration

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

# 9. Docker Compose

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

# 10. Environment Variables

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
    never reuse a development password in staging or production (§77).
-   `WP_PORT` is not yet consumed by `compose.yaml`, which publishes
    `8090:80` directly. Either wire it up as `"${WP_PORT}:80"` or treat
    the variable as documentation until you do.

------------------------------------------------------------------------

# 11. Start WordPress

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

# 12. WP-CLI

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

# 13. Recommended Free Plugin Baseline

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

# 14. Claude Code Access Model

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

# 15. Why Filesystem + CLI Is Better Than Dashboard Automation

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

# 16. Install Claude Code

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

# 17. CLAUDE.md

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

# 18. The Three Documentation Layers

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

# 19. Import the Previous Plan

Save the previous file as:

``` text
docs/PLAN.md
```

Do not turn the entire plan into one huge Claude prompt.

Claude should read it as project documentation.

The implementation should be incremental.

------------------------------------------------------------------------

# 20. Create ARCHITECTURE.md

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

# 21. Create SECURITY.md

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

# 22. Claude's Permission Strategy

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

# 23. First Claude Task: Inspect, Don't Build

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

# 24. Second Claude Task: Plugin Foundation

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

# 25. First Git Commit

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

# 26. Branch Strategy

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

# 27. The Standard Claude Implementation Loop

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

# 28. Product Implementation

> **Ordering note.** This section's position is misleading. Do not start
> Product CRUD until the security foundation is complete —
> §70 items 19–21 (database migrations, authentication/RBAC, audit logs),
> which is Milestone 5 in §69 and Phase 2 in `docs/PLAN.md`.
>
> §44 is explicit that authorization, validation and audit logging are
> built early, not retrofitted. Products introduce the first write
> endpoints; shipping them before authorization exists means either
> exposing them unprotected or retrofitting access control into working
> code later, which is how authorization gaps survive.
>
> Read §30 (RBAC and Audit) and §49 (Database Migrations) before this
> section, even though they are printed after it.

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

Then:

``` text
search
filtering
pagination
categories
attributes
variations
images
bulk operations
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

Only then continue to inventory.

------------------------------------------------------------------------

# 29. Inventory

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

# 30. RBAC and Audit

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

# 31. Orders and Customers

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

------------------------------------------------------------------------

# 32. Algerian Geographic Data

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

------------------------------------------------------------------------

# 33. COD

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

------------------------------------------------------------------------

# 34. Shipping Abstraction

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

------------------------------------------------------------------------

# 35. Yalidine

Implement the adapter from Yalidine's **current official API
documentation** at the time of implementation.

Do not invent endpoints or payloads.

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

------------------------------------------------------------------------

# 36. Zedair

Use exactly the same provider abstraction.

Implement from Zedair's current official documentation.

The core application should not need to know whether the shipment is
handled by:

``` text
Yalidine
Zedair
future provider
```

Only the adapter should know provider-specific details.

------------------------------------------------------------------------

# 37. Payment Abstraction

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

------------------------------------------------------------------------

# 38. Chargily

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

------------------------------------------------------------------------

# 39. Webhooks

Use:

``` text
/wp-json/algerian-commerce/v1/webhooks/chargily
/wp-json/algerian-commerce/v1/webhooks/yalidine
/wp-json/algerian-commerce/v1/webhooks/zedair
```

Each webhook:

``` text
receive
  ↓
verify signature/authentication
  ↓
validate payload
  ↓
identify event
  ↓
check idempotency
  ↓
process
  ↓
store event
  ↓
respond
```

Duplicate webhook delivery must not duplicate:

``` text
payment
shipment
order transition
notification
```

------------------------------------------------------------------------

# 40. Analytics

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

# 41. CMS

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

------------------------------------------------------------------------

# 42. SEO and Marketing

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

# 43. Import/Export

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

# 44. Security Implementation Order

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
```

Use WordPress's security APIs and supported authentication mechanisms.

------------------------------------------------------------------------

# 45. Testing Strategy

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

# 46. Automation Scripts

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

------------------------------------------------------------------------

# 47. Seed Data

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

# 48. Version Pinning

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

# 49. Database Migrations

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

# 50. Recommended Custom Plugin Structure

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

# 51. Composer

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

# 52. API Contract

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

# 53. API Testing Before Next.js

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

# 54. Next.js Integration

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

# 55. Authentication Architecture

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

------------------------------------------------------------------------

# 56. CORS

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

# 57. Production Separation

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

# 58. New Client Provisioning

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

# 59. Client Configuration

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

# 60. Feature Flags

Possible flags:

``` text
ENABLE_COD
ENABLE_CHARGILY
ENABLE_YALIDINE
ENABLE_ZEDAIR
ENABLE_BLOG
ENABLE_REVIEWS
ENABLE_SMS
ENABLE_WHATSAPP
```

This lets one backend template serve different clients.

------------------------------------------------------------------------

# 61. Claude's Standard Feature Prompt

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

# 62. Claude Review Prompt

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

# 63. Third-Party Integration Rule

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

# 64. Security Review Before Each Integration

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

------------------------------------------------------------------------

# 65. Development Workflow for Each Phase

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

# 66. What You Should Do Manually

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

# 67. What Claude Should Do

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

# 68. Do Not Build Everything at Once

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

# 69. Milestone Roadmap

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

# 70. Exact Implementation Order

**This list is the authoritative build sequence.** Sections elsewhere in
this document are grouped by topic, so their printed order does not imply
a build order. Where the two disagree, follow this list — see the
ordering note in §28 for the case that bites first.

Use this as the master sequence:

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
29. Yalidine
30. Zedair
31. Payment abstraction
32. Chargily
33. Coupons
34. Notifications
35. CMS
36. SEO
37. Marketing/pixels
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

# 71. Definition of Done

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

# 72. First-Day Checklist

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

# 73. First Claude Session Checklist

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

# 74. First Successful Backend Milestone

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

# 75. Long-Term Reusable Template

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

# 76. Production Strategy

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

# 77. Important Production Rule

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
