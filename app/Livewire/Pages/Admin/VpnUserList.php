<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Log;
use App\Jobs\RemoveWireGuardPeer;

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

    public function deleteUser($id)
    {
        $user = VpnUser::findOrFail($id);

        // 🚨 Dispatch RemoveWireGuardPeer job (if built)
        \App\Jobs\RemoveWireGuardPeer::dispatch($user);

        // 🚨 Delete user from DB
        $user->delete();

        Log::info("🗑️ Deleted VPN user {$user->username}");

        session()->flash('message', "User {$user->username} deleted successfully!");

        $this->emit('refreshUsers');
    }

    public function generateOvpn($id)
    {
        $user = VpnUser::findOrFail($id);

        // 🚀 Dispatch job to generate OVPN file
        \App\Jobs\GenerateOvpnFile::dispatch($user);

        Log::info("📄 OVPN generation queued for user {$user->username}");

        session()->flash('message', "OVPN file generation for {$user->username} has been queued.");
    }

    public function generateWireGuard($id)
    {
        $user = VpnUser::findOrFail($id);

        // 🚀 Dispatch AddWireGuardPeer job
        \App\Jobs\AddWireGuardPeer::dispatch($user);

        Log::info("🔧 WireGuard peer setup queued for user {$user->username}");

        session()->flash('message', "WireGuard peer setup for {$user->username} has been queued.");
    }
}
