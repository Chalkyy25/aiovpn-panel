<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnServer;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

#[Layout('layouts.app')]
class ServerShow extends Component
{
    /* ───────── State ───────── */
    public VpnServer $server;

    public string $uptime          = '…';
    public string $cpu             = '…';
    public string $memory          = '…';
    public string $bandwidth       = '…';
    public string $deploymentStatus = '…';

    /* ───────── Lifecycle ───────── */
    public function mount(VpnServer $server): void
    {
        $this->server = $server;

        // quick validation so we don't hammer logs if the row is bad
        if (blank($server->ip_address)) {
            logger()->error("Server {$server->id} has no IP address!");
            $this->uptime = '❌ Missing IP';
            return;
        }

        $this->refresh();       // prime data on first load
    }

    /* ───────── Polling action (called by wire:poll) ───────── */
    public function refresh(): void
    {
        $this->server->refresh();
        // Always cast to string, fallback to empty string if null
        $this->deploymentStatus = (string) ($this->server->deployment_status ?? '');

        try {
            $ssh = $this->makeSshClient();

            // small helpers so we don’t break on busybox vs full GNU tools
            $this->uptime    = trim($ssh->exec("uptime"));
            $this->cpu       = trim($ssh->exec("top -bn1 | grep 'Cpu(s)' || top -l 1 | grep 'CPU usage'"));
            $this->memory    = trim($ssh->exec("free -h | grep Mem || vm_stat | head -n 5"));
            $this->bandwidth = trim($ssh->exec("vnstat --oneline || echo 'vnstat not installed'"));
        } catch (\Throwable $e) {
            $this->uptime = '❌ ' . $e->getMessage();
            logger()->warning("Live-stats SSH error (#{$this->server->id}): {$e->getMessage()}");
        }
    }

    /* ───────── Actions ───────── */
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
        $name = $this->server->name;
        $this->server->delete();
        session()->flash('status', "🗑️  Server “{$name}” deleted.");
        $this->redirectRoute('admin.servers.index');
    }

    /** Placeholder – swap in real config generator later */
    public function generateOvpn(): void
    {
        // TODO: generate & return a signed .ovpn file
        session()->flash('status', '📥 .ovpn generation stub triggered (not yet implemented).');
    }

    /* ───────── Helpers ───────── */
    private function makeSshClient(): SSH2
    {
        logger()->info("SSH → {$this->server->ip_address}:{$this->server->ssh_port}");

        $ssh = new SSH2($this->server->ip_address, $this->server->ssh_port);

        // credential handling
        if ($this->server->ssh_type === 'key') {
            if (blank($this->server->ssh_key_path) || !is_file($this->server->ssh_key_path)) {
                throw new \RuntimeException('SSH key not found');
            }
            $key = PublicKeyLoader::load(file_get_contents($this->server->ssh_key_path));
            $login = $ssh->login($this->server->ssh_user, $key);
        } else {
            $login = $ssh->login($this->server->ssh_user, $this->server->ssh_password);
        }

        if (!$login) {
            throw new \RuntimeException('SSH login failed');
        }

        return $ssh;
    }

    /* ───────── View ───────── */
    public function render()
    {
        return view('livewire.pages.admin.server-show');
    }
}
