# PLAN.md --- Algerian Headless E-Commerce Backend Template

## Purpose

This is the **functional master plan** for a reusable WordPress +
WooCommerce backend template designed for the Algerian e-commerce market
and consumed by independent React/Next.js frontends.

-   `PLAN.md` = **what the backend must provide**
-   `ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md` = **how
    to build it with Docker + Git + Claude Code**

------------------------------------------------------------------------

## 1. Architecture

``` text
Next.js Storefront ─┐
                    ├── REST API ── Custom Algerian Commerce Plugin
Next.js Admin ──────┘                         │
                                              ├── WordPress
                                              └── WooCommerce
```

Rules:

1.  Never modify WordPress core.
2.  Never modify WooCommerce core.
3.  Put reusable business logic in `algerian-commerce-core`.
4.  Prefer official WordPress/WooCommerce APIs.
5.  Keep the frontend completely independent of the backend
    implementation.
6.  Keep third-party provider logic behind abstractions.

------------------------------------------------------------------------

# 2. Core REST API

Use:

``` text
/wp-json/algerian-commerce/v1/
```

Requirements:

-   authentication
-   authorization
-   validation
-   pagination
-   filtering
-   predictable errors
-   consistent responses
-   API tests

Success:

``` json
{"success": true, "data": {}}
```

Error:

``` json
{
  "success": false,
  "error": {
    "code": "invalid_request",
    "message": "The request is invalid."
  }
}
```

------------------------------------------------------------------------

# 3. Users, Authentication and RBAC

Implement:

-   secure authentication
-   session/token management
-   logout/revocation
-   password/reset flows
-   capability-based authorization
-   API authorization
-   privileged-user 2FA
-   protection against privilege escalation

Default roles:

``` text
Super Admin
Admin
Manager
Product Manager
Order Manager
Marketing Manager
Support Agent
```

Capabilities should include:

``` text
manage_products
manage_inventory
manage_orders
manage_customers
manage_coupons
manage_content
manage_marketing
view_analytics
manage_shipping
manage_payments
manage_settings
manage_users
view_audit_logs
```

Never rely on frontend visibility for authorization.

------------------------------------------------------------------------

# 4. Product Management

Complete CRUD is mandatory.

``` text
GET    /products
POST   /products
GET    /products/{id}
PATCH  /products/{id}
DELETE /products/{id}
```

Support:

-   name
-   slug
-   description
-   short description
-   SKU
-   regular price
-   sale price
-   cost where required
-   stock
-   stock status
-   categories
-   tags
-   images
-   gallery
-   attributes
-   variations
-   weight
-   dimensions
-   shipping class
-   tax class
-   visibility
-   featured status
-   product status
-   SEO metadata
-   custom metadata

Also support:

-   search
-   filters
-   sorting
-   pagination
-   duplicate
-   bulk updates
-   bulk deletion
-   category assignment
-   media management

------------------------------------------------------------------------

# 5. Categories, Tags and Attributes

Support complete management of:

-   product categories
-   category hierarchy
-   tags
-   global attributes
-   attribute terms
-   variation attributes

Operations:

``` text
create
read
update
delete
reorder
bulk operations
```

------------------------------------------------------------------------

# 6. Variable Products and Variations

Support:

-   variable products
-   variation creation/edit/delete
-   variation SKU
-   variation price
-   sale price
-   stock
-   image
-   attributes
-   status

API:

``` text
GET    /products/{id}/variations
POST   /products/{id}/variations
PATCH  /products/{id}/variations/{variationId}
DELETE /products/{id}/variations/{variationId}
```

------------------------------------------------------------------------

# 7. Inventory

Provide:

-   current stock
-   stock status
-   low-stock thresholds
-   stock adjustments
-   inventory history
-   bulk stock updates
-   SKU lookup
-   low-stock reports
-   out-of-stock reports

Manual changes should record:

``` text
product
previous quantity
new quantity
difference
reason
user
timestamp
```

Use WooCommerce's supported stock mechanisms.

------------------------------------------------------------------------

# 8. Orders

