<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\VpnServer;
use App\Jobs\DeployVpnServer; // Make sure this job exists
use Illuminate\Support\Facades\Log;

class ServerCreate extends Component
{
    public $name, $ip, $protocol = 'OpenVPN', $sshPort = 22, $sshType = 'key',
           $sshKey, $sshPassword, $port = 1194, $transport = 'udp', $dns = '1.1.1.1',
           $enableIPv6 = false, $enableLogging = false, $enableProxy = false,
           $header1 = false, $header2 = false;

    public function create()
    {
        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'protocol' => 'required|in:OpenVPN,WireGuard',
            'sshPort' => 'required|integer|min:1',
            'sshType' => 'required|in:key,password',
            'sshKey' => 'required_if:sshType,key',
            'sshPassword' => 'required_if:sshType,password',
            'port' => 'required|integer|min:1',
            'transport' => 'required|in:udp,tcp',
            'dns' => 'required|string',
            'enableIPv6' => 'boolean',
            'enableLogging' => 'boolean',
            'enableProxy' => 'boolean',
            'header1' => 'boolean',
            'header2' => 'boolean',
        ]);

        $server = VpnServer::create([
            'name' => $this->name,
            'ip_address' => $this->ip,
            'protocol' => $this->protocol,
            'ssh_port' => $this->sshPort,
            'ssh_type' => $this->sshType,
            'ssh_key' => $this->sshKey,
            'ssh_password' => $this->sshPassword,
            'port' => $this->port,
            'transport' => $this->transport,
            'dns' => $this->dns,
            'enable_ipv6' => $this->enableIPv6,
            'enable_logging' => $this->enableLogging,
            'enable_proxy' => $this->enableProxy,
            'header1' => $this->header1,
            'header2' => $this->header2,
            'deployment_status' => 'pending',
            'deployment_log' => "Server created. Waiting for deployment trigger...",
        ]);

        // Dispatch deployment job for automation
        try {
            dispatch(new DeployVpnServer($server));
        } catch (\Exception $e) {
            Log::error('Failed to dispatch DeployVpnServer job: ' . $e->getMessage());
            $server->update([
                'deployment_status' => 'error',
                'deployment_log' => 'Failed to dispatch deployment job.',
            ]);
            session()->flash('status-message', '❌ Failed to trigger deployment.');
            return;
        }

        session()->flash('status-message', '✅ VPN server added and deployment triggered.');
        $this->reset();
    }

    public function render()
    {
        return view('livewire.admin.server-create');
    }
}
