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
# Analytics is a private route like any other, and this stage is the only one
# that can see an Authorization header at all. The capability *split* — who is
# shown money and who is not — needs several accounts and lives in
# tests/Api/analytics.php; the service account here is ac_admin and holds both.
check "GET /analytics/overview without credentials" 401 "$(status "${API}/analytics/overview")"
check "GET /analytics/overview with application password" 200 \
  "$(status -u "$CRED" "${API}/analytics/overview")"
echo

# ------------------------------------------------------------------- CSRF ---
#
# Roadmap §65 lists CSRF, and docs/SECURITY.md → "CSRF" records why this API is
# not exposed to it. The argument rests on one property, and this is the only
# stage that can observe it: **a browser's ambient credentials are never
# sufficient.** Cookies ride along on any cross-site request automatically;
# an Authorization header does not.
#
# WordPress core is what enforces it. `rest_cookie_check_errors()` calls
# `wp_set_current_user(0)` on any REST request that arrives with no
# `X-WP-Nonce`, whatever the cookies said, so the request reaches our
# permission_callback as an anonymous one. That is why the cookie below does
# not need to be a *valid* session for the assertion to mean something — the
# nonce check runs before the cookie is ever trusted, which is exactly the
# property being asserted. What would break this is a plugin or a future change
# that authenticated a request from cookies alone.
echo "CSRF (roadmap §65 — ambient credentials are never enough)"

COOKIE_NAME=$(wpcli wpcli wp eval 'echo LOGGED_IN_COOKIE;')
SESSION="${COOKIE_NAME}=ac_apitest|9999999999|deadbeefdeadbeefdeadbeef|0000000000000000"

check "a read with cookies and no nonce is anonymous" 401 \
  "$(status -H "Cookie: ${SESSION}" "${API}/auth/me")"
check "a write with cookies and no nonce is anonymous" 401 \
  "$(status -X POST -H "Cookie: ${SESSION}" -H 'Content-Type: application/json' \
     -d '{"name":"csrf probe","regular_price":"10"}' "${API}/products")"
check "a nonce that does not verify is refused" 403 \
  "$(status -H "Cookie: ${SESSION}" -H 'X-WP-Nonce: 0000000000' "${API}/auth/me")"

# The control. Both refusals above are also what an unreachable route returns,
# so the same route with a real credential has to succeed or they prove nothing.
check "the same route answers a real credential" 200 "$(status -u "$CRED" "${API}/auth/me")"

# No product was created by the write attempt above. Asserted rather than
# assumed: a CSRF hole is silent from the attacker's side and from ours.
check "the refused write created nothing" "0" \
  "$(wpcli wpcli wp eval '
     $q = new WP_Query(["post_type" => "product", "title" => "csrf probe", "post_status" => "any",
                        "fields" => "ids", "posts_per_page" => 5]);
     echo count($q->posts);')"
echo

# ------------------------------------------------------------------- CORS ---
#
# `API/Cors` removes core's `rest_send_cors_headers` and replaces it with an
# allowlist on our namespace only. That swap happens in `rest_pre_serve_request`
# — a filter rest_do_request() never runs — so tests/Unit/OriginPolicyTest can
# prove the allowlist decides correctly and is structurally blind to whether the
# handler is installed at all.
#
# The contrast with wp/v2 is the assertion that matters: core really does
# reflect any Origin it is given, so seeing our namespace refuse the same
# origin that core echoes proves our handler ran.
echo "CORS (roadmap §46)"

cors_origin() {
  curl -s -D - -o /dev/null -m 15 -H "Origin: $1" -u "$CRED" "$2" \
    | grep -i '^access-control-allow-origin:' | tr -d '\r' | awk '{print $2}'
}

check "an allowed origin is echoed back" "http://localhost:3000" \
  "$(cors_origin "http://localhost:3000" "${API}/products?per_page=1")"
check "a foreign origin gets no allow-origin header" "" \
  "$(cors_origin "https://evil.example" "${API}/products?per_page=1")"
check "core would have echoed that foreign origin" "https://evil.example" \
  "$(cors_origin "https://evil.example" "${BASE}/wp-json/wp/v2/types")"
