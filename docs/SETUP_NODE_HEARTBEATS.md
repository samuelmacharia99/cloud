# Container Node Heartbeat Setup Guide

## Overview

This guide explains how to set up automatic heartbeat monitoring for your container nodes. Heartbeats allow the platform to continuously monitor node health without requiring manual intervention.

**What are heartbeats?**
- Every 2 minutes, each node sends its system metrics (CPU, RAM, storage, uptime) to the platform
- The platform uses these to determine if nodes are online, degraded, or offline
- If a node hasn't sent a heartbeat in 15+ minutes, it's marked offline and admins are notified

---

## Prerequisites

Before starting, you need:

- ✅ A running container node with SSH access
- ✅ Node already added to the platform (`/admin/nodes`)
- ✅ SSH credentials configured in the platform
- ✅ Node ID (visible on the node detail page, e.g., `5`)
- ✅ Platform URL (e.g., `http://servers.talksasa.com`)
- ✅ `curl` and `bc` utilities available on the node

**Check prerequisites on the node:**

```bash
# SSH into your node first
ssh root@your-node-ip

# Verify curl is installed
which curl
# Output: /usr/bin/curl

# Verify bc is installed (for math calculations)
which bc
# Output: /usr/bin/bc

# If either is missing, install them:
apt-get update && apt-get install -y curl bc  # Ubuntu/Debian
yum install -y curl bc                         # CentOS/RHEL
```

---

## Step 1: Gather Required Information

**On the platform admin dashboard:**

1. Go to `/admin/nodes`
2. Click on your container node
3. Copy these values:
   - **Node ID**: The number in the URL bar (e.g., `/admin/nodes/5` → ID is `5`)
   - **Node IP**: Shown in the header (e.g., `95.217.230.115`)
   - **Node Name**: Shown in the header (e.g., `Container Host 1`)

**Your platform URL:**
- If platform is at `servers.talksasa.com`, use: `http://servers.talksasa.com`
- If local development, use: `http://localhost:8000`
- Note: Use `http://` (not `https://` for local) unless SSL is configured

**Example values:**
```
NODE_ID=5
NODE_IP=95.217.230.115
PLATFORM_URL=http://servers.talksasa.com
```

---

## Step 2: SSH Into the Node

From your local machine, SSH into the container node:

```bash
ssh root@95.217.230.115
# Or if using a different username:
ssh admin@95.217.230.115
```

Once connected, you should see a command prompt like:
```
root@container-host-1:~#
```

---

## Step 3: Create the Heartbeat Script

On the node, create the heartbeat sender script:

```bash
# Create the script file
sudo nano /usr/local/bin/send-heartbeat.sh
```

Copy and paste this entire script into nano:

```bash
#!/bin/bash
# Container Node Heartbeat Sender
# Deploys to /usr/local/bin/send-heartbeat.sh
# Runs via cron every 2 minutes

set -e

# Configuration
NODE_ID=${NODE_ID:-""}
PLATFORM_URL=${PLATFORM_URL:-"http://localhost:8000"}
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
STORAGE_TOTAL=$(df /opt/talksasa/containers -B1 | tail -1 | awk '{print $2}')
STORAGE_USED=$(df /opt/talksasa/containers -B1 | tail -1 | awk '{print $3}')
CPU_CORES=$(grep -c ^processor /proc/cpuinfo)
LOAD_AVG=$(cat /proc/loadavg | awk '{print $1}')

# Calculate percentages
RAM_PERCENT=$((RAM_USED * 100 / RAM_TOTAL))
STORAGE_PERCENT=$((STORAGE_USED * 100 / STORAGE_TOTAL))
CPU_PERCENT=$(echo "scale=0; ($LOAD_AVG / $CPU_CORES) * 100" | bc 2>/dev/null || echo "0")
CPU_PERCENT=$((CPU_PERCENT > 100 ? 100 : CPU_PERCENT))

# Convert bytes to GB
RAM_TOTAL_GB=$((RAM_TOTAL / 1024 / 1024 / 1024))
RAM_USED_GB=$((RAM_USED / 1024 / 1024 / 1024))
STORAGE_TOTAL_GB=$((STORAGE_TOTAL / 1024 / 1024 / 1024))
STORAGE_USED_GB=$((STORAGE_USED / 1024 / 1024 / 1024))

# Estimate uptime percentage
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

# Rotate log if it gets too large (10MB)
if [ -f "$LOG_FILE" ] && [ $(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0) -gt 10485760 ]; then
    mv "$LOG_FILE" "$LOG_FILE.$(date +%Y%m%d_%H%M%S)"
fi

exit 0
```

