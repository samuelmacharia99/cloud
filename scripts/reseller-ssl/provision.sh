#!/usr/bin/env bash
#
# Issue or renew a Let's Encrypt certificate for a reseller custom domain.
# Fixes Apache port 80 ACME access, runs certbot, enables HTTPS vhost, reloads Apache.
#
# Run as root, or via sudo from www-data (see install-host.sh).
#
# Usage:
#   provision.sh --domain example.com --webroot /var/www/app/public \
#     --email admin@example.com --logs-dir /path/to/logs
#   provision.sh --domain example.com --webroot ... --renew
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PATCH_PY="${SCRIPT_DIR}/apache_patch.py"
CERTBOT="${CERTBOT_BIN:-/usr/bin/certbot}"
APACHE_CTL="${APACHE_CTL:-apache2ctl}"
APACHE_SITES_AVAILABLE="${APACHE_SITES_AVAILABLE:-/etc/apache2/sites-available}"
APACHE_SITES_ENABLED="${APACHE_SITES_ENABLED:-/etc/apache2/sites-enabled}"
WEB_USER="${WEB_USER:-www-data}"

DOMAIN=""
WEBROOT=""
EMAIL=""
LOGS_DIR=""
RENEW_ONLY=0
SKIP_APACHE=0

log() { echo "[talksasa-ssl] $*"; }
die() { echo "[talksasa-ssl] ERROR: $*" >&2; exit 1; }

usage() {
    cat <<'EOF'
Usage: provision.sh --domain DOMAIN --webroot PATH [options]

Required:
  --domain DOMAIN       FQDN for the certificate (reseller custom domain)
  --webroot PATH        Laravel public/ directory (ACME + DocumentRoot)

Optional:
  --email EMAIL         Let's Encrypt registration email
  --logs-dir PATH       Writable certbot logs directory
  --renew               Renew existing cert only (certbot renew --cert-name)
  --skip-apache         Do not patch/configure Apache (certbot only)
  --help

Environment:
  CERTBOT_BIN, APACHE_CTL, WEB_USER
EOF
    exit 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --webroot) WEBROOT="${2:-}"; shift 2 ;;
        --email) EMAIL="${2:-}"; shift 2 ;;
        --logs-dir) LOGS_DIR="${2:-}"; shift 2 ;;
        --renew) RENEW_ONLY=1; shift ;;
        --skip-apache) SKIP_APACHE=1; shift ;;
        --help|-h) usage ;;
        *) die "Unknown argument: $1 (use --help)" ;;
    esac
done

[[ -n "$DOMAIN" ]] || die "--domain is required"
[[ -n "$WEBROOT" ]] || die "--webroot is required"
[[ "$DOMAIN" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ ]] || die "Invalid domain: $DOMAIN"

WEBROOT="$(cd "$WEBROOT" && pwd)"
CHALLENGE_DIR="${WEBROOT}/.well-known/acme-challenge"
SAFE_NAME="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9.-]/-/g' | sed 's/\./-/g')"
SITE_NAME="talksasa-reseller-${SAFE_NAME}"
SITE_FILE="${APACHE_SITES_AVAILABLE}/${SITE_NAME}.conf"
CERT_LIVE="/etc/letsencrypt/live/${DOMAIN}"
CERT_FULL="${CERT_LIVE}/fullchain.pem"
CERT_KEY="${CERT_LIVE}/privkey.pem"

if [[ $EUID -ne 0 ]]; then
    die "Must run as root (use sudo). Current user: $(whoami)"
fi

if [[ ! -x "$CERTBOT" ]] && ! command -v certbot &>/dev/null; then
    die "certbot not found. Run: sudo bash ${SCRIPT_DIR}/install-host.sh"
fi
[[ -x "$CERTBOT" ]] || CERTBOT="$(command -v certbot)"

ensure_challenge_dir() {
    mkdir -p "$CHALLENGE_DIR"
    chown -R "${WEB_USER}:${WEB_USER}" "${WEBROOT}/.well-known" 2>/dev/null || true
    chmod 755 "${WEBROOT}/.well-known" "$CHALLENGE_DIR" 2>/dev/null || true
    log "ACME challenge directory: $CHALLENGE_DIR"
}

