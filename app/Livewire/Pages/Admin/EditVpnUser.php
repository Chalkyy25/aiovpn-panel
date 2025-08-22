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
class EditVpnUser extends Component
{
    public VpnUser $vpnUser;
    public $username;
    public $password;
    public $selectedServers = [];
    public $expiry = '1m';
    public $maxConnections;
    public $isActive;
    public $extendExpiry = false;

    /**
     * Set up initial component state
     */
    public function mount(VpnUser $vpnUser): void
    {
        $this->vpnUser = $vpnUser;
        $this->username = $vpnUser->username;
        $this->password = $vpnUser->plain_password ?? '';
        $this->selectedServers = $vpnUser->vpnServers->pluck('id')->toArray();
        $this->maxConnections = $vpnUser->max_connections ?? 1;
        $this->isActive = $vpnUser->is_active;

        // Calculate expiry based on current expires_at
        if ($vpnUser->expires_at) {
            $monthsFromNow = now()->diffInMonths($vpnUser->expires_at, false);
            if ($monthsFromNow <= 1) {
                $this->expiry = '1m';
            } elseif ($monthsFromNow <= 3) {
                $this->expiry = '3m';
            } elseif ($monthsFromNow <= 6) {
                $this->expiry = '6m';
            } else {
                $this->expiry = '12m';
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'username' => 'required|unique:vpn_users,username,' . $this->vpnUser->id,
            'password' => 'nullable|min:6',
            'expiry' => 'required|in:1m,3m,6m,12m',
            'selectedServers' => 'required|array|min:1',
            'maxConnections' => 'required|integer|min:1|max:10',
            'isActive' => 'boolean',
            'extendExpiry' => 'boolean',
        ]);

        // Prepare update data
        $updateData = [
            'username' => $this->username,
            'max_connections' => $this->maxConnections,
            'is_active' => $this->isActive,
        ];

        // Only update expiry date if extend expiry is checked
        if ($this->extendExpiry) {
            $months = (int) rtrim($this->expiry, 'm');
            $updateData['expires_at'] = now()->addMonths($months);
        }

        // Only update password if provided
        if (!empty($this->password)) {
            $updateData['plain_password'] = $this->password;
            $updateData['password'] = bcrypt($this->password);
        }

        // Update the VPN user
        $this->vpnUser->update($updateData);

        // Get current server assignments
        $currentServers = $this->vpnUser->vpnServers->pluck('id')->toArray();
        $newServers = $this->selectedServers;

        // Sync the selected servers
        $this->vpnUser->vpnServers()->sync($this->selectedServers);

        // Reload the VPN user to ensure we have the latest data
        $this->vpnUser->refresh();

        // If servers changed, regenerate configs and sync credentials
        if (array_diff($currentServers, $newServers) || array_diff($newServers, $currentServers)) {
            // Regenerate OpenVPN configurations
            VpnConfigBuilder::generate($this->vpnUser);

            // Sync OpenVPN credentials for each server
            foreach ($this->vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
                Log::info("🚀 Synced OpenVPN credentials to $server->name ($server->ip_address)");
            }

            // Set up WireGuard peer for the user
            AddWireGuardPeer::dispatch($this->vpnUser);
            Log::info("🔧 WireGuard peer setup queued for user {$this->vpnUser->username}");
        }

        // Log the successful update
        Log::info("✅ VPN user updated: {$this->vpnUser->username}", [
            'username' => $this->vpnUser->username,
            'expires_at' => $this->vpnUser->expires_at,
            'max_connections' => $this->vpnUser->max_connections,
            'is_active' => $this->vpnUser->is_active,
            'servers' => $this->vpnUser->vpnServers->pluck('name')->toArray()
        ]);

        // Add success message
        session()->flash('success', "✅ VPN user {$this->vpnUser->username} updated successfully!");
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.pages.admin.edit-vpn-user', [
            'servers' => VpnServer::all(),
        ]);
    }
}