Admin functionality:

-   list
-   search
-   filter
-   sort
-   pagination
-   view
-   create
-   edit
-   status changes
-   cancellation
-   refunds where supported
-   notes
-   customer history
-   shipping information
-   payment information
-   tracking
-   order timeline

Support WooCommerce native statuses and additional operational
metadata/states where appropriate:

``` text
COD Pending Confirmation
COD Confirmed
Shipping Prepared
Shipped
Delivered
Returned
```

Avoid creating redundant statuses when metadata/events are sufficient.

------------------------------------------------------------------------

# 9. Customers

Support:

-   customer list
-   search
-   profile
-   details
-   order history
-   total spending
-   order count
-   average order value
-   addresses
-   phone
-   email
-   notes
-   account status
-   COD history

Statistics:

``` text
total orders
completed orders
cancelled orders
returned orders
total revenue
average order value
first order
last order
```

------------------------------------------------------------------------

# 10. Algerian Geographic Data

Maintain structured:

-   wilayas
-   communes
-   postal codes where available
-   provider destination mappings

API:

``` text
GET /locations/wilayas
GET /locations/wilayas/{id}
GET /locations/wilayas/{id}/communes
GET /locations/communes/{id}
```

Keep provider IDs separate from the canonical Algerian geographic data.

------------------------------------------------------------------------

# 11. Currency and Pricing

Primary currency:

``` text
DZD
```

Support:

-   product prices
-   discounts
-   shipping fees
-   payment totals
-   reporting

Use precise monetary calculations and WooCommerce's native pricing
mechanisms.

------------------------------------------------------------------------

# 12. Cash on Delivery

COD is a core Algerian feature.

Support:

-   COD payment method
-   confirmation status
-   confirmation attempts
-   confirmation timestamps
-   rejection
-   unreachable customers
-   cancellation reasons
-   delivery result
-   return result

Suggested operational states:

``` text
Pending Confirmation
Confirmed
Rejected
Unreachable
Cancelled
Delivered
Returned
```

Track:

-   confirmation rate
-   cancellation rate
-   delivery rate
-   return rate
-   customer COD history

------------------------------------------------------------------------

# 13. Shipping Abstraction

Create:

``` text
ShippingService
```

with adapters:

``` text
ShippingService
 ├── YalidineProvider
 ├── ZedairProvider
 └── FutureProvider
```

Core code should request operations such as:

``` text
create shipment
cancel shipment
get tracking
get status
get rates
```

without knowing provider-specific API details.

------------------------------------------------------------------------

# 14. Shipping Rules

Support:

-   shipping zones
-   wilaya pricing
-   commune pricing where required
-   home delivery
-   pickup points where supported
-   shipping fees
-   free-shipping thresholds
-   provider selection
-   estimated delivery
-   tracking
-   shipment status

Configuration must be client-specific.

------------------------------------------------------------------------

# 15. Yalidine

Integrate using Yalidine's **current official API documentation** at
implementation time.

Support where available:

-   authentication
-   destination lookup
-   rates
-   shipment creation
-   cancellation
-   tracking
-   status synchronization
-   labels
-   webhooks
-   error handling

Store provider data separately:

``` text
order_id
provider
provider_shipment_id
tracking_number
status
created_at
updated_at
metadata
```

------------------------------------------------------------------------

# 16. Zedair

Implement Zedair through the same shipping abstraction.

Support according to its current official API:

-   authentication
-   destination mapping
-   rates
-   shipment creation
-   cancellation
-   tracking
-   status synchronization
-   labels where available
-   webhooks where available
-   error handling

No Zedair-specific logic should leak into core order services.

------------------------------------------------------------------------

# 17. Payment Abstraction

Create:

``` text
PaymentService
```

with:

``` text
PaymentService
 ├── ChargilyProvider
 └── FutureProvider
```

Core functionality:

-   create payment
-   verify payment
-   retrieve status
-   process webhook
-   handle failures
-   handle expiration

------------------------------------------------------------------------

# 18. Chargily

Implement against Chargily's **current official API documentation**.

