#!/usr/bin/env bash
# Talksasa Mailcow node installer (Debian/Ubuntu)
#
# Automates Phases A–C from docs/MAILCOW_SETUP.md on a *dedicated* mail VPS.
# Phases D–H (admin password, API key UI, Talksasa node) stay manual.
#
# Usage (on the mail VPS as root):
#   sudo bash scripts/setup-mailcow-node.sh check
#   sudo bash scripts/setup-mailcow-node.sh all
#   # Override if needed: export MAIL_HOST=mail.other.com MAIL_IP=x.x.x.x
#
# Commands:
#   check      Phase A + sizing + port sanity (no changes)
#   bootstrap  Phase B + Docker (hostname, packages, UFW, Docker)
#   install    Phase C (clone mailcow, generate config, pull, up)
#   status     docker compose ps + HTTPS probe
#   api-test   curl Mailcow API (needs MAILCOW_API_KEY)
#   all        check → bootstrap → install (stops if DNS/PTR fail unless --force)
#
# Flags:
#   --force    Skip DNS/PTR / RAM hard stops (lab only — not for production)
#   --yes      Non-interactive confirmations
#
# See: docs/MAILCOW_SETUP.md

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

MAILCOW_DIR="${MAILCOW_DIR:-/opt/mailcow-dockerized}"
MAILCOW_REPO="${MAILCOW_REPO:-https://github.com/mailcow/mailcow-dockerized.git}"
MIN_RAM_MB="${MIN_RAM_MB:-7000}"
# Talksasa production mail hostname (override with MAIL_HOST=... if needed)
MAIL_HOST="${MAIL_HOST:-mail.talksasa.com}"

FORCE=0
ASSUME_YES=0
COMMAND=""

log() { printf '%b[INFO]%b %s\n' "$BLUE" "$NC" "$*"; }
ok() { printf '%b[OK]%b %s\n' "$GREEN" "$NC" "$*"; }
warn() { printf '%b[WARN]%b %s\n' "$YELLOW" "$NC" "$*"; }
err() { printf '%b[ERROR]%b %s\n' "$RED" "$NC" "$*" >&2; }

die() { err "$*"; exit 1; }

usage() {
  sed -n '2,35p' "$0" | sed 's/^# \?//'
  exit "${1:-0}"
}

confirm() {
  local prompt=$1
  if [[ "$ASSUME_YES" -eq 1 ]]; then
    return 0
  fi
  read -r -p "$prompt [y/N] " reply
  [[ "$reply" =~ ^[Yy]$ ]]
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    die "Run as root: sudo -E bash $0 $COMMAND"
  fi
}

detect_public_ip() {
  local ip=""
  ip=$(curl -4 -fsS --max-time 5 https://ifconfig.me 2>/dev/null || true)
  if [[ -z "$ip" ]]; then
    ip=$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)
  fi
  if [[ -z "$ip" ]]; then
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
  fi
  printf '%s' "$ip"
}

resolve_a() {
  local host=$1
  if command -v dig >/dev/null 2>&1; then
    dig +short A "$host" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1
  elif command -v getent >/dev/null 2>&1; then
    getent ahostsv4 "$host" | awk '{print $1; exit}'
  else
    python3 - <<PY 2>/dev/null || true
import socket
print(socket.gethostbyname("$host"))
PY
  fi
}

resolve_ptr() {
  local ip=$1
  if command -v dig >/dev/null 2>&1; then
    dig +short -x "$ip" | sed 's/\.$//' | head -1
  else
    python3 - <<PY 2>/dev/null || true
import socket
try:
    print(socket.gethostbyaddr("$ip")[0].rstrip("."))
except Exception:
    pass
PY
  fi
}

ram_mb() {
  awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      -h|--help) usage 0 ;;
      --force) FORCE=1; shift ;;
      --yes|-y) ASSUME_YES=1; shift ;;
      check|bootstrap|install|status|api-test|all)
        COMMAND=$1
        shift
        ;;
      *)
        die "Unknown argument: $1 (try --help)"
        ;;
    esac
  done
  [[ -n "$COMMAND" ]] || usage 1
}

