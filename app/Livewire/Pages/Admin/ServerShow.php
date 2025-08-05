<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer; // ✅ Import your job
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use Illuminate\Support\Str;

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
    $vpnServer = $vpnServer->fresh();

    // Check if model is null or has no IP
    if (!$vpnServer || blank($vpnServer->ip_address)) {
        logger()->error("Server {$vpnServer->name} has no IP address!", [
            'id' => $vpnServer->id ?? 'null',
            'ip_address' => $vpnServer->ip_address ?? 'null',
        ]);
        $this->uptime = '❌ Missing IP';
        return;
    }

    $this->vpnServer = $vpnServer;
    $this->refresh();
}

    public function refresh(): void
    {
        $this->vpnServer = $this->vpnServer->fresh();
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

    public function getFilteredLogProperty()
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
        try {
            $ssh = $this->makeSshClient();
            $ssh->exec('reboot');
            session()->flash('status', '🔄 Reboot command sent successfully.');
        } catch (\Throwable $e) {
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

        $this->vpnServer->update([
            'deployment_status' => 'queued',
            'deployment_log' => '',
        ]);

        DeployVpnServer::dispatch($this->vpnServer); // ✅ Uses imported class
        session()->flash('status', '✅ Deployment triggered successfully.');
    }

    public function restartVpn(): void
    {
        try {
            $ssh = $this->makeSshClient();
            $ssh->exec('systemctl restart openvpn@server');
            session()->flash('message', '✅ OpenVPN service restarted.');
        } catch (\Throwable $e) {
            session()->flash('message', '❌ Restart failed: ' . $e->getMessage());
        }
    }

    private function makeSshClient(): SSH2
    {
        logger()->info("SSH → {$this->vpnServer->ip_address}:{$this->vpnServer->ssh_port}");
        $ssh = new SSH2($this->vpnServer->ip_address, $this->vpnServer->ssh_port);

        if ($this->vpnServer->ssh_type === 'key') {
            $keyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';
            if (!is_file($keyPath)) {
                throw new \RuntimeException('SSH key not found at ' . $keyPath);
            }
            $key = PublicKeyLoader::load(file_get_contents($keyPath));
            $login = $ssh->login($this->vpnServer->ssh_user, $key);
        } else {
            $login = $ssh->login($this->vpnServer->ssh_user, $this->vpnServer->ssh_password);
        }

        if (!$login) {
            throw new \RuntimeException('SSH login failed');
        }

        return $ssh;
    }

    public function render()
    {
        return view('livewire.pages.admin.server-show');
    }
}