Support where available:

-   payment creation
-   checkout
-   redirect
-   verification
-   status
-   webhooks/callbacks
-   transaction storage
-   failure handling
-   expiration
-   refunds where supported

Critical rule:

> Never trust the frontend as proof that a payment succeeded. Verify
> server-side.

------------------------------------------------------------------------

# 19. Payment Transactions

Maintain transaction records containing appropriate fields:

``` text
internal transaction ID
order ID
provider
provider transaction ID
amount
currency
status
created_at
updated_at
metadata
```

Never store or log secrets unnecessarily.

------------------------------------------------------------------------

# 20. Webhooks

Secure provider webhook endpoints:

``` text
POST /webhooks/chargily
POST /webhooks/yalidine
POST /webhooks/zedair
```

Pipeline:

``` text
receive
→ authenticate/verify
→ validate
→ identify event
→ idempotency check
→ process
→ record event
→ respond
```

Duplicate events must not duplicate payments, shipments, notifications,
or order transitions.

------------------------------------------------------------------------

# 21. Coupons and Promotions

Support:

-   create/edit/delete coupons
-   percentage discounts
-   fixed discounts
-   minimum order
-   maximum discount where supported
-   expiry
-   usage limits
-   per-customer limits
-   product restrictions
-   category restrictions

------------------------------------------------------------------------

# 22. CMS

WordPress is the CMS layer.

Support:

-   pages
-   posts
-   categories
-   menus
-   banners
-   hero sections
-   FAQs
-   promotional blocks
-   reusable content
-   media

The Next.js frontend consumes structured content through the API.

------------------------------------------------------------------------

# 23. Homepage / Content Sections

Provide flexible content sections such as:

``` text
hero
featured_products
categories
promotion
banner
text
image
faq
testimonials
newsletter
custom
```

Do not force the frontend to use a WordPress theme.

------------------------------------------------------------------------

# 24. Media

Support:

-   uploads
-   metadata
-   product images
-   content images
-   featured images
-   deletion
-   search

Security:

-   MIME validation
-   extension validation
-   size limits
-   dangerous-file restrictions
-   authorization
-   safe storage/execution behavior

------------------------------------------------------------------------

# 25. SEO

Use one maintained SEO plugin.

Expose:

``` text
title
description
canonical
robots
Open Graph data
social image
structured data where appropriate
```

Next.js renders the final metadata.

------------------------------------------------------------------------

# 26. Advertising Pixels and Marketing

Create a centralized marketing event layer.

Potential integrations:

-   Meta Pixel
-   Google Analytics/Ads
-   TikTok Pixel
-   future providers

Events:

``` text
PageView
ViewContent
Search
AddToCart
InitiateCheckout
Purchase
```

Use event IDs/deduplication for purchase events.

------------------------------------------------------------------------

# 27. Analytics Dashboard

Endpoints:

``` text
GET /analytics/overview
GET /analytics/revenue
GET /analytics/orders
GET /analytics/products
GET /analytics/customers
GET /analytics/shipping
GET /analytics/cod
```

Metrics:

-   revenue
-   orders
-   customers
-   average order value
-   best sellers
-   low stock
-   cancellations
-   refunds
-   returns
-   COD confirmation rate
-   delivery rate
-   shipping cost
-   revenue by wilaya
-   provider performance

Date ranges:

``` text
today
yesterday
7 days
30 days
90 days
custom
```

------------------------------------------------------------------------

# 28. Revenue and Financial Reporting

Separate:

``` text
order total
gross revenue
net revenue
discounts
shipping revenue
shipping costs
refunds
payment fees
provider fees
profit/margin
```

Only calculate profit when reliable cost data exists.

------------------------------------------------------------------------

# 29. Notifications

Create a notification abstraction.

Support:

-   admin notifications
-   order notifications
-   payment notifications
-   shipping notifications
-   low-stock notifications
-   customer notifications

Potential channels:

``` text
email
SMS
WhatsApp
push
in-app
```

Only activate configured providers.

------------------------------------------------------------------------

