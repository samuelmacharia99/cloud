#!/usr/bin/env bash
#
# One-time server setup so the Talksasa "Provision SSL" button works (www-data → sudo).
#
# Usage (on production as root):
#   sudo bash /var/www/talksasa-cloud/scripts/reseller-ssl/install-host.sh
#   sudo bash install-host.sh /var/www/talksasa-cloud
#
set -euo pipefail

APP_ROOT="${1:-/var/www/talksasa-cloud}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROVISION_SCRIPT="${SCRIPT_DIR}/provision.sh"
PATCH_PY="${SCRIPT_DIR}/apache_patch.py"
SUDOERS_FILE="/etc/sudoers.d/talksasa-reseller-ssl"
WEB_USER="${WEB_USER:-www-data}"

log() { echo "[talksasa-ssl-install] $*"; }
die() { echo "[talksasa-ssl-install] ERROR: $*" >&2; exit 1; }

if [[ $EUID -ne 0 ]]; then
    die "Run as root: sudo bash $0 [$APP_ROOT]"
fi

if [[ ! -d "$APP_ROOT" ]]; then
    die "App root not found: $APP_ROOT"
fi

if [[ ! -f "$PROVISION_SCRIPT" ]]; then
    die "provision.sh not found at $PROVISION_SCRIPT"
fi

log "Installing packages (certbot, apache tools)..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq certbot python3 apache2 curl openssl

log "Enabling Apache modules..."
a2enmod rewrite ssl headers 2>/dev/null || true

chmod +x "$PROVISION_SCRIPT"
chmod +x "$PATCH_PY" 2>/dev/null || true
chmod 755 "$SCRIPT_DIR"

REAL_PROVISION="$(readlink -f "$PROVISION_SCRIPT")"
REAL_CERTBOT="$(readlink -f "$(command -v certbot)")"
REAL_OPENSSL="$(readlink -f "$(command -v openssl)")"
REAL_TEST="$(readlink -f "$(command -v test)")"

log "Writing sudoers: $SUDOERS_FILE"
cat >"$SUDOERS_FILE" <<EOF
# Talksasa reseller branding — Provision SSL (managed by install-host.sh)
Defaults:${WEB_USER} !requiretty
${WEB_USER} ALL=(root) NOPASSWD: ${REAL_PROVISION}
${WEB_USER} ALL=(root) NOPASSWD: ${REAL_CERTBOT} *
${WEB_USER} ALL=(root) NOPASSWD: ${REAL_OPENSSL}
${WEB_USER} ALL=(root) NOPASSWD: ${REAL_TEST}
EOF

chmod 0440 "$SUDOERS_FILE"

if ! visudo -cf "$SUDOERS_FILE"; then
    die "sudoers validation failed — remove $SUDOERS_FILE and fix permissions (must be 0440)"
fi

log "Verifying sudo for ${WEB_USER}..."
if sudo -u "$WEB_USER" sudo -n "$REAL_PROVISION" --help >/dev/null 2>&1; then
    log "sudo -n provision.sh --help OK"
else
    die "sudo test failed for ${WEB_USER}. Check $SUDOERS_FILE"
fi

ENV_FILE="${APP_ROOT}/.env"
if [[ -f "$ENV_FILE" ]]; then
    if grep -q '^RESELLER_SSL_CERTBOT_SUDO=' "$ENV_FILE"; then
        sed -i 's/^RESELLER_SSL_CERTBOT_SUDO=.*/RESELLER_SSL_CERTBOT_SUDO=true/' "$ENV_FILE"
    else
        echo 'RESELLER_SSL_CERTBOT_SUDO=true' >>"$ENV_FILE"
    fi
    if grep -q '^RESELLER_SSL_USE_PROVISION_SCRIPT=' "$ENV_FILE"; then
        sed -i 's|^RESELLER_SSL_USE_PROVISION_SCRIPT=.*|RESELLER_SSL_USE_PROVISION_SCRIPT=true|' "$ENV_FILE"
    else
        echo 'RESELLER_SSL_USE_PROVISION_SCRIPT=true' >>"$ENV_FILE"
    fi
    if grep -q '^RESELLER_SSL_PROVISION_SCRIPT=' "$ENV_FILE"; then
        sed -i "s|^RESELLER_SSL_PROVISION_SCRIPT=.*|RESELLER_SSL_PROVISION_SCRIPT=${REAL_PROVISION}|" "$ENV_FILE"
    else
        echo "RESELLER_SSL_PROVISION_SCRIPT=${REAL_PROVISION}" >>"$ENV_FILE"
    fi
    log "Updated ${ENV_FILE} (RESELLER_SSL_* variables)"
    if [[ -f "${APP_ROOT}/artisan" ]]; then
        (cd "$APP_ROOT" && php artisan config:clear && php artisan cache:clear) || true
        log "If you use config:cache in production, run: cd ${APP_ROOT} && php artisan config:cache"
    fi
else
    log "No .env at ${ENV_FILE} — add manually:"
    echo "  RESELLER_SSL_CERTBOT_SUDO=true"
    echo "  RESELLER_SSL_USE_PROVISION_SCRIPT=true"
    echo "  RESELLER_SSL_PROVISION_SCRIPT=${REAL_PROVISION}"
fi

cat <<EOF

Install complete.

Next steps:
  1. Ensure reseller custom domain DNS A record points to this server.
  2. In the reseller portal → Settings → Branding → click **Provision SSL**.
  3. Or test from shell:
       sudo ${REAL_PROVISION} \\
         --domain server.example.com \\
         --webroot ${APP_ROOT}/public \\
         --email info@yourcompany.com \\
         --logs-dir ${APP_ROOT}/storage/app/ssl-provisioning/test/logs

Logs: /var/log/letsencrypt/letsencrypt.log and Laravel storage/logs/laravel.log

EOF
