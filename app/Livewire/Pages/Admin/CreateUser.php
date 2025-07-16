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
public $deviceName = '';
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
        'deviceName' => 'nullable|string|min:2',
        'selectedServers' => 'required|array|min:1',
    ]);

// Check for duplicate username if entered manually
    if ($this->username && VpnUser::where('username', $this->username)->exists()) {
        $this->addError('username', 'This username is already taken. Please choose another.');
        return;
    }

    // Generate random username if blank
    $finalUsername = $this->username ?: 'user-' . Str::random(6);

    // Generate random plain password
    $plainPassword = Str::random(8);

    // ✅ Create VPN user with device name, save both plain and hashed password
    $vpnUser = VpnUser::create([
        'username' => $finalUsername,
        'plain_password' => $plainPassword,
        'password' => bcrypt($plainPassword),
        'device_name' => $this->deviceName,
    ]);

    // Attach user to selected servers
    $vpnUser->vpnServers()->attach($this->selectedServers);

    // 🔥 Reload to ensure vpnServers relationship is fresh
    $vpnUser->load('vpnServers');

    Log::info("✅ Created VPN user {$finalUsername} for device {$this->deviceName}", [
        'user_id' => $vpnUser->id,
        'servers' => $vpnUser->vpnServers->pluck('id')->toArray(),
        'plain_password' => $plainPassword,
    ]);

    // Dispatch job to set up WireGuard peers
    dispatch(new \App\Jobs\AddWireGuardPeer($vpnUser));

    // Dispatch SyncOpenVPNCredentials and GenerateOvpnFile per assigned server
    foreach ($vpnUser->vpnServers as $server) {
        dispatch(new \App\Jobs\SyncOpenVPNCredentials($server));
        dispatch(new \App\Jobs\GenerateOvpnFile($vpnUser, $server));
    }

    session()->flash('success', "✅ VPN Client {$finalUsername} created for device {$this->deviceName}");

    return redirect()->route('admin.vpn-user-list');
}

    public function render()
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
        ]);
    }
}
