#!/bin/bash
# Talksasa Container Node Setup & Optimization Script
# For: Ubuntu 22.04 LTS with 1.8TB × 2 RAID 1, 64GB RAM
# Run as: sudo bash setup-container-node.sh

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=== Talksasa Container Node Setup ===${NC}"
echo "This script will optimize your server for container hosting"
echo ""

# Color-coded logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [[ $EUID -ne 0 ]]; then
    log_error "This script must be run as root"
    exit 1
fi

# ============================================================================
# SECTION 1: System Updates & Essential Tools
# ============================================================================

log_info "=== SECTION 1: System Updates & Tools ==="

apt update
apt upgrade -y

# Install essential tools
apt install -y \
    curl \
    wget \
    git \
    vim \
    htop \
    iotop \
    nethows \
    jq \
    net-tools \
    ufw \
    fail2ban \
    chrony \
    lm-sensors \
    sysstat \
    mdadm \
    lvm2

log_success "System updated and tools installed"

# ============================================================================
# SECTION 2: RAID Monitoring Setup
# ============================================================================

log_info "=== SECTION 2: RAID Monitoring ==="

# Check RAID status
echo "RAID Array Status:"
cat /proc/mdstat

# Create mdadm monitoring config
cat > /etc/mdadm/mdadm.conf << 'EOF'
# mdadm.conf
ARRAY /dev/md0 metadata=1.2
ARRAY /dev/md1 metadata=1.2
ARRAY /dev/md2 metadata=1.2
MONITOR
  MAILADDR root
  ALERT /usr/local/bin/raid-alert.sh
EOF

# Create alert script
cat > /usr/local/bin/raid-alert.sh << 'EOF'
#!/bin/bash
# RAID degradation alert
echo "CRITICAL: RAID array degradation detected!" | \
    mail -s "CRITICAL: RAID Alert on $(hostname)" root
EOF
chmod +x /usr/local/bin/raid-alert.sh

systemctl restart mdadm-monitor || true

log_success "RAID monitoring configured"

# ============================================================================
# SECTION 3: Storage Optimization
# ============================================================================

log_info "=== SECTION 3: Storage Optimization ==="

# Create docker storage directory
mkdir -p /opt/talksasa/containers
chmod 755 /opt/talksasa/containers

# Get current filesystem info
echo "Current storage layout:"
lsblk -o NAME,SIZE,FSTYPE,MOUNTPOINT
echo ""

# Check if /var/lib/docker exists
if [ -d /var/lib/docker ]; then
    log_info "Docker directory exists at /var/lib/docker"
    du -sh /var/lib/docker
else
    log_warn "Docker directory needs initialization"
fi

log_success "Storage directories prepared"

# ============================================================================
# SECTION 4: Docker Daemon Optimization
# ============================================================================

log_info "=== SECTION 4: Docker Daemon Configuration ==="

# Stop Docker daemon
systemctl stop docker || true

# Create optimized daemon.json
cat > /etc/docker/daemon.json << 'EOF'
{
  "debug": false,
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "storage-driver": "overlay2",
  "storage-opts": [
    "overlay2.override_kernel_check=true"
  ],
  "insecure-registries": [],
  "live-restore": true,
  "userland-proxy": false,
  "icc": false,
  "default-ulimits": {
    "nofile": {
      "Name": "nofile",
      "Hard": 65536,
      "Soft": 65536
    },
    "nproc": {
      "Name": "nproc",
      "Hard": 65536,
      "Soft": 65536
    }
  },
  "max-concurrent-downloads": 10,
  "max-concurrent-uploads": 10,
  "metrics-addr": "127.0.0.1:9323",
  "experimental": false
}
EOF

# Fix permissions
chmod 644 /etc/docker/daemon.json

log_success "Docker daemon configuration optimized"

# ============================================================================
# SECTION 5: System Limits
# ============================================================================

log_info "=== SECTION 5: System Resource Limits ==="

# Configure file descriptor limits
cat > /etc/security/limits.d/99-docker.conf << 'EOF'
* soft nofile 100000
* hard nofile 100000
* soft nproc 100000
* hard nproc 100000
docker soft nofile 100000
docker hard nofile 100000
EOF

# Configure kernel parameters
cat > /etc/sysctl.d/99-container-optimization.conf << 'EOF'
# Container-specific optimizations

# Enable IP forwarding (needed for containers)
net.ipv4.ip_forward = 1

# Increase connection backlog
net.core.somaxconn = 32768

