# VPN User Creation Fix

## Issue
When creating a VPN user, the log message showed an empty servers array despite the validation requiring at least one server to be selected:

```
[2025-08-04 17:27:06] production.INFO: 🔑 WireGuard public key generated:
[2025-08-04 17:27:06] production.INFO: VPN user created {"username":"wg-Q0rncY","expires_at":"2025-09-04 17:27:06","servers":[]}
```

## Root Cause
The log message was being generated immediately after the VPN user was created but before the servers were properly associated with the user. The `vpnServers()->sync()` method associates the servers with the user in the database, but the VPN user object in memory wasn't being refreshed to include these associations before the log message was generated.

## Solution
Two changes were made to fix this issue:

1. Added a call to `$vpnUser->refresh()` after syncing the selected servers. This ensures that the VPN user object is reloaded from the database with all its relationships, including the newly associated servers.

2. Changed the log message to display server names instead of IDs by replacing `pluck('id')` with `pluck('name')`. This makes the log message more informative and easier to understand.

## Code Changes
The changes were made in the `CreateVpnUser.php` file:

```php
// Before
$vpnUser->vpnServers()->sync($this->selectedServers);

// Manually generate OpenVPN configurations
\App\Services\VpnConfigBuilder::generate($vpnUser);

// After
$vpnUser->vpnServers()->sync($this->selectedServers);

// Reload the VPN user to ensure we have the latest data including server associations
$vpnUser->refresh();

// Manually generate OpenVPN configurations
\App\Services\VpnConfigBuilder::generate($vpnUser);
```

And:

```php
// Before
Log::info("VPN user created", [
    'username' => $vpnUser->username,
    'expires_at' => $vpnUser->expires_at,
    'servers' => $vpnUser->vpnServers->pluck('id')->toArray()
]);

// After
Log::info("VPN user created", [
    'username' => $vpnUser->username,
    'expires_at' => $vpnUser->expires_at,
    'servers' => $vpnUser->vpnServers->pluck('name')->toArray()
]);
```

## Expected Result
After these changes, the log message should show the names of the servers associated with the VPN user, for example:

```
[2025-08-04 17:27:06] production.INFO: 🔑 WireGuard public key generated:
[2025-08-04 17:27:06] production.INFO: VPN user created {"username":"wg-Q0rncY","expires_at":"2025-09-04 17:27:06","servers":["Server 1","Server 2"]}
```

This confirms that the servers are properly associated with the VPN user and provides more meaningful information in the logs.