# 30. Email

Use a reliable SMTP solution.

Transactional messages:

-   order confirmation
-   payment confirmation
-   shipment updates
-   delivery updates
-   cancellation
-   refund
-   password reset
-   admin alerts
-   low-stock alerts

------------------------------------------------------------------------

# 31. Search and Filtering

Admin APIs should support:

-   keyword search
-   SKU
-   order number
-   customer
-   phone
-   statuses
-   dates
-   categories
-   stock
-   wilaya
-   payment method
-   shipping provider

Use consistent pagination.

------------------------------------------------------------------------

# 32. Bulk Operations

Support safe bulk operations for:

-   products
-   inventory
-   orders
-   customers
-   categories

Examples:

``` text
bulk update
bulk status change
bulk delete
bulk category assignment
bulk export
```

Destructive operations require authorization and safeguards.

------------------------------------------------------------------------

# 33. Import / Export

Support:

-   product CSV import/export
-   inventory import/export
-   customer export
-   order export
-   category import/export where useful

Pipeline:

``` text
upload
→ validate
→ preview
→ confirm
→ process
→ report
```

Provide dry-run and error reporting.

------------------------------------------------------------------------

# 34. Audit Logs

Record sensitive/business actions:

``` text
user created
role changed
product deleted
price changed
stock changed
order cancelled
refund created
payment changed
shipping changed
settings changed
```

Record:

``` text
actor
action
resource
resource ID
timestamp
IP where appropriate
metadata
```

Do not log secrets or unnecessary personal data.

------------------------------------------------------------------------

# 35. Security

Implement:

-   input validation
-   sanitization
-   output escaping
-   authorization
-   capability checks
-   CSRF/nonce protection where applicable
-   prepared queries
-   secure authentication
-   rate limiting
-   restrictive CORS
-   security headers where appropriate
-   webhook verification
-   secret management
-   file-upload restrictions
-   audit logs
-   brute-force protection
-   privileged-user 2FA
-   backups/recovery

Test:

-   IDOR
-   privilege escalation
-   injection
-   XSS
-   CSRF
-   malicious uploads
-   webhook forgery/replay
-   excessive data exposure

------------------------------------------------------------------------

# 36. API Security

Every private endpoint must validate:

``` text
identity
authorization
input
resource ownership/access
```

Never expose privileged WordPress credentials to the browser.

------------------------------------------------------------------------

# 37. CORS

Development may allow local Next.js origins.

Production must use an explicit allowlist.

Do not use unrestricted `*` for private APIs.

------------------------------------------------------------------------

# 38. Secrets

Never hard-code or commit:

-   database passwords
-   Yalidine credentials
-   Zedair credentials
-   Chargily credentials
-   SMTP credentials
-   webhook secrets
-   authentication tokens

Use environment variables or an appropriate secret manager.

------------------------------------------------------------------------

# 39. Database Strategy

Use WooCommerce data structures for:

-   products
-   variations
-   orders
-   customers
-   coupons

Use custom tables only where justified, potentially for:

``` text
audit_logs
payment_transactions
shipments
webhook_events
notification_events
analytics_aggregates
```

Custom tables require:

-   versioned migrations
-   indexes
-   tests
-   retention/cleanup strategy

------------------------------------------------------------------------

# 40. WooCommerce Compatibility

Respect current WooCommerce architecture, including:

-   HPOS
-   product APIs
-   order APIs
-   customer APIs
-   stock APIs
-   checkout/payment systems
-   hooks/actions/filters

Avoid unnecessary direct SQL manipulation of WooCommerce data.

------------------------------------------------------------------------

# 41. Performance

Provide:

-   pagination
-   indexed queries
-   N+1 avoidance
-   appropriate caching
-   efficient analytics
-   efficient API responses
-   image optimization strategy
-   object caching where justified

Measure before introducing unnecessary complexity.

------------------------------------------------------------------------

# 42. Health Checks and Monitoring

Provide:

``` text
GET /health
```

Check:

-   WordPress
-   database
-   WooCommerce
-   custom plugin
-   required integrations
-   API availability

