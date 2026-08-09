# algerian-commerce-core

The application layer for the Algerian headless commerce backend. WordPress is the platform and
WooCommerce is the commerce engine; all reusable business logic lives here and is exposed over one
REST namespace.

No frontend: this plugin renders no HTML, ships no CSS or JavaScript, and adds no admin screens. It
answers HTTP requests with JSON. See [../../../docs/ARCHITECTURE.md](../../../docs/ARCHITECTURE.md).

## Current state — Milestone 4 (foundation)

Implemented: plugin bootstrap, PSR-4 autoloading, configuration and feature flags, logging with
secret redaction, the `algerian-commerce/v1` namespace, the shared response envelope, error handling,
and the health endpoint.

Not implemented yet: products, orders, customers, inventory, RBAC, shipping, payments, CMS.

```
algerian-commerce-core.php   bootstrap: header, constants, autoload, lifecycle hooks
src/Core/                    Autoloader, Config, Logger, Plugin (wiring + lifecycle)
src/API/                     Response envelope, ApiException, ErrorNormalizer,
                             AbstractController, RestApi, HealthController
tests/Unit/                  unit tests — no WordPress required
```

## Endpoints

| Method | Route | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/wp-json/algerian-commerce/v1/health` | public | stack liveness |

```bash
curl http://localhost:8090/wp-json/algerian-commerce/v1/health
# {"success":true,"status":"ok","data":{"checks":{...}}}
```

Returns HTTP 503 with `"status":"degraded"` when any check fails.

## Response contract

```json
{ "success": true, "data": {} }
{ "success": false, "error": { "code": "not_found", "message": "…", "details": {} } }
```

Controllers never build an envelope by hand — use `Response::success()` / `Response::error()`, or
throw an `ApiException`, whose code and HTTP status the base controller maps for you. Errors raised
by WordPress itself (no matching route, failed `permission_callback`, schema validation) are
rewritten into the same envelope by `ErrorNormalizer`, scoped to this namespace only.

## Adding a controller

1. Extend `AbstractController` and implement `registerRoutes()`.
2. Wrap the callback in `$this->handle(...)` so it shares the error contract.
3. Give every route a real `permission_callback` — `__return_true` requires a justifying comment.
4. Register it in `Plugin::restApi()`.

## Development

Composer is optional at runtime: without `vendor/`, the bundled PSR-4 autoloader in
`src/Core/Autoloader.php` covers `src/`. It is required to run the tests.

```bash
# install dev dependencies (from this directory)
docker run --rm -v "$PWD":/app -w /app composer:2 install

# run the unit suite
docker compose exec wordpress sh -c \
  'cd /var/www/html/wp-content/plugins/algerian-commerce-core && php vendor/bin/phpunit'

# lint
docker compose exec wordpress sh -c \
  'find /var/www/html/wp-content/plugins/algerian-commerce-core -name "*.php" -exec php -l {} \;'
```

Unit tests must run without booting WordPress. Integration tests that need a live WordPress arrive
with the WP test suite in a later milestone.

## Configuration

Secrets come from environment variables only — never the options table, never code. Feature flags
(`ENABLE_COD`, `ENABLE_CHARGILY`, `ENABLE_YALIDINE`, `ENABLE_ZEDAIR`, …) all default to off so one
codebase can serve multiple clients. `AC_LOG_LEVEL` sets the log floor (`debug`, `info`, `warning`,
`error`); it defaults to `debug` when `WP_DEBUG` is on, otherwise `info`.
