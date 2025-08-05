# Server IP Address Fix

This document provides instructions on how to apply the fix for the issue where server IP addresses are not being properly retrieved, causing:
- "Server has no IP address!" error messages in logs
- Blank values for IP, SSH User, VPN Port, etc. in the UI
- Install / Re-Deploy and Restart VPN buttons not working
- "SSH → : (no IP being passed)" log messages

## Issue Description

The issue occurs when the `fresh()` method is called on a VpnServer object, which sometimes returns an object with a missing IP address, even though the IP address is correctly stored in the database. This could be due to a caching issue or a problem with how the model is being retrieved.

## Fix Instructions

### 1. Modify the ServerShow Component

Open the file `app/Livewire/Pages/Admin/ServerShow.php` and make the following changes:

#### a. Update the `mount` method:

```php
public function mount(VpnServer $vpnServer): void
{
    // Log the original server data before refresh
    logger()->info("ServerShow: Original server data before refresh", [
        'id' => $vpnServer->id ?? 'null',
        'ip_address' => $vpnServer->ip_address ?? 'null',
        'name' => $vpnServer->name ?? 'unknown',
    ]);

    // Get the server directly from the database to verify data
    $directServer = VpnServer::find($vpnServer->id);
    logger()->info("ServerShow: Direct database query result", [
        'id' => $directServer->id ?? 'null',
        'ip_address' => $directServer->ip_address ?? 'null',
        'name' => $directServer->name ?? 'unknown',
    ]);

    // Now refresh the server
    $vpnServer = $vpnServer->fresh();
    
    // Log the refreshed server data
    logger()->info("ServerShow: Refreshed server data", [
        'id' => $vpnServer->id ?? 'null',
        'ip_address' => $vpnServer->ip_address ?? 'null',
        'name' => $vpnServer->name ?? 'unknown',
    ]);

    // Check if model is null after refresh or has no IP
    if ($vpnServer === null || blank($vpnServer->ip_address ?? null)) {
        // Get server name safely
        $serverName = $vpnServer?->name;

        // Use a default name if server name is null or empty
        $displayName = $serverName ?: 'unknown';

        logger()->error("Server $displayName has no IP address!", [
            'id' => $vpnServer ? ($vpnServer->id ?? 'null') : 'null',
            'ip_address' => $vpnServer ? ($vpnServer->ip_address ?? 'null') : 'null',
            'name' => $displayName,
        ]);
        
        // If we have a direct server with an IP, use that instead
        if ($directServer && !blank($directServer->ip_address)) {
            logger()->info("ServerShow: Using direct server data instead of refreshed data", [
                'id' => $directServer->id,
                'ip_address' => $directServer->ip_address,
                'name' => $directServer->name,
            ]);
            $vpnServer = $directServer;
        } else {
            $this->uptime = '❌ Missing IP';
            return;
        }
    }

    $this->vpnServer = $vpnServer;
    $this->refresh();
}
```

#### b. Update the `refresh` method:

```php
public function refresh(): void
{
    // Log the original server data before refresh
    logger()->info("ServerShow refresh: Original server data before refresh", [
        'id' => $this->vpnServer->id ?? 'null',
        'ip_address' => $this->vpnServer->ip_address ?? 'null',
        'name' => $this->vpnServer->name ?? 'unknown',
    ]);

    // Get the server directly from the database to verify data
    $directServer = VpnServer::find($this->vpnServer->id);
    logger()->info("ServerShow refresh: Direct database query result", [
        'id' => $directServer->id ?? 'null',
        'ip_address' => $directServer->ip_address ?? 'null',
        'name' => $directServer->name ?? 'unknown',
    ]);

    // Now refresh the server
    $refreshedServer = $this->vpnServer->fresh();
    
    // Log the refreshed server data
    logger()->info("ServerShow refresh: Refreshed server data", [
        'id' => $refreshedServer->id ?? 'null',
        'ip_address' => $refreshedServer->ip_address ?? 'null',
        'name' => $refreshedServer->name ?? 'unknown',
    ]);

    // Check if the refreshed server has an IP address
    if ($refreshedServer === null || blank($refreshedServer->ip_address ?? null)) {
        // If we have a direct server with an IP, use that instead
        if ($directServer && !blank($directServer->ip_address)) {
            logger()->info("ServerShow refresh: Using direct server data instead of refreshed data", [
                'id' => $directServer->id,
                'ip_address' => $directServer->ip_address,
                'name' => $directServer->name,
            ]);
            $refreshedServer = $directServer;
        } else {
            logger()->error("ServerShow refresh: Server has no IP address after refresh and direct query", [
                'id' => $this->vpnServer->id ?? 'null',
                'name' => $this->vpnServer->name ?? 'unknown',
            ]);
            $this->uptime = '❌ Missing IP';
            return;
        }
    }

    $this->vpnServer = $refreshedServer;
    $this->deploymentLog = $this->vpnServer->deployment_log;
    $this->deploymentStatus = (string) ($this->vpnServer->deployment_status ?? '');

    if (in_array($this->deploymentStatus, ['succeeded', 'failed'])) {
        try {
            $ssh = $this->makeSshClient();
            $this->uptime = trim($ssh->exec("uptime"));
            $this->cpu = trim($ssh->exec("top -bn1 | grep 'Cpu(s)' || top -l 1 | grep 'CPU usage'"));
            $this->memory = trim($ssh->exec("free -h | grep Mem || vm_stat | head -n 5"));
            $this->bandwidth = trim($ssh->exec("vnstat --oneline || echo 'vnstat not installed'"));
        } catch (\Throwable $e) {
            $this->uptime = '❌ ' . $e->getMessage();
            logger()->warning("Live-stats SSH error (#{$this->vpnServer->id}): {$e->getMessage()}");
        }
    }
}
```

