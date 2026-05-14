<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\DeployVpnServer;
use App\Models\VpnServer;
use Livewire\Attributes\Layout;
use Livewire\Component;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;


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
            logger()->error("❌ Server {$server->id} missing IP address");

            $this->uptime = '❌ Missing IP';

            return;
        }

        $this->vpnServer = $server;

        $this->refresh();
    }

    public function refresh(): void
    {
        $server = VpnServer::find($this->vpnServer->id);

        if (! $server) {
            return;
        }

        $this->vpnServer = $server;

        $this->deploymentLog = $server->deployment_log ?? '';
        $this->deploymentStatus = $server->deployment_status ?? 'unknown';

        if (! in_array($this->deploymentStatus, ['success', 'deployed'])) {
            return;
        }

        try {

            $ssh = $this->makeSshClient();

            // -------------------------------------------------
            // RAW SSH METRICS
            // -------------------------------------------------

            $this->uptime = trim(
                $ssh->exec('uptime')
            );

            $this->cpu = trim(
                $ssh->exec("top -bn1 | grep 'Cpu(s)' || top -l 1 | grep 'CPU usage'")
            );

            $this->memory = trim(
                $ssh->exec("free -m | grep Mem || vm_stat | head")
            );

            $this->bandwidth = trim(
                $ssh->exec("vnstat --oneline || echo 'vnstat not installed'")
            );

            // -------------------------------------------------
            // PARSE CPU
            // -------------------------------------------------

            $cpuUsage = null;

            if (preg_match('/(\d+\.\d+)\s*id/', $this->cpu, $cpuMatch)) {
                $cpuUsage = round(
                    100 - (float) $cpuMatch[1],
                    1
                );
            }

            // -------------------------------------------------
            // PARSE MEMORY
            // Linux free -m format
            // -------------------------------------------------

            $memoryUsage = null;

            if (preg_match('/Mem:\s+(\d+)\s+(\d+)/', $this->memory, $ramMatch)) {

                $totalRam = (int) $ramMatch[1];
                $usedRam  = (int) $ramMatch[2];

                if ($totalRam > 0) {
                    $memoryUsage = round(
                        ($usedRam / $totalRam) * 100,
                        1
                    );
                }
            }

            // -------------------------------------------------
            // PARSE LOAD
            // -------------------------------------------------

            $loadAverage = null;

            if (preg_match('/load average:\s*([0-9\.]+)/', $this->uptime, $loadMatch)) {
                $loadAverage = (float) $loadMatch[1];
            }

            // -------------------------------------------------
            // UPDATE SERVER HEALTH
            // -------------------------------------------------

            $this->vpnServer->update([
                'cpu_usage'     => $cpuUsage,
                'memory_usage'  => $memoryUsage,
                'load_average'  => $loadAverage,
                'last_sync_at'  => now(),
            ]);

        } catch (Throwable $e) {

            logger()->warning(
                "⚠️ SSH Error [{$this->vpnServer->name}]: {$e->getMessage()}"
            );

            $this->uptime = '❌ ' . $e->getMessage();
        }
    }

    private function makeSshClient(): SSH2
    {
        $ip   = $this->vpnServer->ip_address;
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

            $keyPath = collect($possiblePaths)
                ->first(fn ($path) => is_string($path) && is_file($path));

            if (! $keyPath) {
                throw new RuntimeException('SSH private key not found');
            }

            $keyContents = file_get_contents($keyPath);

            if (! $keyContents) {
                throw new RuntimeException("Unable to read SSH key: {$keyPath}");
            }

            $key = PublicKeyLoader::load($keyContents);

            if (! $ssh->login($user, $key)) {
                throw new RuntimeException('SSH login failed using key');
            }

        } else {

            if (! $ssh->login($user, $this->vpnServer->ssh_password)) {
                throw new RuntimeException('SSH login failed using password');
            }
        }

        return $ssh;
    }

    public function deployServer(): void
    {
        if (blank($this->vpnServer->ip_address)) {

            session()->flash(
                'status',
                '❌ Cannot deploy server without IP address.'
            );

            return;
        }

        $this->vpnServer->update([
            'deployment_status' => 'queued',
            'deployment_log'    => '',
        ]);

        DeployVpnServer::dispatch($this->vpnServer);

        session()->flash(
            'status',
            "🚀 Deployment queued for {$this->vpnServer->name}"
        );
    }

    public function rebootServer(): void
    {
        try {

            $this->makeSshClient()->exec('reboot');

            session()->flash(
                'status',
                '🔄 Reboot command sent.'
            );

        } catch (Throwable $e) {

            session()->flash(
                'status',
                '❌ Reboot failed: ' . $e->getMessage()
            );
        }
    }

    public function restartVpn(): void
    {
        try {

            $this->makeSshClient()->exec(
                'systemctl restart openvpn-server@server;
                 systemctl is-enabled openvpn-server@server-tcp >/dev/null 2>&1
                 && systemctl restart openvpn-server@server-tcp || true'
            );

            session()->flash(
                'status',
                '✅ VPN services restarted.'
            );

        } catch (Throwable $e) {

            session()->flash(
                'status',
                '❌ Restart failed: ' . $e->getMessage()
            );
        }
    }

    public function deleteServer()
    {
        $name = $this->vpnServer->name;

        $this->vpnServer->delete();

        session()->flash(
            'status',
            "🗑️ Server \"{$name}\" deleted."
        );

        return redirect()->route('admin.servers.index');
    }

    public function getFilteredLogProperty(): array
    {
        $lines = explode("\n", $this->deploymentLog ?? '');

        $filtered = [];
        $seen = [];

        foreach ($lines as $line) {

            $line = trim($line);

            if ($line === '' || in_array($line, $seen)) {
                continue;
            }

            $seen[] = $line;

            $color = match (true) {

                str_contains($line, '❌') =>
                    'text-red-400',

                str_contains($line, '✅') =>
                    'text-green-400',

                str_contains($line, 'WARNING') =>
                    'text-yellow-400',

                default =>
                    '',
            };

            $filtered[] = [
                'text'  => $line,
                'color' => $color,
            ];
        }

        return $filtered;
    }

    public function render()
    {
        return view('livewire.pages.admin.server-show')
            ->layoutData([
                'heading' => 'Server Details',
            ]);
    }
}