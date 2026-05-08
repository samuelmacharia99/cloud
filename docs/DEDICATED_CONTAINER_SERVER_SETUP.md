# Dedicated Container Server Setup & Optimization Guide

**Server Specs:**
- RAM: 64 GB
- Storage: 2 × 500GB HDD (1TB total)
- Primary Use: Docker container hosting for Talksasa Cloud customers

**Optimization Goals:**
- Maximum resource utilization
- High availability & fault tolerance
- Efficient I/O performance
- Easy scaling & management

---

## Table of Contents

1. [Pre-Installation Hardware Setup](#pre-installation-hardware-setup)
2. [OS Installation & Base Configuration](#os-installation--base-configuration)
3. [Storage Configuration (RAID & LVM)](#storage-configuration-raid--lvm)
4. [Docker & Container Runtime Setup](#docker--container-runtime-setup)
5. [Networking Configuration](#networking-configuration)
6. [Security Hardening](#security-hardening)
7. [Node Registration with Talksasa](#node-registration-with-talksasa)
8. [Performance Optimization](#performance-optimization)
9. [Monitoring & Logging](#monitoring--logging)
10. [Backup & Disaster Recovery](#backup--disaster-recovery)
11. [Scaling & Multi-Node Setup](#scaling--multi-node-setup)

---

## Pre-Installation Hardware Setup

### BIOS Configuration

1. **Enter BIOS** (usually F2, F10, Del during boot)
2. **Enable:**
   - Virtualization (VT-x/AMD-V)
   - VT-d/IOMMU (for device passthrough if needed)
   - XMP/DOCP memory profiles (maximize RAM speed)
3. **Disable:**
   - Power saving features (C-States, P-States)
   - Soft Power Button
4. **Set:**
   - Boot order: SSD first (if available), then HDD
   - RAID mode: AHCI or Hardware RAID (if controller available)

### Disk Preparation

**Option A: Hardware RAID (Recommended if controller available)**
```
Configure both 500GB drives as RAID 1 (Mirror) via BIOS/RAID utility
Provides automatic failover if one drive fails
```

**Option B: Software RAID (Using Linux mdadm)**
```
Use Linux RAID with LVM for maximum flexibility
Better for adding/replacing drives later
```

**Option C: Single Drive + External Backup**
```
If RAID not available, use one drive for production
Keep second drive as hot-spare for quick swap
```

---

## OS Installation & Base Configuration

### 1. Choose Base OS

**Recommended: Ubuntu Server 22.04 LTS**
```bash
# Why:
# - Long-term support (5 years)
# - Excellent Docker/container support
# - Large community & documentation
# - Good performance for containerized workloads
```

### 2. Installation Steps

```bash
# 1. Download Ubuntu Server 22.04 LTS ISO
#    https://ubuntu.com/download/server

# 2. Create bootable USB/CD
# 3. Boot and run installer
# 4. During installation:
#    - Hostname: talksasa-container-node-1 (or your naming scheme)
#    - Create user: containeradmin (for management)
#    - Use OpenSSH server (YES)
#    - Use LVM (YES) - for flexibility
#    - Partition: Leave space for Docker storage
```

### 3. Post-Installation Setup

```bash
# SSH into server
ssh containeradmin@<server-ip>

# Update system
sudo apt update && sudo apt upgrade -y

# Install essential tools
sudo apt install -y \
  build-essential \
  curl \
  wget \
  git \
  vim \
  htop \
  iotop \
  nethogs \
  jq \
  net-tools \
  ufw \
  fail2ban \
  chrony \
  lm-sensors

# Set timezone
sudo timedatectl set-timezone UTC  # or your timezone
sudo timedatectl status
```

---

## Storage Configuration (RAID & LVM)

### Storage Layout Strategy

```
With 1TB (2×500GB) and 64GB RAM:

Option 1 (Recommended): Balanced Approach
├── OS Partition (50GB)
│   ├── /root
│   ├── /boot
│   └── System services
├── Docker Data (700GB)
│   ├── /var/lib/docker (images, layers, volumes)
│   ├── Container persistent storage
│   └── Backup staging area
└── Swap (250GB)
    └── Emergency memory extension
```

### Implementing Software RAID 1 + LVM

```bash
# 1. Check disk devices
lsblk
sudo fdisk -l

# Assuming /dev/sda and /dev/sdb (both 500GB)

# 2. Prepare both drives for mdadm
sudo apt install -y mdadm

# Create RAID 1 array
sudo mdadm --create /dev/md0 \
  --level=1 \
  --raid-devices=2 \
  /dev/sda1 /dev/sdb1

# Verify RAID
cat /proc/mdstat
sudo mdadm --detail /dev/md0

# 3. Create LVM on RAID

# Create physical volume
sudo pvcreate /dev/md0

# Create volume group
sudo vgcreate vg_storage /dev/md0

# Create logical volumes
sudo lvcreate -L 50G -n lv_root vg_storage
sudo lvcreate -L 700G -n lv_docker vg_storage
sudo lvcreate -L 250G -n lv_swap vg_storage

# Verify LVM
sudo pvdisplay
sudo vgdisplay
sudo lvdisplay
```

### Format and Mount

```bash
# Format filesystems
sudo mkfs.ext4 /dev/vg_storage/lv_root
sudo mkfs.ext4 /dev/vg_storage/lv_docker
sudo mkswap /dev/vg_storage/lv_swap

# Mount (if not already mounted by installer)
sudo mount /dev/vg_storage/lv_docker /var/lib/docker

# Add to /etc/fstab for persistent mounting
echo "/dev/vg_storage/lv_root / ext4 defaults,noatime 0 1" | sudo tee -a /etc/fstab
echo "/dev/vg_storage/lv_docker /var/lib/docker ext4 defaults,noatime 0 2" | sudo tee -a /etc/fstab
echo "/dev/vg_storage/lv_swap none swap sw 0 0" | sudo tee -a /etc/fstab

# Enable swap
sudo swapon -a

# Verify
mount | grep -E "root|docker"
free -h
```

### RAID Monitoring

```bash
# Create mdadm monitoring config
sudo nano /etc/mdadm/mdadm.conf

# Add:
ARRAY /dev/md0 metadata=1.2 name=talksasa:0 UUID=xxxxx devices=/dev/sda1,/dev/sdb1
MONITOR
  MAILADDR root
  ALERT /path/to/alert/script.sh

# Start monitoring daemon
sudo systemctl restart mdadm-raid
```

---

## Docker & Container Runtime Setup

### 1. Install Docker Engine

```bash
# Add Docker repository
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -

sudo add-apt-repository \
  "deb [arch=amd64] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) \
  stable"

# Install Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Verify installation
docker --version
docker run hello-world

# Add containeradmin to docker group (optional, for non-root)
sudo usermod -aG docker containeradmin
```

### 2. Configure Docker Daemon

```bash
# Create/edit Docker daemon config
sudo nano /etc/docker/daemon.json
```

**Optimal Configuration:**

```json
{
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
  "registry-mirrors": [],
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
  "max-concurrent-uploads": 10
}
```

**Key Optimizations:**
- `overlay2`: Modern, efficient storage driver
- `live-restore`: Keep containers running if daemon crashes
- `userland-proxy: false`: Bypass userland proxy for better performance
- `icc: false`: Disable inter-container communication by default (security)
- `max-concurrent-downloads`: Faster image pulls
- Log rotation: Prevent log spam from filling disk

```bash
# Apply configuration
sudo systemctl daemon-reload
sudo systemctl restart docker

# Verify
docker info
```

### 3. Install Docker Compose (if not available)

```bash
sudo apt install -y docker-compose-plugin

# Or install standalone:
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" \
  -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

docker compose version
```

### 4. Container Base Directory Setup

```bash
# Create base directory for all containers (per deployment service)
sudo mkdir -p /opt/talksasa/containers
sudo chmod 755 /opt/talksasa/containers
sudo chown root:docker /opt/talksasa/containers

# Per-container structure will be:
# /opt/talksasa/containers/talksasa-{service-id}-{random}/
#   ├── docker-compose.yml
#   ├── .env (if needed)
#   └── volumes/ (persistent data)
```

### 5. Image Management Strategy

```bash
# Set up image cleanup policy
cat > /usr/local/bin/docker-cleanup.sh << 'EOF'
#!/bin/bash
# Remove dangling images weekly
docker image prune -f --all --filter "until=168h"
# Remove unused volumes
docker volume prune -f --filter "label!=keep"
EOF

sudo chmod +x /usr/local/bin/docker-cleanup.sh

# Add to crontab (weekly)
(sudo crontab -l 2>/dev/null; echo "0 2 * * 0 /usr/local/bin/docker-cleanup.sh") | sudo crontab -
```

---

## Networking Configuration

### 1. Network Interface Setup

```bash
# View current interfaces
ip addr show

# Edit netplan configuration (Ubuntu)
sudo nano /etc/netplan/00-installer-config.yaml
```

**Static IP Configuration:**

```yaml
network:
  version: 2
  ethernets:
    eth0:
      dhcp4: false
      addresses:
        - 192.168.1.100/24  # Change to your network
      gateway4: 192.168.1.1
      nameservers:
        addresses:
          - 8.8.8.8
          - 8.8.4.4
      routes:
        - to: 0.0.0.0/0
          via: 192.168.1.1
```

```bash
# Apply changes
sudo netplan apply

# Verify
ip addr show
```

### 2. Firewall Configuration (UFW)

```bash
# Enable firewall
sudo ufw enable

# Default policies
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Allow SSH (critical!)
sudo ufw allow 22/tcp

# Allow Docker service ports (port range for containers)
sudo ufw allow 30000:40000/tcp
sudo ufw allow 30000:40000/udp

# If running monitoring ports
sudo ufw allow 9100/tcp  # Node exporter
sudo ufw allow 9323/tcp  # Docker metrics

# Allow Talksasa API communication (adjust as needed)
sudo ufw allow from 192.168.1.0/24  # Your local network

# View rules
sudo ufw status numbered
```

### 3. DNS & Service Discovery

```bash
# Install systemd-resolved for local DNS
sudo systemctl enable systemd-resolved

# Configure resolver
sudo nano /etc/systemd/resolved.conf

# Set:
[Resolve]
DNS=8.8.8.8 8.8.4.4
FallbackDNS=1.1.1.1 1.0.0.1
DNSSEC=no
```

### 4. Container Networking

```bash
# Create custom bridge network for containers
docker network create --driver bridge talksasa-net

# Optional: Configure MTU if needed (for some networks)
docker network create \
  --driver bridge \
  --opt com.docker.network.driver.mtu=1500 \
  talksasa-net

# View networks
docker network ls
docker network inspect talksasa-net
```

---

## Security Hardening

### 1. SSH Hardening

```bash
sudo nano /etc/ssh/sshd_config

# Set these options:
Port 2222  # Change from default 22
AddressFamily inet  # IPv4 only
PermitRootLogin no
PubkeyAuthentication yes
PasswordAuthentication no
PermitEmptyPasswords no
X11Forwarding no
MaxAuthTries 3
MaxSessions 5
ClientAliveInterval 300
ClientAliveCountInterval 3
```

```bash
# Restart SSH
sudo systemctl restart sshd

# Verify (new session before closing old one!)
# ssh -p 2222 containeradmin@<server-ip>
```

### 2. Fail2ban Configuration

```bash
sudo nano /etc/fail2ban/jail.local

[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 3

[sshd]
enabled = true
port = 2222
logpath = /var/log/auth.log

[docker-container-limit]
enabled = true
logpath = /var/log/syslog
maxretry = 10
```

```bash
sudo systemctl restart fail2ban
sudo fail2ban-client status
```

### 3. Kernel Security Parameters

```bash
sudo nano /etc/sysctl.d/99-hardening.conf

# Add:
# Network security
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.icmp_echo_ignore_all = 0
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.tcp_syncookies = 1

# Enable IP forwarding (needed for containers)
net.ipv4.ip_forward = 1

# Container security
kernel.unprivileged_userns_clone = 0  # Optional: disable if issues

# Memory protection
vm.panic_on_oom = 0
kernel.panic = 10
```

```bash
# Apply immediately
sudo sysctl -p /etc/sysctl.d/99-hardening.conf
```

### 4. AppArmor/SELinux

```bash
# Ubuntu uses AppArmor by default
sudo apt install -y apparmor apparmor-utils

# Verify
sudo aa-status

# For Docker, ensure AppArmor profile is loaded:
sudo aa-enforce /etc/apparmor.d/docker-default
```

### 5. Regular Updates

```bash
# Enable automatic security updates
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades

# Verify
sudo systemctl status unattended-upgrades
cat /etc/apt/apt.conf.d/50unattended-upgrades
```

---

## Node Registration with Talksasa

### 1. Register Node in Talksasa Dashboard

```bash
# Via Admin Panel:
1. Go to: /admin/nodes
2. Click: "Add Node"
3. Fill:
   - Name: talksasa-container-node-1
   - Type: container_host
   - Hostname: <server-ip or hostname>
   - SSH Port: 2222 (if changed)
   - SSH User: containeradmin
   - Max Containers: 50 (adjust based on your sizing)
   - Status: active
4. Submit
```

### 2. Generate SSH Keys for Talksasa Access

```bash
# On server: Create SSH key for Talksasa control
sudo su - containeradmin

# Generate key without passphrase
ssh-keygen -t rsa -b 4096 -f ~/.ssh/talksasa_key -N ""

# Copy public key
cat ~/.ssh/talksasa_key.pub

# Add to authorized_keys
cat ~/.ssh/talksasa_key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### 3. Configure Talksasa SSH Access

```bash
# In Talksasa admin panel for this node:
1. Go to node edit page
2. Add SSH public key field
3. Paste the public key from above
4. Test connection: "Test SSH" button
```

### 4. Verify Deployment Service Can Access

```bash
# From Talksasa server, test SSH
ssh -p 2222 -i /path/to/talksasa_key containeradmin@<server-ip> "docker ps"

# Should see empty container list or existing containers
```

---

## Performance Optimization

### 1. Kernel Tuning for Containers

```bash
# File descriptor limits
sudo nano /etc/security/limits.conf

# Add:
* soft nofile 100000
* hard nofile 100000
* soft nproc 100000
* hard nproc 100000

# Docker daemon limits (already set in daemon.json)
```

### 2. I/O Optimization

```bash
# Check disk scheduler
cat /sys/block/sda/queue/scheduler

# Set optimal scheduler (for HDD)
# Edit: /etc/default/grub
GRUB_CMDLINE_LINUX_DEFAULT="elevator=deadline"

# Or for SSD
GRUB_CMDLINE_LINUX_DEFAULT="elevator=noop"

sudo update-grub
sudo reboot
```

### 3. Memory Management

```bash
# Set vm.swappiness to use swap only when necessary
sudo sysctl -w vm.swappiness=10

# Make permanent
echo "vm.swappiness=10" | sudo tee -a /etc/sysctl.conf

# Monitor memory usage
free -h
# Should show ~50GB available for containers
```

### 4. CPU Optimization

```bash
# Disable CPU frequency scaling (for consistent performance)
sudo apt install -y cpufrequtils
sudo systemctl disable cpufrequtils

# Or set to performance mode
echo "performance" | sudo tee /sys/devices/system/cpu/cpu*/cpufreq/scaling_governor

# Verify
cat /proc/cpuinfo | grep MHz
```

### 5. Disk Performance Optimization

```bash
# For LVM, enable caching
sudo nano /etc/lvm/lvm.conf

# Set:
use_lvmetad = 1
cache_file_prefix = "/etc/lvm/cache"

# For /var/lib/docker, optimize mount options
sudo nano /etc/fstab

# Change docker mount to:
/dev/vg_storage/lv_docker /var/lib/docker ext4 defaults,noatime,nodiratime,barrier=0 0 2

# Remount
sudo mount -o remount,noatime,nodiratime /var/lib/docker

# Check I/O performance
sudo iotop  # Real-time disk I/O
iostat -x 1 5  # I/O statistics
```

---

## Monitoring & Logging

### 1. System Monitoring with Node Exporter

```bash
# Install Prometheus Node Exporter
wget https://github.com/prometheus/node_exporter/releases/download/v1.5.0/node_exporter-1.5.0.linux-amd64.tar.gz

tar xvfz node_exporter-1.5.0.linux-amd64.tar.gz
sudo mv node_exporter-1.5.0.linux-amd64/node_exporter /usr/local/bin/
sudo useradd --no-create-home --shell /bin/false node_exporter

# Create systemd service
sudo nano /etc/systemd/system/node_exporter.service

[Unit]
Description=Node Exporter
Wants=network-online.target
After=network-online.target

[Service]
User=node_exporter
Group=node_exporter
Type=simple
ExecStart=/usr/local/bin/node_exporter \
  --collector.filesystem.mount-points-exclude=^/(sys|proc|dev|host|etc)($|/) \
  --collector.netdev.device-exclude=^(veth.*)$

[Install]
WantedBy=multi-user.target

# Enable and start
sudo systemctl daemon-reload
sudo systemctl enable node_exporter
sudo systemctl start node_exporter

# Verify metrics
curl http://localhost:9100/metrics
```

### 2. Docker Metrics

```bash
# Enable Docker metrics endpoint
# Already configured in /etc/docker/daemon.json with:
# "metrics-addr": "127.0.0.1:9323"

# View metrics
curl http://localhost:9323/metrics
```

### 3. Centralized Logging

```bash
# Configure Docker to send logs to syslog
sudo nano /etc/docker/daemon.json

# Add:
"log-driver": "syslog",
"log-opts": {
  "syslog-address": "udp://localhost:514",
  "tag": "docker/{{.Name}}"
}

# Or use ELK stack for centralized logging
# (See separate Docker Compose for ELK)

sudo systemctl restart docker
```

### 4. Container Health Monitoring

```bash
# Create monitoring script
cat > /usr/local/bin/monitor-containers.sh << 'EOF'
#!/bin/bash
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$TIMESTAMP] Container Status:"
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.CPUPerc}}\t{{.MemUsage}}"

# Check for unhealthy containers
UNHEALTHY=$(docker ps --filter "health=unhealthy" -q)
if [ -n "$UNHEALTHY" ]; then
  echo "WARNING: Unhealthy containers detected:"
  docker ps --filter "health=unhealthy"
fi
EOF

chmod +x /usr/local/bin/monitor-containers.sh

# Run every 5 minutes
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/monitor-containers.sh >> /var/log/container-monitor.log 2>&1") | crontab -
```

### 5. Alerting Setup

```bash
# Create alert script
cat > /usr/local/bin/alert-critical.sh << 'EOF'
#!/bin/bash
# Check disk usage
DISK_USAGE=$(df /var/lib/docker | awk 'NR==2 {print $5}' | cut -d'%' -f1)
if [ "$DISK_USAGE" -gt 85 ]; then
  echo "ALERT: Disk usage at ${DISK_USAGE}%" | mail -s "Server Alert" admin@talksasa.com
fi

# Check memory usage
MEM_AVAILABLE=$(free -m | awk 'NR==2 {print $7}')
if [ "$MEM_AVAILABLE" -lt 5000 ]; then  # Less than 5GB
  echo "ALERT: Low memory available: ${MEM_AVAILABLE}MB" | mail -s "Server Alert" admin@talksasa.com
fi

# Check RAID status
mdstat=$(cat /proc/mdstat | grep "md0")
if [[ $mdstat == *"[_U]"* ]] || [[ $mdstat == *"[U_]"* ]]; then
  echo "ALERT: RAID degraded!" | mail -s "CRITICAL Server Alert" admin@talksasa.com
fi
EOF

chmod +x /usr/local/bin/alert-critical.sh

# Run every 10 minutes
(crontab -l 2>/dev/null; echo "*/10 * * * * /usr/local/bin/alert-critical.sh") | crontab -
```

---

## Backup & Disaster Recovery

### 1. Backup Strategy

```
Daily Backups:
├── Database dumps (customer data, configs)
├── Persistent volumes (container data)
└── System configurations

Weekly Full Backup:
└── Complete system snapshot
```

### 2. Implement Backup Script

```bash
cat > /usr/local/bin/backup-containers.sh << 'EOF'
#!/bin/bash

BACKUP_DIR="/mnt/backup"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
RETENTION_DAYS=30

mkdir -p "$BACKUP_DIR"

# 1. Backup container volumes
echo "Backing up container volumes..."
for container in $(docker ps -q); do
  CONTAINER_NAME=$(docker inspect --format='{{.Name}}' $container | tr -d '/')
  docker inspect $container -f '{{range .Mounts}}{{if .Source}}{{.Source}} {{end}}{{end}}' | \
    xargs tar czf "$BACKUP_DIR/${CONTAINER_NAME}_${TIMESTAMP}.tar.gz" -C /
done

# 2. Backup docker-compose files
echo "Backing up docker-compose files..."
tar czf "$BACKUP_DIR/compose_${TIMESTAMP}.tar.gz" /opt/talksasa/containers/

# 3. Backup system configs
echo "Backing up system configs..."
tar czf "$BACKUP_DIR/config_${TIMESTAMP}.tar.gz" \
  /etc/docker \
  /etc/systemd/system \
  /opt/talksasa

# 4. Clean old backups
echo "Cleaning old backups..."
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +$RETENTION_DAYS -delete

echo "Backup completed at $(date)"
EOF

chmod +x /usr/local/bin/backup-containers.sh

# Schedule daily at 2 AM
(crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/backup-containers.sh") | crontab -
```

### 3. Backup Verification

```bash
# Test restore (monthly)
tar tzf /mnt/backup/compose_latest.tar.gz | head -20

# Verify backup integrity
sha256sum /mnt/backup/*.tar.gz > /mnt/backup/checksums.txt
sha256sum -c /mnt/backup/checksums.txt
```

### 4. Disaster Recovery Plan

```
In case of complete failure:

1. Re-install OS on new hardware
2. Re-configure storage (RAID/LVM)
3. Restore configs from backup
4. Restore container volumes
5. Re-create containers using docker-compose
6. Verify all services online
```

---

## Scaling & Multi-Node Setup

### 1. Container Spread Strategy

```
With 64GB RAM and 2x500GB storage:

Recommended capacity per node:
├── Max 50 small containers (256MB-512MB each)
├── Max 30 medium containers (1GB-2GB each)
└── Max 10 large containers (4GB+ each)

When approaching limits, provision new node
```

### 2. Load Balancing

```bash
# Install HAProxy for load balancing across nodes
sudo apt install -y haproxy

# Configure /etc/haproxy/haproxy.cfg
# Route traffic to containers across multiple nodes
```

### 3. Multi-Node Monitoring

```bash
# Create central monitoring dashboard showing:
# - Total container capacity
# - Per-node resource usage
# - Health status of each node
# - Alert aggregation
```

### 4. Adding Additional Nodes

```
When you add Node 2:
1. Repeat all setup steps (1-10)
2. Register in Talksasa with name: talksasa-container-node-2
3. Update load balancer routing
4. Test failover scenarios
```

---

## Maintenance Schedule

### Daily Tasks
- Monitor container health
- Check disk usage (alert at 80%)
- Monitor memory availability
- Verify RAID status

### Weekly Tasks
- Full system backup
- Review logs for errors
- Update container images
- Clean unused Docker resources

### Monthly Tasks
- Test backup restoration
- Security updates
- Performance optimization review
- Capacity planning

### Quarterly Tasks
- RAID array testing
- Network performance testing
- Disaster recovery drill
- Security audit

---

## Troubleshooting Reference

### Container Won't Start
```bash
# Check Docker logs
docker logs <container-id>

# Check service health
docker inspect <container-id> --format='{{.State}}'

# Verify network connectivity
docker network inspect talksasa-net
```

### High Disk Usage
```bash
# Find large images
docker images --format "table {{.Repository}}:{{.Tag}}\t{{.Size}}" | sort -k2 -h

# Clean dangling images/volumes
docker image prune -a
docker volume prune

# Check container volumes
docker inspect <container-id> | grep -A 5 Mounts
```

### High Memory Usage
```bash
# Check memory consumption per container
docker stats

# Limit container memory in docker-compose.yml
deploy:
  resources:
    limits:
      memory: 2G
    reservations:
      memory: 1G
```

### RAID Issues
```bash
# Check RAID status
cat /proc/mdstat
sudo mdadm --detail /dev/md0

# Failed drive recovery
sudo mdadm --manage /dev/md0 --fail /dev/sda1
sudo mdadm --manage /dev/md0 --remove /dev/sda1
# Replace drive physically, then:
sudo mdadm --manage /dev/md0 --add /dev/sda1
```

---

## Conclusion

This setup provides:
- **High Availability**: RAID 1 redundancy
- **Scalability**: LVM for flexible storage expansion
- **Security**: Hardened SSH, firewall, security updates
- **Performance**: Optimized Docker, kernel tuning, I/O optimization
- **Monitoring**: Comprehensive logging and alerting
- **Reliability**: Automated backups and disaster recovery

**Total Setup Time**: 4-6 hours  
**Maintenance Time**: 2-3 hours per week  
**Support**: Contact admin@talksasa.com for issues  

---

## Quick Reference Commands

```bash
# System Health Check
free -h && df -h && docker ps && cat /proc/mdstat

# View Container Logs
docker compose -f /opt/talksasa/containers/talksasa-{id}-*/docker-compose.yml logs -f

# Monitor Real-time
watch -n 1 'docker stats --no-stream'

# Backup Now
/usr/local/bin/backup-containers.sh

# Security Audit
sudo ufw status
sudo fail2ban-client status
sudo aa-status
```

---

**Created**: 2026-05-08  
**Version**: 1.0  
**Last Updated**: 2026-05-08
