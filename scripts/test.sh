#!/usr/bin/env bash
#
# Roadmap §66 — the full test run.
#
#   scripts/test.sh            # everything
#   scripts/test.sh unit       # one stage
#
# Stages, in the order §65 lists them. Each is here because the one before it
# is blind to something:
#
#   versions  the running stack against compose.yaml's pins (§68)
#   syntax    php -l over every file
#   unit      pure logic, no WordPress          (tests/Unit)
#   rest      routing, args, permissions, IDOR  (tests/Api, via rest_do_request)
#   http      authentication and rate limiting  (scripts/test-api.sh, real HTTP)
#
# The last stage is not redundant, and it is blind to nothing the others see.
# rest_do_request() misses three things, not one: it never parses an
# Authorization header, so nothing before `http` can observe authentication or
# rate limiting; it never runs rest_pre_serve_request, so CORS headers and §64's
# file downloads are invisible to it; and wp_handle_upload() ends in
# move_uploaded_file(), which by design fails for anything that did not arrive
# over a real POST. See docs/TESTING.md.
#
# The first is roadmap §68's "record tested versions", written as a check rather
# than as a table. A table would be a second copy of what compose.yaml already
# states, and the copy is the one that goes stale; this fails instead. It also
# answers the first question of §68's upgrade drill — branch, upgrade, run
# tests, inspect logs, test integrations, review, merge — which is whether the
# thing that moved is the thing you meant to move.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

PLUGIN=/var/www/html/wp-content/plugins/algerian-commerce-core
STAGE="${1:-all}"
failed=()

run_stage() { [[ "$STAGE" == "all" || "$STAGE" == "$1" ]]; }

banner() { printf '\n\033[1m── %s ──\033[0m\n' "$1"; }

record() {
  if [[ $2 -eq 0 ]]; then
    printf '\033[32m✔ %s\033[0m\n' "$1"
  else
    printf '\033[31m✘ %s\033[0m\n' "$1"
    failed+=("$1")
  fi
}

if ! docker compose ps --format '{{.Name}}' 2>/dev/null | grep -q wordpress; then
  echo "the stack is not running — start it with: docker compose up -d" >&2
  exit 1
fi

if run_stage versions; then
  banner "versions (roadmap §68)"

  # Each pin is read out of compose.yaml, never restated here — a number written
  # in two files is a number that will disagree with itself.
  want_wp=$(sed -n 's/.*image: wordpress:\([0-9.]*\)-php.*/\1/p' compose.yaml | head -1)
  want_php=$(sed -n 's/.*image: wordpress:[0-9.]*-php\([0-9.]*\)-apache.*/\1/p' compose.yaml | head -1)
  want_db=$(sed -n 's/.*image: mysql:\([0-9.]*\).*/\1/p' compose.yaml | head -1)
  want_cli=$(sed -n 's/.*image: wordpress:cli-\([0-9.]*\)-php.*/\1/p' compose.yaml | head -1)
  want_wc=$(sed -n 's/^  woocommerce: "\(.*\)"/\1/p' compose.yaml | head -1)

  got_wp=$(docker compose run --rm -T wpcli wp core version 2>/dev/null | tr -d '\r\n')
  got_php=$(docker compose exec -T wordpress php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;' 2>/dev/null | tr -d '\r\n')
  got_db=$(docker compose exec -T db mysqld --version 2>/dev/null | sed -n 's/.*Ver \([0-9.]*\) .*/\1/p' | tr -d '\r\n')
  got_cli=$(docker compose run --rm -T wpcli wp cli version 2>/dev/null | sed -n 's/WP-CLI \([0-9.]*\)/\1/p' | tr -d '\r\n')
  got_wc=$(docker compose run --rm -T wpcli wp plugin get woocommerce --field=version 2>/dev/null | tr -d '\r\n')

  drift=0
  compare() {
    if [[ "$2" == "$3" && -n "$2" ]]; then
      printf '  \033[32m✔\033[0m %-12s %s\n' "$1" "$3"
    else
      printf '  \033[31m✘\033[0m %-12s running %s, pinned %s\n' "$1" "${3:-<unknown>}" "${2:-<unset>}"
      drift=1
    fi
  }

  compare "wordpress" "$want_wp" "$got_wp"
  # PHP is pinned at the minor: the image tag says php8.4 and the patch moves
  # with the base image, which is the direction security updates arrive from.
  compare "php" "$want_php" "$got_php"
  compare "mysql" "$want_db" "$got_db"
  compare "wp-cli" "$want_cli" "$got_cli"
  compare "woocommerce" "$want_wc" "$got_wc"

  record "versions" "$drift"
fi

if run_stage syntax; then
  banner "syntax"
  docker compose exec -T wordpress sh -c \
    "cd ${PLUGIN} && find src integrations migrations tests -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors' || true" \
    | tee /tmp/ac-syntax.log
  [[ -s /tmp/ac-syntax.log ]] && record "syntax" 1 || record "syntax" 0
fi

if run_stage unit; then
  banner "unit"
  docker compose exec -T wordpress sh -c "cd ${PLUGIN} && php vendor/bin/phpunit"
  record "unit" $?
fi

if run_stage rest; then
  banner "rest (in-process)"
  # Rate limiting off for this stage, and only this stage. The counters live in
  # the database, so every suite shares one per-minute window: the sixth suite
  # to run was collecting 429s for requests that were nothing to do with it,
  # which turns an unrelated failure into a mystery. Rate limiting is the
  # `http` stage's subject, over real HTTP, where it can actually be observed —
  # rest_do_request() cannot see an Authorization header at all.
  for suite in wp-content/plugins/algerian-commerce-core/tests/Api/*.php; do
    [[ -e "$suite" ]] || continue
    name=$(basename "$suite" .php)

    # Per-suite environment. The marketing suite needs a registered provider:
    # without one the claim, the queue and the dedup path are all skipped, and
    # "a shop with no pixel" is the only case left. Those credentials are
    # deliberately fake and the suite never drains a row belonging to a
    # registered provider, so nothing here reaches the network — what Meta is
    # actually sent is covered against recorded responses in
    # tests/Unit/MetaProviderTest.
    #
    # The analytics suite turns its response cache off, so an assertion made
    # after placing an order sees the shop as it is rather than as it was a
    # minute ago. What the cache itself guarantees — above all that a payload
    # with money in it never shares a key with one without — is a unit test on
    # the pure key function, in tests/Unit/AnalyticsCacheTest.
    extra=()
    if [[ "$name" == "marketing" ]]; then
      extra=(-e ENABLE_MARKETING_PIXELS=1
             -e META_PIXEL_ID=000000000000000
             -e META_CAPI_ACCESS_TOKEN=ac-test-token-not-real)
    elif [[ "$name" == "analytics" ]]; then
      extra=(-e AC_ANALYTICS_CACHE_TTL=0)
    fi

    docker compose run --rm -T -e AC_RATE_LIMIT_DISABLED=1 "${extra[@]}" wpcli wp eval-file - < "$suite" 2>&1 | grep -vE '^ Container|^\s*$'
    record "rest:${name}" "${PIPESTATUS[0]}"
  done
fi

if run_stage http; then
  banner "http"
  bash scripts/test-api.sh
  record "http" $?
fi

banner "summary"
if [[ ${#failed[@]} -eq 0 ]]; then
  printf '\033[32mall stages passed\033[0m\n'
  exit 0
fi

printf '\033[31mfailed: %s\033[0m\n' "${failed[*]}"
exit 1
