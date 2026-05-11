# DirectAdmin Node Monitoring Investigation

## Current Status

### What DirectAdmin Nodes Are

DirectAdmin nodes are **shared hosting control panel servers** that provide:
- Hosting account creation/management
- Domain management
- Package management
- Multi-user support with role-based access

**Node Type:** `directadmin`  
**Database Fields:**
- `api_url` - DirectAdmin API endpoint (e.g., `http://server.com:2222`)
- `da_port` - DirectAdmin port (default 2222)
- `da_login_key` - Encrypted DirectAdmin login key (used for API auth)
- `ssh_username` - SSH access (optional, for system monitoring)
- `ssh_password` - SSH password (optional)
- `api_token` - Generic API token field (unused for DA)

---

## Current Monitoring Status

### What's Implemented ❌

DirectAdmin nodes are **NOT** currently included in the monitoring system:

| Feature | Container/Database | DirectAdmin |
|---------|-------------------|------------|
| Automatic polling | ✅ Every 2 minutes | ❌ No |
| Heartbeat support | ✅ Via script | ❌ No |
| System metrics | ✅ CPU, RAM, storage | ❌ No |
| Connection testing | ✅ Test Health button | ✅ Test Connection (API only) |
| Metrics dashboard | ✅ 24h Monitoring | ❌ No |
| Status tracking | ✅ Online/Degraded/Offline | ⚠️ Online only (manual test) |

### Current DirectAdmin Testing

**Method:** API-based (DirectAdminService)

```php
// Current testConnection() for DirectAdmin:
$service = new DirectAdminService($node);
$packages = $service->getPackages();  // Only tests API connectivity
$node->update(['status' => 'online']); // Sets status only if API responds
```

**Issues:**
- ❌ Only tests if DirectAdmin API is responding (not actual server health)
- ❌ If server is overloaded but API responds, still marked online
- ❌ No system resource monitoring (CPU, RAM, storage)
- ❌ No historical data or trending
- ❌ Doesn't detect degraded conditions

---

## DirectAdmin Architecture

### Access Methods

DirectAdmin servers have **two ways to connect:**

```
1. DirectAdmin API (HTTP/HTTPS)
   └─ URL: http://server.com:2222/api
   └─ Auth: BasicAuth with login key
   └─ Purpose: Account management, package sync
   └─ Limitation: Only application-level metrics

2. SSH Access (Optional)
   └─ Port: Usually 22
   └─ Auth: SSH username/password
   └─ Purpose: System-level monitoring (uptime, disk, memory)
   └─ Advantage: Same as container/database servers
```

### What We Can Monitor

**Via SSH (System Level):**
- ✅ CPU cores and usage
- ✅ RAM total and used
- ✅ Disk storage usage
- ✅ System uptime
- ✅ Load average
- ✅ Running processes

**Via DirectAdmin API (Application Level):**
- ✅ Number of accounts
- ✅ Disk usage per account
- ✅ Resource allocation per package
- ✅ License status
- ✅ DirectAdmin service status
- ❓ Server resource info (API may expose some stats)

---

## Recommended Approach

### Option 1: SSH-Based Monitoring (Recommended) ⭐

**Use the existing polling system for DirectAdmin servers via SSH**

**Advantages:**
- ✅ Reuse existing PollNodeHealthCommand code
- ✅ Same monitoring as container/database servers
- ✅ No DirectAdmin API knowledge needed
- ✅ Works on any DirectAdmin server with SSH access
- ✅ Consistent metrics across all node types
- ✅ Immediate implementation (just add to isMonitored())

**Implementation:**
1. Update Node::isMonitored() to include 'directadmin'
2. Update PollNodeHealthCommand to handle DirectAdmin
3. Update TestHealth button to support DirectAdmin
4. Update dashboard to show monitoring for DirectAdmin

**Code Change Required:**
```php
// In app/Models/Node.php
public function isMonitored(): bool
{
    return in_array($this->type, ['container_host', 'database_server', 'directadmin']);
}
```

---

### Option 2: DirectAdmin API-Based Monitoring

**Use DirectAdmin API to get server statistics**

**Advantages:**
- ✅ No need for SSH access
- ✅ Native integration with DirectAdmin
- ✅ Can get application-level metrics

**Disadvantages:**
- ❌ DirectAdmin API may not expose all system metrics
- ❌ Would need separate code path in polling command
- ❌ More complex implementation
- ❌ API limitations vary by DirectAdmin version
- ❌ Can't get real system metrics (CPU%, memory%, disk%)

**Note:** DirectAdmin API has limited system statistics. Would need to verify what's available.

