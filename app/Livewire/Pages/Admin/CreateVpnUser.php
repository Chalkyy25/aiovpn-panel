<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Livewire\Component;

class CreateVpnUser extends Component
{
public $username;
public $password;
public $selectedServers = [];
public $expiry;

public function save()
{
$this->validate([
'username' => 'required|unique:vpn_users,username',
'password' => 'required|min:4',
'expiry' => 'required|in:1m,3m,6m,12m',
'selectedServers' => 'required|array|min:1',
]);

$vpnUser = VpnUser::create([
'username' => $this->username,
'password' => $this->password,
'expires_at' => now()->addMonths((int) rtrim($this->expiry, 'm')),
]);

$vpnUser->vpnServers()->sync($this->selectedServers);

session()->flash('message', 'VPN user created successfully!');
return redirect()->route('admin.vpn-users.index');
}

public function render()
{
return view('livewire.pages.admin.create-vpn-user', [
'servers' => VpnServer::all(),
]);
}
}
