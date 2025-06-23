<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    public $name = '';
    public $email = '';
    public $password = '';
    public $role = '';
    public $vpn_server_id = ''; // For client assignment

    public $vpnServers = [];

    public function mount()
    {
	$this->vpnServers = \App\Models\VpnServer::all();
    }

public function save()
{
    $this->validate([
        'name'     => 'required',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|min:6',
        'role'     => 'required|in:admin,reseller,client',
	'vpn_server_id' => 'required_if:role,client|exists:vpn_servers,id',
    ]);

    // Create the user
    $user = User::create([
        'name'     => $this->name,
        'email'    => $this->email,
        'password' => bcrypt($this->password),
        'role'     => $this->role,
    ]);

    // ---- VPN User + Bulk Sync: only if it's a client ----
if ($user->role === 'client') {
    // Use the selected server from the dropdown
    $serverId = $this->vpn_server_id;
    $vpnServer = \App\Models\VpnServer::find($serverId);

    $vpnUsername = strtolower(str_replace(' ', '', $user->name)) . rand(1000, 9999);
    $vpnPassword = Str::random(10);

    $vpnUser = \App\Models\VpnUser::create([
        'vpn_server_id' => $vpnServer->id,
        'username'      => $vpnUsername,
        'password'      => $vpnPassword,
        'client_id'     => $user->id,
    ]);

    // Bulk sync
    \App\Jobs\SyncOpenVPNCredentials::dispatch($vpnServer);
}
    // Redirect to user management page
    return redirect()->route('admin.users.index');
}
    public function render()
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
        ]);
    }
}
