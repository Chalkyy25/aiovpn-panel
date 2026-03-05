<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnServer;
use App\Jobs\DeployVpnServer;
use Livewire\Component;
use Livewire\Attributes\Layout;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
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

    public function mount($vpnserver): void
    {
        $server = VpnServer::findOrFail($vpnserver);

        if (blank($server->ip_address)) {
            logger()->error("❌ Server {$server->id} has no IP address!");
            $this->uptime = '❌ Missing IP';
            return;
        }

        $this->vpnServer = $server;
        $this->refresh();
    }

    public function refresh(): void
    {
        $server = VpnServer::find($this->vpnServer->id);

        if (blank($server?->ip_address)) {
            logger()->error("❌ Refresh: Server {$this->vpnServer->id} has no IP address!");
            $this->uptime = '❌ Missing IP';
            return;
        }

        $this->vpnServer = $server;
        $this->deploymentLog = $server->deployment_log ?? '';
        $this->deploymentStatus = $server->deployment_status ?? 'unknown';

            if (in_array($this->deploymentStatus, ['success', 'failed'])) {
            try {
                $ssh = $this->makeSshClient();
                $this->uptime = trim($ssh->exec("uptime"));
                $this->cpu = trim($ssh->exec("top -bn1 | grep 'Cpu(s)' || top -l 1 | grep 'CPU usage'"));
                $this->memory = trim($ssh->exec("free -h | grep Mem || vm_stat | head -n 5"));
                $this->bandwidth = trim($ssh->exec("vnstat --oneline || echo 'vnstat not installed'"));
            } catch (Throwable $e) {
                logger()->warning("⚠️ SSH Error: {$e->getMessage()}");
                $this->uptime = '❌ ' . $e->getMessage();
            }
        }
    }

    private function makeSshClient(): SSH2
    {
        $ip = $this->vpnServer->ip_address;
        $port = $this->vpnServer->ssh_port ?? 22;
        $user = $this->vpnServer->ssh_user ?? 'root';

        $ssh = new SSH2($ip, $port);

        if ($this->vpnServer->ssh_type === 'key') {
            $possiblePaths = [
                '/var/www/aiovpn/storage/app/ssh_keys/id_rsa',
                storage_path('app/ssh_keys/id_rsa'),
                base_path('storage/app/ssh_keys/id_rsa'),
                base_path('storage/ssh_keys/id_rsa'),
            ];

            $keyPath = collect($possiblePaths)->first(fn ($path) => is_string($path) && is_file($path));

            if (!is_string($keyPath) || $keyPath === '') {
                throw new RuntimeException('SSH private key not found.');
            }

            $keyContents = @file_get_contents($keyPath);
            if ($keyContents === false) {
                throw new RuntimeException("Failed to read SSH private key: {$keyPath}");
            }

            $key = PublicKeyLoader::load($keyContents);

            if (!$ssh->login($user, $key)) {
                throw new RuntimeException('SSH login failed (key)');
            }
        } else {
            if (!$ssh->login($user, $this->vpnServer->ssh_password)) {
                throw new RuntimeException('SSH login failed (password)');
            }
        }

        return $ssh;
    }

    public function deployServer(): void
    {
        if (blank($this->vpnServer->ip_address)) {
            session()->flash('status', '❌ Cannot deploy — missing IP address.');
            return;
        }

        $this->vpnServer->update([
            'deployment_status' => 'queued',
            'deployment_log' => '',
        ]);

        DeployVpnServer::dispatch($this->vpnServer);

        session()->flash('status', '🚀 Deployment started.');
    }

    public function rebootServer(): void
    {
        try {
            $this->makeSshClient()->exec('reboot');
            session()->flash('status', '🔄 Reboot command sent.');
        } catch (Throwable $e) {
            session()->flash('status', '❌ Reboot failed: ' . $e->getMessage());
        }
    }

    public function restartVpn(): void
    {
        try {
            // Restart both modern services (UDP and TCP if enabled)
            $this->makeSshClient()->exec('systemctl restart openvpn-server@server; systemctl is-enabled openvpn-server@server-tcp 2>/dev/null && systemctl restart openvpn-server@server-tcp || true');
            session()->flash('message', '✅ OpenVPN services restarted.');
        } catch (Throwable $e) {
            session()->flash('message', '❌ Restart failed: ' . $e->getMessage());
        }
    }

    public function deleteServer()
    {
        $name = $this->vpnServer->name;
        $this->vpnServer->delete();

        // Safe interpolation with straight quotes
        session()->flash('status', "🗑️ Server \"{$name}\" deleted.");

        return redirect()->route('admin.servers.index');
    }

    public function getFilteredLogProperty(): array
    {
        $lines = explode("\n", $this->deploymentLog ?? '');
        $filtered = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || in_array($line, $seen)) continue;

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

    public function render()
    {
        return view('livewire.pages.admin.server-show')
            ->layoutData(['heading' => 'Server Details']);

    }
}
