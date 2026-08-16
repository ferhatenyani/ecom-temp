#!/usr/bin/env bash
#
# Roadmap §66 — destroy the development environment and rebuild it.
#
#   scripts/reset.sh                 # asks, loudly, and refuses if it cannot
#   scripts/reset.sh --yes           # for automation; still refuses non-local
#   scripts/reset.sh --keep-backup   # take a backup first, then destroy
#
# §66 says: "Make it explicitly destructive." So it is, in four ways, and none
# of them is the default.
#
#   1. `docker compose down -v` is the destructive path and it is **never** what
#      happens without an explicit answer. There is no flag that makes
#      destruction implicit, and no argument order that reaches it by accident.
#   2. It refuses outright unless the environment declares itself local.
#      WP_ENVIRONMENT_TYPE is the same switch WordPress uses to decide whether
#      Application Passwords may travel over plain HTTP (compose.yaml), so
#      "staging" and "production" already mean something here — this reads it
#      rather than inventing a second signal that could disagree.
#   3. It names what it is about to destroy, with counts, read from the database
#      it is about to drop. "907 orders" stops a hand that "the database" does
#      not.
#   4. The confirmation is a typed word, not a keypress. `y` is muscle memory;
#      DESTROY is a decision.
#
# What it destroys: both volumes. `db_data` holds every order, customer and
# audit row; `wordpress_data` holds WordPress itself, WooCommerce, and
# **wp-content/uploads** — the media library, which is not in this repository
# and is not recoverable from it. The plugin source survives because it is a
# bind mount from the working tree.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

ASSUME_YES=0
KEEP_BACKUP=0
SEED=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --yes|-y)      ASSUME_YES=1; shift ;;
    --keep-backup) KEEP_BACKUP=1; shift ;;
    --no-seed)     SEED=0; shift ;;
    -h|--help)     sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[1m→\033[0m %s\n' "$1"; }
die()  { printf '\n\033[31m✘ %s\033[0m\n' "$1" >&2; exit 1; }

# ------------------------------------------------------- refuse non-local ----
ENV_TYPE="${WP_ENVIRONMENT_TYPE:-$(sed -n 's/^WP_ENVIRONMENT_TYPE=//p' .env 2>/dev/null | head -1)}"
ENV_TYPE="${ENV_TYPE:-local}"

if [[ "$ENV_TYPE" != "local" && "$ENV_TYPE" != "development" ]]; then
  die "WP_ENVIRONMENT_TYPE is \"${ENV_TYPE}\". This script is development-only and will not run here."
fi

# ------------------------------------------------------------- inventory ----
printf '\033[31m\033[1m'
printf '╔════════════════════════════════════════════════════════════════════╗\n'
printf '║  RESET — this destroys the development database and the WordPress  ║\n'
printf '║  install, including wp-content/uploads. There is no undo.          ║\n'
printf '╚════════════════════════════════════════════════════════════════════╝\n'
printf '\033[0m\n'

if docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | grep -q '^db running'; then
  printf 'About to be destroyed:\n'
  docker compose run --rm -T wpcli wp eval '
    global $wpdb;
    $rows = [
        "orders"     => "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders",
        "products"   => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = \"product\"",
        "customers"  => "SELECT COUNT(*) FROM {$wpdb->users}",
        "audit rows" => "SELECT COUNT(*) FROM {$wpdb->prefix}ac_audit_logs",
        "media"      => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = \"attachment\"",
    ];
    foreach ($rows as $label => $sql) {
        printf("  %-12s %s\n", $label, (string) ($wpdb->get_var($sql) ?? "?"));
    }
  ' 2>/dev/null | grep -v '^ Container' | tr -d '\r'
  printf '  volumes      db_data, wordpress_data\n\n'
else
  printf 'The stack is not running, so nothing could be counted.\n'
  printf 'The volumes db_data and wordpress_data will still be removed.\n\n'
fi

# --------------------------------------------------------------- confirm ----
if [[ $ASSUME_YES -ne 1 ]]; then
  if [[ ! -t 0 ]]; then
    # A destructive script that reads a confirmation from a pipe is a
    # destructive script with no confirmation.
    die "refusing to reset without a terminal to confirm at — pass --yes if you mean it"
  fi

  printf 'Type DESTROY to continue, anything else to abort: '
  read -r answer
  [[ "$answer" == "DESTROY" ]] || die "aborted; nothing was changed"
fi

# ---------------------------------------------------------------- backup ----
if [[ $KEEP_BACKUP -eq 1 ]]; then
  step "taking a backup first"
  bash scripts/backup.sh --label pre-reset || die "the backup failed — refusing to destroy anything"
fi

# --------------------------------------------------------------- destroy ----
step "stopping the stack and removing both volumes"
# -t 120 rather than the default 10s: SIGKILLing mysqld mid-shutdown (exit 137)
# makes the next boot do crash recovery, and CLAUDE.md records that costing an
# afternoon. The volume is about to be deleted, but this same shape of command
# is the one people copy elsewhere.
docker compose stop -t 120 || true
docker compose down -v || die "docker compose down -v failed"
printf '   \033[32mgone\033[0m\n'

# --------------------------------------------------------------- rebuild ----
step "rebuilding"
args=()
[[ $SEED -eq 0 ]] && args+=("--no-seed")
bash scripts/setup.sh "${args[@]}" || die "setup failed after the reset — the stack is not usable"

printf '\n\033[32m✔ reset complete\033[0m\n'
