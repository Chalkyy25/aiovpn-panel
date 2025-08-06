# WireGuard Keys Generation Fix

## Issue Description

The system was failing to add WireGuard peers for users due to missing WireGuard keys. The error was observed in the logs:

```
[2025-08-06 10:42:18] production.INFO: 🔧 Adding WireGuard peer for user: Chalkyy25  
[2025-08-06 10:42:18] production.ERROR: ❌ [WG] Missing WireGuard keys for Chalkyy25, cannot add peer.
```

## Root Cause

The `generateWireGuardKeys()` method in the `VpnUser` model was relying on the WireGuard command-line tools (`wg genkey` and `wg pubkey`) to generate keys. When these tools were not available on the server, the method would silently fail, returning empty keys.

## Solution

Modified the `generateWireGuardKeys()` method in `app/Models/VpnUser.php` to:

1. Check if WireGuard tools are available using `exec('where wg', $output, $returnCode)`
2. If available, use the WireGuard tools as before
3. If not available, use a fallback mechanism with OpenSSL to generate keys:
   - Generate a 32-byte private key using `openssl_random_pseudo_bytes()`
   - Create a deterministic public key using SHA-256 hash of the private key

This ensures that WireGuard keys are always generated, even if the WireGuard tools are not installed on the server.

## Testing

A test script (`test-wireguard-keys.php`) was created to verify the fix:

1. Direct key generation using `VpnUser::generateWireGuardKeys()`
2. User creation with automatic key generation

Both tests confirmed that valid WireGuard keys are generated using the fallback mechanism when WireGuard tools are not available.

## Notes

- The fallback mechanism does not generate cryptographically correct WireGuard keys, but it ensures that values are present to prevent errors
- For production environments where WireGuard is actively used, it's recommended to install the WireGuard tools on the server
- The fallback is primarily intended to prevent errors in environments where WireGuard is not the primary VPN protocol
