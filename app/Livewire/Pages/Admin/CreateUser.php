<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Jobs\CreateVpnUser;

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

        // Check for duplicate username if manually entered
        if ($this->username && VpnUser::where('username', $this->username)->exists()) {
            $this->addError('username', 'This username is already taken. Please choose another.');
            return;
        }

        // Randomize username/password
        $finalUsername = $this->username ?: 'user-' . Str::random(6);
        $plainPassword = Str::random(8);

        // Dispatch CreateVpnUser job for each selected server
        foreach ($this->selectedServers as $serverId) {
            dispatch(new CreateVpnUser(
                username: $finalUsername,
                vpnServerId: $serverId,
                clientId: null, // or auth()->id() if needed
                password: $plainPassword
            ));
        }

        Log::info("✅ Queued VPN user creation for {$finalUsername} (password: {$plainPassword}) on servers: ", $this->selectedServers);

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