ensure_mail_host() {
  MAIL_HOST="${MAIL_HOST:-mail.talksasa.com}"
  if [[ -z "$MAIL_HOST" ]]; then
    die "Set MAIL_HOST first, e.g. export MAIL_HOST=mail.talksasa.com"
  fi
  if [[ ! "$MAIL_HOST" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ ]]; then
    die "MAIL_HOST looks invalid: $MAIL_HOST"
  fi
  log "Using MAIL_HOST=$MAIL_HOST"
}

ensure_mail_ip() {
  MAIL_IP="${MAIL_IP:-}"
  if [[ -z "$MAIL_IP" ]]; then
    MAIL_IP=$(detect_public_ip)
    log "Detected MAIL_IP=$MAIL_IP (override with export MAIL_IP=...)"
  fi
  [[ -n "$MAIL_IP" ]] || die "Could not detect MAIL_IP; export MAIL_IP=x.x.x.x"
}

cmd_check() {
  ensure_mail_host
  ensure_mail_ip

  local failures=0
  local a_record ptr_record mem

  log "=== Phase A check: DNS / PTR / sizing ==="
  log "MAIL_HOST=$MAIL_HOST  MAIL_IP=$MAIL_IP"

  mem=$(ram_mb)
  if (( mem < MIN_RAM_MB )); then
    warn "RAM ${mem} MB is below recommended ~8 GB (MIN_RAM_MB=$MIN_RAM_MB)"
    failures=$((failures + 1))
  else
    ok "RAM ${mem} MB"
  fi

  if ! command -v dig >/dev/null 2>&1; then
    warn "dig not installed yet; using fallback resolvers (install dnsutils in bootstrap)"
  fi

  a_record=$(resolve_a "$MAIL_HOST" || true)
  if [[ -z "$a_record" ]]; then
    err "No A record for $MAIL_HOST"
    failures=$((failures + 1))
  elif [[ "$a_record" != "$MAIL_IP" ]]; then
    err "A record mismatch: $MAIL_HOST → $a_record (expected $MAIL_IP)"
    failures=$((failures + 1))
  else
    ok "A record $MAIL_HOST → $a_record"
  fi

  ptr_record=$(resolve_ptr "$MAIL_IP" || true)
  if [[ -z "$ptr_record" ]]; then
    err "No PTR for $MAIL_IP — set reverse DNS at the provider to $MAIL_HOST"
    failures=$((failures + 1))
  elif [[ "${ptr_record,,}" != "${MAIL_HOST,,}" ]]; then
    err "PTR mismatch: $MAIL_IP → $ptr_record (expected $MAIL_HOST)"
    failures=$((failures + 1))
  else
    ok "PTR $MAIL_IP → $ptr_record"
  fi

  if ss -lnt 2>/dev/null | grep -qE ':25\s'; then
    warn "Something already listens on :25 — free the port before Mailcow"
    failures=$((failures + 1))
  else
    ok "Port 25 appears free"
  fi

  for p in 80 443; do
    if ss -lnt 2>/dev/null | grep -qE ":${p}\\s"; then
      warn "Port $p is in use — Mailcow needs it (or use a reverse proxy carefully)"
    fi
  done

  if [[ "$failures" -gt 0 ]]; then
    err "Check failed ($failures issue(s)). Fix DNS/PTR/RAM before install."
    if [[ "$FORCE" -eq 1 ]]; then
      warn "--force set: continuing anyway (lab only)"
      return 0
    fi
    return 1
  fi

  ok "Phase A checkpoint passed"
}

