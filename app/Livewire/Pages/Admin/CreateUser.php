<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\VpnServer;
use App\Jobs\CreateVpnUser;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\log;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    public $username = '';
    public $vpn_server_id = '';

    public $vpnServers = [];

    public function mount()
    {
        $this->vpnServers = VpnServer::all();
    }

    public function save()
    {
Log::info('🔥 Save method triggered');
        $this->validate([
            'username' => 'nullable|string|min:3',
            'vpn_server_id' => 'required|exists:vpn_servers,id',
        ]);

        // Generate random username if blank
        $finalUsername = $this->username ?: 'user-' . Str::random(6);

        // Dispatch CreateVpnUser job for this client
        CreateVpnUser::dispatch(
            $finalUsername,
            $this->vpn_server_id,
            null, // no client_id needed for pure VPN user accounts
            Str::random(12) // random password
        );

        session()->flash('success', '✅ VPN Client created successfully!');
        return redirect()->route('admin.users.index');
    }

    public function render()
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
        ]);
    }
}
