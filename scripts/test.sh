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
#   syntax  php -l over every file
#   unit    pure logic, no WordPress          (tests/Unit)
#   rest    routing, args, permissions, IDOR  (tests/Api, via rest_do_request)
#   http    authentication and rate limiting  (scripts/test-api.sh, real HTTP)
#
# The last stage is not redundant: rest_do_request() never parses an
# Authorization header, so nothing before it can observe authentication or
# rate limiting at all.
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

if run_stage syntax; then
  banner "syntax"
  docker compose exec -T wordpress sh -c \
    "cd ${PLUGIN} && find src migrations tests -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors' || true" \
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
  for suite in wp-content/plugins/algerian-commerce-core/tests/Api/*.php; do
    [[ -e "$suite" ]] || continue
    name=$(basename "$suite" .php)
    docker compose run --rm -T wpcli wp eval-file - < "$suite" 2>&1 | grep -vE '^ Container|^\s*$'
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
