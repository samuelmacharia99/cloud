# Container Node Monitoring System

## Overview

The container node monitoring system determines whether nodes are **online**, **degraded**, or **offline** through a heartbeat-based mechanism. The system is passive (nodes must send heartbeats to the platform) rather than active polling.

---

## How Node Status is Determined

### Status Determination Timeline

Node status is determined by the **age of `last_heartbeat_at`**:

| Status | Condition | Heartbeat Age |
|--------|-----------|---------------|
| **Online** | Heartbeat received recently | < 5 minutes |
| **Degraded** | Heartbeat is stale | 5–15 minutes |
| **Offline** | No recent heartbeat | ≥ 15 minutes or `NULL` |
| **Maintenance** | Manual override | User-set |

### Status Transitions

The **CheckNodeHealthCommand** runs every 5 minutes (`*/5 * * * *`) to evaluate all active monitored nodes:

```
Node.php::isMonitored() → includes 'container_host' and 'database_server' types only
  ↓
CheckNodeHealthCommand runs every 5 minutes
  ↓
For each monitored node:
  - If last_heartbeat_at IS NULL → status = offline
  - Else if last_heartbeat_at < 15 minutes ago → status = offline (alert sent for container nodes)
  - Else if last_heartbeat_at < 5 minutes ago → status = degraded
  - Else (< 5 minutes) → status = online (recover if was degraded/offline)
```

**Database fields involved:**
- `nodes.last_heartbeat_at` (timestamp, nullable)
- `nodes.status` (enum: online, offline, degraded, maintenance)
- `nodes.is_active` (boolean flag)

---

## How Nodes Send Heartbeats

### The Heartbeat Endpoint

**Route:** `POST /admin/nodes/{node}/heartbeat`  
**Controller:** `Admin/NodeController::heartbeat()`  
**Authentication:** Required (nodes must authenticate)

### Heartbeat Request Format

```json
{
  "uptime_percentage": 98,
  "ram_used_gb": 32,
  "ram_total_gb": 64,
  "storage_used_gb": 450,
  "storage_total_gb": 1800,
  "cpu_percentage": 45
}
```

All fields are **optional**. At minimum, a POST to the endpoint is sufficient to record the heartbeat.

### What Happens When a Heartbeat Arrives

1. **Always:** `last_heartbeat_at` is updated to `now()`
2. **If monitoring data is provided** (i.e., any of the usage fields):
   - A new `NodeMonitoring` record is created
   - The node's status may be auto-degraded if thresholds are exceeded:
     - RAM > 85% → degraded
     - Storage > 90% → degraded
     - Uptime < 95% → degraded
3. **Response:** JSON confirmation is returned

### Example Response

```json
{
  "success": true,
  "message": "Heartbeat recorded."
}
```

---

## Monitoring Data Storage

### NodeMonitoring Table (Historical Metrics)

Each heartbeat with monitoring data creates a `NodeMonitoring` record:

| Column | Type | Purpose |
|--------|------|---------|
| `node_id` | FK | Links to the node |
| `uptime_percentage` | integer (0–100) | System uptime % in last 24h |
| `ram_used_gb` | integer | RAM currently in use |
| `ram_total_gb` | integer | Total available RAM |
| `storage_used_gb` | integer | Storage currently in use |
| `storage_total_gb` | integer | Total available storage |
| `cpu_percentage` | integer | CPU utilization % |
| `recorded_at` | timestamp | When this reading was taken |

### Health Thresholds (NodeMonitoring::isHealthy())

```
Healthy when:
  - RAM usage ≤ 85%
  - Storage usage ≤ 90%
  - Uptime ≥ 95%

Degraded when ANY threshold is exceeded:
  - RAM > 85% OR
  - Storage > 90% OR
  - Uptime < 95%

Alert conditions:
  - RAM > 90%: "RAM critically high"
  - Storage > 95%: "Storage critically high"
  - Uptime < 90%: "Uptime critically low"
```

---

## The CheckNodeHealthCommand

**Cron Schedule:** `*/5 * * * *` (every 5 minutes)  
**Database Seed:** `CronJobSeeder` (auto-enabled)  
**Command:** `php artisan cron:check-node-health`

### What It Does

