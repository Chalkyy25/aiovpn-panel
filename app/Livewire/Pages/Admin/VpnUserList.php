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
        'refreshUsers' => '$refresh',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

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

    /**
     * Delete a VPN user and remove their WireGuard peer.
     */
    public function deleteUser($id)
{
    $user = VpnUser::findOrFail($id);

    dispatch(new \App\Jobs\RemoveWireGuardPeer($user));

    $username = $user->username;
    $user->delete();

    Log::info("🗑️ Deleted VPN user {$username}");
    session()->flash('message', "User {$username} deleted successfully!");

    $this->dispatch('refreshUsers'); // Keep if you're listening for it somewhere

    // Force local refresh
    $this->vpnUsers = VpnUser::all();
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
}
