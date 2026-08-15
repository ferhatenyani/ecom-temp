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

printf '=== %d passed, %d failed ===\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
