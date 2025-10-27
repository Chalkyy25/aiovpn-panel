 OpenVPN Path Fix - October 27, 2025

## Issue

Deployment was failing with errors:
1. **Port conflict**: Management port 7505 already in use
2. **Missing files**: `Cannot pre-load keyfile (ta.key)`

## Root Cause

1. **Legacy service conflict**: Old `openvpn@server` service (uses `/etc/openvpn/` path) was still running and holding port 7505
2. **Relative paths**: Server config used relative paths (`ca.crt`, `ta.key`) but systemd's `WorkingDirectory=/etc/openvpn/server` meant they weren't found

## Solutions Implemented

### 1. Updated Deployment Script

**File:** `resources/scripts/deploy-openvpn.sh`

**Changes:**
- ✅ Stop and disable legacy `openvpn@server` and `openvpn@server-tcp` services before starting modern ones
- ✅ Use absolute paths for all certificate/key files in configs:
  - `ca /etc/openvpn/ca.crt` (was `ca ca.crt`)
  - `cert /etc/openvpn/server.crt` (was `cert server.crt`)
  - `key /etc/openvpn/server.key` (was `key server.key`)
  - `dh /etc/openvpn/dh.pem` (was `dh dh.pem`)
  - `tls-crypt /etc/openvpn/ta.key` (was `tls-crypt ta.key`)

### 2. Created Fix Script for Existing Servers

**File:** `fix-openvpn-paths.sh`

Repairs already-deployed servers by:
- Stopping legacy services
- Fixing paths in existing configs
- Restarting modern services

### 3. Verification on Germany Server

```bash
✅ UDP service: running (port 1194, mgmt 7505)
✅ TCP service: running (port 443, mgmt 7506)
✅ Monitoring timer: active (2s interval)
✅ No port conflicts
```

## Technical Details

### Modern vs Legacy SystemD Services

| Service Name | Config Path | Working Directory | Status |
|-------------|-------------|-------------------|---------|
| `openvpn@server` (legacy) | `/etc/openvpn/` | `/etc/openvpn/` | ❌ Should be disabled |
| `openvpn-server@server` (modern) | `/etc/openvpn/server/` | `/etc/openvpn/server/` | ✅ Should be used |

### Why Absolute Paths Matter

When systemd starts `openvpn-server@server.service`:
- Sets `WorkingDirectory=/etc/openvpn/server`
- Relative path `ca.crt` resolves to `/etc/openvpn/server/ca.crt` ❌
- Absolute path `/etc/openvpn/ca.crt` works regardless ✅

## Files to Deploy

### For New Servers
Use updated `deploy-openvpn.sh` - it now handles everything correctly.

### For Existing Servers
1. Run `fix-openvpn-paths.sh` first to fix paths and stop legacy services
2. Then optionally run `fix-dashboard-monitoring.sh` to upgrade monitoring

## Deployment Commands

### Fix Existing Germany Server
```bash
cat fix-openvpn-paths.sh | ssh root@5.22.212.177 "bash -s"
```

### Fix Spain Server
```bash
cat fix-openvpn-paths.sh | ssh root@5.22.218.134 "bash -s"
```

### Fix UK Server
```bash
cat fix-openvpn-paths.sh | ssh root@83.136.254.231 "bash -s"
```

## Verification Steps

After running the fix:

```bash
# Check services are running
systemctl is-active openvpn-server@server
systemctl is-active openvpn-server@server-tcp

# Check management ports
netstat -tlnp | grep -E ':(7505|7506)'

# Test monitoring
/usr/local/bin/ovpn-mgmt-push.sh

# Check legacy services are stopped
systemctl is-active openvpn@server  # should be "inactive"
```

## Future Deployments

All new deployments using the updated `deploy-openvpn.sh` will:
- ✅ Automatically stop legacy services
- ✅ Use absolute paths in all configs
- ✅ Use modern `openvpn-server@` systemd units
- ✅ Have merged UDP+TCP monitoring from the start

## Related Changes

This fix complements the merged monitoring solution (see `CHANGELOG-MERGED-MONITORING.md`):
- Old approach: Separate timers reading status files
- New approach: Single timer querying management interfaces
- Real-time: client-connect/disconnect hooks

## Lessons Learned

1. **Always use absolute paths** when systemd sets WorkingDirectory
2. **Check for legacy services** before enabling modern ones
3. **Management ports** are superior to status files for monitoring
4. **Test on existing server** before updating deployment script for new servers
