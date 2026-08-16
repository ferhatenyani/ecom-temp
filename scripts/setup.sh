#!/usr/bin/env bash
#
# Roadmap §66 — bring a machine from nothing to a working, seeded install.
#
#   scripts/setup.sh [--no-seed] [--no-pull]
#
# Runs §66's list in order: check Docker, start the containers, wait for the
# services, install WooCommerce, activate the plugins, create the roles, run the
# migrations, import the Algeria data, seed development data, run a health
# check. Every step is idempotent — this is the script you re-run after
# `docker compose down`, not only the one you run once.
#
# Four decisions in here were deferred to this script by name, and each one is a
# state nothing else fails loudly about:
#
#   **WooCommerce is installed at the pinned version.** It lives in the
#   `wordpress_data` volume, which is not version-controlled, so
#   `wp plugin install woocommerce` takes whatever is current — roadmap §68's
#   "do not depend indefinitely on latest" arriving through the one component
#   with no image tag to pin. compose.yaml declares it under `x-tested-versions`
#   and this reads that value. `scripts/test.sh versions` already fails on drift.
#
#   **The store currency is set to DZD.** A fresh install comes back `USD`, and
#   nothing breaks: prices render, orders save, and §62 publishes the wrong
#   currency to Google while §62b reports conversions in the wrong one to Meta.
#   WooCommerce records the currency per order, so this cannot be fixed
#   retroactively — it has to be right before the first order.
#
#   **Akismet and Hello Dolly are deleted, not deactivated.** Neither does
#   anything on a headless backend and unused code still has to be patched. They
#   live in the volume, so a fresh install brings them back and this removes
#   them again.
#
#   **The roles are installed before anything writes.** Every service asserts a
#   capability, so a seed run against a site with no `ac_*` roles fails as a
#   wall of 403s that reads like a broken seeder.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

SEED=1
PULL=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-seed) SEED=0; shift ;;
    --no-pull) PULL=0; shift ;;
    -h|--help) sed -n '2,35p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[1m── %s ──\033[0m\n' "$1"; }
note() { printf '   %s\n' "$1"; }
ok()   { printf '   \033[32m✔\033[0m %s\n' "$1"; }
warn() { printf '   \033[33m!\033[0m %s\n' "$1"; }
die()  { printf '\n\033[31m✘ %s\033[0m\n' "$1" >&2; exit 1; }

wpcli() { docker compose run --rm -T wpcli "$@" 2>&1 | grep -v '^ Container' | tr -d '\r'; }
wpq()   { docker compose run --rm -T wpcli "$@" >/dev/null 2>&1; }

# ------------------------------------------------------------------ docker --
step "docker"
command -v docker >/dev/null 2>&1 || die "docker is not installed"
docker compose version >/dev/null 2>&1 || die "the docker compose v2 plugin is not available"
docker info >/dev/null 2>&1 || die "the docker daemon is not responding — is Docker running?"
ok "docker $(docker --version | sed 's/Docker version //; s/,.*//')"

# ------------------------------------------------------------------- .env ----
step "environment"
if [[ ! -f .env ]]; then
  [[ -f .env.example ]] || die "neither .env nor .env.example exists"

  cp .env.example .env
  chmod 600 .env

  # compose.yaml interpolates DB_PASSWORD and DB_ROOT_PASSWORD into the db
  # container's environment, so an empty value here creates a MySQL install with
  # a blank password rather than failing. Generated rather than prompted:
  # nothing else needs to know them, and a password nobody typed is one nobody
  # reuses.
  for key in DB_PASSWORD DB_ROOT_PASSWORD; do
    secret=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)
    if grep -q "^${key}=" .env; then
      sed -i "s|^${key}=.*|${key}=${secret}|" .env
    else
      printf '%s=%s\n' "$key" "$secret" >> .env
    fi
  done

  ok ".env created from .env.example with generated database passwords"
  warn "provider credentials (Yalidine, ZR Express, Chargily, Meta, SMTP) are blank"
  warn "an adapter whose keys are blank simply never registers — see compose.yaml"
else
  ok ".env is present"
fi

# The pinned WooCommerce version, read from the one place it is written down.
WC_VERSION=$(sed -n 's/^  woocommerce: "\(.*\)"/\1/p' compose.yaml | head -1)
[[ -n "$WC_VERSION" ]] || die "compose.yaml has no x-tested-versions.woocommerce — see roadmap §68"
ok "WooCommerce pinned at ${WC_VERSION}"

# --------------------------------------------------------------- containers --
step "containers"
if [[ $PULL -eq 1 ]]; then
  note "pulling pinned images"
  docker compose pull --quiet 2>&1 | tail -3
fi

docker compose up -d || die "docker compose up failed"
ok "db, wordpress up"

note "waiting for mysqld"
ready=0
for _ in $(seq 1 60); do
  if docker compose exec -T db sh -c \
      'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" -N -B "$MYSQL_DATABASE" -e "SELECT 1"' >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done
[[ $ready -eq 1 ]] || die "the database never accepted a connection"
ok "mysqld accepting connections"

note "waiting for apache"
PORT="${WP_PORT:-$(sed -n 's/^WP_PORT=//p' .env | head -1)}"
PORT="${PORT:-8090}"
BASE="http://localhost:${PORT}"