# Without Vary, a shared cache can serve the allowed origin's approved response
# to the next caller — including the refusal, which is the worse direction.
check "the response varies on Origin, even when refusing" "yes" \
  "$(curl -s -D - -o /dev/null -m 15 -H 'Origin: https://evil.example' -u "$CRED" "${API}/products?per_page=1" \
     | grep -qi '^vary:.*origin' && echo yes || echo no)"
echo

# ------------------------------------------------------------------- §69 ---
#
# Roadmap §69: exercise product CRUD, order CRUD, customer queries,
# authentication and permissions over real HTTP **before** the Next.js admin
# becomes the main testing interface.
#
# The tests/Api suites already cover all of this in far more depth, in-process.
# What they cannot cover is the transport, and this is a round trip through the
# whole of it: Apache, the permalink rewrite, the Authorization header, a JSON
# request body read off the wire, and our envelope on the way back. §69's point
# is that the API stands on its own, so what is proven here is the path a
# Next.js client will actually take — not the business rules, which have their
# own suites and do not need a second, thinner copy.
echo "CRUD over HTTP (roadmap §69)"

json() { curl -s -m 30 -u "$CRED" -H 'Content-Type: application/json' "$@"; }
field() { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | cut -d: -f2; }

# Start from a clean slate, trash included. A previous run that ended early
# leaves the SKU held by a trashed product, and every assertion below would then
# be about that instead — the same reason the rate-limit counters are cleared at
# the top of this script.
wpcli wpcli wp eval '
foreach (["publish", "draft", "pending", "private", "trash"] as $status) {
    foreach (wc_get_products(["sku" => "ac-69-probe", "status" => $status,
                              "limit" => 20, "return" => "ids"]) as $id) {
        wp_delete_post((int) $id, true);
    }
}
echo "purged";' >/dev/null

CREATED=$(json -X POST -d '{"name":"AC §69 probe","regular_price":"1234.00","sku":"ac-69-probe"}' "${API}/products")
PROBE_ID=$(field "$CREATED" id)

check "POST /products creates" "true" "$([[ -n "${PROBE_ID:-}" && "${PROBE_ID}" -gt 0 ]] && echo true || echo false)"
check "the response is our envelope" "true" \
  "$(grep -q '"success":true' <<<"$CREATED" && echo true || echo false)"
check "GET /products/{id} reads it back" 200 "$(status -u "$CRED" "${API}/products/${PROBE_ID:-0}")"
check "PATCH /products/{id} updates" "true" \
  "$(json -X PATCH -d '{"regular_price":"999.00"}' "${API}/products/${PROBE_ID:-0}" \
     | grep -q '"regular_price":"999.00"' && echo true || echo false)"
check "the update survives a fresh read" "true" \
  "$(json "${API}/products/${PROBE_ID:-0}" | grep -q '"regular_price":"999.00"' && echo true || echo false)"

# A duplicate SKU is the one write conflict a storefront hits constantly, and
# §65's API list names "duplicate" as its own category. 409 rather than 400:
# the request is well formed, the shop's state is what refuses it.
check "a duplicate SKU is a conflict" 409 \
  "$(status -X POST -u "$CRED" -H 'Content-Type: application/json' \
     -d '{"name":"AC §69 duplicate","regular_price":"1.00","sku":"ac-69-probe"}' "${API}/products")"

# Orders and customers: read paths only. An order write over HTTP would leave
# stock movements and audit rows behind in a database this script does not own,
# and tests/Api/orders.php already drives the whole lifecycle in-process.
check "GET /orders lists" 200 "$(status -u "$CRED" "${API}/orders?per_page=1")"
check "GET /customers lists" 200 "$(status -u "$CRED" "${API}/customers?per_page=1")"
check "GET /orders/{unknown} is 404" 404 "$(status -u "$CRED" "${API}/orders/99999999")"
check "pagination is honoured over the wire" "1" \
  "$(json "${API}/products?per_page=1" | grep -o '"per_page":[0-9]*' | head -1 | cut -d: -f2)"
check "a bad argument is 400, not 500" 400 "$(status -u "$CRED" "${API}/products?per_page=9999")"

