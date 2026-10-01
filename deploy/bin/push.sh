#!/usr/bin/env bash
# Ship tx-client to its VPS and roll it out with zero downtime.
#
# Runs on a workstation, from anywhere:
#
#   1. rsync this repository's WORKING TREE to /opt/tx-client on the VPS --
#      uncommitted changes included, by design: the VPS is deployed straight
#      from this machine, without a commit
#   2. run deploy/bin/deploy.sh there (build, migrate, roll app + web)
#
# On a FRESH host, run deploy/host/install.sh there once, and create
# deploy/.env and deploy/.env.docker (see env.example, env.docker.example).
#
# Preview what would ship without deploying, or ship without deploying:
#   DRY_RUN=1 deploy/bin/push.sh
#   SYNC_ONLY=1 deploy/bin/push.sh
#
# Usage:
#   deploy/bin/push.sh
#   VPS=root@host deploy/bin/push.sh
set -euo pipefail

APP_SRC="$(cd "$(dirname "$0")/../.." && pwd)"
VPS="${VPS:-root@45.90.109.176}"
REMOTE_DIR="${REMOTE_DIR:-/opt/tx-client}"

log() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

rsync_flags=(-az --delete)
[[ -n "${DRY_RUN:-}" ]] && rsync_flags+=(-n --itemize-changes --checksum)

log "Syncing app from $APP_SRC ($(git -C "$APP_SRC" rev-parse --abbrev-ref HEAD) @ $(git -C "$APP_SRC" rev-parse --short HEAD), $(git -C "$APP_SRC" status --porcelain | wc -l | tr -d ' ') uncommitted changes)"
# --delete removes files deleted from the app. Excluded paths are never
# deleted on the server, which is what protects the env files and anything
# the build or the containers produce.
rsync "${rsync_flags[@]}" \
    --exclude '/.git/' \
    --exclude '/.claude/' \
    --exclude '/.agents/' \
    --exclude '/.codex/' \
    --exclude '/node_modules/' \
    --exclude '/vendor/' \
    --exclude '/public/build/' \
    --exclude '/public/hot' \
    --exclude '/public/storage' \
    --exclude '/bootstrap/ssr/' \
    --exclude '/storage/app/' \
    --exclude '/storage/logs/' \
    --exclude '/storage/framework/' \
    --exclude '/storage/pail/' \
    --exclude '/storage/inertia-devtools/' \
    --exclude '/resources/js/actions/' \
    --exclude '/resources/js/routes/' \
    --exclude '/resources/js/wayfinder/' \
    --exclude '/bootstrap/cache/*.php' \
    --exclude '/graphify-out/' \
    --exclude '/.env' \
    --exclude '/.env.*' \
    --exclude '/deploy/.env' \
    --exclude '/deploy/.env.docker' \
    --exclude '/deploy/backups/' \
    --exclude '/.phpunit.cache/' \
    --exclude '/.phpunit.result.cache' \
    --exclude '/database/database.sqlite' \
    --exclude '*.log' \
    --exclude '/*.sql' \
    --exclude '.DS_Store' \
    "$APP_SRC/" "$VPS:$REMOTE_DIR/"

if [[ -n "${DRY_RUN:-}" || -n "${SYNC_ONLY:-}" ]]; then
    log "Not deploying (DRY_RUN or SYNC_ONLY set)"
    exit 0
fi

log "Deploying on the VPS"
ssh "$VPS" "cd '$REMOTE_DIR/deploy' && ./bin/deploy.sh"
