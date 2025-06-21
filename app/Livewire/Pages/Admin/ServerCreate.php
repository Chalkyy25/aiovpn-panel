<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Log;

#[Layout('layouts.app')]
class ServerCreate extends Component
{
    public $name;
    public $ip;
    public $protocol     = 'OpenVPN';
    public $sshType      = 'key';
    public $sshPort      = 22;
    public $sshUsername  = 'root';
    public $sshPassword;

    public $port         = 1194;
    public $transport    = 'udp';
    public $dns          = '1.1.1.1';

    public $enableIPv6   = false;
    public $enableLogging = false;
    public $enableProxy  = false;
    public $header1      = false;
    public $header2      = false;

    public function create()
    {
        Log::info('🛠️ Server creation triggered', ['ip' => $this->ip]);

        $this->validate([
            'name'        => 'required|string|max:100',
            'ip'          => 'required|ip',
            'protocol'    => 'required|in:OpenVPN,WireGuard',
            'sshPort'     => 'required|integer|min:1|max:65535',
            'sshType'     => 'required|in:key,password',
            'sshUsername' => 'required|string',
            'sshPassword' => $this->sshType === 'password' ? 'required|string' : 'nullable',
            'port'        => 'nullable|integer',
            'transport'   => 'nullable|in:udp,tcp',
            'dns'         => 'nullable|string',
        ]);

        $sshKeyPath = null;
        if ($this->sshType === 'key') {
            $sshKeyPath = '/var/www/aiovpn/storage/app/ssh_keys/id_rsa';
            if (!file_exists($sshKeyPath)) {
                Log::warning("⚠️ SSH key path missing: $sshKeyPath");
            }
        }

        $server = VpnServer::create([
            'name'              => $this->name,
            'ip_address'        => $this->ip,
            'protocol'          => strtolower($this->protocol),
            'ssh_port'          => $this->sshPort,
            'ssh_user'          => $this->sshUsername,
            'ssh_type'          => $this->sshType,
            'ssh_password'      => $this->sshType === 'password' ? $this->sshPassword : null,
            'ssh_key'           => $sshKeyPath, // <-- FIXED: use 'ssh_key'
            'port'              => $this->port,
            'transport'         => $this->transport,
            'dns'               => $this->dns,
            'enable_ipv6'       => $this->enableIPv6,
            'enable_logging'    => $this->enableLogging,
            'enable_proxy'      => $this->enableProxy,
            'header1'           => $this->header1,
            'header2'           => $this->header2,
            'deployment_status' => 'queued',
            'deployment_log'    => '',
        ]);

        Log::info('🚀 Dispatching server deployment job for server #' . $server->id);
        dispatch(new DeployVpnServer($server));

        return redirect()->route('admin.servers.install-status', $server);
    }

    public function render()
    {
        return view('livewire.pages.admin.server-create');
    }
}
