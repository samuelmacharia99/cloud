#!/usr/bin/env bash
#
# Install supervised default and long-running backup queue workers.
#
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/talksasa-cloud}"
SERVICE_USER="${SERVICE_USER:-www-data}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
PLATFORM_CRON_WORKERS="${PLATFORM_CRON_WORKERS:-2}"
CONTAINER_CRON_WORKERS="${CONTAINER_CRON_WORKERS:-4}"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root: sudo bash scripts/install-queue-workers.sh"
  exit 1
fi

if [[ ! -f "${APP_PATH}/artisan" ]]; then
  echo "Laravel app not found at ${APP_PATH}"
  exit 1
fi

if ! [[ "${PLATFORM_CRON_WORKERS}" =~ ^[1-9][0-9]*$ && "${CONTAINER_CRON_WORKERS}" =~ ^[1-9][0-9]*$ ]]; then
  echo "Worker counts must be positive integers."
  exit 1
fi

for unit in talksasa-queue.service talksasa-platform-cron-queue@.service talksasa-container-cron-queue@.service talksasa-backup-queue.service; do
  source_unit="${APP_PATH}/deploy/${unit}"
  target_unit="/etc/systemd/system/${unit}"
  temp_unit="$(mktemp)"

  sed \
    -e "s|WorkingDirectory=.*|WorkingDirectory=${APP_PATH}|" \
    -e "s|ExecStart=/usr/bin/php|ExecStart=${PHP_BIN}|" \
    -e "s|ExecReload=/usr/bin/php|ExecReload=${PHP_BIN}|" \
    -e "s|User=.*|User=${SERVICE_USER}|" \
    -e "s|Group=.*|Group=${SERVICE_USER}|" \
    -e "s|/var/www/talksasa-cloud|${APP_PATH}|g" \
    "${source_unit}" > "${temp_unit}"

  install -m 644 "${temp_unit}" "${target_unit}"
  rm -f "${temp_unit}"
done

systemctl daemon-reload
systemctl enable --now talksasa-queue.service
systemctl enable --now talksasa-backup-queue.service

systemctl is-active --quiet talksasa-queue.service
systemctl is-active --quiet talksasa-backup-queue.service

for ((worker = 1; worker <= PLATFORM_CRON_WORKERS; worker++)); do
  systemctl enable --now "talksasa-platform-cron-queue@${worker}.service"
  systemctl is-active --quiet "talksasa-platform-cron-queue@${worker}.service"
done

for ((worker = 1; worker <= CONTAINER_CRON_WORKERS; worker++)); do
  systemctl enable --now "talksasa-container-cron-queue@${worker}.service"
  systemctl is-active --quiet "talksasa-container-cron-queue@${worker}.service"
done

echo "Installed Talksasa workers: ${PLATFORM_CRON_WORKERS} platform cron, ${CONTAINER_CRON_WORKERS} container cron, default, and backup."
