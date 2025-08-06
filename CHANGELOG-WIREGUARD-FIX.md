# WireGuard Peer Setup Error Handling Improvements

## Issue Description
The system was failing to add WireGuard peers to servers but:
1. Not capturing or displaying the actual error messages (showing empty "Error:" lines in logs)
2. Incorrectly reporting success despite failures

Example from logs:
```
[2025-08-06 10:47:02] production.ERROR: ❌ [WG] Failed to add peer for Chalkyy25 on Germany  
[2025-08-06 10:47:02] production.ERROR: Error:   
[2025-08-06 10:47:02] production.INFO: 🔧 Adding WireGuard peer to server: UK London (83.136.254.231)  
[2025-08-06 10:47:03] production.ERROR: ❌ [WG] Failed to add peer for Chalkyy25 on UK London  
[2025-08-06 10:47:03] production.ERROR: Error:   
[2025-08-06 10:47:03] production.INFO: ✅ Completed WireGuard peer setup for user: Chalkyy25
```

## Changes Made

### 1. Improved Error Capture in SSH Command Execution
- Replaced simple `exec()` with `proc_open()` for better process control
- Added stderr redirection to capture error output
- Added fallback error messages for common failure scenarios
- Ensured error output is never empty when a command fails

### 2. Fixed Success/Failure Tracking
- Modified `addPeerToServer` method to return boolean success/failure status
- Updated `handle` method to track success/failure of each server operation
- Added conditional success/failure reporting based on actual results
- Added a clear error message when the process completes with errors

## Expected Impact
- Error logs will now show detailed error messages instead of empty "Error:" lines
- The system will only report success when all operations actually succeed
- Failed operations will be clearly indicated with meaningful error messages
- Administrators will have better visibility into WireGuard peer setup issues

## Files Modified
- `app/Jobs/AddWireGuardPeer.php`