find_vhost_configs_for_domain() {
    local f
    for f in "${APACHE_SITES_ENABLED}"/* "${APACHE_SITES_AVAILABLE}"/*; do
        [[ -f "$f" ]] || continue
        [[ "$f" == *"${SITE_NAME}.conf" ]] && continue
        if grep -qiE "^\s*ServerName\s+${DOMAIN}\s*$" "$f" 2>/dev/null \
            || grep -qiE "ServerAlias\s+.*\b${DOMAIN}\b" "$f" 2>/dev/null; then
            echo "$f"
        fi
    done | sort -u
}

patch_existing_apache_configs() {
    [[ $SKIP_APACHE -eq 1 ]] && return 0
    [[ -f "$PATCH_PY" ]] || die "Missing helper: $PATCH_PY"

    local configs patched=0
    configs="$(find_vhost_configs_for_domain || true)"

    if [[ -n "$configs" ]]; then
        while IFS= read -r cfg; do
            [[ -z "$cfg" ]] && continue
            real_cfg="$(readlink -f "$cfg" 2>/dev/null || echo "$cfg")"
            log "Patching Apache config for ACME: $real_cfg"
            python3 "$PATCH_PY" "$DOMAIN" "$real_cfg" || true
            patched=1
        done <<< "$configs"
    fi

    if [[ $patched -eq 0 ]]; then
        log "No existing vhost for ${DOMAIN}; creating ${SITE_FILE}"
        write_combined_vhost 0
        a2ensite "${SITE_NAME}" >/dev/null 2>&1 || true
    fi
}

write_combined_vhost() {
    local with_ssl="${1:-0}"
    local ssl_block=""

    if [[ $with_ssl -eq 1 && -f "$CERT_FULL" && -f "$CERT_KEY" ]]; then
        ssl_block=$(cat <<SSL

<VirtualHost *:443>
    ServerName ${DOMAIN}
    DocumentRoot ${WEBROOT}

    SSLEngine on
    SSLCertificateFile ${CERT_FULL}
    SSLCertificateKeyFile ${CERT_KEY}

    <Directory ${WEBROOT}>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/talksasa-reseller-${SAFE_NAME}-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/talksasa-reseller-${SAFE_NAME}-ssl-access.log combined
</VirtualHost>
SSL
)
    fi

    cat >"$SITE_FILE" <<EOF
# Managed by Talksasa reseller SSL (scripts/reseller-ssl/provision.sh)
# Domain: ${DOMAIN}

<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${WEBROOT}

    <Directory ${WEBROOT}>
        AllowOverride All
        Require all granted
    </Directory>

# BEGIN TALKSASA_ACME
    RewriteEngine On
    RewriteRule ^/\.well-known/acme-challenge/ - [L]
# END TALKSASA_ACME
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

    ErrorLog \${APACHE_LOG_DIR}/talksasa-reseller-${SAFE_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/talksasa-reseller-${SAFE_NAME}-access.log combined
</VirtualHost>
${ssl_block}
EOF
    a2ensite "${SITE_NAME}" >/dev/null 2>&1 || true
}

reload_apache() {
    [[ $SKIP_APACHE -eq 1 ]] && return 0
    if ! "$APACHE_CTL" configtest 2>&1; then
        die "Apache configtest failed — fix errors before retrying SSL provision"
    fi
    systemctl reload apache2 2>/dev/null || service apache2 reload
    log "Apache reloaded"
}

http_challenge_ok() {
    local token="talksasa-probe-$$"
    local url="http://${DOMAIN}/.well-known/acme-challenge/${token}"
    echo "probe" >"${CHALLENGE_DIR}/${token}"
    chown "${WEB_USER}:${WEB_USER}" "${CHALLENGE_DIR}/${token}" 2>/dev/null || true

    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$url" 2>/dev/null || echo "000")"
    rm -f "${CHALLENGE_DIR}/${token}"

    if [[ "$code" == "200" ]]; then
        log "HTTP ACME probe OK (200) for ${DOMAIN}"
        return 0
    fi

    log "HTTP ACME probe failed: GET ${url} => HTTP ${code} (expected 200)"
    return 1
}

run_certbot_issue() {
    local -a cmd
    cmd=(
        "$CERTBOT" certonly --webroot
        -w "$WEBROOT"
        -d "$DOMAIN"
        --non-interactive --agree-tos
        --keep-until-expiring
    )

    [[ -n "$EMAIL" ]] && cmd+=(--email "$EMAIL") || cmd+=(--register-unsafely-without-email)
    [[ -n "$LOGS_DIR" ]] && mkdir -p "$LOGS_DIR" && cmd+=(--logs-dir "$LOGS_DIR")

    log "Running: ${cmd[*]}"
    "${cmd[@]}"
}

run_certbot_renew() {
    local -a cmd
    cmd=("$CERTBOT" renew --cert-name "$DOMAIN" --non-interactive)
    [[ -n "$LOGS_DIR" ]] && mkdir -p "$LOGS_DIR" && cmd+=(--logs-dir "$LOGS_DIR")
    log "Running: ${cmd[*]}"
    "${cmd[@]}"
}

enable_ssl_vhost() {
    [[ $SKIP_APACHE -eq 1 ]] && return 0
    write_combined_vhost 1
    reload_apache
}

# --- main ---

ensure_challenge_dir

if [[ $RENEW_ONLY -eq 1 ]]; then
    run_certbot_renew
    enable_ssl_vhost
    log "SUCCESS: renewed certificate for ${DOMAIN}"
    echo "CERT_PATH=${CERT_FULL}"
    echo "KEY_PATH=${CERT_KEY}"
    exit 0
fi

if [[ $SKIP_APACHE -eq 0 ]]; then
    patch_existing_apache_configs
    reload_apache
fi

if ! http_challenge_ok; then
    die "Port 80 must return HTTP 200 for /.well-known/acme-challenge/ (no redirect to HTTPS). Apache was patched; check DNS, firewalls, and duplicate vhosts (apachectl -S)."
fi

run_certbot_issue

[[ -f "$CERT_FULL" && -f "$CERT_KEY" ]] || die "Certbot finished but certificate files missing under ${CERT_LIVE}"

enable_ssl_vhost

log "SUCCESS: certificate issued for ${DOMAIN}"
echo "CERT_PATH=${CERT_FULL}"
echo "KEY_PATH=${CERT_KEY}"
