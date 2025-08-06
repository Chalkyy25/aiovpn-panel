# VPN Real-Time Monitoring System

## Overview

This implementation adds comprehensive real-time VPN monitoring capabilities to the aiovpn-panel, allowing administrators to track online users, connection details, and server statistics in real-time.

## Features Implemented

### ✅ Real-Time User Monitoring
- **Live online count per server** - Shows how many users are connected to each server
- **List of currently connected users** with connection details:
  - Username and device name
  - Client IP address
  - Virtual IP address (if available)
  - Connection time and duration
  - Data transfer statistics (bytes sent/received)
- **"Last seen" timestamp** for users not currently online
- **Online status badges** in user lists with green/gray indicators

### ✅ Real-Time Dashboard
- **Statistics cards** showing:
  - Total online users
  - Active connections
  - Active servers
  - Average connection time
- **Server filtering** - View connections for all servers or filter by specific server
- **Active connections table** with detailed information
- **Recently disconnected users** section
- **Manual disconnect functionality** for administrators
- **Auto-refresh every 5 seconds** using Livewire polling

### ✅ Automated Status Updates
- **Scheduled job** that runs every minute (configurable to 30 seconds)
- **OpenVPN status log parsing** from `/etc/openvpn/openvpn-status.log`
- **Automatic database updates** with current connection status
- **Console command** for manual execution and testing

## Files Created/Modified

### New Files Created:
1. **Database Migration**: `database/migrations/2025_08_06_185600_create_vpn_user_connections_table.php`
2. **Model**: `app/Models/VpnUserConnection.php`
3. **Job**: `app/Jobs/UpdateVpnConnectionStatus.php`
4. **Console Command**: `app/Console/Commands/UpdateVpnStatus.php`
5. **Livewire Component**: `app/Livewire/Pages/Admin/VpnDashboard.php`
6. **Blade View**: `resources/views/livewire/pages/admin/vpn-dashboard.blade.php`
7. **Test Script**: `test_vpn_status_update.php`

### Modified Files:
1. **VpnUser Model**: Added relationships to VpnUserConnection
2. **VpnServer Model**: Added relationships to VpnUserConnection
3. **VpnUserList Component**: Added activeConnections relationship loading and online status ordering
4. **VpnUserList View**: Added online status indicators and connection count badges
5. **Kernel**: Added scheduled command for status updates
6. **Routes**: Added VPN dashboard route

## Database Schema

### New Table: `vpn_user_connections`
```
- id (primary key)
- vpn_user_id (foreign key to vpn_users)
- vpn_server_id (foreign key to vpn_servers)
- is_connected (boolean)
- client_ip (string, nullable)
- virtual_ip (string, nullable)
- connected_at (timestamp, nullable)
- disconnected_at (timestamp, nullable)
- bytes_received (bigint, default 0)
- bytes_sent (bigint, default 0)
- created_at, updated_at (timestamps)
```

### Existing Fields Used:
- `vpn_users.is_online` (boolean)
- `vpn_users.last_seen_at` (timestamp)
- `vpn_users.last_ip` (string)

## Setup Instructions

### 1. Run Database Migration
```bash
php artisan migrate
```

### 2. Start the Scheduler
Ensure Laravel's task scheduler is running:
```bash
# Add to crontab
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### 3. For 30-Second Updates (Optional)
If you want updates every 30 seconds instead of every minute, add this to your crontab:
```bash
# Run every 30 seconds
* * * * * cd /path-to-your-project && php artisan vpn:update-status
* * * * * cd /path-to-your-project && sleep 30 && php artisan vpn:update-status
```

### 4. Queue Workers (Recommended)
For better performance, run queue workers:
```bash
php artisan queue:work
```

## Usage

### Accessing the Dashboard
- **URL**: `/admin/vpn-dashboard`
- **Route Name**: `admin.vpn-dashboard`
- **Access**: Admin users only

### Manual Status Update
```bash
# Queue the job (recommended)
php artisan vpn:update-status

# Run synchronously (for testing)
php artisan vpn:update-status --sync
```

### Testing the System
```bash
# Run the test script
php test_vpn_status_update.php
```

## How It Works

### 1. Status Collection
- The `UpdateVpnConnectionStatus` job runs every minute
- It connects to each active VPN server via SSH
- Reads the OpenVPN status log at `/etc/openvpn/openvpn-status.log`
- Parses the CSV-format log to extract connection details

### 2. Data Processing
- Matches connected users with database records
- Updates `vpn_user_connections` table with current status
- Updates global `is_online` status in `vpn_users` table
- Handles disconnections and tracks session durations

### 3. Real-Time Display
- Dashboard polls every 5 seconds for updates
- User list polls every 10 seconds for status changes
- Shows live connection counts and user status
- Provides detailed connection information

## OpenVPN Status Log Format

The system parses logs in this format:
```
Common Name,Real Address,Bytes Received,Bytes Sent,Connected Since
username1,192.168.1.100:54321,1024,2048,2025-08-06 18:30:15
username2,192.168.1.101:54322,2048,4096,2025-08-06 18:25:30
```

## Features

### Dashboard Features:
- ✅ Live statistics cards
- ✅ Server filtering
- ✅ Active connections table
- ✅ Recently disconnected users
- ✅ Manual disconnect functionality
- ✅ Real-time updates (5-second polling)
- ✅ Responsive design with dark mode support

### User List Features:
- ✅ Online status indicators (green/gray dots)
- ✅ Last seen timestamps
- ✅ Connection count badges
- ✅ Real-time updates (10-second polling)
- ✅ Online users shown first

### Backend Features:
- ✅ Robust error handling
- ✅ SSH connection management
- ✅ Log parsing with multiple date formats
- ✅ Automatic cleanup of stale connections
- ✅ Queue-based processing
- ✅ Comprehensive logging

## Troubleshooting

### Common Issues:

1. **No connections showing**:
   - Check if OpenVPN servers are active
   - Verify SSH connectivity to servers
   - Check OpenVPN status log exists at `/etc/openvpn/openvpn-status.log`

2. **Status not updating**:
   - Ensure scheduler is running
   - Check queue workers are processing jobs
   - Review Laravel logs for errors

3. **SSH connection failures**:
   - Verify SSH keys and credentials in server settings
   - Check firewall rules
   - Test manual SSH connection

### Logs to Check:
- Laravel logs: `storage/logs/laravel.log`
- Queue logs: Check queue worker output
- System logs: Check cron execution

## Performance Considerations

- The system is designed to handle multiple servers and users efficiently
- Database queries are optimized with proper indexing
- Real-time updates use Livewire polling (can be adjusted)
- Queue processing prevents blocking of web requests

## Security Notes

- All SSH connections use configured authentication (keys/passwords)
- No sensitive data is exposed in the frontend
- Admin-only access to monitoring features
- Proper input validation and sanitization

## Future Enhancements

Potential improvements that could be added:
- WebSocket integration for true real-time updates
- Historical connection analytics
- Email/SMS alerts for connection events
- API endpoints for external monitoring
- Export functionality for connection reports
- Bandwidth usage graphs and charts

---

## Summary

This implementation provides a comprehensive real-time VPN monitoring solution that meets all the requirements:

✅ **Real-time users online per server dashboard**  
✅ **OpenVPN status log parsing**  
✅ **Live online count per server**  
✅ **List of connected users with connection details**  
✅ **"Last seen" timestamps for offline users**  
✅ **Scheduled updates every 30 seconds to 1 minute**  
✅ **Online status badges in user interface**  
✅ **All necessary files, jobs, and Livewire components created**

The system is production-ready and provides administrators with comprehensive visibility into their VPN infrastructure.