cmd_bootstrap() {
  require_root
  ensure_mail_host
  export DEBIAN_FRONTEND=noninteractive

  log "=== Phase B: hostname, packages, UFW, Docker ==="

  if [[ -f /etc/os-release ]]; then
    # shellcheck source=/dev/null
    . /etc/os-release
    case "${ID:-}" in
      debian|ubuntu) ;;
      *)
        warn "Untested distro: ${ID:-unknown}. Script targets Debian/Ubuntu."
        confirm "Continue anyway?" || die "Aborted"
        ;;
    esac
  fi

  log "Setting hostname to $MAIL_HOST"
  hostnamectl set-hostname "$MAIL_HOST"
  if grep -qE '^10\.0\.0\.1|^127\.0\.1\.1' /etc/hosts 2>/dev/null; then
    sed -i -E "s/^(10\.0\.0\.1|127\.0\.1\.1)[[:space:]].*/127.0.1.1\t${MAIL_HOST}/" /etc/hosts || true
  elif ! grep -q "$MAIL_HOST" /etc/hosts; then
    echo "127.0.1.1 ${MAIL_HOST}" >> /etc/hosts
  fi
  ok "Hostname: $(hostname -f 2>/dev/null || hostname)"

  log "Installing base packages"
  apt-get update -y
  apt-get install -y \
    ca-certificates curl gnupg lsb-release \
    git openssl gawk coreutils grep jq \
    ufw fail2ban chrony dnsutils net-tools

  log "Configuring UFW (SSH + mail ports)"
  ufw --force reset >/dev/null 2>&1 || true
  ufw default deny incoming
  ufw default allow outgoing
  ufw allow OpenSSH
  ufw allow 80/tcp
  ufw allow 443/tcp
  ufw allow 25/tcp
  ufw allow 465/tcp
  ufw allow 587/tcp
  ufw allow 993/tcp
  ufw --force enable
  ok "UFW enabled"
  ufw status numbered || true

  if ! command -v docker >/dev/null 2>&1; then
    log "Installing Docker Engine + Compose plugin"
    curl -fsSL https://get.docker.com | CHANNEL=stable sh
    apt-get install -y docker-compose-plugin
  else
    ok "Docker already installed"
    apt-get install -y docker-compose-plugin || true
  fi

  systemctl enable --now docker
  docker --version
  docker compose version
  ok "Phase B checkpoint passed"
}

generate_mailcow_config() {
  local tz
  tz=$(timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || echo UTC)

  if [[ -f "${MAILCOW_DIR}/mailcow.conf" ]]; then
    ok "mailcow.conf already exists — skipping generate_config.sh"
    # Ensure hostname matches if we can
    if grep -q '^MAILCOW_HOSTNAME=' "${MAILCOW_DIR}/mailcow.conf"; then
      local existing
      existing=$(grep '^MAILCOW_HOSTNAME=' "${MAILCOW_DIR}/mailcow.conf" | cut -d= -f2- | tr -d '"')
      if [[ "$existing" != "$MAIL_HOST" ]]; then
        warn "mailcow.conf hostname is '$existing' but MAIL_HOST=$MAIL_HOST"
        if confirm "Update MAILCOW_HOSTNAME in mailcow.conf to $MAIL_HOST?"; then
          sed -i "s/^MAILCOW_HOSTNAME=.*/MAILCOW_HOSTNAME=${MAIL_HOST}/" "${MAILCOW_DIR}/mailcow.conf"
          ok "Updated MAILCOW_HOSTNAME=$MAIL_HOST"
        fi
      fi
    fi
    return 0
  fi

  log "Running generate_config.sh (hostname=$MAIL_HOST, tz=$tz)"
  # Prompt order (upstream): hostname → timezone confirm/enter → branch
  # Using "y" accepts detected timezone; empty branch keeps default (master).
  if ! printf '%s\n%s\n%s\n' "$MAIL_HOST" "y" "" | ./generate_config.sh; then
    die "generate_config.sh failed. Run it interactively: cd ${MAILCOW_DIR} && ./generate_config.sh"
  fi

  [[ -f "${MAILCOW_DIR}/mailcow.conf" ]] || die "mailcow.conf was not created"

  # Force hostname in case prompts drifted across versions
  if grep -q '^MAILCOW_HOSTNAME=' mailcow.conf; then
    sed -i "s/^MAILCOW_HOSTNAME=.*/MAILCOW_HOSTNAME=${MAIL_HOST}/" mailcow.conf
  fi
  ok "mailcow.conf ready"
}

