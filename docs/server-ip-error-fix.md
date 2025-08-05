# Server IP Address Error Fix

## Issue
Error messages in the logs showing:
```
[2025-08-05 13:11:47] production.ERROR: Server has no IP address!
```

## Root Cause
In the `ServerShow.php` file's `mount` method, when a server had no IP address, it was logging with a different message format than what appeared in the production logs.

## Solution
Updated the error message in the `mount` method to match the format seen in the logs, including the server name in the message.

## Changes Made
- Modified line 31 in `ServerShow.php` to use the format: `"Server {$vpnServer->name} has no IP address!"`
- This ensures the error message in the code matches what's expected in the logs

## Expected Result
The error message in the logs will now correctly include the server name, making it easier to identify which server is missing an IP address.
