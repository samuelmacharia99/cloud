#!/usr/bin/env bash
# Idempotent bootstrap for Talksasa container nodes (Ubuntu 22.04/24.04)
# Run as: sudo bash scripts/bootstrap-container-node.sh

set -euo pipefail

log() { printf '[INFO] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*"; }
ok() { printf '[OK] %s\n' "$*"; }

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo bash scripts/bootstrap-container-node.sh"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

log "Updating apt index and base packages"
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release jq ufw fail2ban chrony

if ! command -v docker >/dev/null 2>&1; then
  log "Installing Docker Engine + Compose plugin"
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
    $(. /etc/os-release && echo "${VERSION_CODENAME}") stable" > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
  ok "Docker already installed"
fi

log "Ensuring Docker service is enabled"
systemctl enable --now docker

log "Configuring Docker daemon defaults"
install -d -m 0755 /etc/docker
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "storage-driver": "overlay2",
  "live-restore": true,
  "userland-proxy": false,
  "max-concurrent-downloads": 10,
  "max-concurrent-uploads": 10,
  "metrics-addr": "127.0.0.1:9323"
}
EOF
systemctl restart docker

log "Installing Nginx + Certbot for domain binding/SSL"
apt-get install -y nginx certbot python3-certbot-nginx
systemctl enable --now nginx

log "Preparing Talksasa container directories"
install -d -m 0755 /opt/talksasa/containers

log "Applying host sysctl tuning for container networking"
cat > /etc/sysctl.d/99-talksasa-container.conf <<'EOF'
net.ipv4.ip_forward=1
net.core.somaxconn=32768
vm.swappiness=10
EOF
sysctl --system >/dev/null

log "Applying firewall rules (safe repeatable)"
ufw --force enable >/dev/null 2>&1 || true
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 30000:40000/tcp >/dev/null
ufw allow 30000:40000/udp >/dev/null

log "Ensuring Fail2ban service is enabled"
systemctl enable --now fail2ban

ok "Bootstrap complete"
echo
echo "Verification commands:"
echo "  docker --version && docker compose version"
echo "  nginx -t"
echo "  systemctl is-active docker nginx fail2ban"
echo "  ls -ld /opt/talksasa/containers"