# Increase file descriptor limit
fs.file-max = 2097152

# Memory optimization
vm.swappiness = 10
vm.panic_on_oom = 0
kernel.panic = 10

# Network security
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.tcp_syncookies = 1

# Increase port range for containers (30000-40000)
net.ipv4.ip_local_port_range = 30000 40000

# Performance tuning
net.ipv4.tcp_max_tw_buckets = 2000000
net.ipv4.tcp_fin_timeout = 10
EOF

sysctl -p /etc/sysctl.d/99-container-optimization.conf > /dev/null

log_success "System limits and kernel parameters optimized"

# ============================================================================
# SECTION 6: Networking Configuration
# ============================================================================

log_info "=== SECTION 6: Networking ==="

# Create custom Docker network
docker network create talksasa-net 2>/dev/null || log_warn "Network may already exist"

log_success "Docker network configured"

# ============================================================================
# SECTION 7: Firewall Configuration
# ============================================================================

log_info "=== SECTION 7: Firewall (UFW) ==="

# Enable firewall
ufw --force enable > /dev/null

# Set default policies
ufw default deny incoming > /dev/null
ufw default allow outgoing > /dev/null

# Allow SSH (CRITICAL!)
ufw allow 22/tcp > /dev/null

# Allow container ports (30000-40000)
ufw allow 30000:40000/tcp > /dev/null
ufw allow 30000:40000/udp > /dev/null

# Allow Docker metrics
ufw allow 9323/tcp > /dev/null

# Allow Node exporter
ufw allow 9100/tcp > /dev/null

log_success "Firewall configured"
echo "Firewall rules:"
ufw status numbered | tail -20

# ============================================================================
# SECTION 8: SSH Hardening
# ============================================================================

log_info "=== SECTION 8: SSH Security ==="

# Backup original sshd_config
cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup.$(date +%s)

# Apply security settings
sed -i 's/#PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/#PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/#PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/#PermitEmptyPasswords.*/PermitEmptyPasswords no/' /etc/ssh/sshd_config
sed -i 's/#X11Forwarding.*/X11Forwarding no/' /etc/ssh/sshd_config
sed -i 's/#MaxAuthTries.*/MaxAuthTries 3/' /etc/ssh/sshd_config

# Verify SSH config
sshd -t && log_success "SSH configuration valid" || log_error "SSH config error"

systemctl reload sshd

log_success "SSH hardened"

# ============================================================================
# SECTION 9: Fail2ban Configuration
# ============================================================================

log_info "=== SECTION 9: Fail2ban Setup ==="

cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 3

[sshd]
enabled = true
port = ssh
logpath = /var/log/auth.log
EOF

systemctl restart fail2ban

log_success "Fail2ban configured"

# ============================================================================
# SECTION 10: Monitoring Setup
# ============================================================================

log_info "=== SECTION 10: Monitoring ==="

# Install and configure Node Exporter
NODEEXP_VERSION="1.7.0"

if ! command -v node_exporter &> /dev/null; then
    cd /tmp
    wget https://github.com/prometheus/node_exporter/releases/download/v${NODEEXP_VERSION}/node_exporter-${NODEEXP_VERSION}.linux-amd64.tar.gz
    tar xvfz node_exporter-${NODEEXP_VERSION}.linux-amd64.tar.gz
    cp node_exporter-${NODEEXP_VERSION}.linux-amd64/node_exporter /usr/local/bin/
    chmod +x /usr/local/bin/node_exporter
    rm -rf node_exporter-${NODEEXP_VERSION}.linux-amd64*

    # Create systemd service
    cat > /etc/systemd/system/node_exporter.service << 'NODEEXP'
[Unit]
Description=Node Exporter
After=network.target

[Service]
User=root
Group=root
Type=simple
ExecStart=/usr/local/bin/node_exporter \
  --collector.filesystem.mount-points-exclude=^/(sys|proc|dev|host|etc)($|/) \
  --collector.netdev.device-exclude=^(veth.*)$ \
  --collector.textfile.directory=/var/lib/node_exporter/textfile_collector

[Install]
WantedBy=multi-user.target
NODEEXP

    mkdir -p /var/lib/node_exporter/textfile_collector
    chmod 755 /var/lib/node_exporter/textfile_collector

    systemctl daemon-reload
    systemctl enable node_exporter
    systemctl start node_exporter

    log_success "Node Exporter installed"
else
    log_warn "Node Exporter already installed"
fi

