#!/usr/bin/env bash
#
# Roadmap §66 — the stack health check.
#
#   scripts/health.sh [base-url]
#
# §66 asks this to check Docker, WordPress, the database, WooCommerce, the
# custom plugin, the REST API and the health endpoint. Five of those seven are
# already answered from the inside by `GET /health`, which exists, is public and
# reports `wordpress`, `database`, `woocommerce`, `plugin` and `schema`. So this
# script does not re-implement them: it checks the two layers the endpoint
# cannot see, and then asks the endpoint for the rest.
#
#   the container layer  a running endpoint says nothing about the *other*
#                        containers. `wpcli` is run-on-demand and `db` can be
#                        up while mysqld is still doing crash recovery.
#   the transport        the endpoint answering `rest_do_request()` and the
#                        endpoint answering Apache on the published port are
#                        different claims. Permalinks, the port mapping and the
#                        rewrite all live between them.
#   the endpoint         asked once, and its own verdict is reported per check
#                        rather than summarised, because "degraded" without a
#                        column is a fault nobody can act on.
#
# What it deliberately does **not** check is versions. `scripts/test.sh versions`
# is roadmap §68's record and compares the running stack against compose.yaml's
# pins; a second comparison here would be a second copy of that logic, and the
# copy is the one that drifts. Health is "is this stack answering", not "is this
# stack the one we tested".
#
# Exit codes: 0 healthy, 1 degraded or unreachable. Safe to run from a monitor.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

BASE="${1:-http://localhost:${WP_PORT:-8090}}"
API="${BASE}/wp-json/algerian-commerce/v1"

pass=0
fail=0

ok()   { printf '  \033[32m✔\033[0m %-28s %s\n' "$1" "${2:-ok}"; pass=$((pass + 1)); }
bad()  { printf '  \033[31m✘\033[0m %-28s %s\n' "$1" "${2:-error}"; fail=$((fail + 1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ------------------------------------------------------------------ docker --
head_ "docker"

if ! command -v docker >/dev/null 2>&1; then
  bad "docker" "not installed"
  printf '\n\033[31mcannot continue without docker\033[0m\n'
  exit 1
fi
ok "docker" "$(docker --version | sed 's/Docker version //; s/,.*//')"

if ! docker compose version >/dev/null 2>&1; then
  bad "docker compose" "the v2 plugin is not available"
  exit 1
fi
ok "docker compose" "$(docker compose version --short 2>/dev/null)"

for service in db wordpress; do
  state=$(docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | awk -v s="$service" '$1 == s {print $2}')
  [[ "$state" == "running" ]] && ok "container ${service}" "$state" || bad "container ${service}" "${state:-not running}"
done

# `wpcli` is run-on-demand, so it is *supposed* to be absent from `ps`. What
# matters is that it can start and reach the install — the uid 33/82 mismatch
# CLAUDE.md records makes "the image runs" and "the image can work" different
# questions.
if docker compose run --rm -T wpcli wp core version >/dev/null 2>&1; then
  ok "container wpcli" "runs on demand"
else
  bad "container wpcli" "cannot run wp"
fi

# ---------------------------------------------------------------- database --
head_ "database"

# Asked of mysqld itself rather than through WordPress: a database that is up
# but still recovering answers this and fails everything else, and that is a
# distinction worth being able to make at 3am.
if docker compose exec -T db sh -c 'mysqladmin ping --silent -u"$MYSQL_USER" -p"$MYSQL_PASSWORD"' >/dev/null 2>&1; then
  ok "mysqld" "accepting connections"
else
  bad "mysqld" "not answering"
fi

# ------------------------------------------------------------------- http ---
head_ "http (${BASE})"

code=$(curl -s -o /dev/null -w '%{http_code}' -m 15 "${BASE}/" 2>/dev/null)
[[ "$code" == "200" || "$code" == "301" || "$code" == "302" ]] \
  && ok "wordpress" "HTTP ${code}" \
  || bad "wordpress" "HTTP ${code:-no answer}"

# The namespace index rather than a route: it proves the REST API is mounted and
# the permalink rewrite works without depending on any one controller.
code=$(curl -s -o /dev/null -w '%{http_code}' -m 15 "${BASE}/wp-json/algerian-commerce/v1" 2>/dev/null)
[[ "$code" == "200" ]] \
  && ok "rest api" "namespace registered" \
  || bad "rest api" "HTTP ${code:-no answer} at /wp-json/algerian-commerce/v1"

# --------------------------------------------------------- health endpoint --
head_ "health endpoint"

body=$(curl -s -m 15 "${API}/health" 2>/dev/null)
code=$(curl -s -o /dev/null -w '%{http_code}' -m 15 "${API}/health" 2>/dev/null)

if [[ -z "$body" ]]; then
  bad "GET /health" "no answer"
else
  # 503 is the endpoint reporting a real fault, not a broken endpoint — it
  # answers "degraded" with the same body shape, so the checks below still read.
  [[ "$code" == "200" ]] && ok "GET /health" "HTTP 200" || bad "GET /health" "HTTP ${code}"

  for check in wordpress database woocommerce plugin schema; do
    verdict=$(printf '%s' "$body" | grep -o "\"${check}\":\"[a-z]*\"" | head -1 | sed 's/.*:"//; s/"//')
    [[ "$verdict" == "ok" ]] && ok "  ${check}" "ok" || bad "  ${check}" "${verdict:-absent}"
  done
fi

# ---------------------------------------------------------------- summary ---
printf '\n'
if [[ $fail -eq 0 ]]; then
  printf '\033[32mhealthy — %d checks passed\033[0m\n' "$pass"
  exit 0
fi

printf '\033[31mdegraded — %d of %d checks failed\033[0m\n' "$fail" "$((pass + fail))"
exit 1
