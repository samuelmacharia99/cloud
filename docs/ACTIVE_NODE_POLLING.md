# Active Node Polling (Alternative to Heartbeats)

## Overview

Instead of requiring each node to send heartbeats, the platform can **actively poll node health** every 2 minutes directly from the platform server. This is simpler than the heartbeat approach and doesn't require any scripts on your container nodes.

**Comparison:**

| Aspect | Heartbeat Approach | Polling Approach |
|--------|-------------------|-----------------|
| **Where monitoring runs** | On each node (decentralized) | On platform server (centralized) |
| **Scripts needed on nodes** | ✅ Yes (send-heartbeat.sh) | ❌ No |
| **Cron setup on nodes** | ✅ Yes | ❌ No |
| **Frequency** | Every 2-3 minutes | Every 2 minutes |
| **Who initiates** | Node → Platform | Platform → Node (SSH) |
| **Complexity** | Medium (multi-node setup) | Low (single server setup) |
| **Network load** | Light (nodes send minimal data) | Slightly heavier (platform pulls metrics) |
| **Monitoring speed** | Slower (passive) | Faster (active probing) |
| **CPU on nodes** | Minimal | Minimal (SSH overhead) |

---

## How It Works

```
Platform Cron (every 2 minutes):
  ↓
For each active container/database/directadmin node:
  ├─ SSH into node
  ├─ Run: uptime, free, df, nproc, load avg
  ├─ Parse metrics
  ├─ Record in NodeMonitoring table
  ├─ Update node status (online/degraded/offline)
  └─ If error → Mark offline + log error
  ↓
Repeat every 2 minutes
```

**Result:**
- Dashboard shows real-time node status
- No heartbeat dependency
- Centralized monitoring from platform

---

## Setup Instructions

### Step 1: Run Seeder

The polling command has already been added to the cron seeder. Update your database:

```bash
php artisan db:seed --class=CronJobSeeder
```

This will:
- ✅ Create "Poll Node Health" cron job (enabled)
- ✅ Disable "Check Node Health" job (heartbeat-based)

**Verify:**

```bash
php artisan cron:status | grep -i "poll\|check"
```

Should show:
```
Poll Node Health          | cron:poll-node-health   | */2 * * * * | Enabled
Check Node Health         | cron:check-node-health  | */5 * * * * | Disabled
```

### Step 2: Enable the Cron Job (Manually)

If the seeder doesn't enable it automatically:

```bash
# Via tinker
php artisan tinker
> CronJob::where('command', 'cron:poll-node-health')->update(['enabled' => true])
> CronJob::where('command', 'cron:check-node-health')->update(['enabled' => false])
> exit
```

Or update via the admin panel:
1. Go to `/admin/settings#cron`
2. Find "Poll Node Health"
3. Toggle **Enabled** to ON
4. Find "Check Node Health"
5. Toggle **Enabled** to OFF
6. Save

### Step 3: Ensure SSH Credentials Are Set

For polling to work, **each node must have SSH credentials configured** in the platform:

```bash
# Go to /admin/nodes
# For each node, click Edit and ensure:
✅ SSH Username (e.g., "root")
✅ SSH Password OR DirectAdmin Login Key
```

**Test credentials by clicking "Test Health"** - if it succeeds, polling will work.

### Step 4: Wait for Polling to Start

The polling will start automatically:
- **First run:** When the next 2-minute mark arrives (e.g., at 14:02, 14:04, 14:06, etc.)
- **Or manually:** `php artisan cron:poll-node-health`

### Step 5: Monitor the Dashboard

After 2-3 minutes, check the node detail page:
- **Last Heartbeat** should update to "just now"
- **24h Monitoring Dashboard** should show metrics
- **Status** should show Online/Degraded/Offline

---

## Testing Polling

### Manual Test

Run the polling command directly:

```bash
php artisan cron:poll-node-health
```

**Expected output:**
```
Polled 3 node(s). 3 healthy.
```

**View detailed logs:**

```bash
tail -20 storage/logs/laravel.log | grep "NODE"
```

Sample output:
```
[2026-05-10 14:02:15] local.INFO: NODE HEALTHY: Container Host 1 - RAM: 50%, Storage: 30%, CPU: 25%
[2026-05-10 14:02:25] local.INFO: NODE HEALTHY: Container Host 2 - RAM: 45%, Storage: 28%, CPU: 20%
[2026-05-10 14:04:15] local.INFO: NODE DEGRADED: Database Server 1 - RAM: 88%, Storage: 92%, Uptime: 93%
```

### Cron Execution Log

View when polling runs via the cron system:

```bash
php artisan cron:show poll-node-health
```

### Platform Dashboard

1. Go to `/admin/nodes`
2. Click on a node
3. Verify:
   - ✅ Last Heartbeat is recent ("just now")
   - ✅ Node Status is "Online" or "Degraded"
   - ✅ 24h Monitoring shows metrics
   - ✅ Monitoring History has recent entries

---

## Node Status Based on Metrics

The polling command determines status as follows:

