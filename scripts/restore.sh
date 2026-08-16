#!/usr/bin/env bash
#
# Roadmap §66, docs/PLAN.md §44 — the other half of backup.sh.
#
#   scripts/restore.sh --verify <backup-dir>     # prove the dump restores; touches nothing
#   scripts/restore.sh <backup-dir> [--yes]      # restore over this stack; DESTRUCTIVE
#     [--with-env] [--with-plugin]
#
# **A backup is not valid until a restore has been tested.** That is
# docs/PLAN.md §44 and docs/SECURITY.md → "Backups", and it is also the lesson
# CLAUDE.md records about the MySQL 8.0 → 8.4 upgrade: booting a new major
# against the volume rewrites it irreversibly, so the dump had to be verified
# *before* it was needed rather than after. This script exists so that
# verification is a command rather than an intention.
#
# `--verify` is the mode that should be run routinely. It starts a throwaway
# MySQL container of the pinned version, restores the dump into it, counts every
# table and compares those counts against the manifest the backup carries. The
# running stack is never touched, so it is safe on a machine serving traffic —
# which is the only way a restore drill ever actually gets run.
#
# The restoring mode is deliberately not the default and cannot be reached
# without either typing the confirmation or passing --yes. It drops and recreates
# the database, so everything written since the backup is gone. `.env` and the
# plugin are opt-in: a restore that silently overwrote live provider credentials
# with the ones from a week ago would be a very quiet outage.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

MODE="restore"
DIR=""
ASSUME_YES=0
WITH_ENV=0
WITH_PLUGIN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --verify)      MODE="verify"; shift ;;
    --yes|-y)      ASSUME_YES=1; shift ;;
    --with-env)    WITH_ENV=1; shift ;;
    --with-plugin) WITH_PLUGIN=1; shift ;;
    -h|--help)     sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*)            echo "unknown argument: $1" >&2; exit 2 ;;
    *)             DIR="$1"; shift ;;
  esac
done

step() { printf '\033[1m→\033[0m %s\n' "$1"; }
die()  { printf '\033[31m✘ %s\033[0m\n' "$1" >&2; exit 1; }

[[ -n "$DIR" ]] || die "which backup? usage: scripts/restore.sh [--verify] <backup-dir>"
[[ -d "$DIR" ]] || die "no such directory: ${DIR}"

DUMP="${DIR}/database.sql.gz"
[[ -f "$DUMP" ]] || die "no database.sql.gz in ${DIR}"

if [[ -f "${DIR}/manifest.txt" ]]; then
  printf '\n\033[1mbackup\033[0m %s\n' "$DIR"
  grep -E '^(created|git commit|mysql|wordpress|woocommerce|schema version)' "${DIR}/manifest.txt" | sed 's/^/  /'
  printf '\n'
fi

# ==============================================================================
# verify — restore into a throwaway container and compare row counts
# ==============================================================================
if [[ "$MODE" == "verify" ]]; then
  # The pin is read out of compose.yaml, never restated: a dump verified against
  # a different major version has proved something about the wrong database.
  IMAGE="mysql:$(sed -n 's/.*image: mysql:\([0-9.]*\).*/\1/p' compose.yaml | head -1)"
  CONTAINER="ac-restore-verify-$$"
  PASSWORD="verify-$$"

  step "starting a throwaway ${IMAGE}"
  if ! docker run --rm -d --name "$CONTAINER" \
      -e MYSQL_ROOT_PASSWORD="$PASSWORD" -e MYSQL_DATABASE=wordpress \
      "$IMAGE" >/dev/null; then
    die "could not start ${IMAGE}"
  fi

  cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; }
  trap cleanup EXIT

  step "waiting for it to accept connections"
  # A real query, not `mysqladmin ping`. Ping succeeds against the temporary
  # server the entrypoint runs *during* initialization — before root has its
  # password and before the database exists — so it reports ready and the very
  # next command fails with "Access denied", which reads as a broken backup
  # rather than as a race. Asking for a row from the target database is the
  # check that cannot pass early.
  ready=0
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER" sh -c \
        "MYSQL_PWD='${PASSWORD}' mysql -uroot -N -B wordpress -e 'SELECT 1'" >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 2
  done
  [[ $ready -eq 1 ]] || die "the throwaway database never came up"

  step "restoring the dump into it"
  if ! gzip -dc "$DUMP" | docker exec -i "$CONTAINER" \
      sh -c "MYSQL_PWD='${PASSWORD}' mysql -uroot wordpress"; then
    die "the dump did not restore — this backup is not a backup"
  fi

  step "comparing row counts against the manifest"
  # Every count is taken with COUNT(*), which is the number the manifest holds —
  # information_schema.table_rows is an estimate for InnoDB and would disagree
  # with a correct restore.
  #
  # Note the absent `-i` on the docker exec below. With it, docker reads stdin,
  # which here is the manifest being fed to the loop: the first table would be
  # compared, the rest swallowed, and the run would report "1 table, every count
  # matches" in green. That is exactly the shape of failure the `checked -eq 0`
  # guard exists for, and it happened on the first run of this script.
  mismatch=0
  checked=0
  missing=0

  while read -r table expected; do
    [[ -z "$table" ]] && continue
    [[ "$table" =~ ^[a-zA-Z0-9_]+$ ]] || continue

    got=$(docker exec "$CONTAINER" sh -c \
      "MYSQL_PWD='${PASSWORD}' mysql -uroot -N -B wordpress -e 'SELECT COUNT(*) FROM \`${table}\`;'" 2>/dev/null)

    if [[ -z "$got" ]]; then
      printf '  \033[31m✘\033[0m %-40s absent from the restore\n' "$table"
      missing=$((missing + 1))
      continue
    fi

    checked=$((checked + 1))

    if [[ "$got" == "$expected" ]]; then
      printf '  \033[32m✔\033[0m %-40s %s\n' "$table" "$got"
    else
      printf '  \033[31m✘\033[0m %-40s %s (manifest says %s)\n' "$table" "$got" "$expected"
      mismatch=$((mismatch + 1))
    fi
  done < <(sed -n '/^row counts/,$p' "${DIR}/manifest.txt" | sed '1d' | awk 'NF == 2 {print $1, $2}')

  printf '\n'
  if [[ $checked -eq 0 ]]; then
    # The failure this repository has already been bitten by once: a check that
    # matched nothing reports success in exactly the same way as a clean run.
    die "no table was compared — the manifest has no row counts, so nothing was proved"
  fi

  if [[ $mismatch -eq 0 && $missing -eq 0 ]]; then
    printf '\033[32m✔ restore verified — %d tables, every row count matches\033[0m\n' "$checked"
    printf '  the throwaway container has been removed; this stack was not touched\n'
    exit 0
  fi

  die "${mismatch} table(s) disagree and ${missing} are missing — this backup is not a backup"
