<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    public $username = '';
    public $selectedServers = []; // Changed from single ID to array for multi-select
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

    // Create the VPN user
    $vpnUser = VpnUser::create([
        'username' => $finalUsername,
        'password' => bcrypt(Str::random(12)),
    ]);

    // Attach to selected servers (many-to-many)
    $vpnUser->vpnServers()->attach($this->selectedServers);

    // Optional: dispatch jobs to generate configs, peers, etc.
    // Example:
    // GenerateWireGuardConfig::dispatch($vpnUser);
    // SyncOpenVPNCredentials::dispatch($vpnUser);

    session()->flash('success', '✅ VPN Client created and assigned to servers successfully!');
    return redirect()->route('admin.users.index');
}

    public function render()
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
        ]);
    }
}
