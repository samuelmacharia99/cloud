#!/usr/bin/env bash
#
# Production-safe deployment script.
# - Never touches .env
# - Backs up settings before migrations
# - Only runs allowlisted seeders
# - Refuses to run on non-production APP_ENV unless DEPLOY_FORCE=1
#
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/talksasa-cloud}"
PLATFORM_CRON_WORKERS="${PLATFORM_CRON_WORKERS:-2}"
CONTAINER_CRON_WORKERS="${CONTAINER_CRON_WORKERS:-4}"
cd "$APP_PATH"

log() { echo "[deploy] $(date '+%Y-%m-%d %H:%M:%S') $*"; }
fail() { log "ERROR: $*"; exit 1; }

if [[ ! -f .env ]]; then
  fail ".env not found in ${APP_PATH}"
fi

APP_ENV_VALUE="$(grep -E '^APP_ENV=' .env | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
if [[ "${APP_ENV_VALUE}" != "production" && "${DEPLOY_FORCE:-}" != "1" ]]; then
  fail "APP_ENV is '${APP_ENV_VALUE:-unset}' — set DEPLOY_FORCE=1 to override or fix .env"
fi

BACKUP_TIME="$(date +%Y%m%d_%H%M%S)"
CODE_BACKUP="../talksasa-cloud.backup.${BACKUP_TIME}"

log "Creating code backup at ${CODE_BACKUP}"
cp -a . "${CODE_BACKUP}"

log "Fetching latest main"
git fetch origin
git checkout main
git pull origin main

log "Installing Composer dependencies (no dev)"
composer install --no-dev --no-ansi --no-interaction --no-progress --prefer-dist --optimize-autoloader

log "Backing up settings table before migrations"
php artisan settings:backup

PENDING="$(php artisan migrate:status 2>/dev/null | grep -c 'Pending' || true)"
if [[ "${PENDING}" -gt 0 ]]; then
  log "Running ${PENDING} pending migration(s)"
  php artisan migrate --force
else
  log "No pending migrations"
fi

log "Rebuilding caches"
php artisan view:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

log "Syncing allowlisted seeders (cron jobs, new settings keys, notification templates)"
php artisan db:seed --class=CronJobSeeder --force
php artisan cron:refresh-schedules
php artisan db:seed --class=SettingSeeder --force
php artisan db:seed --class=EmailTemplateSeeder --force
php artisan db:seed --class=SmsTemplateSeeder --force
php artisan db:seed --class=CurrencySeeder --force

if command -v npm >/dev/null 2>&1 && [[ -f package.json ]]; then
  log "Building frontend assets"
  npm ci --no-audit --no-fund 2>/dev/null || npm install --no-audit --no-fund
  npm run build
fi

log "Installing/updating scheduler and queue worker units"
sudo env APP_PATH="${APP_PATH}" SERVICE_USER="${DEPLOY_SERVICE_USER:-www-data}" PHP_BIN="$(command -v php)" \
  bash scripts/install-scheduler.sh
sudo env APP_PATH="${APP_PATH}" SERVICE_USER="${DEPLOY_SERVICE_USER:-www-data}" PHP_BIN="$(command -v php)" \
  PLATFORM_CRON_WORKERS="${PLATFORM_CRON_WORKERS}" CONTAINER_CRON_WORKERS="${CONTAINER_CRON_WORKERS}" \
  bash scripts/install-queue-workers.sh

log "Restarting queue workers on the new release"
php artisan queue:restart
sudo systemctl restart talksasa-queue.service
sudo systemctl restart talksasa-backup-queue.service
sudo systemctl is-active --quiet talksasa-queue.service
sudo systemctl is-active --quiet talksasa-backup-queue.service
for ((worker = 1; worker <= PLATFORM_CRON_WORKERS; worker++)); do
  sudo systemctl restart "talksasa-platform-cron-queue@${worker}.service"
  sudo systemctl is-active --quiet "talksasa-platform-cron-queue@${worker}.service"
done
for ((worker = 1; worker <= CONTAINER_CRON_WORKERS; worker++)); do
  sudo systemctl restart "talksasa-container-cron-queue@${worker}.service"
  sudo systemctl is-active --quiet "talksasa-container-cron-queue@${worker}.service"
done

log "Running one scheduler tick and verifying runtime health"
sudo systemctl start talksasa-scheduler.service
sudo systemctl is-enabled --quiet talksasa-scheduler.timer
sudo systemctl is-active --quiet talksasa-scheduler.timer
php artisan cron:verify-runtime

log "Syncing mail DNS (MX/SPF/DKIM/DMARC) for Cloudflare-managed Mailcow domains"
php artisan mailcow:sync-cloudflare-dns || log "WARN: mailcow:sync-cloudflare-dns reported failures (check output above)"

log "Deployment complete"
log "Code backup: ${CODE_BACKUP}"
log "Settings backups: storage/backups/deploy/"