fi

# ==============================================================================
# restore — over the running stack. Destructive.
# ==============================================================================
if ! docker compose ps --format '{{.Service}} {{.State}}' 2>/dev/null | grep -q '^db running'; then
  die "the db container is not running — start it with: docker compose up -d"
fi

printf '\033[31m\033[1mThis will DROP the wordpress database and restore %s over it.\033[0m\n' "$DIR"
printf 'Everything written since that backup will be gone. Uploads will be overwritten.\n'
[[ $WITH_ENV -eq 1 ]] && printf '  --with-env: .env will be overwritten with the backup'"'"'s copy.\n'
[[ $WITH_PLUGIN -eq 1 ]] && printf '  --with-plugin: the plugin directory will be overwritten.\n'
printf '\n'

if [[ $ASSUME_YES -ne 1 ]]; then
  if [[ ! -t 0 ]]; then
    die "refusing to restore without a terminal to confirm at — pass --yes if you mean it"
  fi
  printf 'Type RESTORE to continue: '
  read -r answer
  [[ "$answer" == "RESTORE" ]] || die "not confirmed; nothing was changed"
fi

step "recreating the database"
# Root, because DROP DATABASE is not the `wordpress` user's to make. Both
# passwords are read from the container's own environment rather than passed in.
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS \`${MYSQL_DATABASE}\`; CREATE DATABASE \`${MYSQL_DATABASE}\` DEFAULT CHARACTER SET utf8mb4;"' \
  || die "could not recreate the database"

step "restoring the dump"
gzip -dc "$DUMP" | docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
  || die "the restore failed — the database is now empty; fix the dump and run this again"

if [[ -f "${DIR}/uploads.tar.gz" ]]; then
  step "restoring wp-content/uploads"
  TMP=$(mktemp -d)
  tar -xzf "${DIR}/uploads.tar.gz" -C "$TMP"
  docker compose cp "${TMP}/uploads/." wordpress:/var/www/html/wp-content/uploads >/dev/null \
    || printf '   \033[33mcould not copy uploads into the volume\033[0m\n'
  # docker cp writes as root. The two images disagree about who www-data is
  # (uid 33 here, 82 in the cli image), which is why the uid is spelled out
  # rather than the name — see compose.yaml.
  docker compose exec -T -u 0 wordpress chown -R 33:33 /var/www/html/wp-content/uploads
  rm -rf "$TMP"
elif [[ -f "${DIR}/uploads.absent" ]]; then
  printf '   the backup recorded no uploads directory; leaving this one alone\n'
fi

if [[ $WITH_ENV -eq 1 && -f "${DIR}/env" ]]; then
  step "restoring .env"
  cp .env ".env.before-restore-$(date +%F-%H%M%S)" 2>/dev/null || true
  cp "${DIR}/env" .env
  chmod 600 .env
  printf '   the previous .env was kept as .env.before-restore-*\n'
  printf '   \033[33mrestart the stack so the containers read it: docker compose up -d\033[0m\n'
fi

if [[ $WITH_PLUGIN -eq 1 && -f "${DIR}/plugin.tar.gz" ]]; then
  step "restoring the plugin"
  tar -xzf "${DIR}/plugin.tar.gz" -C wp-content/plugins
  printf '   vendor/ is not in the archive — run composer install if the plugin needs it\n'
fi

step "checking the result"
bash scripts/health.sh || printf '\n\033[33mhealth check failed after the restore — read the output above\033[0m\n'

printf '\n\033[32m✔ restored from %s\033[0m\n' "$DIR"
