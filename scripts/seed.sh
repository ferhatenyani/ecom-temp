#!/usr/bin/env bash
#
# Roadmap §67, docs/PLAN.md §46 — load the development fixtures.
#
#   scripts/seed.sh [--dry-run] [--keep-notifications]
#
# Two commands in a fixed order, and the order is the point.
#
# **Geography first, and from §51's dataset rather than from anything here.**
# PLAN §46 lists "Algerian locations" among the seed data, and this repository
# already ships 69 wilayas and 1,541 communes as generated JSON with an importer
# that loads them. A seed inventing its own Algiers would be a second source of
# truth for the one dataset where that is most expensive: a courier matches
# communes *by name*, so a fixture spelling one differently is a delivery
# address the adapter cannot resolve. So this calls `import-algeria`.
#
# **Then the shop, through the services.** `wp algerian-commerce seed` writes
# categories, products, variations, customers, coupons and orders — every one of
# them through the same service the REST API uses, so the fixtures are proof the
# API can build this shop rather than a state it would refuse. See src/Seed/.
#
# It is idempotent. Run it twice and the second run updates.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

ARGS=()
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; ARGS+=("--dry-run"); shift ;;
    --keep-notifications) ARGS+=("--keep-notifications"); shift ;;
    --as) ARGS+=("--as=${2:-}"); shift 2 ;;
    -h|--help) sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[1m→\033[0m %s\n' "$1"; }

if ! docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | grep -q '^wordpress running'; then
  echo "the stack is not running — start it with: docker compose up -d" >&2
  exit 1
fi

step "Algerian geography (roadmap §51)"
geo_args=()
[[ $DRY_RUN -eq 1 ]] && geo_args+=("--dry-run")
docker compose run --rm -T wpcli wp algerian-commerce import-algeria "${geo_args[@]}" \
  2>&1 | grep -v '^ Container' || exit 1

step "shop fixtures (roadmap §67)"
docker compose run --rm -T wpcli wp algerian-commerce seed "${ARGS[@]}" \
  2>&1 | grep -v '^ Container' || exit 1

if [[ $DRY_RUN -eq 0 ]]; then
  # Said here rather than buried in the command's output: the notification queue
  # is the one thing a seed run touches that somebody might come looking for.
  printf '\nThe notifications the seeded orders queued have been discarded '
  printf '(--keep-notifications keeps them).\nWooCommerce'"'"'s own transactional mail is '
  printf 'short-circuited for the run — it sends synchronously,\nso there would be nothing '
  printf 'left to discard afterwards. See src/Seed/Seeder.php.\n'
fi
