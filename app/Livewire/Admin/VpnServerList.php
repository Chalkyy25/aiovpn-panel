<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\VpnServer;

class VpnServerList extends Component
{
    public $name, $ip, $protocol = 'OpenVPN';
    public $adding = false;
    public $confirmingDeleteId = null;

    public function save()
    {
        $this->validate([
            'name' => 'required',
            'ip' => 'required|ip',
            'protocol' => 'required|in:OpenVPN,WireGuard',
        ]);

        VpnServer::create([
            'name' => $this->name,
            'ip' => $this->ip,
            'protocol' => $this->protocol,
            'deployment_status' => 'Pending',
            'deployment_log' => '',
        ]);

        session()->flash('message', '✅ Server added.');
        $this->reset(['name', 'ip', 'protocol', 'adding']);
    }

    public function confirmDelete($id)
    {
        $this->confirmingDeleteId = $id;
    }

    public function deleteServer()
    {
        VpnServer::find($this->confirmingDeleteId)?->delete();
        $this->confirmingDeleteId = null;
        session()->flash('message', '🗑️ Server deleted.');
    }

    public function render()
    {
        return view('livewire.admin.vpn-server-list', [
            'servers' => VpnServer::latest()->get()
        ]);
    }
}
