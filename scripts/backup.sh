#!/usr/bin/env bash
#
# Roadmap §66, docs/PLAN.md §44 — back up the database, the uploads and the
# configuration.
#
#   scripts/backup.sh [--keep N] [--label NAME]
#
# Produces one directory per run under backups/, holding four things and a
# manifest:
#
#   database.sql.gz   every table, dumped from inside the db container
#   uploads.tar.gz    wp-content/uploads, which lives in the volume
#   env               the .env this stack runs on — SECRETS, mode 600
#   plugin.tar.gz     the custom plugin as deployed
#   manifest.txt      versions, row counts and the git commit
#
# **`wp db export` and `wp db query` are not options and never will be while the
# images stay as they are.** The `wordpress:cli` image ships a MariaDB client;
# MySQL 8 authenticates with `caching_sha2_password`, whose plugin is not in
# /usr/lib/mariadb/plugin/, so the connection fails before any SQL runs:
#
#   mariadb-dump: Got error: 1045: "Plugin caching_sha2_password could not be
#   loaded ... No such file or directory" when trying to connect
#
# The dump therefore runs inside `db`, which has MySQL's own client. Each flag
# is load-bearing:
#
#   MYSQL_PWD            keeps the password out of the process list and out of
#                        shell history — it is read from the container's own
#                        environment and never passed in from here
#   --single-transaction a consistent InnoDB snapshot without locking the site
#   --quick              streams rows instead of buffering a table in memory
#   --no-tablespaces     avoids the PROCESS privilege, which `wordpress` lacks
#
# **Uploads are not in this repository.** wp-content/uploads lives in the
# `wordpress_data` volume and is gitignored besides, so copying the repo is not
# a backup of the media library — §61 made that library a first-class resource
# with its own endpoint. `docker compose cp` is how it comes out.
#
# **A backup is not valid until a restore has been tested** (docs/SECURITY.md →
# "Backups", docs/PLAN.md §44). `scripts/restore.sh` is the other half and it is
# meant to be run, not merely to exist — `scripts/restore.sh --verify <dir>`
# restores into a throwaway container and compares row counts without touching
# this stack. That is the same drill CLAUDE.md records for a MySQL major
# upgrade, and for the same reason: the failure is discovered while the original
# is still there or it is not discovered at all.
#
# backups/ is **local development only**. Production backups belong off-site
# (§44) — this writes a plain directory precisely so that `rclone`, `restic` or
# a mounted volume can take it from here.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

KEEP=7
LABEL=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --keep)  KEEP="${2:-7}"; shift 2 ;;
    --label) LABEL="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,50p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

STAMP=$(date +%F-%H%M%S)
NAME="${STAMP}${LABEL:+-${LABEL}}"
DEST="backups/${NAME}"

step() { printf '\033[1m→\033[0m %s\n' "$1"; }
die()  { printf '\033[31m✘ %s\033[0m\n' "$1" >&2; exit 1; }

if ! docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | grep -q '^db running'; then
  die "the db container is not running — start it with: docker compose up -d"
fi

mkdir -p "$DEST" || die "cannot create ${DEST}"
# The directory holds .env and every customer record in the shop. It is
# gitignored, which stops it being published; this stops it being world-readable
# on a shared machine, which is the other half.
chmod 700 "$DEST"

# ------------------------------------------------------------------ database --
step "dumping the database"
if ! docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --single-transaction --quick --no-tablespaces \
     -u"$MYSQL_USER" "$MYSQL_DATABASE"' 2>"${DEST}/database.err" | gzip > "${DEST}/database.sql.gz"
then
  die "mysqldump failed — see ${DEST}/database.err"
fi

# `set -o pipefail` catches a mysqldump that failed mid-stream, but an empty or
# truncated dump that exited 0 would still pass. The trailer is what a complete
# dump ends with, so the check is the file's own last line.
if ! gzip -dc "${DEST}/database.sql.gz" | tail -5 | grep -q 'Dump completed'; then
  die "the dump has no completion marker — treat it as truncated, not as a backup"
fi
rm -f "${DEST}/database.err"
printf '   %s\n' "$(du -h "${DEST}/database.sql.gz" | cut -f1) database.sql.gz"