# Filtering — roadmap §82. Only this stage can see these, and the reason is
# narrow but real: every in-process assertion reaches the route through
# `set_param('attributes', ['pa_x' => 'y'])`, an array handed straight to PHP.
# Over the wire `attributes[pa_x]=y` is a *query string* that PHP has to parse
# into that array first, and a route arg declared `type => object` has to accept
# what comes out. Nothing before this line has ever exercised that.
echo
echo "filtering over the wire (§82)"
check "a bracketed attribute filter parses" 400 \
  "$(status -u "$CRED" "${API}/products?attributes%5Bpa_nonexistent%5D=m")"
check "...and refuses the unknown attribute by name" "facetable_attributes" \
  "$(curl -s -m 15 -u "$CRED" "${API}/products?attributes%5Bpa_nonexistent%5D=m" \
     | grep -o 'facetable_attributes' | head -1)"
check "a scalar where a map belongs is 400" 400 \
  "$(status -u "$CRED" "${API}/products?attributes=pa_size")"
check "a comma-separated category list parses" 200 \
  "$(status -u "$CRED" "${API}/products?category=1,2&per_page=1")"
check "a non-numeric category is refused by the arg pattern" 400 \
  "$(status -u "$CRED" "${API}/products?category=tapis")"
check "an inverted price band is 400" 400 \
  "$(status -u "$CRED" "${API}/products?min_price=500&max_price=100")"
check "an unknown facet group is 400" 400 \
  "$(status -u "$CRED" "${API}/products?facets=everything")"
check "facets arrive under meta, not data" "\"scope\":\"publish\"" \
  "$(curl -s -m 15 -u "$CRED" "${API}/products?per_page=1&facets=price,stock_status" \
     | grep -o '"scope":"publish"' | head -1)"
check "a listing that asks for none carries no facet block" "" \
  "$(curl -s -m 15 -u "$CRED" "${API}/products?per_page=1" | grep -o '"facets"' | head -1)"