# Create container monitoring script
cat > /usr/local/bin/monitor-containers.sh << 'MONITOR'
#!/bin/bash
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$TIMESTAMP] Container Status:"
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.CPUPerc}}\t{{.MemUsage}}"

UNHEALTHY=$(docker ps --filter "health=unhealthy" -q)
if [ -n "$UNHEALTHY" ]; then
  echo "WARNING: Unhealthy containers:"
  docker ps --filter "health=unhealthy"
fi
MONITOR

chmod +x /usr/local/bin/monitor-containers.sh

# Schedule monitoring
(crontab -l 2>/dev/null || true; echo "*/5 * * * * /usr/local/bin/monitor-containers.sh >> /var/log/container-monitor.log 2>&1") | crontab -

log_success "Monitoring configured (Node Exporter + container monitor)"

# ============================================================================
# SECTION 11: Backup Setup
# ============================================================================

log_info "=== SECTION 11: Backup Configuration ==="

mkdir -p /mnt/backup
chmod 755 /mnt/backup

# Create backup script
cat > /usr/local/bin/backup-containers.sh << 'BACKUP'
#!/bin/bash
BACKUP_DIR="/mnt/backup"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"

echo "[$(date)] Starting backup..."

# Backup docker-compose files
if [ -d /opt/talksasa/containers ]; then
    tar czf "$BACKUP_DIR/compose_${TIMESTAMP}.tar.gz" /opt/talksasa/containers/ 2>/dev/null && \
        echo "[$(date)] Compose files backed up" || true
fi

# Backup system configs
tar czf "$BACKUP_DIR/config_${TIMESTAMP}.tar.gz" \
    /etc/docker \
    /etc/systemd/system \
    /opt/talksasa 2>/dev/null && \
    echo "[$(date)] Config files backed up" || true

# Clean old backups
echo "[$(date)] Cleaning old backups..."
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +$RETENTION_DAYS -delete

echo "[$(date)] Backup completed"
BACKUP

chmod +x /usr/local/bin/backup-containers.sh

# Schedule daily backup at 2 AM
(crontab -l 2>/dev/null || true; echo "0 2 * * * /usr/local/bin/backup-containers.sh >> /var/log/backup.log 2>&1") | crontab -

log_success "Backup configured (daily at 2 AM)"

# ============================================================================
# SECTION 12: Docker Restart & Cleanup
# ============================================================================

log_info "=== SECTION 12: Docker Daemon Restart ==="

# Start Docker with new configuration
systemctl daemon-reload
systemctl start docker

# Wait for daemon
sleep 3

# Verify Docker is running
if docker ps > /dev/null 2>&1; then
    log_success "Docker daemon started successfully"
else
    log_error "Docker daemon failed to start!"
    exit 1
fi

# Show Docker info
echo ""
echo "Docker Configuration:"
docker info | grep -E "Storage|Cgroup|Log|Runtime" | head -10

# ============================================================================
# SECTION 13: Security Audit
# ============================================================================

log_info "=== SECTION 13: Security Audit ==="

echo "System Security Summary:"
echo "  SSH Status:" $(systemctl is-active ssh)
echo "  Firewall:" $(ufw status | head -1)
echo "  Fail2ban:" $(systemctl is-active fail2ban)
echo "  Docker:" $(systemctl is-active docker)
echo ""
echo "RAID Status:"
cat /proc/mdstat | grep md
echo ""
echo "Memory:"
free -h | head -2 | tail -1
echo ""
echo "Disk Usage:"
df -h | grep -E "^/dev/(md|sd)" | head -5

# ============================================================================
# Final Summary
# ============================================================================

echo ""
log_success "=== Setup Complete! ==="
echo ""
echo "Next Steps:"
echo "  1. Register this node in Talksasa: /admin/nodes"
echo "  2. Node hostname: $(hostname)"
echo "  3. Node IP: $(hostname -I | awk '{print $1}')"
echo "  4. SSH Port: 22"
echo "  5. Test SSH key access"
echo "  6. Deploy test container: docker run hello-world"
echo ""
echo "Monitoring:"
echo "  - Node Exporter: http://$(hostname -I | awk '{print $1}'):9100/metrics"
echo "  - Docker metrics: http://127.0.0.1:9323/metrics"
echo "  - Container logs: /var/log/container-monitor.log"
echo "  - System logs: journalctl -u docker -f"
echo ""
echo "Documentation: /docs/DEDICATED_CONTAINER_SERVER_SETUP.md"
echo ""
