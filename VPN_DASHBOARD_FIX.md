# VPN Dashboard Monitoring Fix - Oct 26, 2025

## Problem
Dashboard wasn't updating after adding TCP stealth servers (port 443/mgmt 7506).

## Root Causes Found

### 1. ❌ Deployment Status Mismatch
- **Issue**: Code looked for `deployment_status = 'succeeded'` but servers were marked as `'deployed'`
- **Files affected**:
  - `app/Livewire/Pages/Admin/VpnDashboard.php` (2 locations)
  - `app/Console/Commands/UpdateVpnStatus.php`

### 2. ❌ TCP Management Port Not Checked
- **Issue**: Monitoring only checked UDP port 7505, missed TCP port 7506
- **Files affected**:
  - `app/Console/Commands/UpdateVpnStatus.php`
  
### 3. ⚠️ Broadcast Server Not Running (Supervisor Issue)
- **Issue**: Soketi/Reverb websocket server not running → real-time updates fail
- **Evidence**: `Connection refused for URI http://127.0.0.1:8080`

### 4. ⚠️ Wrong Server IPs
- **Issue**: Spain used 5.22.177.143 (should be 5.22.218.134), UK used 5.22.214.56 (should be 83.136.254.231)
- **Fixed**: Updated IPs in database

## Fixes Applied

### Code Changes

**1. VpnDashboard.php** - Accept both deployment statuses:
```php
// Before:
->where('deployment_status', 'succeeded')

// After:
->whereIn('deployment_status', ['succeeded', 'deployed'])
```

**2. UpdateVpnStatus.php** - Check TCP management port:
```php
// Now checks both ports 7505 (UDP) and 7506 (TCP)
$mgmtPorts = [$mgmtPort];
if ($mgmtPort == 7505) {
    $mgmtPorts[] = 7506; // Add TCP stealth server management port
}
```

**3. UpdateVpnStatus.php** - Include TCP status files:
```php
$candidates = [
    '/run/openvpn/server.status',        // UDP
    '/run/openvpn/server-tcp.status',    // TCP stealth
    '/var/log/openvpn-status-tcp.log',   // TCP log
    // ... other paths
];
```

### Database Updates
```sql
-- Fixed server IPs
UPDATE vpn_servers SET ip_address = '5.22.218.134' WHERE name LIKE '%Spain%';
UPDATE vpn_servers SET ip_address = '83.136.254.231' WHERE name LIKE '%UK%';
```

## Current Status ✅

### Working
- ✅ Monitoring job `UpdateVpnConnectionStatus` finds deployed servers
- ✅ TCP management port 7506 is checked alongside UDP 7505
- ✅ **Germany server showing 2 active TCP connections!**
- ✅ Dashboard queries now include 'deployed' status
- ✅ Server IPs corrected

### Still Needs Attention

#### 🔴 Critical: Start Broadcasting Server
You need to run Soketi/Reverb for real-time dashboard updates:

**Option A: Laravel Reverb (recommended)**
```powershell
# Install if not already
composer require laravel/reverb

# Start the server
php artisan reverb:start
```

**Option B: Soketi (if using that)**
```powershell
# Install globally
npm install -g @soketi/soketi

# Start
soketi start
```

**Option C: Use Supervisor (production)**
Create `supervisor` config to auto-start broadcasting server.

#### 🟡 Optional: Verify Spain/UK Servers
```powershell
# Test SSH connectivity
ssh root@5.22.218.134 "echo Spain OK"
ssh root@83.136.254.231 "echo UK OK"
```

## Testing

### Manual Test
```powershell
# Run monitoring once
php artisan vpn:update-status -vvv

# Check logs
Get-Content storage\logs\vpn.log -Tail 20
```

### Expected Result
```
Germany Frankfurt: mgmt responded on port 7506 (2 clients, 993 bytes)
```

## Scheduler Status

Your scheduler runs every minute automatically if you have:
```powershell
# Windows Task Scheduler running:
php artisan schedule:run
```

Or on Linux/production with cron:
```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

## Next Steps

1. **Start broadcasting server** (Reverb/Soketi)
2. **Verify dashboard updates in real-time**
3. **Fix Spain/UK SSH if needed** (currently failing to connect)
4. **Set up Supervisor** for production auto-restart

## Evidence of Fix

From logs at 18:29:51:
```
Germany Frankfurt: mgmt responded on port 7506 (2 clients, 993 bytes)
```

This proves TCP monitoring is now working! 🎉
