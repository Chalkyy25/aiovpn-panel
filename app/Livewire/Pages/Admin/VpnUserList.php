<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\GenerateOvpnFile;
use App\Models\VpnUser;
use App\Models\WireguardPeer;
use App\Services\WireGuardService;
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
        session()->flash('message', "User {$username} deleted successfully.");

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

        // Assuming your job can accept just the user and fan-out internally
        GenerateOvpnFile::dispatch($user);

        Log::info("🌀 Config pack queued for user {$user->username}");

        session()->flash(
            'message',
            "Config pack for {$user->username} queued: OpenVPN and WireGuard."
        );
    }

    /**
     * Ensure WireGuard peers exist on all linked servers for a user.
     * This uses WireGuardService instead of the old AddWireGuardPeer job.
     */
    public function generateWireGuard(int $id): void
    {
        $user = VpnUser::with('vpnServers')->findOrFail($id);

        if ($user->vpnServers->isEmpty()) {
            session()->flash('message', "User {$user->username} is not associated with any servers.");
            return;
        }

        if (blank($user->wireguard_public_key) || blank($user->wireguard_address)) {
            session()->flash('message', "User {$user->username} has no WireGuard identity yet.");
            return;
        }

        /** @var WireGuardService $wg */
        $wg = app(WireGuardService::class);

        foreach ($user->vpnServers as $server) {
            if (! $server->supportsWireGuard()) {
                continue;
            }

            try {
                $wg->ensurePeerForUser($server, $user);
                Log::info("🔧 WireGuard peer ensured for {$user->username} on {$server->name}");
            } catch (\Throwable $e) {
                Log::error("WireGuard peer creation failed for {$user->username} on {$server->name}: ".$e->getMessage());
            }
        }

        session()->flash(
            'message',
            "WireGuard peers ensured for {$user->username} on all linked servers."
        );
    }

    /**
     * Soft-remove WireGuard peers for this user:
     * - mark DB peers as revoked
     * - you can later add a scheduled job to prune from wg0 if you want.
     */
    public function forceRemoveWireGuardPeer(int $id): void
    {
        $user = VpnUser::with('vpnServers')->findOrFail($id);

        if (blank($user->wireguard_public_key)) {
            session()->flash('message', "User {$user->username} has no WireGuard public key.");
            return;
        }

        $count = WireguardPeer::where('vpn_user_id', $user->id)->update([
            'revoked' => true,
        ]);

        Log::info("🔧 WireGuard peers marked revoked for {$user->username}", [
            'user_id' => $user->id,
            'count'   => $count,
        ]);

        session()->flash(
            'message',
            "WireGuard peers for {$user->username} have been marked revoked ({$count} records)."
        );
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