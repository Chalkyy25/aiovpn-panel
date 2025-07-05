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
    $this->validate([
        'username' => 'nullable|string|min:3',
        'vpn_server_id' => 'required|exists:vpn_servers,id',
    ]);

    // Check if username already exists
    if ($this->username && \App\Models\VpnUser::where('username', $this->username)->exists()) {
        $this->addError('username', 'This username is already taken. Please choose another.');
        return;
    }

    // Generate random username if blank
    $finalUsername = $this->username ?: 'user-' . Str::random(6);

    // Dispatch job
    CreateVpnUser::dispatch(
        $finalUsername,
        $this->vpn_server_id,
        null,
        Str::random(12)
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
