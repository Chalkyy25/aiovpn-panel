<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\RemoveWireGuardPeer;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use Livewire\WithPagination;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Log;
use App\Jobs\GenerateOvpnFile;
use App\Jobs\AddWireGuardPeer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

#[Layout('layouts.app')]
class VpnUserList extends Component
{
    use WithPagination;

    public $search = '';

    #[On('refreshUsers')]
    public function refresh()
    {
        // Refresh component
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Delete a VPN user and automatically clean up peers and files.
     */
    public function deleteUser($id): void
    {
        $user = VpnUser::findOrFail($id);
        $username = $user->username;

        // Delete user - cleanup jobs will be automatically dispatched via model events
        $user->delete();

        Log::info("🗑️ Deleted VPN user $username with auto-cleanup");
        session()->flash('message', "User $username deleted successfully! Cleanup jobs have been queued.");

        // Reset pagination to reflect an updated list
        $this->resetPage();
    }

    /**
     * Generate modern stealth-enabled OpenVPN configs for this user.
     */
    public function generateOvpn($id): void
    {
        $user = VpnUser::findOrFail($id);

        // Use the updated GenerateOvpnFile job which now supports stealth configs
        GenerateOvpnFile::dispatch($user);

        Log::info("�️ Modern stealth OVPN generation queued for user $user->username");

        session()->flash('message', "Modern stealth OVPN configs for $user->username have been queued. Includes unified (TCP 443 + UDP fallback), stealth, and traditional variants.");
    }

    /**
     * Generate WireGuard peer setup for this user.
     */
    public function generateWireGuard($id): void
    {
        $user = VpnUser::findOrFail($id);

        AddWireGuardPeer::dispatch($user);

        Log::info("🔧 WireGuard peer setup queued for user $user->username");

        session()->flash('message', "WireGuard peer setup for $user->username has been queued.");
    }

    /**
     * Force remove WireGuard peer for this user.
     */
    public function forceRemoveWireGuardPeer($id): void
    {
        $user = VpnUser::findOrFail($id);

        if (empty($user->wireguard_public_key)) {
            session()->flash('message', "User $user->username has no WireGuard public key.");
            return;
        }

        $servers = $user->vpnServers;

        if ($servers->isEmpty()) {
            session()->flash('message', "User $user->username is not associated with any servers.");
            return;
        }

        foreach ($servers as $server) {
            Log::info("🔧 Force removing WireGuard peer for user $user->username on server $server->name");
            RemoveWireGuardPeer::dispatch(clone $user, $server);
        }

        Log::info("🔧 WireGuard peer removal forced for user $user->username");
        session()->flash('message', "WireGuard peer removal for $user->username has been queued.");
    }

    /**
     * Toggle active/inactive status for a VPN user.
     */
    public function toggleActive($id): void
    {
        $user = VpnUser::findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        Log::info("🔁 User $user->username active status toggled to " . ($user->is_active ? 'active' : 'inactive'));
        session()->flash('message', "User $user->username is now " . ($user->is_active ? 'active' : 'inactive'));

        $this->resetPage();
    }


    /**
     * Renders the VPN user list with optional search filtering.
     */
    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
{
    $users = VpnUser::with([
            'vpnServers:id,name',
            'activeConnections:id,vpn_user_id,connected_at',
            'connections:id,vpn_user_id,disconnected_at,is_connected',
        ])
        ->when($this->search, fn($q) =>
            $q->where('username', 'like', '%'.$this->search.'%')
        )
        ->orderBy('is_online', 'desc')
        ->orderBy('id', 'desc')
        ->paginate(20);

    return view('livewire.pages.admin.vpn-user-list', compact('users'));
}
}
