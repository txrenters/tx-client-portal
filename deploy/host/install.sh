#!/usr/bin/env bash
# One-time host setup for tx-client's VPS. Run as root, from
# /opt/tx-client/deploy:  host/install.sh
#
# Idempotent -- safe to re-run. Docker must already be installed.
set -euo pipefail

log() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

HERE="$(cd "$(dirname "$0")" && pwd)"
SLUG=txclient

log "Installing nginx and certbot"
apt-get update
apt-get install -y nginx certbot python3-certbot-nginx

log "Installing the app.texasrenters.com vhost"
# Never overwrite a copy certbot has already added TLS to.
if ! grep -q 'ssl_certificate' /etc/nginx/sites-available/app.texasrenters.com 2>/dev/null; then
    install -m 0644 "$HERE/app.texasrenters.com.conf" /etc/nginx/sites-available/app.texasrenters.com
fi
ln -sfn /etc/nginx/sites-available/app.texasrenters.com /etc/nginx/sites-enabled/app.texasrenters.com
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx
systemctl reload nginx

log "Firewall: SSH, HTTP, HTTPS"
apt-get install -y ufw
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

log "Creating Docker volumes"
for volume in pgdata storage-app storage-logs build-history; do
    docker volume create "${SLUG}_${volume}" >/dev/null
    echo "${SLUG}_${volume}"
done

log "Nightly database backup (03:15 UTC)"
chmod +x "$HERE/backup-db.sh"
echo "15 3 * * * root $HERE/backup-db.sh >> /var/log/txclient-backup.log 2>&1" > /etc/cron.d/txclient-backup
chmod 0644 /etc/cron.d/txclient-backup

log "Done"
