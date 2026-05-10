#!/bin/bash
# Container Node Heartbeat Sender
# Deploy to /usr/local/bin/send-heartbeat.sh on each container node
# Add to crontab: */2 * * * * /usr/local/bin/send-heartbeat.sh

set -e

# Configuration
NODE_ID=${NODE_ID:-""}
PLATFORM_URL=${PLATFORM_URL:-"http://servers.talksasa.com"}
LOG_FILE="/var/log/heartbeat.log"

# Exit if NODE_ID not set
if [ -z "$NODE_ID" ]; then
    echo "[$(date)] ERROR: NODE_ID environment variable not set" >> "$LOG_FILE"
    exit 1
fi

# Collect system metrics
UPTIME=$(uptime | awk -F'up' '{print $2}' | awk -F',' '{print $1}' | xargs)
RAM_TOTAL=$(free -b | grep Mem | awk '{print $2}')
RAM_USED=$(free -b | grep Mem | awk '{print $3}')
CPU_CORES=$(grep -c ^processor /proc/cpuinfo)
LOAD_AVG=$(cat /proc/loadavg | awk '{print $1}')

# Storage metrics - try multiple paths, use first that exists
STORAGE_TOTAL=0
STORAGE_USED=0
STORAGE_PATH=""

for path in /opt/talksasa/containers /opt/containers /srv/containers /var/lib/docker /; do
    if [ -d "$path" ]; then
        STORAGE_PATH="$path"
        STORAGE_TOTAL=$(df "$path" -B1 2>/dev/null | tail -1 | awk '{print $2}')
        STORAGE_USED=$(df "$path" -B1 2>/dev/null | tail -1 | awk '{print $3}')
        break
    fi
done

# Calculate percentages (avoid division by zero)
RAM_PERCENT=$((RAM_TOTAL > 0 ? RAM_USED * 100 / RAM_TOTAL : 0))
STORAGE_PERCENT=$((STORAGE_TOTAL > 0 ? STORAGE_USED * 100 / STORAGE_TOTAL : 0))
CPU_PERCENT=$(echo "scale=0; ($LOAD_AVG / $CPU_CORES) * 100" | bc 2>/dev/null || echo "0")
CPU_PERCENT=$((CPU_PERCENT > 100 ? 100 : CPU_PERCENT))

# Convert bytes to GB (handle zero/missing values)
RAM_TOTAL_GB=$((RAM_TOTAL > 0 ? RAM_TOTAL / 1024 / 1024 / 1024 : 0))
RAM_USED_GB=$((RAM_USED > 0 ? RAM_USED / 1024 / 1024 / 1024 : 0))
STORAGE_TOTAL_GB=$((STORAGE_TOTAL > 0 ? STORAGE_TOTAL / 1024 / 1024 / 1024 : 0))
STORAGE_USED_GB=$((STORAGE_USED > 0 ? STORAGE_USED / 1024 / 1024 / 1024 : 0))

# Estimate uptime percentage (simple: 99% if running normally, 95% if load high)
if [ $(echo "$LOAD_AVG > 3" | bc 2>/dev/null || echo 0) -eq 1 ]; then
    UPTIME_PERCENT=95
else
    UPTIME_PERCENT=99
fi

# Send heartbeat to platform
HEARTBEAT_URL="${PLATFORM_URL}/admin/nodes/${NODE_ID}/heartbeat"

RESPONSE=$(curl -s -X POST "$HEARTBEAT_URL" \
    -H "Content-Type: application/json" \
    -d "{
        \"uptime_percentage\": $UPTIME_PERCENT,
        \"ram_used_gb\": $RAM_USED_GB,
        \"ram_total_gb\": $RAM_TOTAL_GB,
        \"storage_used_gb\": $STORAGE_USED_GB,
        \"storage_total_gb\": $STORAGE_TOTAL_GB,
        \"cpu_percentage\": $CPU_PERCENT
    }" 2>&1)

# Log result
STATUS=$?
if [ $STATUS -eq 0 ]; then
    echo "[$(date)] Heartbeat sent: RAM ${RAM_USED_GB}/${RAM_TOTAL_GB}GB ($RAM_PERCENT%), Storage ${STORAGE_USED_GB}/${STORAGE_TOTAL_GB}GB ($STORAGE_PERCENT%), CPU ${CPU_PERCENT}%, Uptime $UPTIME_PERCENT%" >> "$LOG_FILE"
else
    echo "[$(date)] ERROR: Heartbeat failed (curl exit code $STATUS)" >> "$LOG_FILE"
    exit 1
fi

# Rotate log if it gets too large
if [ -f "$LOG_FILE" ] && [ $(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE") -gt 10485760 ]; then
    mv "$LOG_FILE" "$LOG_FILE.$(date +%Y%m%d_%H%M%S)"
    gzip "$LOG_FILE".*
fi

exit 0