```
If SSH connection fails:
  Status = Offline
  Action = Log error

If SSH succeeds but metrics are collected:
  IF RAM > 85% OR Storage > 90% OR Uptime < 95%:
    Status = Degraded
    Log = "NODE DEGRADED: {reason}"
  ELSE:
    Status = Online
    Log = "NODE HEALTHY"
```

**Example thresholds:**
- ✅ Healthy: RAM 50%, Storage 60%, Uptime 99%
- 🟡 Degraded: RAM 88%, Storage 92%, Uptime 93%
- 🔴 Offline: SSH connection fails, no response, or timeout

---

## Advantages of Polling Approach

✅ **Simpler Setup**
- No scripts to deploy on nodes
- No cron jobs to configure on nodes
- Centralized control from one server

✅ **Faster Detection**
- Polls every 2 minutes (vs passive heartbeat waits)
- Proactively tests connectivity
- Detects issues immediately

✅ **No Node Configuration**
- Nodes don't need special setup
- Works with existing SSH access
- Just ensure SSH credentials are in platform

✅ **Better for Development**
- Test locally without complexity
- Easy to debug (see logs immediately)
- Manual testing with "Test Health" button

✅ **Reliable Monitoring**
- Platform controls the frequency
- No heartbeat dependency
- Active verification of connectivity

---

## Disadvantages of Polling Approach

⚠️ **More Network Traffic**
- Platform initiates SSH connection every 2 minutes per node
- For 10 nodes: 720 SSH connections/day
- For 100 nodes: 7,200 SSH connections/day

⚠️ **Platform Resource Usage**
- SSH connections consume CPU/memory on platform
- More database writes (monitoring records)
- Potential bottleneck with many nodes

⚠️ **Less Scalable**
- If you have 100+ nodes, polling becomes expensive
- Heartbeats are lighter (node sends minimal data)
- Better for small-to-medium deployments (< 50 nodes)

⚠️ **SSH Timeout Issues**
- If a node is slow, SSH may timeout
- Network latency affects polling frequency
- Heartbeats are more resilient

---

## When to Use Each Approach

### Use **Polling** when:
- ✅ You have < 50 nodes
- ✅ Nodes are in same network/low latency
- ✅ You want simplicity over scalability
- ✅ You prefer centralized control
- ✅ Nodes may not have internet access

### Use **Heartbeats** when:
- ✅ You have 50+ nodes
- ✅ Nodes are geographically distributed
- ✅ You want to minimize platform load
- ✅ You need passive, lightweight monitoring
- ✅ You can deploy scripts on nodes

---

## Switching Between Approaches

### From Heartbeats to Polling

```bash
# Disable heartbeat checking
php artisan tinker
> CronJob::where('command', 'cron:check-node-health')->update(['enabled' => false])
> CronJob::where('command', 'cron:poll-node-health')->update(['enabled' => true])

# Delete the heartbeat script from nodes (optional but recommended)
# ssh root@node-ip
# sudo rm /usr/local/bin/send-heartbeat.sh
# sudo crontab -e  # Remove the heartbeat cron line
```

### From Polling to Heartbeats

```bash
# Enable heartbeat checking
php artisan tinker
> CronJob::where('command', 'cron:poll-node-health')->update(['enabled' => false])
> CronJob::where('command', 'cron:check-node-health')->update(['enabled' => true])

# Deploy heartbeat script to each node (see SETUP_NODE_HEARTBEATS.md)
```

---

## Troubleshooting

### Problem: Polling doesn't run

**Check if cron job is enabled:**

```bash
php artisan cron:status | grep poll
```

If not enabled:
```bash
php artisan tinker
> CronJob::where('command', 'cron:poll-node-health')->update(['enabled' => true])
```

**Check if cron is processing jobs:**

```bash
# View cron execution log
tail -50 storage/logs/laravel.log | grep cron

# Manually trigger
php artisan cron:poll-node-health
```

### Problem: "SSH connection failed" errors

**SSH credentials are missing or wrong:**

1. Go to `/admin/nodes` → Click node → Edit
2. Verify:
   - ✅ SSH Username is set
   - ✅ SSH Password or Login Key is set
3. Click "Test Health" to verify connectivity

**Network connectivity issue:**

```bash
# From platform server, test SSH to node
ssh -v root@node-ip echo "test"

# Check firewall
sudo ufw status | grep 22
```

### Problem: Nodes show "Offline" after polling starts

**Most common cause:** SSH timeout (polling is too slow)

**Increase SSH timeout:**

Edit `app/Console/Commands/PollNodeHealthCommand.php`:

```php
// Change from 5 seconds to 10 seconds
$ssh->exec('echo "OK"', 10);  // was 5
```

**Or reduce polling frequency:**

Edit in admin panel: `/admin/settings#cron`
- Change "Poll Node Health" schedule from `*/2` (every 2 min) to `*/5` (every 5 min)

### Problem: High CPU usage on platform

**Polling is resource-intensive with many nodes.**