# ------------------------------------------------------------------- uploads --
step "copying wp-content/uploads out of the volume"
UPLOAD_TMP=$(mktemp -d)
if docker compose cp wordpress:/var/www/html/wp-content/uploads "${UPLOAD_TMP}/uploads" >/dev/null 2>&1; then
  tar -czf "${DEST}/uploads.tar.gz" -C "$UPLOAD_TMP" uploads
  printf '   %s (%s files)\n' \
    "$(du -h "${DEST}/uploads.tar.gz" | cut -f1) uploads.tar.gz" \
    "$(find "${UPLOAD_TMP}/uploads" -type f | wc -l | tr -d ' ')"
else
  # A shop that has never had a media upload has no directory, and that is not
  # a failure. Recorded rather than passed over in silence, because "uploads
  # missing" and "uploads empty" must not look the same in six months.
  printf '   \033[33mno uploads directory in the volume — nothing to copy\033[0m\n'
  : > "${DEST}/uploads.absent"
fi
rm -rf "$UPLOAD_TMP"

# ------------------------------------------------------------- configuration --
step "copying the configuration"
if [[ -f .env ]]; then
  cp .env "${DEST}/env"
  chmod 600 "${DEST}/env"
  printf '   env (600, contains secrets)\n'
else
  printf '   \033[33mno .env — provider credentials are not in this backup\033[0m\n'
fi

# compose.yaml is version-controlled, so what is worth recording is *which*
# commit, not another copy of the file. The plugin is archived even though it is
# also in git: a restore that needs a git remote to be reachable is a restore
# with a dependency, and §44 asks for the custom code itself.
step "archiving the plugin"
# The excludes come before the path: GNU tar applies them only to arguments
# that follow, and silently archives everything when they trail.
tar --exclude=vendor --exclude=.phpunit.cache \
  -czf "${DEST}/plugin.tar.gz" -C wp-content/plugins algerian-commerce-core
printf '   %s\n' "$(du -h "${DEST}/plugin.tar.gz" | cut -f1) plugin.tar.gz"

# ------------------------------------------------------------------ manifest --
step "writing the manifest"
{
  echo "created            ${STAMP}"
  echo "host               $(hostname)"
  echo "git commit         $(git rev-parse HEAD 2>/dev/null || echo unknown)"
  echo "git branch         $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
  echo "git working tree   $([[ -n "$(git status --porcelain 2>/dev/null)" ]] && echo dirty || echo clean)"
  echo "mysql              $(docker compose exec -T db mysqld --version 2>/dev/null | sed 's/.*Ver \([0-9.]*\).*/\1/')"
  echo "wordpress          $(docker compose run --rm -T wpcli wp core version 2>/dev/null | tr -d '\r\n')"
  echo "woocommerce        $(docker compose run --rm -T wpcli wp plugin get woocommerce --field=version 2>/dev/null | tr -d '\r\n')"
  echo "schema version     $(docker compose run --rm -T wpcli wp eval 'echo get_option("ac_core_db_version", "?");' 2>/dev/null | tr -d '\r\n')"
  echo
  echo "row counts (the restore check compares these)"
  docker compose run --rm -T wpcli wp eval '
    global $wpdb;
    foreach ($wpdb->get_col("SHOW TABLES") as $table) {
        printf("  %-40s %d\n", $table, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"));
    }
  ' 2>/dev/null | tr -d '\r'
} > "${DEST}/manifest.txt"

grep -E '^(git commit|schema version)' "${DEST}/manifest.txt" | sed 's/^/   /'

# ------------------------------------------------------------------ rotation --
if [[ "$KEEP" -gt 0 ]]; then
  mapfile -t old < <(find backups -mindepth 1 -maxdepth 1 -type d -name '20*' | sort -r | tail -n +$((KEEP + 1)))
  if [[ ${#old[@]} -gt 0 ]]; then
    step "removing $((${#old[@]})) backup(s) beyond --keep ${KEEP}"
    for dir in "${old[@]}"; do
      printf '   %s\n' "$dir"
      rm -rf "$dir"
    done
  fi
fi

printf '\n\033[32m✔ %s\033[0m\n' "$DEST"
printf '\nA backup is not valid until a restore has been tested:\n'
printf '  scripts/restore.sh --verify %s\n' "$DEST"