---

### Option 3: Hybrid Approach (Best)

**Use SSH for system metrics + DirectAdmin API for application metrics**

```
PollNodeHealthCommand:
  For each DirectAdmin node:
    1. SSH in for system metrics (CPU, RAM, storage, uptime)
    2. Query DirectAdmin API for application health (accounts, packages, license)
    3. Record both in NodeMonitoring
    4. Set status based on both metrics
```

**Advantages:**
- ✅ Complete picture of node health
- ✅ Detects both system and application issues
- ✅ Can alert if DirectAdmin service is down (API fails) but system is up
- ✅ Can alert if system is degraded even if DirectAdmin API responds
- ✅ Most informative monitoring

---

## Implementation Roadmap

### Phase 1: Basic Monitoring (Immediate) ⭐

**Enable system-level monitoring for DirectAdmin via SSH**

**Changes:**
```
1. Update Node model:
   - Add 'directadmin' to isMonitored()

2. Update PollNodeHealthCommand:
   - Add 'directadmin' to node type filter
   - Reuse existing SSH metric collection

3. Update NodeController:
   - Extend testHealth() to support directadmin type
   - Or create separate testDirectAdminHealth() method

4. Update View:
   - Show Test Health button for directadmin nodes
   - Add monitoring dashboard for directadmin nodes

5. Update Documentation:
   - Add DirectAdmin to polling/heartbeat guides
```

**Effort:** ~2 hours  
**Complexity:** Low (reuse existing code)

---

### Phase 2: Enhanced Monitoring (Future)

**Add DirectAdmin API metrics**

**Changes:**
```
1. Extend DirectAdminService:
   - Add getServerStats() method
   - Add getAccountStats() method
   - Add getLicenseStatus() method

2. Update PollNodeHealthCommand:
   - Call DirectAdminService for API stats
   - Combine with SSH metrics
   - Record in extended NodeMonitoring fields

3. Update NodeMonitoring model:
   - Add DirectAdmin-specific fields
   - (directadmin_accounts, directadmin_resources, etc.)

4. Update Dashboard:
   - Show DirectAdmin-specific metrics
   - Alert if API fails but system is up
```

**Effort:** ~4 hours  
**Complexity:** Medium (DirectAdmin API integration)

---

## Key Findings

### 1. DirectAdmin Servers Are Just Linux Servers

DirectAdmin runs on standard Linux (CentOS, Ubuntu, etc.). It's just a web application running on top.

**This means:**
- ✅ SSH access can monitor system resources
- ✅ Same monitoring as any other Linux server
- ✅ No special handling needed for OS-level metrics

### 2. Current Test Only Checks API

The existing `testConnection()` method only:
- Calls DirectAdmin API
- Checks if it responds with packages
- Sets status to 'online' if API works

**Problem:** Server could be overloaded, low on disk, but API still responds → Marked "online" when degraded.

### 3. SSH Access Is Critical for Full Monitoring

For DirectAdmin nodes to be fully monitored, they **must have SSH credentials configured:**

```
Required fields for DirectAdmin monitoring:
✅ ssh_username (e.g., "root")
✅ ssh_password (encrypted)
```

**Current state:** Not all DirectAdmin nodes may have these set.

### 4. isMonitored() Excludes DirectAdmin

```php
// Current code (app/Models/Node.php:78-81)
public function isMonitored(): bool
{
    return in_array($this->type, ['container_host', 'database_server']);
    // ❌ DirectAdmin is excluded
}
```

**This prevents DirectAdmin from being monitored.**

---

## What DirectAdmin API Provides

The existing DirectAdminService can get:

```php
// Current methods
$service->getPackages()        // List hosting packages
$service->getConnectionDiagnostics()  // Connection info

// Stubbed (not implemented)
$service->createHostingAccount()
$service->suspendAccount()
$service->terminateAccount()
$service->syncPackages()
```

**Missing methods:**
- getServerStats() - Server resource usage
- getAccountStats() - Per-account usage
- getLicenseStatus() - License info
- getSystemHealth() - Overall health

---

## DirectAdmin API Endpoints Available

Based on DirectAdmin documentation, the API provides:

```
CMD_API_STATS - Server statistics
CMD_API_ADMIN_STATS - Admin account stats
CMD_API_RESELLER_STATS - Reseller stats
CMD_API_USER_STATS - User account stats
CMD_API_SYSTEM - System information
```

**Potential:** These endpoints may provide system metrics we can use.

---

## Prerequisites for Monitoring DirectAdmin

For both polling approaches, each DirectAdmin node needs:

