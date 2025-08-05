# Server IP Address Error Fix

## Issue Description

The application was logging multiple errors with the message:
```
[2025-08-05 13:33:00] production.ERROR: Server has no IP address!
```

These errors occurred when the application encountered a VPN server with no IP address. The error message was missing the server name, making it difficult to identify which server was causing the issue.

## Root Cause

In the `ServerShow.php` file, when a server had no IP address, the error logging code was trying to access the server name without properly checking if the server or its name property was null. This could happen in several scenarios:

1. When `$vpnServer->fresh()` returned null
2. When the server name was null or an empty string
3. When trying to access properties of a null server object

## Fix Implemented

The fix involved improving the error handling in the `mount()` method of the `ServerShow` component:

1. Added proper null checks for the server object after the `fresh()` call
2. Added safe access to the server name property
3. Implemented a fallback to 'unknown' when the server name is null or empty
4. Added the server name to the log context for better debugging

### Code Changes

The following changes were made to `app/Livewire/Pages/Admin/ServerShow.php`:

```php
// Before:
if (!$vpnServer || blank($vpnServer->ip_address)) {
    logger()->error("Server {$vpnServer->name} has no IP address!", [
        'id' => $vpnServer->id ?? 'null',
        'ip_address' => $vpnServer->ip_address ?? 'null',
    ]);
    $this->uptime = '❌ Missing IP';
    return;
}

// After:
if ($vpnServer === null || blank($vpnServer->ip_address ?? null)) {
    // Get server name safely
    $serverName = $vpnServer ? $vpnServer->name : null;
    
    // Use a default name if server name is null or empty
    $displayName = $serverName ? $serverName : 'unknown';
    
    logger()->error("Server {$displayName} has no IP address!", [
        'id' => $vpnServer ? ($vpnServer->id ?? 'null') : 'null',
        'ip_address' => $vpnServer ? ($vpnServer->ip_address ?? 'null') : 'null',
        'name' => $displayName,
    ]);
    $this->uptime = '❌ Missing IP';
    return;
}
```

## Testing

The fix was tested with multiple scenarios:
1. Server with a name but no IP address
2. Server with an empty name and no IP address
3. Server with a null name and no IP address

All scenarios now correctly display either the server name or 'unknown' in the error message, and include the name in the log context.

## Benefits

1. Error messages now include the server name when available, making it easier to identify problematic servers
2. When the server name is not available, 'unknown' is used as a fallback
3. The server name is included in the log context for better filtering and analysis
4. The code is more robust against null or empty values
5. The application no longer crashes when encountering edge cases with server data
