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
    public VpnServer $vpnServer;

    public string $uptime          = '…';
    public string $cpu             = '…';
    public string $memory          = '…';
    public string $bandwidth       = '…';
    public string $deploymentStatus = '…';
    public string $deploymentLog   = '';

    /* ───────── Lifecycle ───────── */
    public function mount(VpnServer $vpnServer): void
    {
        $this->vpnServer = $vpnServer;

        // quick validation so we don't hammer logs if the row is bad
        if (blank($vpnServer->ip_address)) {
            logger()->error("Server {$vpnServer->id} has no IP address!");
            $this->uptime = '❌ Missing IP';
            return;
        }

        $this->refresh(); // prime data on first load
    }

    /* ───────── Polling action (called by wire:poll) ───────── */
    public function refresh(): void
    {
        $this->vpnServer->refresh();
        $this->deploymentLog = $this->vpnServer->deployment_log; // <-- Add this

        if (blank($this->vpnServer->ip_address)) {
            logger()->warning("Server #{$this->vpnServer->id} has no IP address during refresh!");
            $this->uptime = '❌ Missing IP';
            return;
        }

        $this->deploymentStatus = (string) ($this->vpnServer->deployment_status ?? '');

        try {
            $ssh = $this->makeSshClient();

            $this->uptime    = trim($ssh->exec("uptime"));
            $this->cpu       = trim($ssh->exec("top -bn1 | grep 'Cpu(s)' || top -l 1 | grep 'CPU usage'"));
            $this->memory    = trim($ssh->exec("free -h | grep Mem || vm_stat | head -n 5"));
            $this->bandwidth = trim($ssh->exec("vnstat --oneline || echo 'vnstat not installed'"));
        } catch (\Throwable $e) {
            $this->uptime = '❌ ' . $e->getMessage();
            logger()->warning("Live-stats SSH error (#{$this->vpnServer->id}): {$e->getMessage()}");
        }
    }
    public function getFilteredLogProperty()
    {
        $lines = explode("\n", $this->deploymentLog ?? '');

        $filtered = array_filter($lines, function ($line) {
            return !preg_match('/^\.+\+|\*+|DH parameters appear to be ok|Generating DH parameters|DEPRECATED OPTION|Reading database|^-----$/', $line)
                && trim($line) !== '';
        });

        return $filtered;
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
        $name = $this->vpnServer->name;
        $this->vpnServer->delete();
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
        logger()->info("SSH → {$this->vpnServer->ip_address}:{$this->vpnServer->ssh_port}");

        $ssh = new SSH2($this->vpnServer->ip_address, $this->vpnServer->ssh_port);

        // credential handling
        if ($this->vpnServer->ssh_type === 'key') {
            if (blank($this->vpnServer->ssh_key) || !is_file($this->vpnServer->ssh_key)) {
                throw new \RuntimeException('SSH key not found');
            }
            $key = PublicKeyLoader::load(file_get_contents($this->vpnServer->ssh_key));
            $login = $ssh->login($this->vpnServer->ssh_user, $key);
        } else {
            $login = $ssh->login($this->vpnServer->ssh_user, $this->vpnServer->ssh_password);
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
