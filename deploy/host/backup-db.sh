#!/usr/bin/env bash
# Nightly backup, installed as a cron job by host/install.sh: a Postgres dump
# and the uploaded files (the storage-app volume), the application's state.
# Keeps 14 days in /opt/tx-client/deploy/backups, and copies each night's
# files off this server when deploy/backup.env names an rclone remote:
#   BACKUP_REMOTE=b2:txclient-backups   # any remote set up with `rclone config`
#   BACKUP_REMOTE_KEEP_DAYS=90          # optional; default 90
#
# Restore the database with:
#   gunzip -c backups/<file>.sql.gz | docker compose exec -T db psql -U client_portal client_portal
# and the files with:
#   gunzip -c backups/<file>.tar.gz | docker compose exec -T app tar xf - -C /var/www/html/storage
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p backups
chmod 700 backups

stamp="$(date -u +%Y%m%d-%H%M%S)"
database="backups/client_portal-$stamp.sql.gz"
files="backups/storage-app-$stamp.tar.gz"

docker compose -f docker-compose.yml exec -T db \
    sh -c 'pg_dump --clean --if-exists -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$database.tmp"
mv "$database.tmp" "$database"

docker compose -f docker-compose.yml exec -T app \
    tar cf - -C /var/www/html/storage app | gzip > "$files.tmp"
mv "$files.tmp" "$files"

find backups \( -name 'client_portal-*.sql.gz' -o -name 'storage-app-*.tar.gz' \) -mtime +14 -delete

if [ -f backup.env ]; then
    # shellcheck disable=SC1091
    . ./backup.env
fi

if [ -z "${BACKUP_REMOTE:-}" ]; then
    echo "$(date -u +%FT%TZ) WARNING: no BACKUP_REMOTE in deploy/backup.env; backups are only on this server."
    exit 0
fi

rclone copy "$database" "$BACKUP_REMOTE"
rclone copy "$files" "$BACKUP_REMOTE"
rclone delete --min-age "${BACKUP_REMOTE_KEEP_DAYS:-90}d" \
    --include 'client_portal-*.sql.gz' --include 'storage-app-*.tar.gz' "$BACKUP_REMOTE"

echo "$(date -u +%FT%TZ) Backed up $database and $files to $BACKUP_REMOTE."
