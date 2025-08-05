<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer; // ✅ Import your job
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
class ServerShow extends Component
{
    public VpnServer $vpnServer;

    public string $uptime = '…';
    public string $cpu = '…';
    public string $memory = '…';
    public string $bandwidth = '…';
    public string $deploymentStatus = '…';
    public string $deploymentLog = '';

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
            } catch (Throwable $e) {
                $this->uptime = '❌ ' . $e->getMessage();
                logger()->warning("Live-stats SSH error (#{$this->vpnServer->id}): {$e->getMessage()}");
            }
        }
    }

    public function getFilteredLogProperty(): array
    {
        $lines = explode("\n", $this->deploymentLog ?? '');
        $filtered = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION|Reading database|^-----$/', $line)) continue;
            if (in_array($line, $seen)) continue;
            $seen[] = $line;

            $color = match (true) {
                str_contains($line, '❌') => 'text-red-400',
                str_contains($line, '✅') => 'text-green-400',
                str_contains($line, 'WARNING') => 'text-yellow-400',
                default => '',
            };

            $filtered[] = ['text' => $line, 'color' => $color];
        }

        return $filtered;
    }

    public function rebootServer(): void
    {
        // Check if IP address is missing or empty
        if (blank($this->vpnServer->ip_address)) {
            session()->flash('status', '❌ Reboot failed: Server IP address is missing.');
            return;
        }

        try {
            $ssh = $this->makeSshClient();
            $ssh->exec('reboot');
            session()->flash('status', '🔄 Reboot command sent successfully.');
        } catch (Throwable $e) {
            session()->flash('status', '❌ Reboot failed: ' . $e->getMessage());
        }
    }

    public function deleteServer(): void
    {
        $name = $this->vpnServer->name;
        $this->vpnServer->delete();
        session()->flash('status', "🗑️ Server “{$name}” deleted.");
        $this->redirectRoute('admin.servers.index');
    }

    public function generateConfig(): void
    {
        session()->flash('message', '📥 Client config generation triggered.');
    }

    public function deployServer(): void
    {
        if ($this->vpnServer->is_deploying) {
            session()->flash('status', '⚠️ Already deploying.');
            return;
        }

        // Check if IP address is missing or empty
        if (blank($this->vpnServer->ip_address)) {
            session()->flash('status', '❌ Deployment failed: Server IP address is missing.');
            return;
        }

        $this->vpnServer->update([
            'deployment_status' => 'queued',
            'deployment_log' => '',
        ]);

        DeployVpnServer::dispatch($this->vpnServer); // ✅ Uses imported class
        session()->flash('status', '✅ Deployment triggered successfully.');
    }

    public function restartVpn(): void
    {
        // Check if IP address is missing or empty
        if (blank($this->vpnServer->ip_address)) {
            session()->flash('message', '❌ Restart failed: Server IP address is missing.');
            return;
        }

        try {
            $ssh = $this->makeSshClient();
            $ssh->exec('systemctl restart openvpn@server');
            session()->flash('message', '✅ OpenVPN service restarted.');
        } catch (Throwable $e) {
            session()->flash('message', '❌ Restart failed: ' . $e->getMessage());
        }
    }

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
            // Try multiple possible paths for the SSH key
            $possiblePaths = [
                '/var/www/aiovpn/storage/app/ssh_keys/id_rsa',
                storage_path('app/ssh_keys/id_rsa'),
                base_path('storage/app/ssh_keys/id_rsa'),
                base_path('storage/ssh_keys/id_rsa')
            ];

            $keyPath = null;
            foreach ($possiblePaths as $path) {
                if (is_file($path)) {
                    $keyPath = $path;
                    break;
                }
            }

            if (!$keyPath) {
                throw new RuntimeException('SSH key not found in any of the expected locations');
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

    public function render()
    {
        return view('livewire.pages.admin.server-show');
    }
}