Do not expose sensitive diagnostics.

------------------------------------------------------------------------

# 43. Logging

Log useful:

-   application errors
-   integration failures
-   webhook processing
-   security events
-   important business events

Never log:

-   passwords
-   API secrets
-   authentication tokens
-   unnecessary payment data

Use request/correlation IDs where practical.

------------------------------------------------------------------------

# 44. Backups and Recovery

Back up:

``` text
database
uploads/media
configuration
custom code
```

Define:

-   frequency
-   retention
-   off-site storage
-   restoration procedure
-   restoration testing

A backup is not considered valid until restoration has been tested.

------------------------------------------------------------------------

# 45. Testing

Every major module needs tests.

## Unit

-   calculations
-   validation
-   business rules
-   permissions
-   provider mappings

## Integration

-   WordPress
-   WooCommerce
-   database
-   custom plugin

## API

-   success
-   validation errors
-   authentication
-   authorization
-   not found
-   pagination
-   edge cases

## Security

-   IDOR
-   privilege escalation
-   injection
-   XSS
-   CSRF where applicable
-   rate limits
-   webhook forgery
-   replay
-   malicious uploads

------------------------------------------------------------------------

# 46. Development Seed Data

Provide fake development data for:

-   categories
-   products
-   variations
-   customers
-   orders
-   coupons
-   Algerian locations

Never use real customer data as seed data.

------------------------------------------------------------------------

# 47. WP-CLI Automation

Create commands such as:

``` bash
wp algerian-commerce health
wp algerian-commerce migrate
wp algerian-commerce seed
wp algerian-commerce import-algeria
wp algerian-commerce sync-shipping
wp algerian-commerce clear-cache
```

Destructive commands require explicit confirmation.

------------------------------------------------------------------------

# 48. Client Configuration

Separate reusable code from client configuration.

Configuration may include:

``` text
store name
logo
currency
enabled payment providers
enabled shipping providers
COD
shipping rules
analytics IDs
marketing settings
notification providers
```

Secrets remain environment variables.

------------------------------------------------------------------------

# 49. Feature Flags

Support optional features:

``` text
COD
Chargily
Yalidine
Zedair
reviews
blog
marketing pixels
SMS
WhatsApp
newsletter
```

One backend template should serve different clients without modifying
core business logic.

------------------------------------------------------------------------

# 50. API Versioning

Start with:

``` text
v1
```

Do not silently introduce breaking changes.

Use a new API version for breaking changes.

Document deprecations.

------------------------------------------------------------------------

# 51. Frontend Contract

The backend must be consumable by:

``` text
Next.js storefront
Next.js admin
mobile apps
future frontends
```

The backend must not depend on React components or UI implementation.

------------------------------------------------------------------------

# 52. Admin API Coverage

The future admin panel must be able to manage:

``` text
Dashboard
Users
Roles
Products
Categories
Attributes
Inventory
Orders
Customers
Coupons
Shipping
Payments
COD
Analytics
CMS
Media
Marketing
Notifications
Settings
Audit logs
Import/export
```

Routine administration should not require direct WordPress dashboard
access.

------------------------------------------------------------------------

# 53. Storefront API Coverage

The storefront should be able to retrieve/use:

``` text
products
categories
product details
variations
search
availability
pricing
cart
checkout
customer accounts
orders
CMS content
shipping options
payment options
SEO metadata
```

Cart/checkout architecture must use supported WooCommerce mechanisms and
be finalized during implementation.

------------------------------------------------------------------------

# 54. Free Plugin Baseline

Prioritize free/core functionality.

Core:

``` text
WooCommerce
```

Potential supporting plugins, subject to current compatibility and
maintenance:

``` text
Wordfence Security
WP Mail SMTP or FluentSMTP
Rank Math SEO
Meta for WooCommerce
UpdraftPlus
Advanced Custom Fields
```

Do not install multiple plugins solving the same problem.

The custom plugin should own Algerian-specific business logic.

------------------------------------------------------------------------

# 55. Documentation

