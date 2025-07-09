<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    public $username = '';
    public $selectedServers = [];
    public $vpnServers = [];

    public function mount()
    {
        $this->vpnServers = VpnServer::all();
    }

    public function save()
    {
        $this->validate([
            'username' => 'nullable|string|min:3',
            'selectedServers' => 'required|array|min:1',
        ]);

        // Check for duplicate username if entered manually
        if ($this->username && VpnUser::where('username', $this->username)->exists()) {
            $this->addError('username', 'This username is already taken. Please choose another.');
            return;
        }

        // Generate random username if blank
        $finalUsername = $this->username ?: 'user-' . Str::random(6);

        // Create VPN user with random password
        $vpnUser = VpnUser::create([
            'username' => $finalUsername,
            'password' => bcrypt(Str::random(12)),
        ]);

        // Attach user to selected servers
        $vpnUser->vpnServers()->attach($this->selectedServers);

        Log::info("✅ Created VPN user {$finalUsername} and assigned to servers.", [
            'user_id' => $vpnUser->id,
            'servers' => $this->selectedServers
        ]);

        // Dispatch jobs to set up WireGuard peers and sync OpenVPN credentials
	dispatch(new \App\Jobs\AddWireGuardPeer($vpnUser));
	dispatch(new \App\Jobs\SyncOpenVPNCredentials($vpnUser));

        session()->flash('success', '✅ VPN Client created and assigned to servers successfully!');
        return redirect()->route('admin.vpn-user-list');
    }

    public function render()
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
        ]);
    }
}
