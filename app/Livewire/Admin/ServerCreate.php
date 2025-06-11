<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\VpnServer;

class ServerCreate extends Component
{
    public $name, $ip, $protocol = 'OpenVPN', $sshPort = 22, $sshType = 'key',
           $sshKey, $sshPassword, $port = 1194, $transport = 'udp', $dns = '1.1.1.1',
           $enableIPv6 = false, $enableLogging = false, $enableProxy = false,
           $header1 = false, $header2 = false;

    public function create()
    {
        $this->validate([
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
        ]);

        VpnServer::create([
            'name' => $this->name,
            'ip' => $this->ip,
            'protocol' => $this->protocol,
            'deployment_status' => 'pending',
            'deployment_log' => "Server created. Waiting for deployment trigger...",
        ]);

        session()->flash('status-message', '✅ VPN server added successfully.');
        $this->reset();
    }

    public function render()
    {
        return view('livewire.admin.server-create');
    }
}
