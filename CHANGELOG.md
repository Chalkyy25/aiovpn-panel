# Changelog

## [1.0.1] - 2025-08-05

### Fixed

- Fixed issue where server IP addresses were not being properly retrieved after calling `fresh()` method, causing:
  - "Server has no IP address!" error messages in logs
  - Blank values for IP, SSH User, VPN Port, etc. in the UI
  - Install / Re-Deploy and Restart VPN buttons not working
  - "SSH → : (no IP being passed)" log messages

### Changes

- Modified `ServerShow` component to add more debugging information and fix the issue:
  - Added logging of original server data before refresh
  - Added direct database query to verify server data
  - Added logging of refreshed server data
  - Added fallback to use direct database query result if refreshed data is missing IP address

- Modified `makeSshClient` method to add more debugging information and fix the issue:
  - Added logging of server data before creating SSH client
  - Added direct database query to get server data if IP address is missing
  - Added fallback to use direct database query result if current data is missing IP address

- Added test scripts to verify the fix:
  - `fix-server-ip.php`: Checks for VPN servers with missing IP addresses in the database
  - `test-server-fix.php`: Tests the ServerShow component with real and test servers

### Technical Details

The issue was that when the `fresh()` method was called on a VpnServer object, it sometimes returned an object with a missing IP address, even though the IP address was correctly stored in the database. This could be due to a caching issue or a problem with how the model was being retrieved.

The fix ensures that if the refreshed data is missing the IP address, the component will try to get the server directly from the database and use that instead. This ensures that the IP address is always available for SSH commands and UI display.
