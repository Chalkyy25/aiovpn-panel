<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ServerCreate extends Component
{
    /* ───── Form fields ───── */
    public $name;
    public $ip;
    public $protocol   = 'OpenVPN';
    public $sshType    = 'key';
    public $sshPort    = 22;
    public $sshUsername = 'root';
    public $sshPassword;

    public $port       = 1194;
    public $transport  = 'udp';
    public $dns        = '1.1.1.1';

    public $enableIPv6    = false;
    public $enableLogging = false;
    public $enableProxy   = false;
    public $header1       = false;
    public $header2       = false;

    /* ───── Deployment state ───── */
    public $serverId      = null;
    public $deploymentLog = '';
    public $isDeploying   = false;

    /* Polling for live log */
    public function refreshLog()
    {
        if ($this->serverId) {
            $server = VpnServer::find($this->serverId);
            $this->deploymentLog = $server->deployment_log ?? '';
        }
    }

    /* Create & deploy */
    public function create()
    {
        $this->validate([
            'name'       => 'required|string|max:100',
            'ip'         => 'required|ip',
            'protocol'   => 'required|in:OpenVPN,WireGuard',
            'sshPort'    => 'required|integer|between:1,65535',
            'sshType'    => 'required|in:key,password',
            'sshUsername'=> 'required|string',
            'sshPassword'=> $this->sshType === 'password' ? 'required|string' : 'nullable',
            'port'       => 'nullable|integer',
            'transport'  => 'nullable|in:udp,tcp',
            'dns'        => 'nullable|string',
        ]);

        $server = VpnServer::create([
            'name'            => $this->name,
            'ip_address'      => $this->ip,
            'protocol'        => strtolower($this->protocol),
            'ssh_port'        => $this->sshPort,
            'ssh_user'        => $this->sshUsername,
            'ssh_type'        => $this->sshType,
            'ssh_password'    => $this->sshType === 'password' ? $this->sshPassword : null,
            'port'            => $this->port,
            'transport'       => $this->transport,
            'dns'             => $this->dns,
            'enable_ipv6'     => $this->enableIPv6,
            'enable_logging'  => $this->enableLogging,
            'enable_proxy'    => $this->enableProxy,
            'header1'         => $this->header1,
            'header2'         => $this->header2,
            'deployment_status' => 'queued',
            'deployment_log'    => '',
        ]);

        /* set UI state before launching job */
        $this->serverId      = $server->id;
        $this->deploymentLog = '';
        $this->isDeploying   = true;

        Log::info("🚀 Dispatching DeployVpnServer for #{$server->id}");
        /** sync queue = immediate run but in queue lifecycle */
        dispatch(new DeployVpnServer($server))
            ->onQueue('deployments');
            Log::info("✅ DeployVpnServer job was successfully dispatched to the queue for IP: {$server->ip_address}");


	$this->isDeploying = false;

	return redirect()->route('admin.servers.install-status', [
   	 'vpnServer' => $server->id
	]);


    }

    public function render()
    {
        return view('livewire.pages.admin.server-create');
    }
}
