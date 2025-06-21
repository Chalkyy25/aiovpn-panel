<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerShow extends Component
{
    public VpnServer $server;

    public $uptime = '...';
    public $cpu = '...';
    public $memory = '...';
    public $bandwidth = '...';
    public $deploymentStatus = '...';

    public function mount(VpnServer $server)
    {
        $this->server = $server;

        if (empty($server->ip_address)) {
            logger()->error("Missing IP for Server ID {$server->id}");
        }

        $this->refresh(); // preload
    }

    public function refresh()
    {
        $this->server->refresh(); // get latest DB info
        $this->deploymentStatus = $this->server->deployment_status;

        $ip = trim((string) $this->server->ip_address);
        $port = $this->server->ssh_port ?? 22;

        if (empty($ip)) {
            $this->uptime = '❌ IP address is missing';
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->uptime = "❌ Invalid IP address: [$ip]";
            return;
        }

        logger()->info("Connecting to SSH on $ip:$port");

        try {
            $ssh = new SSH2($ip, $port);

            if ($this->server->ssh_type === 'key') {
                $key = PublicKeyLoader::load(file_get_contents($this->server->ssh_key_path));
                $login = $ssh->login($this->server->ssh_user, $key);
            } else {
                $login = $ssh->login($this->server->ssh_user, $this->server->ssh_password);
            }

            if (!$login) {
                $this->uptime = '❌ SSH login failed';
                return;
            }

            $this->uptime    = trim($ssh->exec('uptime'));
            $this->cpu       = trim($ssh->exec("top -bn1 | grep 'Cpu(s)' || top -l 1 | grep 'CPU usage'"));
            $this->memory    = trim($ssh->exec("free -h | grep Mem || vm_stat | grep 'Pages'"));
            $this->bandwidth = trim($ssh->exec("vnstat --oneline || echo 'vnstat not installed'"));

        } catch (\Exception $e) {
            $this->uptime = '❌ ' . $e->getMessage();
            logger()->error('SSH connection failed', ['error' => $e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.pages.admin.server-show');
    }
}