ready=0
for _ in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -m 5 "${BASE}/" 2>/dev/null)
  # Any answer means Apache is serving; 500 is what an uninstalled WordPress
  # gives and is handled two steps down.
  [[ -n "$code" && "$code" != "000" ]] && { ready=1; break; }
  sleep 2
done
[[ $ready -eq 1 ]] || die "nothing answered on ${BASE} — check: docker compose logs wordpress"
ok "apache answering on ${BASE}"

# ---------------------------------------------------------------- wordpress --
step "wordpress"
if wpq wp core is-installed; then
  ok "already installed ($(wpcli wp core version))"
else
  note "installing WordPress"
  ADMIN_USER="${WP_ADMIN_USER:-admin}"
  ADMIN_EMAIL="${WP_ADMIN_EMAIL:-$(sed -n 's/^AC_ADMIN_EMAIL=//p' .env | head -1)}"
  ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.test}"
  ADMIN_PASS=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)

  wpcli wp core install \
    --url="$BASE" \
    --title="${WP_TITLE:-Algerian Commerce}" \
    --admin_user="$ADMIN_USER" \
    --admin_email="$ADMIN_EMAIL" \
    --admin_password="$ADMIN_PASS" \
    --skip-email || die "wp core install failed"

  ok "installed"
  printf '\n   \033[1madmin credentials — printed once, not stored\033[0m\n'
  printf '     user     %s\n     password %s\n\n' "$ADMIN_USER" "$ADMIN_PASS"
fi

# Pretty permalinks: the REST API answers at /wp-json only when the rewrite
# exists. Without this every route is reachable only as ?rest_route=, which no
# client in this project uses.
if [[ "$(wpcli wp option get permalink_structure)" != "/%postname%/" ]]; then
  wpq wp rewrite structure '/%postname%/' --hard
  wpq wp rewrite flush --hard
  ok "permalinks set to /%postname%/"
else
  ok "permalinks already pretty"
fi

# --------------------------------------------------------------- woocommerce --
step "woocommerce"
# Not through wpcli(), which folds stderr into stdout so the output can be
# read. Here that would capture WP-CLI's "the 'woocommerce' plugin could not be
# found" *as the version*, and the message below would then report an error
# string as the installed release. On a fresh volume that is the normal path.
INSTALLED=$(docker compose run --rm -T wpcli wp plugin get woocommerce --field=version 2>/dev/null \
  | grep -v '^ Container' | tr -d '\r')

if [[ "$INSTALLED" == "$WC_VERSION" ]]; then
  ok "WooCommerce ${WC_VERSION} already installed"
else
  [[ -n "$INSTALLED" ]] && note "WooCommerce ${INSTALLED} is installed; moving to the pinned ${WC_VERSION}"
  # --force so an existing install at another version is replaced rather than
  # skipped. This is the step that stops `latest` creeping in.
  wpcli wp plugin install woocommerce --version="$WC_VERSION" --force --activate \
    || die "could not install WooCommerce ${WC_VERSION}"
  ok "WooCommerce ${WC_VERSION} installed"
fi

wpq wp plugin is-active woocommerce || wpq wp plugin activate woocommerce
ok "WooCommerce active"

# HPOS. The plugin declares custom_order_tables compatibility and every
# repository here reaches orders through the CRUD; a legacy install would put
# orders in wp_posts, where §63's analytics answer 501 by design.
wpq wp option update woocommerce_custom_orders_table_enabled yes
wpq wp option update woocommerce_feature_custom_order_tables_enabled yes
ok "HPOS enabled"

if [[ "$(wpcli wp option get woocommerce_currency)" != "DZD" ]]; then
  wpq wp option update woocommerce_currency DZD
  ok "currency set to DZD (a fresh install returns USD, and nothing complains)"
else
  ok "currency is DZD"
fi

# -------------------------------------------------------------- housekeeping --
step "bundled plugins"
for bundled in akismet hello; do
  if wpq wp plugin is-installed "$bundled"; then
    wpq wp plugin delete "$bundled" && ok "deleted ${bundled}" || warn "could not delete ${bundled}"
  else
    ok "${bundled} absent"
  fi
done

# ------------------------------------------------------------------- plugin --
step "algerian-commerce-core"
wpq wp plugin is-active algerian-commerce-core || wpcli wp plugin activate algerian-commerce-core \
  || die "could not activate algerian-commerce-core — check: docker compose logs wordpress"
ok "active"

note "roles and capabilities"
wpcli wp algerian-commerce roles || die "role installation failed"

note "database migrations"
wpcli wp algerian-commerce migrate || die "migrations failed"

# --------------------------------------------------------------------- data --
if [[ $SEED -eq 1 ]]; then
  step "development data"
  bash scripts/seed.sh || die "seeding failed"
else
  step "development data"
  note "skipped (--no-seed); geography is not loaded either"
  warn "run scripts/seed.sh before the test suites — several expect the wilayas"
fi

# ------------------------------------------------------------------- health --
step "health"
bash scripts/health.sh "$BASE" || die "the stack came up but is not healthy"

printf '\n\033[32m✔ ready — %s\033[0m\n' "$BASE"
printf '  API      %s/wp-json/algerian-commerce/v1\n' "$BASE"
printf '  tests    scripts/test.sh\n'
printf '  backup   scripts/backup.sh\n'
