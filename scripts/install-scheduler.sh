#!/usr/bin/env bash
#
# Install systemd timer to run `php artisan schedule:run` every minute.
#
set -euo pipefail

APP_PATH="${APP_PATH:-/var/www/talksasa-cloud}"
SERVICE_USER="${SERVICE_USER:-www-data}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root: sudo bash scripts/install-scheduler.sh"
  exit 1
fi

if [[ ! -f "${APP_PATH}/artisan" ]]; then
  echo "Laravel app not found at ${APP_PATH}"
  exit 1
fi

TMP_SERVICE="$(mktemp)"
TMP_TIMER="$(mktemp)"

sed \
  -e "s|WorkingDirectory=.*|WorkingDirectory=${APP_PATH}|" \
  -e "s|ExecStart=.*|ExecStart=${PHP_BIN} artisan schedule:run|" \
  -e "s|User=.*|User=${SERVICE_USER}|" \
  -e "s|Group=.*|Group=${SERVICE_USER}|" \
  -e "s|/var/www/talksasa-cloud|${APP_PATH}|g" \
  "${APP_PATH}/deploy/talksasa-scheduler.service" > "${TMP_SERVICE}"

cp "${APP_PATH}/deploy/talksasa-scheduler.timer" "${TMP_TIMER}"

install -m 644 "${TMP_SERVICE}" /etc/systemd/system/talksasa-scheduler.service
install -m 644 "${TMP_TIMER}" /etc/systemd/system/talksasa-scheduler.timer

rm -f "${TMP_SERVICE}" "${TMP_TIMER}"

systemctl daemon-reload
systemctl enable talksasa-scheduler.timer
systemctl restart talksasa-scheduler.timer

echo "Installed talksasa-scheduler.timer"
systemctl status talksasa-scheduler.timer --no-pager || true
echo ""
echo "Ensure .env has SCHEDULER_ENABLED=true on production."