**To save the file in nano:**
1. Press `Ctrl + X`
2. Press `Y` (for yes)
3. Press `Enter` (to confirm filename)

**Make it executable:**

```bash
sudo chmod +x /usr/local/bin/send-heartbeat.sh
```

**Verify it was created:**

```bash
ls -la /usr/local/bin/send-heartbeat.sh
# Output: -rwxr-xr-x 1 root root 2345 May 10 12:34 /usr/local/bin/send-heartbeat.sh
```

---

## Step 4: Configure Environment Variables

The heartbeat script needs to know which node this is and where the platform is.

**Option A: System-wide (recommended)**

```bash
# Add to system environment
echo 'NODE_ID=5' | sudo tee -a /etc/environment
echo 'PLATFORM_URL=http://servers.talksasa.com' | sudo tee -a /etc/environment

# Verify (you may need to reload shell or log back in)
cat /etc/environment
```

**Option B: Via cron directly (see Step 5)**

If you prefer, you can set these in the crontab instead.

---

## Step 5: Add Cron Job

Now set up the cron job to run the heartbeat script every 2 minutes.

**Edit crontab:**

```bash
sudo crontab -e
```

This will open an editor. Choose your preferred editor if prompted. Add this line at the end:

```bash
*/2 * * * * NODE_ID=5 PLATFORM_URL=http://servers.talksasa.com /usr/local/bin/send-heartbeat.sh >> /var/log/heartbeat.log 2>&1
```

**Replace these values:**
- `5` → Your node ID
- `http://servers.talksasa.com` → Your platform URL

**Save and exit:**
- If using `nano`: Press `Ctrl + X`, then `Y`, then `Enter`
- If using `vi`: Press `Esc`, then type `:wq`, then `Enter`

**Verify cron was added:**

```bash
sudo crontab -l
# Should show your new line
```

---

## Step 6: Test the Heartbeat Script

**Run it manually to verify it works:**

```bash
NODE_ID=5 PLATFORM_URL=http://servers.talksasa.com /usr/local/bin/send-heartbeat.sh
```

**Check for errors:**

```bash
# View the log
sudo cat /var/log/heartbeat.log

# Expected output:
# [Fri May 10 12:34:56 UTC 2025] Heartbeat sent: RAM 32/64GB (50%), Storage 200/1800GB (11%), CPU 25%, Uptime 99%
```

**If you see an error:**

See the **Troubleshooting** section below.

---

## Step 7: Verify on the Platform

**Wait 2-3 minutes**, then check the platform dashboard:

1. Go to `/admin/nodes`
2. Click on your container node
3. Check the **Last Heartbeat** field
   - Should show "just now" or "a few seconds ago"
   - If it shows "Never", see troubleshooting

4. Scroll down to **24h Monitoring Dashboard**
   - Should display CPU, RAM, and Storage metrics
   - If blank, the heartbeat didn't include metrics

5. Scroll further to **Monitoring History**
   - Should show a new row with your metrics

---

## Step 8: Monitor Cron Execution

Monitor the heartbeat log to ensure it's running regularly:

