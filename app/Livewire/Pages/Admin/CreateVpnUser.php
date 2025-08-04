<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateVpnUser extends Component
{
public $username;
public $password;
public $selectedServers = [];
public $expiry = '1m'; // Set default value

/**
 * Set up initial component state
 */
public function mount(): void
{
    // Set default expiry to 1 month if not specified
    if (empty($this->expiry)) {
        $this->expiry = '1m';
    }
}

public function save(): void
{
$this->validate([
'username' => 'required|unique:vpn_users,username',
'password' => 'required|min:4',
'expiry' => 'required|in:1m,3m,6m,12m',
'selectedServers' => 'required|array|min:1',
]);

// Extract the duration in months from the expiry string
$months = (int) rtrim($this->expiry, 'm');

$vpnUser = VpnUser::create([
'username' => $this->username,
'plain_password' => $this->password, // Store plaintext password for reference
'password' => bcrypt($this->password), // Store hashed password for authentication
'expires_at' => now()->addMonths($months),
]);

$vpnUser->vpnServers()->sync($this->selectedServers);

// Log the successful creation for debugging
Log::info("VPN user created", [
    'username' => $vpnUser->username,
    'expires_at' => $vpnUser->expires_at,
    'servers' => $vpnUser->vpnServers->pluck('id')->toArray()
]);

// Add success message and reset form
session()->flash('success', 'VPN user created successfully!');

// Reset form fields
$this->reset(['username', 'password', 'selectedServers']);
$this->expiry = '1m'; // Reset to default
}

public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
{
return view('livewire.pages.admin.create-vpn-user', [
'servers' => VpnServer::all(),
]);
}
}
