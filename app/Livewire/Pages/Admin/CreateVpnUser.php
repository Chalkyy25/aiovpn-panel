<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\AddWireGuardPeer;
use App\Services\VpnConfigBuilder;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
'username' => 'nullable|unique:vpn_users,username',
'password' => 'nullable|min:6',
'expiry' => 'required|in:1m,3m,6m,12m',
'selectedServers' => 'required|array|min:1',
]);

// Auto-generate username and password similar to CreateUser.php
$finalUsername = $this->username ?: 'user-' . Str::random(6);
$plainPassword = Str::random(8);

// Extract the duration in months from the expiry string
$months = (int) rtrim($this->expiry, 'm');

$vpnUser = VpnUser::create([
'username' => $finalUsername,
'plain_password' => $plainPassword, // Store plaintext password for reference
'password' => bcrypt($plainPassword), // Store hashed password for authentication
'expires_at' => now()->addMonths($months),
]);

// Sync the selected servers
$vpnUser->vpnServers()->sync($this->selectedServers);

// Reload the VPN user to ensure we have the latest data including server associations
$vpnUser->refresh();

// Manually generate OpenVPN configurations
VpnConfigBuilder::generate($vpnUser);

// Manually sync OpenVPN credentials for each server
foreach ($vpnUser->vpnServers as $server) {
    SyncOpenVPNCredentials::dispatch($server);
    Log::info("🚀 Synced OpenVPN credentials to $server->name ($server->ip_address)");
}

// Log the successful creation for debugging
Log::info("✅ VPN user created: {$vpnUser->username} (password: {$plainPassword})", [
    'username' => $vpnUser->username,
    'plain_password' => $plainPassword,
    'expires_at' => $vpnUser->expires_at,
    'servers' => $vpnUser->vpnServers->pluck('name')->toArray()
]);

// Set up WireGuard peer for the user
AddWireGuardPeer::dispatch($vpnUser);
Log::info("🔧 WireGuard peer setup queued for user {$vpnUser->username}");

// Add success message and reset form
session()->flash('success', "✅ VPN user {$vpnUser->username} created successfully! Password: {$plainPassword}");

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