```bash
# View last 10 heartbeats
sudo tail -20 /var/log/heartbeat.log

# Watch it in real-time (updates every 2 min)
sudo tail -f /var/log/heartbeat.log

# Press Ctrl+C to exit
```

**Expected pattern:**
```
[Fri May 10 12:30:00 UTC 2025] Heartbeat sent: RAM 32/64GB (50%), ...
[Fri May 10 12:32:00 UTC 2025] Heartbeat sent: RAM 32/64GB (50%), ...
[Fri May 10 12:34:00 UTC 2025] Heartbeat sent: RAM 32/64GB (50%), ...
```

If there's a gap > 5 minutes, the cron job may not be running.

---

## Step 9: Repeat for Other Nodes

For each additional container node, repeat **Steps 2-8** with that node's:
- SSH credentials
- Node ID (different for each node)
- Same or different PLATFORM_URL (if nodes are in same cluster, use same URL)

---

## Node Status Behavior

Once heartbeat is set up, here's what to expect:

| Time Since Last Heartbeat | Status | Color | Action |
|---------------------------|--------|-------|--------|
| < 5 minutes | **Online** | 🟢 Green | All good, no alerts |
| 5-15 minutes | **Degraded** | 🟡 Amber | Warning, monitor closely |
| ≥ 15 minutes | **Offline** | 🔴 Red | Alert email sent to admins |
| Never received | **Offline** | 🔴 Red | Node not communicating |

---

## Troubleshooting

### Problem: "Heartbeat failed" in log

**Check platform connectivity:**

```bash
# Test if platform is reachable
curl -I http://servers.talksasa.com

# Should return: HTTP/1.1 200 OK
```

**If unreachable:**
- Verify platform URL is correct
- Check firewall rules on both nodes and platform
- Verify platform is running: `sudo systemctl status apache2` (or nginx)

### Problem: "NODE_ID environment variable not set"

**Ensure variables are set:**

```bash
# Check if variables are in environment
env | grep NODE_ID

# If blank, set them manually:
export NODE_ID=5
export PLATFORM_URL=http://servers.talksasa.com

# Run the script
/usr/local/bin/send-heartbeat.sh
```

### Problem: "Permission denied" on log file

```bash
# Fix permissions
sudo chmod 666 /var/log/heartbeat.log

# Or create the file with correct permissions:
sudo touch /var/log/heartbeat.log
sudo chmod 666 /var/log/heartbeat.log
```

### Problem: Cron not running (no new log entries)

**Check if cron service is running:**

```bash
sudo systemctl status cron
# or
sudo systemctl status crond
```

**If not running:**

```bash
sudo systemctl start cron
sudo systemctl enable cron  # Start on boot
```

**Check cron syntax:**

```bash
# Validate your crontab
crontab -l | grep send-heartbeat

# Should show: */2 * * * * NODE_ID=5 PLATFORM_URL=... /usr/local/bin/send-heartbeat.sh
```

**Check system cron logs:**

```bash
# On Ubuntu/Debian
sudo grep CRON /var/log/syslog | tail -10

# On CentOS/RHEL
sudo tail -10 /var/log/cron
```

### Problem: Platform shows "Never" for Last Heartbeat

**Manually test the heartbeat endpoint:**

```bash
# On the node, test the endpoint directly
curl -X POST http://servers.talksasa.com/admin/nodes/5/heartbeat \
  -H "Content-Type: application/json" \
  -d '{
    "uptime_percentage": 99,
    "ram_used_gb": 32,
    "ram_total_gb": 64,
    "storage_used_gb": 200,
    "storage_total_gb": 1800,
    "cpu_percentage": 25
  }'

# Should return: {"success": true, "message": "Heartbeat recorded."}
```

**If response is an error:**
- Check platform logs: `tail -50 storage/logs/laravel.log`
- Verify node ID exists in database
- Ensure platform is responding to requests

### Problem: Cron runs but shows high CPU/RAM