Maintain:

``` text
README.md
API.md
ARCHITECTURE.md
SECURITY.md
DEPLOYMENT.md
```

Document:

-   setup
-   configuration
-   API
-   integrations
-   environment variables
-   troubleshooting
-   backups
-   restore
-   deployment

------------------------------------------------------------------------

# 56. Recommended Implementation Phases

## Phase 1 --- Foundation

``` text
WordPress
WooCommerce
custom plugin
REST API
configuration
logging
health
```

## Phase 2 --- Security and Users

``` text
authentication
RBAC
capabilities
audit logs
rate limiting
security foundation
```

## Phase 3 --- Commerce

``` text
products
variations
categories
attributes
inventory
orders
customers
coupons
```

## Phase 4 --- Algeria

``` text
wilayas
communes
postal data
shipping rules
COD
```

## Phase 5 --- Shipping

``` text
shipping abstraction
Yalidine
Zedair
tracking
status synchronization
```

## Phase 6 --- Payments

``` text
payment abstraction
Chargily
transactions
webhooks
```

## Phase 7 --- CMS and Marketing

``` text
CMS
media
SEO
pixels
marketing events
notifications
```

## Phase 8 --- Analytics

``` text
dashboard
revenue
products
orders
customers
shipping
COD
```

## Phase 9 --- Operations

``` text
import/export
backups
monitoring
performance
security hardening
```

## Phase 10 --- API Stabilization

``` text
API documentation
integration tests
error consistency
pagination
versioning
frontend contract
```

------------------------------------------------------------------------

# 57. Definition of Done

The backend template is complete when:

``` text
[ ] Product CRUD
[ ] Variations
[ ] Categories/attributes
[ ] Inventory
[ ] Orders
[ ] Customers
[ ] Users/RBAC
[ ] Analytics
[ ] Revenue reporting
[ ] CMS
[ ] Media
[ ] SEO data
[ ] Marketing events/pixels
[ ] Algerian geographic data
[ ] COD
[ ] Shipping abstraction
[ ] Yalidine
[ ] Zedair
[ ] Payment abstraction
[ ] Chargily
[ ] Secure/idempotent webhooks
[ ] Coupons
[ ] Notifications
[ ] Import/export
[ ] Audit logs
[ ] Security controls
[ ] API documentation
[ ] Automated tests
[ ] Backup/recovery process
[ ] Health checks
[ ] Next.js API integration verified
```

------------------------------------------------------------------------

# 58. Final Architecture

``` text
                    WORDPRESS
                        |
                   WOOCOMMERCE
                        |
             ALGERIAN COMMERCE CORE
                        |
        +---------------+---------------+
        |               |               |
     Commerce        Integrations      CMS
        |               |               |
   Products          Yalidine         Pages
   Inventory         Zedair           Media
   Orders            Chargily         Content
   Customers
   Coupons
   COD
        |
        +---------------+
                        |
                    REST API
                        |
             +----------+----------+
             |                     |
        Next.js Admin         Next.js Store
```

The finished backend is a reusable template:

``` text
Backend Template
      |
      +── Client A
      +── Client B
      +── Client C
      +── Client D
```

Each client receives the same backend foundation with client-specific
configuration and a completely custom Next.js frontend.

------------------------------------------------------------------------

# 59. Source-of-Truth Rule

For third-party integrations, always use the provider's current official
documentation as the implementation authority.

This applies especially to:

-   Yalidine
-   Zedair
-   Chargily
-   WordPress
-   WooCommerce

This plan specifies required capabilities, not frozen third-party
endpoints or payloads.

------------------------------------------------------------------------

# 60. Relationship With the Docker/Claude Roadmap

Keep these as separate documents:

``` text
docs/
├── PLAN.md
└── ALGERIAN_HEADLESS_ECOMMERCE_CLAUDE_DOCKER_GIT_ROADMAP.md
```

`PLAN.md` answers:

> What are we building?

The roadmap answers:

> How do we build it efficiently with Docker + Git + Claude Code?

Claude should read both before implementing major features.
