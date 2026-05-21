# Container Host SSH Configuration Guide

## Problem

When users click **Stop**, **Start**, or **Restart** buttons on container dashboards (`/my/services/{id}/container`), they were receiving a generic "Failed to stop/restart container" error. The root cause is that container host nodes (servers) were not configured with SSH credentials required to execute Docker commands remotely.

## Root Cause

Container management operations require SSH access to the hosting node to execute Docker Compose commands:
- `docker compose stop` - Stop container
- `docker compose start` - Start container  
- `docker compose restart` - Restart container
- `docker compose down` - Terminate container

If the node doesn't have SSH credentials configured in the database, the SSH connection fails silently, and users see a generic error.

## Solution

### 1. Configure Node SSH Credentials

Use the new Artisan command to configure SSH credentials for container host nodes:

#### Option A: Configure Specific Node
```bash
php artisan node:configure-ssh --node-id=5
```

Or by hostname:
```bash
php artisan node:configure-ssh --hostname=container-02.internal
```

#### Option B: Configure All Nodes Missing Credentials
```bash
php artisan node:configure-ssh --all
```

#### Option C: Interactive Mode
```bash
php artisan node:configure-ssh
```

### 2. SSH Credential Setup

When configuring a node, you'll be asked for:

1. **SSH Username** (default: root)
2. **Authentication Method**:
   - **Password**: Simple password authentication (encrypted in database)
   - **Private Key**: RSA/Ed25519 SSH key for key-based auth

### 3. Connection Test

The command will automatically test the SSH connection after configuration to verify credentials are correct:

```
Testing SSH connection...
✓ SSH connection successful
Output: SSH connection successful
Linux container-02 5.10.0-1-amd64 #1 SMP Debian 5.10.3-1 (2020-12-31) x86_64 GNU/Linux
```

## Technical Changes

### Files Modified

1. **app/Http/Controllers/Customer/ContainerController.php**
   - Added pre-flight validation in `start()`, `stop()`, `restart()` methods
   - Now checks node has SSH credentials before attempting operations
   - Shows detailed error messages instead of generic failures
   - Example: "Container host 'container-02' is not properly configured (missing SSH credentials). Please contact support."

2. **app/Services/Provisioning/ContainerDeploymentService.php**
   - Added `validateNodeSSHCredentials(Node $node)` method
   - Called by `suspend()`, `unsuspend()`, `restart()`, `terminate()` methods
   - Provides descriptive error messages with node hostname and what credentials are missing

3. **app/Console/Commands/ConfigureNodeSSH.php** (NEW)
   - Interactive Artisan command for configuring node SSH credentials
   - Supports single node, multiple nodes, or all-at-once configuration
   - Includes SSH connection testing
   - Encrypts passwords using Laravel's encryption

### Error Message Improvements

**Before:**
```
Failed to stop container
```

**After:**
```
Container host is not properly configured (missing SSH credentials). Please contact support.
```

**With detailed logging:**
```
Container host 'container-02.internal' is not configured: missing SSH username. 
An administrator needs to configure SSH credentials for this node.
```

## Database Schema

Nodes table has the following SSH-related fields:

```sql
- ssh_username (string, nullable)
- ssh_password (string, encrypted, nullable)
- da_login_key (string, encrypted, nullable) 
- ssh_port (string, default: 22)
```

At least one of `ssh_password` or `da_login_key` is required if `ssh_username` is set.

## Security Notes

1. **Passwords are encrypted** - SSH passwords are stored encrypted using Laravel's encryption key
2. **Keys are encrypted** - SSH private keys are stored encrypted in the database
3. **No plain text** - SSH credentials are never logged or displayed in plain text
4. **Per-node credentials** - Each node can have different credentials
5. **Fallback auth** - SSH service tries password first, then falls back to SSH key if available

## Testing

To test if a node is properly configured:

```bash
php artisan tinker
>>> $node = App\Models\Node::find(5);
>>> echo $node->ssh_username; // Should return username
>>> echo $node->ssh_password ? '✓' : '✗'; // Check if password is set
>>> echo $node->da_login_key ? '✓' : '✗'; // Check if key is set
```

## Troubleshooting

### Issue: SSH connection test fails

**Cause**: Invalid credentials or network connectivity issues

**Solution**:
1. Verify IP address is correct: `ping <node-ip>`
2. Verify SSH port is open: `nc -zv <node-ip> 22`
3. Test SSH manually: `ssh -p <port> <username>@<node-ip>`
4. Check node is running and has SSH service enabled

### Issue: "SSH authentication failed"

**Cause**: Wrong username, password, or key format

**Solution**:
1. Re-run the configuration command: `php artisan node:configure-ssh --node-id=<id>`
2. Verify username matches actual SSH user on the server
3. Ensure password/key is correct and matches the user

### Issue: "Command exited with status..."

**Cause**: SSH connection works, but Docker command failed

**Solution**:
1. Verify Docker and Docker Compose are installed on the node
2. Verify SSH user has permissions to run Docker commands (usually requires `sudo` or being in `docker` group)
3. Check node logs for Docker errors

## Monitoring

### Check Node SSH Status

Visit admin panel → Nodes section to view each node's SSH configuration status.

### Monitor SSH Operations

SSH operations are logged in `storage/logs/laravel.log`:

```
[2026-05-21 14:30:00] local.INFO: Container restarted for service 67
[2026-05-21 14:30:01] local.INFO: Container suspended for service 68
```

### Check Deployment Status

```bash
php artisan tinker
>>> $deployment = App\Models\ContainerDeployment::find(1);
>>> $deployment->status; // running, stopped, deploying, failed
>>> $deployment->last_status_check_at; // When status was last checked
```

## Related Documentation

- [[DirectAdmin API Endpoints]] - How DirectAdmin nodes work
- [[Phase 1 Container Deployment]] - Container deployment architecture
- [[Dedicated Container Server]] - Production container server setup
