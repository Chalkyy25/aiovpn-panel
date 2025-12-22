# WireGuard & OpenVPN Device Limit Implementation

## Overview

This system enforces device connection limits for VPN users across both OpenVPN and WireGuard protocols. When a user exceeds their `max_connections` limit, the **oldest connections are automatically disconnected** to make room for new ones.

**🔥 NEW: Sessions are now ACTUALLY KILLED on the VPN server**, not just marked as disconnected in the database.

## How It Works

### 1. Database Schema

Each `VpnUser` has a `max_connections` field:
- `0` = Unlimited devices
- `1` = Single device only
- `2+` = Multiple devices allowed

### 2. Enforcement Points

#### A. Real-time Auto-Enforcement (DeployEventController)

When VPN servers push management data to `/api/vpn-servers/{server}/events`:

```php
// app/Http/Controllers/Api/DeployEventController.php
$this->enforceDeviceLimits($server->id, $now);
```

This runs **after** all connection states are updated and:
- ✅ Detects users over their device limit
- ✅ **Kills oldest sessions on the VPN server** (via SSH)
- ✅ Updates database to mark as disconnected
- ✅ Works for both WireGuard and OpenVPN

**When a user connects a new device and exceeds their limit, the oldest device is immediately disconnected.**

#### B. Manual Enforcement (VpnUser Model)

You can also enforce limits programmatically:

```php
$user = VpnUser::find($userId);

// Check if over limit
if ($user->isOverDeviceLimit()) {
    // Disconnect oldest connections
    $disconnected = $user->enforceDeviceLimit();
    echo "Disconnected {$disconnected} devices";
}
```

### 3. Connection Tracking

All active connections are tracked in the `vpn_user_connections` table:

- **session_key**: Unique identifier per connection
  - WireGuard: `wg:{public_key}`
  - OpenVPN: `ovpn:{mgmt_port}:{client_id}:{username}`
- **is_connected**: Boolean flag
- **connected_at**: Timestamp when connection started
- **protocol**: `WIREGUARD` or `OPENVPN`

## Implementation Details

### How Sessions Are Actually Killed

#### For WireGuard:
```bash
# Remove peer from WireGuard interface
wg set wg0 peer <PUBLIC_KEY> remove
```

#### For OpenVPN:
```bash
# Kill client via management interface
echo "kill <CLIENT_ID>" | nc 127.0.0.1 7505
```

### DeployEventController::enforceDeviceLimits()

```php
private function enforceDeviceLimits(int $serverId, Carbon $now): void
{
    // Get all users with connections on this server
    $userIds = VpnUserConnection::where('vpn_server_id', $serverId)
        ->where('is_connected', true)
        ->distinct()
        ->pluck('vpn_user_id');

    foreach ($userIds as $userId) {
        $user = VpnUser::find($userId);
        
        // Skip unlimited users
        if ((int) $user->max_connections === 0) {
            continue;
        }

        // Get ALL active connections across ALL servers
        $activeConnections = VpnUserConnection::with('vpnServer')
            ->where('vpn_user_id', $userId)
            ->where('is_connected', true)
            ->orderBy('connected_at', 'asc') // oldest first
            ->get();

        if ($activeConnections->count() > $user->max_connections) {
            // 1. Kill session on VPN server (via SSH)
            $this->killSession($conn);
            
            // 2. Update database
            $conn->update(['is_connected' => false, ...]);
        }
    }
}
```

### killSession() - The Magic

```php
private function killSession(VpnUserConnection $conn): void
{
    $server = $conn->vpnServer;
    
    if ($conn->protocol === 'WIREGUARD') {
        // Remove WireGuard peer
        $command = sprintf('wg set %s peer %s remove', 
            $server->wg_interface, 
            $conn->public_key
        );
        $this->executeRemoteCommand($server, $command);
        
    } else {
        // Kill OpenVPN client
        $command = sprintf('echo "kill %s" | nc 127.0.0.1 %d', 
            $conn->client_id, 
            $conn->mgmt_port
        );
        $this->executeRemoteCommand($server, $command);
    }
}
```

### VpnUser Model Methods

#### `canConnect(): bool`

Check if user can establish a new connection:

```php
if (!$user->canConnect()) {
    return response()->json(['error' => 'Device limit reached'], 403);
}
```

#### `isOverDeviceLimit(): bool`

Check if user currently exceeds their limit:

```php
if ($user->isOverDeviceLimit()) {
    // Take action
}
```

#### `enforceDeviceLimit(): int`

