#!/usr/bin/env bash
# Nightly Postgres dump, installed as a cron job by host/install.sh.
# Keeps 14 days in /opt/tx-client/deploy/backups. Restore with:
#   gunzip -c backups/<file>.sql.gz | docker compose exec -T db psql -U client_portal client_portal
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p backups
chmod 700 backups

file="backups/client_portal-$(date -u +%Y%m%d-%H%M%S).sql.gz"
docker compose -f docker-compose.yml exec -T db \
    sh -c 'pg_dump --clean --if-exists -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$file.tmp"
mv "$file.tmp" "$file"

find backups -name 'client_portal-*.sql.gz' -mtime +14 -delete