cmd_install() {
  require_root
  ensure_mail_host

  log "=== Phase C: install Mailcow into ${MAILCOW_DIR} ==="

  if [[ ! -d "$MAILCOW_DIR/.git" ]]; then
    if [[ -e "$MAILCOW_DIR" ]] && [[ ! -d "$MAILCOW_DIR" ]]; then
      die "$MAILCOW_DIR exists and is not a directory"
    fi
    if [[ -d "$MAILCOW_DIR" ]] && [[ -n "$(ls -A "$MAILCOW_DIR" 2>/dev/null || true)" ]]; then
      die "$MAILCOW_DIR is not empty and is not a mailcow git clone"
    fi
    umask 0022
    mkdir -p "$(dirname "$MAILCOW_DIR")"
    log "Cloning $MAILCOW_REPO"
    git clone "$MAILCOW_REPO" "$MAILCOW_DIR"
  else
    ok "Mailcow repo already present"
  fi

  cd "$MAILCOW_DIR"
  generate_mailcow_config

  log "Pulling images (this takes a while)"
  docker compose pull

  log "Starting Mailcow"
  docker compose up -d

  log "Waiting 30s for containers to settle..."
  sleep 30
  docker compose ps

  ok "Phase C started"
  echo
  warn "NEXT (manual — do not skip):"
  echo "  1. Open https://${MAIL_HOST}/admin  (default admin / moohoo — change NOW)"
  echo "  2. Configuration → Access → API: create Read-Write key"
  echo "  3. Allowlist Talksasa app server IP (APP_IP)"
  echo "  4. From the app server:  MAILCOW_API_KEY=... bash $0 api-test"
  echo "  5. Admin → Nodes → Add Mailcow node in Talksasa (see docs/MAILCOW_SETUP.md)"
}

cmd_status() {
  ensure_mail_host
  if [[ ! -d "$MAILCOW_DIR" ]]; then
    die "Mailcow not found at $MAILCOW_DIR"
  fi
  cd "$MAILCOW_DIR"
  docker compose ps
  echo
  log "Probing https://${MAIL_HOST}/"
  if curl -fsS -o /dev/null -w "HTTP %{http_code}\n" --max-time 15 "https://${MAIL_HOST}/" || true; then
    :
  fi
  log "Admin UI: https://${MAIL_HOST}/admin"
  log "Webmail:  https://${MAIL_HOST}/SOGo/"
}

cmd_api_test() {
  ensure_mail_host
  local key="${MAILCOW_API_KEY:-}"
  if [[ -z "$key" ]]; then
    die "export MAILCOW_API_KEY=your-read-write-key"
  fi
  log "GET https://${MAIL_HOST}/api/v1/get/status/version"
  curl -sS -H "X-API-Key: ${key}" \
    "https://${MAIL_HOST}/api/v1/get/status/version"
  echo
  ok "If you see JSON with a version, Phase E API path works from this host"
  warn "Talksasa needs this same call to succeed from the *app server* IP allowlisted in Mailcow"
}

cmd_all() {
  log "Running check → bootstrap → install"
  cmd_check
  cmd_bootstrap
  if ! confirm "Proceed to pull/start Mailcow?"; then
    die "Stopped before install"
  fi
  cmd_install
}

parse_args "$@"

case "$COMMAND" in
  check) cmd_check ;;
  bootstrap) cmd_bootstrap ;;
  install) cmd_install ;;
  status) cmd_status ;;
  api-test) cmd_api_test ;;
  all) cmd_all ;;
  *) usage 1 ;;
esac