# Permissions, with a second credential that holds none of the capabilities
# above. This is the §69 line that the in-process suites *can* cover but that
# nothing has yet checked travels correctly with a real Authorization header.
SUPPORT_CRED=$(wpcli -e AC_LOGIN=ac_apitest_support wpcli wp eval '
$login = getenv("AC_LOGIN");
$u = get_user_by("login", $login);
if (!$u) {
    $id = wp_insert_user(["user_login" => $login, "user_pass" => wp_generate_password(32),
                          "user_email" => $login . "@example.test", "role" => "ac_support_agent"]);
    $u = get_user_by("id", $id);
}
$u->set_role("ac_support_agent");
foreach (WP_Application_Passwords::get_user_application_passwords($u->ID) as $p) {
    WP_Application_Passwords::delete_application_password($u->ID, $p["uuid"]);
}
$new = WP_Application_Passwords::create_new_application_password($u->ID, ["name" => "api-test-support"]);
echo $login, ":", $new[0];
')

check "a Support Agent authenticates" 200 "$(status -u "$SUPPORT_CRED" "${API}/auth/me")"
check "...and may read customers" 200 "$(status -u "$SUPPORT_CRED" "${API}/customers?per_page=1")"
check "...but not products" 403 "$(status -u "$SUPPORT_CRED" "${API}/products")"
check "...nor orders" 403 "$(status -u "$SUPPORT_CRED" "${API}/orders")"
check "...nor write a product" 403 \
  "$(status -X POST -u "$SUPPORT_CRED" -H 'Content-Type: application/json' \
     -d '{"name":"nope","regular_price":"1.00"}' "${API}/products")"

# DELETE trashes; force=true is the permanent one. That is WooCommerce's own
# behaviour and the audit trail names the two differently (`product.trashed`
# against `product.deleted`), so a client that meant to empty the catalogue and
# only trashed it can tell. Both halves are asserted here because the difference
# is invisible from the status code alone — the first DELETE also answers 200.
check "DELETE /products/{id} trashes it" 200 "$(status -X DELETE -u "$CRED" "${API}/products/${PROBE_ID:-0}")"
check "a trashed product is still addressable" 200 "$(status -u "$CRED" "${API}/products/${PROBE_ID:-0}")"
check "DELETE ?force=true removes it" 200 \
  "$(status -X DELETE -u "$CRED" "${API}/products/${PROBE_ID:-0}?force=true")"
check "the forced-deleted product is gone" 404 "$(status -u "$CRED" "${API}/products/${PROBE_ID:-0}")"
echo

# --------------------------------------------------------------- account --
#
# §59c's session travels as the `X-Customer-Token` header, which
# rest_do_request() cannot carry — tests/Api/account.php has to use the
# parameter, so the header path a real storefront uses would otherwise never
# run. The IDOR itself is proven in depth there; what is here is the transport
# and the one thing only this stage can see at all: **the login brute-force
# lockout**, which needs a real client IP.
#
# That lockout is not automatic. RateLimitGuard hooks
# `application_password_failed_authentication`, which is WordPress's admin path;
# a customer login goes nowhere near it, so AccountService records the failure
# itself. If this block reports 401 where it expects 429, that wiring is gone
# and the shop's customer logins are unlimited — the same regression the
# application-password guard already shipped with once.
echo "customer accounts (roadmap §59c)"

ACCT_EMAIL="ac-http-account@example.test"
ACCT_PASS="CorrectHorseBatteryHttp"

wpcli -e AC_EMAIL="$ACCT_EMAIL" wpcli wp eval '
$u = get_user_by("email", getenv("AC_EMAIL"));
if ($u) { wp_delete_user($u->ID); }
echo "cleared";' >/dev/null

ACCT_BODY=$(curl -s -m 30 -X POST -H 'Content-Type: application/json' \
  -d "{\"email\":\"${ACCT_EMAIL}\",\"password\":\"${ACCT_PASS}\",\"first_name\":\"Http\"}" \
  "${API}/account/register")
ACCT_TOKEN=$(printf '%s' "$ACCT_BODY" | grep -o '"token":"[^"]*"' | head -1 | cut -d'"' -f4)

check "registration needs no credential" "true" \
  "$([[ ${#ACCT_TOKEN} -gt 40 ]] && echo true || echo false)"

# THE PROPERTY THIS BLOCK EXISTS FOR: the header carries the session.
check "the X-Customer-Token header carries the session" 200 \
  "$(status -H "X-Customer-Token: ${ACCT_TOKEN}" "${API}/account")"
check "no header is 401" 401 "$(status "${API}/account")"
check "a forged header is 401" 401 \
  "$(status -H "X-Customer-Token: ${ACCT_EMAIL}|9999999999|a|b" "${API}/account")"

# A shopper session must not open any staff route, whatever it is sent as.
check "a customer session opens no staff route" 401 \
  "$(status -H "X-Customer-Token: ${ACCT_TOKEN}" "${API}/orders")"
check "...and is not an Authorization credential" 401 \
  "$(status -u "${ACCT_EMAIL}:${ACCT_PASS}" "${API}/products")"

# The lockout. Wrong passwords, until the limiter answers instead of the login.
locked=""
for _ in $(seq 1 25); do
  if [[ "$(status -X POST -H 'Content-Type: application/json' \
       -d "{\"email\":\"${ACCT_EMAIL}\",\"password\":\"wrong wrong wrong\"}" \
       "${API}/account/login")" == "429" ]]; then
    locked=429
    break
  fi
done
check "repeated bad logins are rate limited, not 401 forever" 429 "${locked:-401}"

# ...and the correct password is refused too while the address is locked out,
# which is what makes it a lockout rather than a slow-down.
check "a locked-out address is refused a correct password" 429 \
  "$(status -X POST -H 'Content-Type: application/json' \
     -d "{\"email\":\"${ACCT_EMAIL}\",\"password\":\"${ACCT_PASS}\"}" "${API}/account/login")"

"${COMPOSE[@]}" run --rm -T wpcli wp eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%ac_rl_%\"");' >/dev/null 2>&1
check "and works again once unlocked" 200 \
  "$(status -X POST -H 'Content-Type: application/json' \
     -d "{\"email\":\"${ACCT_EMAIL}\",\"password\":\"${ACCT_PASS}\"}" "${API}/account/login")"

wpcli -e AC_EMAIL="$ACCT_EMAIL" wpcli wp eval '
$u = get_user_by("email", getenv("AC_EMAIL"));
if ($u) { wp_delete_user($u->ID); }
echo "cleaned";' >/dev/null
echo

# ------------------------------------------------------------------ cart --
#
# §59b's cart is covered in depth by tests/Api/cart.php. One property is not
# reachable from there and belongs here: the token travels as the `Cart-Token`
# **header**. rest_do_request() parses no headers, so the in-process suite has
# to use the `cart_token` parameter, and the header path — the one a real
# storefront will use — would otherwise never be executed.
#
# The cart is also the only public write surface in the API, so "no credential
# needed" is asserted rather than assumed.
echo "cart (roadmap §59b — the header path)"

CART_SKU="ac-http-cart"
CART_PID=$(wpcli -e AC_SKU="$CART_SKU" wpcli wp eval '
$sku = getenv("AC_SKU");
foreach (["publish", "draft", "trash"] as $status) {
    foreach (wc_get_products(["sku" => $sku, "status" => $status, "limit" => 20, "return" => "ids"]) as $id) {
        wp_delete_post((int) $id, true);
    }
}
$p = new WC_Product_Simple();
$p->set_name("AC http cart fixture");
$p->set_sku($sku);
$p->set_regular_price("1000.00");
$p->set_status("publish");
echo (int) $p->save();
')

check "POST /cart/items needs no credential" 201 \
  "$(status -X POST -H 'Content-Type: application/json' \
     -d "{\"product_id\":${CART_PID:-0},\"quantity\":2}" "${API}/cart/items")"

CART_BODY=$(curl -s -m 30 -X POST -H 'Content-Type: application/json' \
  -d "{\"product_id\":${CART_PID:-0},\"quantity\":2}" "${API}/cart/items")
CART_TOKEN=$(printf '%s' "$CART_BODY" | grep -o '"cart_token":"[^"]*"' | head -1 | cut -d'"' -f4)

check "a token comes back in meta" "true" "$([[ ${#CART_TOKEN} -gt 40 ]] && echo true || echo false)"
check "the cart totalled the catalogue price" "2000.00" \
  "$(printf '%s' "$CART_BODY" | grep -o '"subtotal":"[^"]*"' | head -1 | cut -d'"' -f4)"

# THE PROPERTY THIS BLOCK EXISTS FOR: the header carries the cart.
check "the Cart-Token header restores the cart" "2" \
  "$(curl -s -m 30 -H "Cart-Token: ${CART_TOKEN}" "${API}/cart" \
     | grep -o '"items_count":[0-9]*' | head -1 | cut -d: -f2)"

# ...and without it, the same request is a different, empty cart.
check "no header means a new, empty cart" "0" \
  "$(curl -s -m 30 "${API}/cart" | grep -o '"items_count":[0-9]*' | head -1 | cut -d: -f2)"

check "a forged token in the header opens an empty cart" "0" \
  "$(curl -s -m 30 -H "Cart-Token: eyJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjoidF9mYWtlIn0.forged" "${API}/cart" \
     | grep -o '"items_count":[0-9]*' | head -1 | cut -d: -f2)"

# The §59b rule, over the wire.
check "a client-sent price is refused over HTTP" 400 \
  "$(status -X POST -H 'Content-Type: application/json' \
     -d "{\"product_id\":${CART_PID:-0},\"quantity\":1,\"price\":\"0.01\"}" "${API}/cart/items")"

wpcli -e AC_SKU="$CART_SKU" wpcli wp eval '
foreach (["publish", "draft", "trash"] as $status) {
    foreach (wc_get_products(["sku" => getenv("AC_SKU"), "status" => $status,
                              "limit" => 20, "return" => "ids"]) as $id) {
        wp_delete_post((int) $id, true);
    }
}
echo "cleaned";' >/dev/null
echo

# ---------------------------------------------------------------- exports --
# THE THIRD REGRESSION TEST.
#
# §64's exports answer with a file instead of the JSON envelope, and that
# happens in `rest_pre_serve_request` — a filter rest_do_request() never runs.
# So tests/Api/import-export.php can prove the CSV *body* and is structurally
# blind to whether the response is actually served as a download. Only this
# stage can see the headers.
echo "exports"
EXPORT_HEADERS=$(curl -s -D - -o /dev/null -m 30 -u "$CRED" "${API}/export/inventory?limit=2")

check "an export is served as text/csv" "yes" \
  "$(grep -qi '^content-type: text/csv' <<<"$EXPORT_HEADERS" && echo yes || echo no)"
check "an export is served as an attachment" "yes" \
  "$(grep -qi '^content-disposition: attachment' <<<"$EXPORT_HEADERS" && echo yes || echo no)"
# One shop's private data. Even over TLS, a shared cache holding it is a
# disclosure waiting for the next request.
check "an export is not cacheable" "yes" \
  "$(grep -qi '^cache-control:.*no-store' <<<"$EXPORT_HEADERS" && echo yes || echo no)"
check "the filename cannot carry a header injection" "yes" \
  "$(grep -i '^content-disposition' <<<"$EXPORT_HEADERS" | grep -qE 'filename="[A-Za-z0-9._-]+"' && echo yes || echo no)"

# The body is the CSV itself and not an envelope around it.
EXPORT_BODY=$(curl -s -m 30 -u "$CRED" "${API}/export/inventory?limit=2")
check "the body is a CSV, not JSON" "yes" \
  "$(grep -q '"success"' <<<"$EXPORT_BODY" && echo no || echo yes)"
check "the CSV names its columns" "yes" \
  "$(head -1 <<<"$EXPORT_BODY" | grep -q 'sku' && echo yes || echo no)"

# An error must never be served raw: a client would save the error message as
# products.csv. The envelope comes back with the 4xx.
ERROR_BODY=$(curl -s -m 30 -u "$CRED" "${API}/export/orders?limit=999999")
check "an export error is still the envelope" "yes" \
  "$(grep -q '"success":false' <<<"$ERROR_BODY" && echo yes || echo no)"
check "an export error is not text/csv" 400 \
  "$(status -u "$CRED" "${API}/export/orders?limit=999999")"
echo

# ------------------------------------------------------------------ media --
#
# THE OTHER REGRESSION TEST.
#
# This is the only stage that can perform a real multipart upload.
# rest_do_request() cannot: wp_handle_upload() finishes with
# move_uploaded_file(), which by design fails for anything that did not arrive
# over a real POST. So tests/Api/media.php proves every hostile file is refused
# and stops there, and everything below the refusals — the file actually being
# written, its metadata actually being stripped, an appended payload actually
# disappearing — can only be observed here.
#
# It runs before the lockout section on purpose: that section deliberately
# spends the IP's authentication budget.
echo "media uploads (roadmap §61, §65 file upload abuse)"

MEDIA_TMP=$(mktemp -d)
trap 'rm -rf "$MEDIA_TMP"' EXIT

# Built inside the container, which has GD, and carried out as base64: a
# genuine JPEG carrying an EXIF ImageDescription, a JPEG comment and a PHP
# payload appended after the end-of-image marker. That is a polyglot with
# metadata — the file the checklist is about.
"${COMPOSE[@]}" exec -T wordpress php <<'PHP' | tr -d '\r' > "${MEDIA_TMP}/hostile.b64"
<?php
$image = imagecreatetruecolor(60, 40);
imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
ob_start();
imagejpeg($image, null, 90);
$jpeg = (string) ob_get_clean();
imagedestroy($image);

$tiff = "II*\0" . pack('V', 8) . pack('v', 1)
      . pack('vvVa4', 0x010E, 2, 3, "AC\0\0") . pack('V', 0);
$exif = "Exif\0\0" . $tiff;
$app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
$comment = 'ac-metadata-marker';
$com = "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment;

echo base64_encode(
    substr($jpeg, 0, 2) . $app1 . $com . substr($jpeg, 2) . "\n<?php system(\$_GET[0]); ?>"
);
PHP

base64 -d < "${MEDIA_TMP}/hostile.b64" > "${MEDIA_TMP}/tapis berbère.jpg"
# Padded past UploadPolicy::MIN_BYTES, so this exercises the content sniff
# rather than the "empty or truncated" floor — a 28-byte web shell would be
# refused for the wrong reason and the test would prove nothing.
{ printf '%s' "<?php system(\$_GET['c']); ?>"; head -c 400 /dev/zero | tr '\0' '#'; } > "${MEDIA_TMP}/shell.jpg"
head -c 9000000 /dev/zero > "${MEDIA_TMP}/big.jpg"

upload() { status -X POST -u "$CRED" -F "$1" "${API}/media"; }

# JSON escapes forward slashes, so a URL lifted straight out of a response is
# not one curl can fetch. Missing this made the payload assertion below pass
# against an empty body.
media_field() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | cut -d'"' -f4 | sed 's|\\/|/|g'; }

check "POST /media without credentials" 401 \
  "$(status -X POST -F "file=@${MEDIA_TMP}/shell.jpg" "${API}/media")"
check "a PHP file renamed .jpg is refused" 415 \
  "$(upload "file=@${MEDIA_TMP}/shell.jpg;type=image/jpeg")"
check "a file over the cap is refused" 413 \
  "$(upload "file=@${MEDIA_TMP}/big.jpg")"
check "a double extension is refused" 400 \
  "$(upload "file=@${MEDIA_TMP}/tapis berbère.jpg;filename=shell.php.jpg")"

# A traversal filename over real HTTP never reaches the application: PHP's own
# multipart parser applies basename() before $_FILES exists, so what arrives is
# `evil.jpg`. UploadPolicy's check is the layer that does not depend on that
# being true, and tests/Api/media.php is where it is proven. What matters here
# is the outcome — the file lands inside the uploads directory, never above it.
ESCAPED=$(curl -s -m 30 -X POST -u "$CRED" \
  -F "file=@${MEDIA_TMP}/tapis berbère.jpg;filename=../../evil.jpg" "${API}/media")
ESCAPED_URL=$(media_field "$ESCAPED" url)
check "a traversal filename cannot escape the uploads directory" "true" \
  "$(printf '%s' "$ESCAPED_URL" | grep -qE '/wp-content/uploads/[0-9]{4}/[0-9]{2}/evil(-[0-9]+)?\.jpg$' && echo true || echo false)"
status -X DELETE -u "$CRED" "${API}/media/$(printf '%s' "$ESCAPED" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)" >/dev/null

# The one that has to succeed, and the four things that must be true of it.
CREATED=$(curl -s -m 30 -X POST -u "$CRED" \
  -F "file=@${MEDIA_TMP}/tapis berbère.jpg" -F "alt=Tapis berbère" "${API}/media")
MEDIA_ID=$(printf '%s' "$CREATED" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
MEDIA_URL=$(media_field "$CREATED" url)

check "a real image uploads" "true" "$([[ -n "${MEDIA_ID:-}" ]] && echo true || echo false)"
check "the stored file is served" 200 "$(status "${MEDIA_URL:-${BASE}/missing}")"

# The client's filename is never the stored one: no spaces, no accents, no path.
check "the filename is rewritten" "true" \
  "$(printf '%s' "$MEDIA_URL" | grep -qE '/tapis-berb-re(-[0-9]+)?\.jpg$' && echo true || echo false)"

# Nothing appended after the end-of-image marker survives the re-encode. This
# is what makes a polyglot inert rather than merely unexecutable. Fetched to a
# file first: `grep -c` over an empty body also answers 0, which would be a
# passing assertion about nothing.
curl -s -m 15 -o "${MEDIA_TMP}/stored.jpg" "${MEDIA_URL:-${BASE}/missing}"
check "the stored file came back" "true" \
  "$([[ -s "${MEDIA_TMP}/stored.jpg" ]] && echo true || echo false)"
check "the appended payload is gone" "0" \
  "$(grep -c '<?php' "${MEDIA_TMP}/stored.jpg" || true)"

# ...and neither does the metadata. Asked of the file on disk, because a
# `grep` over the response body would not see an EXIF tag that had been kept.
STRIPPED=$(wpcli -e AC_MEDIA_ID="${MEDIA_ID:-0}" wpcli wp eval '
$path = get_attached_file((int) getenv("AC_MEDIA_ID"));
$bytes = is_string($path) && is_file($path) ? (string) file_get_contents($path) : "";
$exif = $bytes === "" ? false : @exif_read_data($path);
$kept = is_array($exif) && isset($exif["ImageDescription"]);
echo (!$kept && !str_contains($bytes, "ac-metadata-marker") && $bytes !== "") ? "stripped" : "kept";
')
check "the metadata is stripped" "stripped" "$STRIPPED"

check "the item reads back" 200 "$(status -u "$CRED" "${API}/media/${MEDIA_ID:-0}")"
check "the item deletes" 200 "$(status -X DELETE -u "$CRED" "${API}/media/${MEDIA_ID:-0}")"

# Defence in depth, and the layer that does not depend on any of the above
# being right: the web server must refuse to execute anything in the uploads
# directory. A compose or Apache edit could silently drop this.
"${COMPOSE[@]}" exec -T wordpress sh -c \
  'mkdir -p /var/www/html/wp-content/uploads/ac-probe &&
   printf "%s" "<?php echo 1;" > /var/www/html/wp-content/uploads/ac-probe/t.php' >/dev/null 2>&1
check "uploads refuses to serve PHP" 403 "$(status "${BASE}/wp-content/uploads/ac-probe/t.php")"
"${COMPOSE[@]}" exec -T wordpress rm -rf /var/www/html/wp-content/uploads/ac-probe >/dev/null 2>&1
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

# The contract is "wrong credentials start as 401 and become 429 once the
# budget is spent" — not an exact attempt number.
#
# Asserting a precise count made this flaky: the window is fixed, not sliding,
# so a run that straddles a 15-minute boundary splits its failures across two
# counter keys and never reaches the threshold. That is a documented property
# of the limiter, not a bug in it, and a test that trips over it is testing
# the clock. Loop until the behaviour appears instead.
check "the first wrong credential is 401, not yet limited" 401 \
  "$(status -u "ac_apitest:wrong wrong wrong wrong" "${API}/products")"

locked=""
for _ in $(seq 1 25); do
  if [[ "$(status -u "ac_apitest:wrong wrong wrong wrong" "${API}/products")" == "429" ]]; then
    locked=429
    break
  fi
done

check "sustained wrong credentials become 429, not 401 forever" 429 "${locked:-401}"
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

# ---------------------------------------------------- the documented contract --
#
# Roadmap §70: the frontend is built against `/algerian-commerce/v1`, and
# docs/API.md is the document it is built from. A reference that drifts from the
# router is worse than none — it sends somebody to an endpoint that does not
# exist, or hides one that does.
#
# So the document is a **check**, the way §68's version pins are. This is the
# same reasoning that kept the tested versions in compose.yaml rather than in a
# table: the copy is the thing that goes stale, so the copy has to be verified.
#
# It runs here rather than in a tests/Api suite because docs/ is not inside the
# plugin, and only the plugin directory is mounted into the containers. This
# stage is on the host and can read both halves — the live routes over HTTP,
# and the file on disk.
#
# Direction matters: this asserts every **registered** route is documented, not
# the reverse. A route in the document with no registration is legitimate —
# docs/API.md lists the Yalidine and ZR Express webhooks, which by design are
# 404 until their secrets are configured.
echo "documented contract (roadmap §70)"

DOC="docs/API.md"

if [[ ! -f "$DOC" ]]; then
  check "docs/API.md exists" "yes" "no"
else
  # `(?P<id>\d+)` in the router is `{id}` in prose. Normalising here rather than
  # writing regexes into the document keeps the document readable, which is the
  # only reason anyone opens it.
  mapfile -t ROUTES < <(
    curl -s -m 15 "${API}" \
      | grep -o '\\/algerian-commerce\\/v1[^"]*' \
      | sed 's|\\/|/|g' \
      | sed 's|/algerian-commerce/v1||' \
      | sed -E 's|\(\?P<([a-z_]+)>[^)]*\)|{\1}|g' \
      | grep -v '^$' \
      | sort -u
  )

  # The floor. A grep that quietly matched nothing would report a fully
  # documented API in exactly the same way a fully documented API does — the
  # failure this repository has already shipped once.
  check "the namespace index lists the routes" "yes" \
    "$([[ ${#ROUTES[@]} -ge 80 ]] && echo yes || echo "no (${#ROUTES[@]} found)")"

  UNDOCUMENTED=()
  for route in "${ROUTES[@]}"; do
    grep -qF -- "\`${route}\`" "$DOC" || UNDOCUMENTED+=("$route")
  done

  if [[ ${#UNDOCUMENTED[@]} -eq 0 ]]; then
    check "every registered route is in docs/API.md" "0" "0"
  else
    check "every registered route is in docs/API.md" "0" "${#UNDOCUMENTED[@]}"
    printf '         %s\n' "${UNDOCUMENTED[@]}"
  fi
fi
echo

printf '=== %d passed, %d failed ===\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