**The script collects metrics every 2 minutes, which is minimal overhead.** If you see high CPU:

```bash
# Check what's using CPU
top -b -n 1 | grep send-heartbeat

# If it's consuming > 5% CPU, there may be a lock issue:
ps aux | grep send-heartbeat
# Kill any stuck processes:
pkill -f send-heartbeat
```

---

## Advanced: Changing Heartbeat Frequency

By default, heartbeats run every 2 minutes. To change this:

**Edit crontab:**

```bash
sudo crontab -e
```

**Change the timing:**

```bash
# Every 1 minute (more aggressive monitoring)
* * * * * NODE_ID=5 PLATFORM_URL=http://servers.talksasa.com /usr/local/bin/send-heartbeat.sh

# Every 3 minutes (less frequent)
*/3 * * * * NODE_ID=5 PLATFORM_URL=http://servers.talksasa.com /usr/local/bin/send-heartbeat.sh

# Every 5 minutes (recommended for light monitoring)
*/5 * * * * NODE_ID=5 PLATFORM_URL=http://servers.talksasa.com /usr/local/bin/send-heartbeat.sh
```

**Note:** The platform checks node health every 5 minutes. Setting heartbeat frequency to `*/2` (every 2 minutes) gives a 3-minute safety buffer.

---

## Advanced: Custom Storage Path

By default, the script checks `/opt/talksasa/containers`. If your storage is elsewhere:

**Edit the script:**

```bash
sudo nano /usr/local/bin/send-heartbeat.sh
```

**Find this line:**

```bash
STORAGE_TOTAL=$(df /opt/talksasa/containers -B1 | tail -1 | awk '{print $2}')
```

**Replace with your path:**

```bash
STORAGE_TOTAL=$(df /your/storage/path -B1 | tail -1 | awk '{print $2}')
STORAGE_USED=$(df /your/storage/path -B1 | tail -1 | awk '{print $3}')
```

**Save and the cron will use the new path.**

---

## Summary Checklist

- [ ] Gathered Node ID, Platform URL, and Node IP
- [ ] SSH'd into the node
- [ ] Created `/usr/local/bin/send-heartbeat.sh`
- [ ] Set NODE_ID and PLATFORM_URL environment variables
- [ ] Added cron job (runs every 2 minutes)
- [ ] Tested manually: `/usr/local/bin/send-heartbeat.sh`
- [ ] Verified log file: `sudo tail -f /var/log/heartbeat.log`
- [ ] Waited 2-3 minutes and checked platform dashboard
- [ ] Confirmed "Last Heartbeat" shows recent time
- [ ] Confirmed metrics appear in 24h Monitoring Dashboard
- [ ] Repeated for all other container nodes

---

## What Happens After Setup

✅ **Automatic Monitoring:**
- Every 2 minutes: Node sends heartbeat with metrics
- Every 5 minutes: Platform evaluates node health
- Instant: Admin notified if node goes offline

✅ **Dashboard Updates:**
- Last Heartbeat time updates automatically
- Metrics graph updates every 2 minutes
- Node status changes: Online → Degraded → Offline (as needed)

✅ **Alerts:**
- Container node goes offline → Email alert to admins
- Lists all affected containers
- Admins can manually move services if needed

---

## Next Steps

Once heartbeats are running on all nodes:

1. **Monitor the dashboard** regularly to ensure all nodes show "Online"
2. **Review logs** if any nodes show degraded/offline status
3. **Set up failover** (optional) - migrate containers if a node goes down
4. **Configure backup monitoring** (optional) - escalate alerts if node stays offline > 30 min

---

## Support

If heartbeats aren't working after following these steps:

1. Check the troubleshooting section above
2. Review `/var/log/heartbeat.log` on the node for errors
3. Review `storage/logs/laravel.log` on the platform for endpoint errors
4. Run the manual heartbeat test (see Troubleshooting section)
5. Contact support with the logs and error messages