Options:
1. Reduce polling frequency: `*/5` instead of `*/2`
2. Reduce number of monitored nodes (set `is_active = false` for less critical ones)
3. Switch to heartbeat approach for better scalability

### Problem: Monitoring data has gaps

**If nodes are slow to respond, SSH may timeout and miss metrics.**

Fix:
1. Increase SSH timeout in PollNodeHealthCommand (see above)
2. Check node network latency: `ping node-ip`
3. Check if node is overloaded: click "Test Health" and see metrics

---

## Performance Tips

### Optimize for Many Nodes (50+)

```bash
# If you have many nodes, reduce polling frequency:
# Go to /admin/settings#cron
# "Poll Node Health" schedule: */5 * * * * (every 5 minutes instead of 2)
```

### Optimize for Few Nodes (< 10)

```bash
# With few nodes, keep aggressive polling:
# "Poll Node Health" schedule: */2 * * * * (every 2 minutes)
# Get metrics every 2 minutes for better responsiveness
```

### Monitor Platform Load

Check if polling is causing high load:

```bash
# Monitor platform CPU during polling
watch -n 1 'ps aux | grep artisan | grep poll'

# Check database query performance
php artisan tinker
> DB::enableQueryLog();
> // Run polling
> dd(DB::getQueryLog());
```

---

## Configuration Reference

**PollNodeHealthCommand.php (`app/Console/Commands/`)**

Key behaviors:
- Only polls nodes where `is_active = true`
- Only polls `container_host` and `database_server` types
- Skips nodes without SSH credentials configured
- Updates `last_heartbeat_at` on success (same as heartbeats)
- Records metrics to `node_monitorings` table
- Sets status: Online (healthy) → Degraded (thresholds exceeded) → Offline (SSH failed)
- Logs all results to `storage/logs/laravel.log`

**Thresholds:**
- RAM > 85% → Degraded
- Storage > 90% → Degraded
- Uptime < 95% → Degraded

---

## DirectAdmin Node Monitoring

**DirectAdmin nodes are now fully supported** in the active polling system!

### What Changed

- ✅ DirectAdmin nodes are now monitored via SSH every 2 minutes (just like container/database servers)
- ✅ System metrics collected: CPU, RAM, storage, uptime, load average
- ✅ "Test Health" button available on DirectAdmin node pages
- ✅ 24h Monitoring dashboard shows for DirectAdmin nodes
- ✅ Status automatically set to Online/Degraded/Offline based on metrics

### DirectAdmin Node Setup

For DirectAdmin servers to be monitored, ensure:

```
1. Go to /admin/nodes → Click DirectAdmin node → Edit
2. Configure SSH credentials:
   - SSH Username: (e.g., "root")
   - SSH Password: (your SSH password)
   - SSH Port: (usually 22)
3. Mark as "Active" (checkbox)
4. Save changes
5. Click "Test Health" to verify connectivity
```

### Important Notes

- DirectAdmin servers are standard Linux machines running the DirectAdmin control panel
- SSH access works exactly like container/database servers
- No special DirectAdmin API integration required for basic monitoring
- Node health is determined by system resources (CPU, RAM, storage, uptime)
- This is **Phase 1** (system metrics only). Phase 2 will add DirectAdmin-specific metrics (accounts, resources, etc.)

---

## Next Steps

1. ✅ Run the seeder: `php artisan db:seed --class=CronJobSeeder`
2. ✅ Enable polling in admin panel or via tinker
3. ✅ Verify SSH credentials are set for all nodes
4. ✅ Wait 2 minutes for first polling run
5. ✅ Check dashboard to see metrics update
6. ✅ Monitor logs: `tail -f storage/logs/laravel.log | grep NODE`

---

## FAQ

**Q: Can I use polling and heartbeats at the same time?**
A: Not recommended. The CheckNodeHealthCommand would override polling results. Choose one approach.

**Q: What if a node is offline during polling?**
A: Platform waits for SSH timeout (5 seconds by default), then marks node offline. Next poll retries.

**Q: Do I need heartbeat scripts if I use polling?**
A: No. Remove `/usr/local/bin/send-heartbeat.sh` and cron entries from all nodes.

**Q: How much traffic does polling generate?**
A: ~5-10 KB per poll per node. For 10 nodes every 2 min = ~3 MB/day.

**Q: Can polling work over slow networks?**
A: Yes, but you may need to increase SSH timeout or reduce polling frequency.

**Q: What if platform server goes down?**
A: Polling stops. Nodes can't be monitored (unlike heartbeats where nodes send data independently).

---

## Summary

| Aspect | Polling |
|--------|---------|
| Setup complexity | ⭐ Low (seeder + enable) |
| Scaling ability | ⭐⭐ Medium (good for < 50 nodes) |
| Monitoring latency | ⭐⭐⭐ Fast (2 min polls) |
| Node setup required | ⭐ None (SSH only) |
| Platform load | ⭐⭐ Moderate |
| Node types supported | Container, Database, DirectAdmin |
| Recommended for | Small-medium deployments |

Use polling for simplicity on small-to-medium deployments. Switch to heartbeats when you scale to 50+ nodes.