#### c. Update the `makeSshClient` method:

```php
private function makeSshClient(): SSH2
{
    // Log server data before creating SSH client
    logger()->info("makeSshClient: Server data", [
        'id' => $this->vpnServer->id ?? 'null',
        'ip_address' => $this->vpnServer->ip_address ?? 'null',
        'name' => $this->vpnServer->name ?? 'unknown',
        'ssh_port' => $this->vpnServer->ssh_port ?? '22',
        'ssh_user' => $this->vpnServer->ssh_user ?? 'null',
        'ssh_type' => $this->vpnServer->ssh_type ?? 'null',
    ]);

    // Validate IP address
    if (blank($this->vpnServer->ip_address)) {
        // Try to get the server directly from the database
        $directServer = VpnServer::find($this->vpnServer->id);
        
        if ($directServer && !blank($directServer->ip_address)) {
            logger()->info("makeSshClient: Using direct server data instead of current data", [
                'id' => $directServer->id,
                'ip_address' => $directServer->ip_address,
                'name' => $directServer->name,
            ]);
            $this->vpnServer = $directServer;
        } else {
            throw new RuntimeException('Server IP address is missing or empty');
        }
    }

    // Validate SSH port
    $sshPort = $this->vpnServer->ssh_port ?? 22;

    logger()->info("SSH → {$this->vpnServer->ip_address}:$sshPort");
    $ssh = new SSH2($this->vpnServer->ip_address, $sshPort);

    if ($this->vpnServer->ssh_type === 'key') {
        $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';
        if (!is_file($keyPath)) {
            throw new RuntimeException('SSH key not found at ' . $keyPath);
        }
        $key = PublicKeyLoader::load(file_get_contents($keyPath));
        $login = $ssh->login($this->vpnServer->ssh_user, $key);
    } else {
        $login = $ssh->login($this->vpnServer->ssh_user, $this->vpnServer->ssh_password);
    }

    if (!$login) {
        throw new RuntimeException('SSH login failed');
    }

    return $ssh;
}
```

### 2. Check for Missing IP Addresses in the Database

Create a file `fix-server-ip.php` in the root directory of your application with the following content:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\VpnServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "Checking for VPN servers with missing IP addresses...\n\n";

// Get all servers
$servers = VpnServer::all();
echo "Found " . $servers->count() . " VPN servers in the database.\n";

// Check for servers with missing IP addresses
$serversWithMissingIp = $servers->filter(function ($server) {
    return empty($server->ip_address);
});

echo "Found " . $serversWithMissingIp->count() . " servers with missing IP addresses.\n";

if ($serversWithMissingIp->count() > 0) {
    echo "\nServers with missing IP addresses:\n";
    
    foreach ($serversWithMissingIp as $server) {
        echo "ID: {$server->id}, Name: {$server->name}\n";
        
        // Check if there's an 'ip' field in the database that might have the IP address
        $rawServer = DB::table('vpn_servers')->where('id', $server->id)->first();
        
        // Debug: Print all fields of the server
        echo "  Raw database fields:\n";
        foreach ((array)$rawServer as $field => $value) {
            echo "    $field: " . ($value ?: 'NULL') . "\n";
        }
        
        // Ask for a new IP address
        echo "\n  Enter a new IP address for this server (or leave empty to skip): ";
        $newIp = trim(fgets(STDIN));
        
        if (!empty($newIp)) {
            // Validate IP address
            if (filter_var($newIp, FILTER_VALIDATE_IP)) {
                // Update the server
                $server->ip_address = $newIp;
                $server->save();
                
                echo "  ✅ Updated server {$server->name} with IP address {$newIp}\n";
                Log::info("Updated server {$server->name} (ID: {$server->id}) with IP address {$newIp}");
            } else {
                echo "  ❌ Invalid IP address: {$newIp}\n";
            }
        } else {
            echo "  ⚠️ Skipped updating server {$server->name}\n";
        }
        
        echo "\n";
    }
    
    // Verify the updates
    $remainingServersWithMissingIp = VpnServer::all()->filter(function ($server) {
        return empty($server->ip_address);
    });
    
    echo "After updates, there are " . $remainingServersWithMissingIp->count() . " servers with missing IP addresses.\n";
    
    if ($remainingServersWithMissingIp->count() > 0) {
        echo "\nRemaining servers with missing IP addresses:\n";
        foreach ($remainingServersWithMissingIp as $server) {
            echo "ID: {$server->id}, Name: {$server->name}\n";
        }
    }
} else {
    echo "\nAll servers have IP addresses set. No action needed.\n";
}

echo "\nDone.\n";
```

Run this script to check if there are any servers with missing IP addresses in your database:

```bash
php fix-server-ip.php
```

If there are any servers with missing IP addresses, the script will prompt you to enter a new IP address for each one.

## Verification

After applying the fix, you should see:
- Server IP addresses displayed in the UI
- Install / Re-Deploy and Restart VPN buttons working
- SSH commands receiving the proper IP address
- No more "Server has no IP address!" error messages in the logs

## Technical Details

The fix ensures that if the refreshed data is missing the IP address, the component will try to get the server directly from the database and use that instead. This ensures that the IP address is always available for SSH commands and UI display.

The added logging will help diagnose any future issues with server data retrieval.