Automatically disconnect oldest connections:

```php
$disconnected = $user->enforceDeviceLimit();
// Returns number of connections disconnected
```

## Usage Examples

### Example 1: Check Before Connection

```php
$user = VpnUser::where('username', $username)->first();

if (!$user->canConnect()) {
    throw new \Exception("Maximum devices ({$user->max_connections}) reached. Disconnect an existing device first.");
}

// Proceed with connection...
```

### Example 2: Auto-enforce After Connection

```php
// After establishing new connection
$user->enforceDeviceLimit(); // Will auto-disconnect oldest if over limit
```

### Example 3: Manual Admin Action

```php
// Force all users to comply with limits
VpnUser::where('max_connections', '>', 0)->each(function ($user) {
    $user->enforceDeviceLimit();
});
```

## Logging

All device limit enforcement is logged to the `vpn` channel with detailed information:

```log
[2024-12-22 10:30:45] vpn.WARNING: DEVICE_LIMIT: User vpn-user123 (42) exceeded limit: 3/2 devices - disconnecting 1 oldest session(s)
[2024-12-22 10:30:45] vpn.INFO: DEVICE_LIMIT: ✂️ Killed WIREGUARD session wg:ABC123XYZ for user vpn-user123 on server Germany-VPN
[2024-12-22 10:30:45] vpn.DEBUG: DEVICE_LIMIT: WireGuard peer ABC123XYZ removed from wg0
```

### What Gets Logged:

1. **Warning when limit exceeded**: Shows username, user ID, current count vs max
2. **Info when session killed**: Shows protocol, session key, username, server name  
3. **Debug for server commands**: Shows actual WireGuard/OpenVPN commands executed

### Viewing Logs:

```bash
# Watch device limit activity in real-time
tail -f storage/logs/vpn.log | grep DEVICE_LIMIT

# See only warnings (users exceeding limits)
tail -f storage/logs/vpn.log | grep "DEVICE_LIMIT.*exceeded"

# See actual kill actions
tail -f storage/logs/vpn.log | grep "✂️ Killed"
```

## Configuration

### Setting User Limits

**Via Livewire Component:**

```php
// resources/views/livewire/pages/admin/edit-vpn-user.blade.php
<input type="number" min="0" wire:model.lazy="maxConnections" />
```

**Via Model:**

```php
$user->update(['max_connections' => 2]); // Allow 2 devices
$user->update(['max_connections' => 0]); // Unlimited
```

## Testing

### Test Device Limit Enforcement

```bash
# Connect multiple devices for same user
# When limit is reached, oldest connection will be auto-disconnected

# Check logs
tail -f storage/logs/vpn.log | grep DEVICE_LIMIT
```

### Verify in Database

```sql
-- Check active connections per user
SELECT 
    vpn_user_id,
    username,
    max_connections,
    COUNT(*) as active_count
FROM vpn_user_connections
JOIN vpn_users ON vpn_users.id = vpn_user_connections.vpn_user_id
WHERE is_connected = true
GROUP BY vpn_user_id, username, max_connections
HAVING active_count > max_connections AND max_connections > 0;
```

## Protocol Support

✅ **OpenVPN**: Fully supported
✅ **WireGuard**: Fully supported

Both protocols use the same enforcement logic. The system identifies connections by their unique `session_key` format.

## Future Enhancements

- [ ] Add webhook/notification when user hits device limit
- [ ] Per-server device limits (currently global per user)
- [ ] Grace period before disconnecting oldest device
- [ ] UI notification to user when they're about to be disconnected
- [ ] Device naming/identification for better UX

## Troubleshooting

### Limit Not Enforcing

1. Check `max_connections` is set: `SELECT id, username, max_connections FROM vpn_users;`
2. Verify enforcement is enabled: Check `DeployEventController::store()` calls `enforceDeviceLimits()`
3. Check logs: `tail -f storage/logs/vpn.log`

### Connections Not Tracking

1. Ensure management events are being received: Check `/api/vpn-servers/{server}/events` endpoint
2. Verify `session_key` is being set correctly
3. Check `vpn_user_connections` table has recent `updated_at` timestamps

## Related Files

- **Controller**: `app/Http/Controllers/Api/DeployEventController.php`
- **Model**: `app/Models/VpnUser.php`
- **Migration**: `database/migrations/2025_07_27_133819_add_connection_tracking_to_vpn_users.php`
- **Job**: `app/Jobs/UpdateVpnConnectionStatus.php` (OpenVPN legacy poller)