1. **Queries** all active monitored nodes (`is_active = true`, type in ['container_host', 'database_server'])
2. **Evaluates** each node's `last_heartbeat_at`
3. **Updates** node status based on heartbeat age
4. **Sends Alert Email** if a container node goes offline (lists affected containers)
5. **Logs** all transitions (offline/degraded/online)

### Alert Email Template

When a container node goes offline:

```
Subject: URGENT: Container Node {hostname} Is Offline

Body:
  - Node Details (hostname, IP, status, heartbeat time)
  - Affected Services (count + list of containers)
  - Migration Instructions (how to move services to another node)
```

### Cron Log Location

View cron execution history:

```bash
# Check cron status
php artisan cron:status

# View latest log
php artisan cron:show check-node-health

# Manual trigger (for testing)
php artisan cron:check-node-health
```

---

## Container Metrics Collection

### Separate System: CollectContainerMetricsCommand

**Cron Schedule:** `*/5 * * * *` (every 5 minutes)  
**What it does:** Collects individual container CPU/memory/I/O via SSH `docker stats`

This is **independent** from node heartbeats:
- Nodes send heartbeats (node-level metrics)
- The platform SSH's into nodes to collect container-level metrics
- Both systems run on the same 5-minute interval but are separate

**Not tied to heartbeat status** — metrics collection happens via SSH regardless of heartbeat state.

---

## Currently Missing: Active Heartbeat Sender

### Problem

The heartbeat endpoint exists and works, but **there's no system in place for nodes to actively send heartbeats**. 

The platform has:
- ✅ Heartbeat receiver (endpoint)
- ✅ Status checker (cron job)
- ✅ Monitoring data storage (NodeMonitoring table)
- ✅ Alert system (email on offline)

But nodes lack:
- ❌ A script/service to send heartbeats
- ❌ A cron job on the node to periodically POST heartbeat data
- ❌ Documentation on how to set it up

### What Should Happen

On each container node, a **cron job should run every 2–3 minutes** (more frequent than the 5-minute checker for safety margin):

```bash
# Run on container node, every 2 minutes
*/2 * * * * /usr/local/bin/send-heartbeat.sh

# send-heartbeat.sh should:
# 1. Collect node uptime, RAM, storage, CPU
# 2. POST to /admin/nodes/{id}/heartbeat with metrics
# 3. Handle failures gracefully (retry, log errors)
```

---

## Monitoring Dashboard (Admin View)

### Node Show Page (`admin.nodes.show`)

Displays:

**Real-time Status Card:**
- Node status (online/offline/degraded/maintenance)
- Last heartbeat age (e.g., "2 minutes ago")

**Resource Utilization:**
- CPU percentage & available cores
- RAM usage (GB/total) & percentage
- Storage usage (GB/total) & percentage

**24h Monitoring Dashboard** (only for `container_host` and `database_server`):
- Uptime gauge with color-coded health
- RAM usage gauge with threshold indicators
- Storage usage gauge with threshold indicators
- Alert banner if any threshold is exceeded

**Monitoring History Table:**
- Lists last 20 monitoring readings
- Time, uptime %, RAM %, storage %, health status
- Color-coded by health threshold

---

## Node Status Colors & Meanings

| Status | Color | Meaning |
|--------|-------|---------|
| **Online** | 🟢 Emerald | Heartbeat < 5 min, healthy |
| **Degraded** | 🟡 Amber | Heartbeat 5–15 min OR resource threshold exceeded |
| **Offline** | 🔴 Red | No heartbeat for 15+ min or never received |
| **Maintenance** | 🔵 Blue | Manually set by admin |

---

## Key Code Locations

| What | Where |
|------|-------|
| Node model | `app/Models/Node.php` |
| NodeMonitoring model | `app/Models/NodeMonitoring.php` |
| Heartbeat endpoint | `app/Http/Controllers/Admin/NodeController::heartbeat()` |
| Status checker cron | `app/Console/Commands/CheckNodeHealthCommand.php` |
| Cron seeder | `database/seeders/CronJobSeeder.php` |
| Node show view | `resources/views/admin/nodes/show.blade.php` |
| Container metrics cron | `app/Console/Commands/CollectContainerMetricsCommand.php` |

---

## Database Schema

### nodes table (relevant columns)

