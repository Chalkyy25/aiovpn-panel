<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Log;
use App\Jobs\RemoveWireGuardPeer;
use App\Jobs\GenerateOvpnFile;
use App\Jobs\AddWireGuardPeer;

class VpnUserList extends Component
{
    use WithPagination;

    public $search = '';

    protected $listeners = [
        'refreshUsers' => '$refresh', // optional, in case something external triggers it
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    /**
     * Delete a VPN user and remove their WireGuard peer.
     */
    public function deleteUser($id)
    {
        $user = VpnUser::findOrFail($id);

        // Remove WireGuard peer
        dispatch(new RemoveWireGuardPeer($user));

        $username = $user->username;

        // Delete user
        $user->delete();

        Log::info("🗑️ Deleted VPN user {$username}");
        session()->flash('message', "User {$username} deleted successfully!");

        // Reset pagination to reflect updated list
        $this->resetPage();
    }

    /**
     * Generate an OpenVPN config file for this user.
     */
    public function generateOvpn($id)
    {
        $user = VpnUser::findOrFail($id);

        GenerateOvpnFile::dispatch($user);

        Log::info("📄 OVPN generation queued for user {$user->username}");

        session()->flash('message', "OVPN file generation for {$user->username} has been queued.");
    }

    /**
     * Generate WireGuard peer setup for this user.
     */
    public function generateWireGuard($id)
    {
        $user = VpnUser::findOrFail($id);

        AddWireGuardPeer::dispatch($user);

        Log::info("🔧 WireGuard peer setup queued for user {$user->username}");

        session()->flash('message', "WireGuard peer setup for {$user->username} has been queued.");
    }

    /**
     * Renders the VPN user list with optional search filtering.
     */
    public function render()
    {
        $users = VpnUser::with('vpnServers')
            ->when($this->search, fn($q) =>
                $q->where('username', 'like', '%' . $this->search . '%')
            )
            ->orderBy('id', 'desc')
            ->paginate(20);

        return view('livewire.pages.admin.vpn-user-list', compact('users'))
            ->layout('layouts.app');
    }
}