| Requirement | Current Status | Priority |
|-------------|----------------|----------|
| SSH username configured | ❓ Check database | High |
| SSH password set | ❓ Check database | High |
| SSH port accessible | ❓ Depends on firewall | High |
| DirectAdmin API accessible | ✅ Already tested | Medium |
| Node marked is_active=true | ❓ Check database | High |

**Action:** Query database to see what DirectAdmin nodes exist and what credentials are set.

---

## Database Check

Run this to see current DirectAdmin node setup:

```sql
SELECT id, name, ip_address, type, status, is_active, 
       ssh_username, CASE WHEN ssh_password IS NOT NULL THEN 'SET' ELSE 'NOT SET' END as ssh_password_status,
       CASE WHEN da_login_key IS NOT NULL THEN 'SET' ELSE 'NOT SET' END as da_key_status
FROM nodes
WHERE type = 'directadmin';
```

---

## Summary of Findings

| Aspect | Finding | Action |
|--------|---------|--------|
| **Are DirectAdmin nodes monitored?** | No, excluded from polling | Add to isMonitored() |
| **Can they be monitored via SSH?** | Yes, they're Linux servers | Use existing SSH code |
| **Can they be monitored via API?** | Maybe, need to verify DirectAdmin API | Phase 2 enhancement |
| **Current test method** | API-based only | Limited |
| **Recommended approach** | SSH-based system metrics | Immediate implementation |
| **Complexity** | Low (reuse existing code) | 2-3 hours work |
| **Breaking changes?** | None | Safe to implement |

---

## Recommended Next Steps

### Step 1: Enable SSH Monitoring (Immediate)

```php
// Update app/Models/Node.php line 80
return in_array($this->type, ['container_host', 'database_server', 'directadmin']);
```

### Step 2: Extend PollNodeHealthCommand

Update polling command to also handle DirectAdmin nodes (same SSH approach).

### Step 3: Update testHealth() in NodeController

Add support for directadmin type to testHealth() method.

### Step 4: Update Dashboard Views

Show monitoring data for DirectAdmin nodes like container/database nodes.

### Step 5: Documentation

Update ACTIVE_NODE_POLLING.md and SETUP_NODE_HEARTBEATS.md to include DirectAdmin.

---

## Rollout Plan

**Phase 1 (This Week):**
1. Update isMonitored() to include directadmin ✅
2. Extend PollNodeHealthCommand for directadmin ✅
3. Add directadmin support to testHealth() ✅
4. Update views to show monitoring for directadmin ✅
5. Update documentation ✅

**Phase 2 (Later):**
1. Extend DirectAdminService with stats methods
2. Add DirectAdmin API metrics to polling
3. Create DirectAdmin-specific monitoring dashboard
4. Alert on API failures vs system failures

---

## Files That Need Changes

```
1. app/Models/Node.php
   - Update isMonitored() method

2. app/Console/Commands/PollNodeHealthCommand.php
   - Add directadmin to type filter
   - (reuse existing SSH code)

3. app/Http/Controllers/Admin/NodeController.php
   - Add directadmin support to testHealth()
   - Or extend testConnection()

4. resources/views/admin/nodes/show.blade.php
   - Show monitoring dashboard for directadmin
   - Show Test Health button for directadmin

5. docs/ACTIVE_NODE_POLLING.md
   - Add DirectAdmin section
   - Document that DA nodes use SSH monitoring

6. docs/SETUP_NODE_HEARTBEATS.md
   - Add DirectAdmin section
   - Note that DA nodes need SSH credentials
```

---

## Questions to Answer

1. **Are there existing DirectAdmin nodes?**
   - If yes: Do they have SSH credentials configured?
   - If no: When will they be added?

2. **Is SSH access available on DirectAdmin servers?**
   - DirectAdmin typically runs on servers where you have SSH
   - Should be yes, but confirm

3. **Should we use SSH or API for monitoring?**
   - Recommendation: SSH for system metrics (Phase 1)
   - Plus API for application metrics (Phase 2)

4. **What's the priority?**
   - Can DirectAdmin nodes exist without monitoring?
   - Or should this be implemented immediately?

---

## Conclusion

**DirectAdmin nodes are currently NOT monitored.** They exist in the system and can have packages synced, but there's no health tracking, no system metrics, and no degradation detection.

**Implementing SSH-based monitoring for DirectAdmin is straightforward:** Just add 'directadmin' to the node types being monitored. The existing PollNodeHealthCommand can handle them the same way as container/database servers.

**Recommend:** Phase 1 implementation immediately (simple code changes) + Phase 2 later for advanced DirectAdmin-specific metrics.

