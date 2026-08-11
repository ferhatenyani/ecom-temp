#!/usr/bin/env bash
#
# HTTP-level API tests.
#
# These run against the real stack over real HTTP, which is the point.
# rest_do_request() — what the in-process checks use — never parses an
# Authorization header, so it cannot see authentication or rate limiting at
# all. A rate-limit guard that let every credential-guessing attempt through
# once shipped with a completely green unit suite for exactly that reason.
#
#   scripts/test-api.sh [base-url]
#
set -uo pipefail

BASE="${1:-http://localhost:${WP_PORT:-8090}}"
API="${BASE}/wp-json/algerian-commerce/v1"
COMPOSE=(docker compose)

pass=0
fail=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    printf '  \033[32mPASS\033[0m %-52s %s\n' "$label" "$actual"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %-52s %s (expected %s)\n' "$label" "$actual" "$expected"
    fail=$((fail + 1))
  fi
}

status() { curl -s -o /dev/null -w '%{http_code}' -m 15 "$@"; }

wpcli() { "${COMPOSE[@]}" run --rm -T "$@" 2>/dev/null | tr -d '\r'; }

echo "Testing ${API}"
echo

# ---------------------------------------------------------------- fixtures --
# A throwaway service account and a freshly minted application password. The
# password is never echoed; only its length is, so a failing CI log cannot
# leak a working credential.
CRED=$(wpcli -e AC_LOGIN=ac_apitest wpcli wp eval '
$login = getenv("AC_LOGIN");
$u = get_user_by("login", $login);
if (!$u) {
    $id = wp_insert_user(["user_login" => $login, "user_pass" => wp_generate_password(32),
                          "user_email" => $login . "@example.test", "role" => "ac_admin"]);
    $u = get_user_by("id", $id);
}
$u->set_role("ac_admin");
foreach (WP_Application_Passwords::get_user_application_passwords($u->ID) as $p) {
    WP_Application_Passwords::delete_application_password($u->ID, $p["uuid"]);
}
$new = WP_Application_Passwords::create_new_application_password($u->ID, ["name" => "api-test"]);
echo $login, ":", $new[0];
')

if [[ ${#CRED} -lt 10 ]]; then
  echo "could not create a test credential — is the stack up?" >&2
  exit 1
fi
echo "credential issued (${#CRED} chars, not printed)"

# Start from a clean slate: a lockout left by a previous run would make every
# assertion below meaningless.
IP=$(wpcli wpcli wp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%ac_rl_%\""); echo "cleared";')
echo "rate-limit counters: ${IP}"
echo

# ----------------------------------------------------------------- public ---
echo "public"
check "GET /health is public" 200 "$(status "${API}/health")"
echo

# ---------------------------------------------------------- authentication --
echo "authentication"
check "GET /products without credentials" 401 "$(status "${API}/products")"
check "GET /products with application password" 200 "$(status -u "$CRED" "${API}/products")"
check "GET /inventory with application password" 200 "$(status -u "$CRED" "${API}/inventory")"
check "GET /auth/me without credentials" 401 "$(status "${API}/auth/me")"
check "GET /auth/me with application password" 200 "$(status -u "$CRED" "${API}/auth/me")"
check "GET /auth/me reports the plugin capabilities" "ac_manage_inventory" \
  "$(curl -s -m 15 -u "$CRED" "${API}/auth/me" | grep -o 'ac_manage_inventory' | head -1)"
check "GET /auth/me hides core WordPress capabilities" "" \
  "$(curl -s -m 15 -u "$CRED" "${API}/auth/me" | grep -o 'install_plugins' | head -1)"
echo

# ----------------------------------------------------- brute-force lockout --
#
# THE REGRESSION TEST.
#
# The first implementation hooked rest_pre_dispatch only. When Basic auth
# credentials are present and wrong, WordPress fails the request during
# authentication and serves 401 without ever dispatching — so the guard never
# ran, and every attempt returned 401 forever while the unit suite stayed
# green. If this block reports 401 where it expects 429, that bug is back.
echo "brute-force lockout (limit 10 failures / 15 min per IP)"

# Boundary note: the failure from the request in flight is recorded during
# authentication, before rest_authentication_errors runs. The request that
# spends the last of the budget therefore reports 429 rather than 401 — the
# caller is told the moment it is exhausted. Assertions sit either side of
# that edge rather than exactly on it.
for _ in $(seq 1 8); do
  status -u "ac_apitest:wrong wrong wrong wrong" "${API}/products" > /dev/null
done

check "wrong credentials inside the budget are 401" 401 \
  "$(status -u "ac_apitest:wrong wrong wrong wrong" "${API}/products")"

status -u "ac_apitest:wrong wrong wrong wrong" "${API}/products" > /dev/null

check "once the budget is spent, attempts are 429 not 401" 429 \
  "$(status -u "ac_apitest:wrong wrong wrong wrong" "${API}/products")"
check "a locked-out address gets Retry-After" "true" \
  "$(curl -s -D - -o /dev/null -m 15 -u "ac_apitest:wrong wrong wrong wrong" "${API}/products" \
     | grep -qi '^retry-after:' && echo true || echo false)"
check "other REST namespaces are unaffected" 401 \
  "$(status -u "ac_apitest:wrong wrong wrong wrong" "${BASE}/wp-json/wp/v2/users/me")"
echo

# --------------------------------------------------------------- recovery ---
echo "operator recovery"
SEEN=$(wpcli wpcli wp eval '
$l = AlgerianCommerce\Core\Plugin::instance()->rateLimiter();
global $wpdb;
$row = $wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"_transient_ac_rl_authfail%\" LIMIT 1");
echo $row ? "locked" : "none";
')
check "a lockout is recorded" "locked" "$SEEN"

"${COMPOSE[@]}" run --rm -T wpcli wp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%ac_rl_%\"");' >/dev/null 2>&1
check "a valid credential works again after unlock" 200 "$(status -u "$CRED" "${API}/products")"
echo

printf '=== %d passed, %d failed ===\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