```sql
- id (PK)
- name
- hostname
- ip_address
- type (enum: dedicated_server, container_host, load_balancer, database_server)
- status (enum: online, offline, degraded, maintenance)
- last_heartbeat_at (timestamp, nullable)
- last_health_check_at (timestamp, nullable)
- is_active (boolean)
- cpu_cores, ram_gb, storage_gb
- cpu_used, ram_used_gb, storage_used_gb
```

### node_monitorings table

```sql
- id (PK)
- node_id (FK → nodes)
- uptime_percentage (integer 0–100)
- ram_used_gb, ram_total_gb
- storage_used_gb, storage_total_gb
- cpu_percentage
- recorded_at (timestamp)
```

---

## Next Steps / TODO

To complete the monitoring system:

1. **Create node heartbeat sender script** (`/usr/local/bin/send-heartbeat.sh`)
   - Collect system metrics (uptime, free RAM, disk space, CPU usage)
   - POST to `/admin/nodes/{id}/heartbeat` with auth token
   - Handle network failures gracefully
   - Log to a file for debugging

2. **Document node setup** (update setup-container-node.sh)
   - Add heartbeat script installation
   - Add cron job registration
   - Include authentication token generation

3. **Add authentication** to heartbeat endpoint (optional but recommended)
   - Current endpoint requires HTTP auth (not visible in code—check middleware)
   - Document how nodes should authenticate (API token? Basic auth?)

4. **Add heartbeat status page** to admin dashboard
   - Show all nodes with last heartbeat times
   - Highlight stale/offline nodes
   - Quick manual test/trigger heartbeat button

5. **Implement failover logic** (optional)
   - When container node goes offline, offer admin option to:
     - Migrate containers to another node
     - Retry connectivity
     - Manually restore status

---

## Troubleshooting

### Node Shows "Offline" But Server is Running

**Cause:** Node hasn't sent a heartbeat in 15+ minutes.

**Fix:**
1. SSH into the node and check if heartbeat script is running
2. Check if `/usr/local/bin/send-heartbeat.sh` exists and is executable
3. Review `/var/log/heartbeat.log` for errors
4. Manually test heartbeat: `curl -X POST http://platform/admin/nodes/{id}/heartbeat`
5. Check node's network connectivity to platform

### Monitoring Data Shows "Never"

**Cause:** Node has sent heartbeats but no monitoring data.

**Fix:**
1. Check if heartbeat script is sending metrics data
2. Verify metrics collection is accurate on the node
3. Manually POST heartbeat with sample data:
   ```bash
   curl -X POST http://platform/admin/nodes/1/heartbeat \
     -H "Content-Type: application/json" \
     -d '{
       "uptime_percentage": 99,
       "ram_used_gb": 32,
       "ram_total_gb": 64,
       "storage_used_gb": 500,
       "storage_total_gb": 1800
     }'
   ```

### Cron Job Not Running

**Check:**
```bash
# See if cron job is registered
php artisan cron:status

# Run manually to test
php artisan cron:check-node-health

# View cron log
tail -f storage/logs/laravel.log | grep "check-node-health"
```

---

## Testing Heartbeat System

### Manual Test

```bash
# As admin user on the platform

# 1. Register test node in /admin/nodes
# 2. Note the node ID (e.g., ID=5)

# 3. SSH into the node and send test heartbeat:
curl -X POST http://your-platform.com/admin/nodes/5/heartbeat \
  -H "Content-Type: application/json" \
  -d '{
    "uptime_percentage": 98,
    "ram_used_gb": 16,
    "ram_total_gb": 64,
    "storage_used_gb": 200,
    "storage_total_gb": 1800,
    "cpu_percentage": 25
  }'

# 4. Check response
# Expected: {"success": true, "message": "Heartbeat recorded."}

# 5. Go to admin node detail page
# Last Heartbeat should now show "just now" or "seconds ago"
# Status should be "Online"
# 24h Monitoring section should display the metrics

# 6. Wait 15 minutes without sending another heartbeat
# Node status should change to "Degraded" (after 5 min)
# Node status should change to "Offline" (after 15 min)
```

### Simulated Offline Scenario

```bash
# Stop sending heartbeats for 15+ minutes
# Monitor the admin dashboard

# Expected behavior:
# - At 5 min: status → degraded
# - At 15 min: status → offline (alert email sent)
# - Services list shows containers deployed on this node
```
