<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\GenerateOvpnFile;
use App\Jobs\RemoveWireGuardPeer;
use App\Models\VpnUser;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class VpnUserList extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $configUserId = null;
    public int $configProgress = 0;
    public string $configMessage = '';

    #[On('refreshUsers')]
    public function refresh(): void
    {
        // noop, wire:poll will re-render
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function deleteUser(int $id): void
    {
        $user = VpnUser::findOrFail($id);
        $username = $user->username;

        $user->delete();

        Log::info("🗑️ Deleted VPN user {$username} with auto-cleanup");
        session()->flash('message', "User {$username} deleted successfully. Cleanup jobs have been queued.");

        $this->resetPage();
    }

    /**
     * Queue full config pack generation (OpenVPN variants + WireGuard) for a user.
     */
    public function generateOvpn(int $id): void
    {
        $user = VpnUser::with('vpnServers')->findOrFail($id);

        if ($user->vpnServers->isEmpty()) {
            session()->flash('message', "User {$user->username} has no servers linked.");
            return;
        }

        $this->configUserId   = $user->id;
        $this->configProgress = 1;
        $this->configMessage  = "Starting config pack for {$user->username}";

        GenerateOvpnFile::dispatch($user);

        Log::info("🌀 Config pack queued for user {$user->username}");

        session()->flash(
            'message',
            "Config pack for {$user->username} queued: OpenVPN (unified, stealth, UDP) and WireGuard."
        );
    }

    /**
     * Generate WireGuard peers on all linked servers for a user.
     */
    public function generateWireGuard(int $id): void
    {
        $user = VpnUser::with('vpnServers')->findOrFail($id);

        if ($user->vpnServers->isEmpty()) {
            session()->flash('message', "User {$user->username} is not associated with any servers.");
            return;
        }

        foreach ($user->vpnServers as $server) {
            AddWireGuardPeer::dispatch($user, $server)->onQueue('wg');
            Log::info("🔧 WireGuard peer setup queued for {$user->username} on {$server->name}");
        }

        session()->flash('message', "WireGuard peer setup for {$user->username} has been queued on all linked servers.");
    }

    /**
     * Force remove WireGuard peers for this user from all linked servers.
     */
    public function forceRemoveWireGuardPeer(int $id): void
    {
        $user = VpnUser::with('vpnServers')->findOrFail($id);

        if (empty($user->wireguard_public_key)) {
            session()->flash('message', "User {$user->username} has no WireGuard public key.");
            return;
        }

        if ($user->vpnServers->isEmpty()) {
            session()->flash('message', "User {$user->username} is not associated with any servers.");
            return;
        }

        foreach ($user->vpnServers as $server) {
            Log::info("🔧 Force removing WireGuard peer for {$user->username} on {$server->name}");
            RemoveWireGuardPeer::dispatch(clone $user, $server);
        }

        Log::info("🔧 WireGuard peer removal forced for {$user->username}");
        session()->flash('message', "WireGuard peer removal for {$user->username} has been queued.");
    }

    public function toggleActive(int $id): void
    {
        $user = VpnUser::findOrFail($id);
        $user->is_active = ! $user->is_active;
        $user->save();

        Log::info("🔁 User {$user->username} active status toggled to ".($user->is_active ? 'active' : 'inactive'));
        session()->flash('message', "User {$user->username} is now ".($user->is_active ? 'active' : 'inactive'));

        $this->resetPage();
    }

    /**
     * Polled by Livewire to update per-user config generation progress.
     */
    public function pollConfigProgress(): void
    {
        if (! $this->configUserId) {
            return;
        }

        $data = cache()->get("config_progress:{$this->configUserId}");

        if (! $data) {
            return;
        }

        $this->configProgress = (int) ($data['percent'] ?? 0);
        $this->configMessage  = (string) ($data['message'] ?? '');

        // Optional auto-clear
        // if ($this->configProgress >= 100) {
        //     $this->configUserId = null;
        // }
    }

    public function render(): Factory|Application|View|\Illuminate\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $users = VpnUser::with([
                'vpnServers:id,name',
                'activeConnections:id,vpn_user_id,connected_at',
                'connections:id,vpn_user_id,disconnected_at,is_connected',
            ])
            ->when($this->search, fn ($q) =>
                $q->where('username', 'like', '%'.$this->search.'%')
            )
            ->orderBy('is_online', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return view('livewire.pages.admin.vpn-user-list', compact('users'));
    }
}