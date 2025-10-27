# Merged OpenVPN Monitoring Implementation

**Date:** October 27, 2025  
**Status:** ✅ Deployed to Germany server, ready for all deployments

## Summary

Replaced separate UDP and TCP status file polling with a unified management interface approach that:
- Queries both UDP (port 7505) and TCP (port 7506) management interfaces
- Merges client lists into single JSON payload
- Uses real-time client-connect/disconnect hooks for instant updates
- Falls back to 2-second timer (vs previous 5-second separate timers)

## Benefits

1. **Real-time updates**: Dashboard shows connections immediately via hooks
2. **Unified view**: Single API call contains both UDP and TCP clients
3. **Reduced API calls**: One merged call every 2s vs two separate calls every 5s
4. **Better accuracy**: Management interface data is always current vs status file lag
5. **Simpler maintenance**: Single script and timer vs separate UDP/TCP services

## Implementation Details

### New Script: `/usr/local/bin/ovpn-mgmt-push.sh`

```bash
- Connects to management ports 7505 (UDP) and 7506 (TCP)
- Issues "status 3" command to get CSV-format client lists
- Python parser merges both lists, removing duplicates by username
- Posts single JSON event to panel API
```

### Systemd Components

**Service:** `ovpn-mgmt-push.service` (oneshot)
- Sources environment from `/etc/default/ovpn-status-push`
- Executes `/usr/local/bin/ovpn-mgmt-push.sh`

**Timer:** `ovpn-mgmt-push.timer`
- Runs every 2 seconds (vs previous 5s)
- Starts 3 seconds after boot

### OpenVPN Hooks

Added to both `server.conf` and `server-tcp.conf`:
```
client-connect "/usr/local/bin/ovpn-mgmt-push.sh"
client-disconnect "/usr/local/bin/ovpn-mgmt-push.sh"
```

When clients connect/disconnect, OpenVPN immediately calls the script for instant updates.

## Deployment

### Automatic (New Servers)
Run `deploy-openvpn.sh` - monitoring solution is now built-in.

### Manual (Existing Servers)
Use `fix-dashboard-monitoring.sh`:

```bash
cat fix-dashboard-monitoring.sh | ssh root@SERVER_IP "bash -s"
```

This will:
1. Create `/usr/local/bin/ovpn-mgmt-push.sh`
2. Add hooks to OpenVPN configs
3. Create and enable systemd service/timer
4. Disable old separate timers
5. Restart OpenVPN services to apply hooks

## Files Modified

### `resources/scripts/deploy-openvpn.sh`
- Removed: Separate `ovpn-status-push.timer` and `ovpn-status-push-tcp.timer`
- Added: Merged `ovpn-mgmt-push` solution with hooks
- Changed: Timer interval from 5s → 2s
- Changed: Data source from status files → management interface

### Created: `fix-dashboard-monitoring.sh`
Standalone script for retrofitting existing servers with new monitoring approach.

## Testing

Verified on Germany server (5.22.212.177):
```bash
✅ Timer running: systemctl status ovpn-mgmt-push.timer
✅ Real-time hooks: Client connects trigger immediate update
✅ Merged data: Both UDP and TCP clients in single JSON payload
✅ Panel API: Successfully posting to /api/servers/99/events
```

## Migration Notes

- Old timers are automatically disabled by deployment script
- No database changes required
- Panel API endpoint remains the same: `/api/servers/{id}/events`
- JSON format compatible with existing dashboard code

## JSON Payload Example

```json
{
  "status": "mgmt",
  "ts": "2025-10-27T07:03:06.055456Z",
  "clients": 2,
  "users": [
    {
      "username": "user1",
      "client_ip": "1.2.3.4",
      "virtual_ip": "10.8.0.2",
      "bytes_received": 123456,
      "bytes_sent": 654321,
      "connected_at": 1730000000
    },
    {
      "username": "user2",
      "client_ip": "5.6.7.8",
      "virtual_ip": "10.8.100.2",
      "bytes_received": 789012,
      "bytes_sent": 210987,
      "connected_at": 1730000100
    }
  ]
}
```

## Backwards Compatibility

- Panel dashboard already checks `deployed` field before showing server
- Existing servers with old monitoring will continue working until upgraded
- No breaking changes to API contract

## Future Improvements

Consider adding:
- WireGuard client monitoring via `wg show wg0 dump`
- Combined WG + OVPN client list in single payload
- Client disconnect reason tracking
- Bandwidth rate monitoring (not just totals)
