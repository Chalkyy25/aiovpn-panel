<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EditVpnUser extends Component
{
    public VpnUser $vpnUser;

    // form fields
    public string $username = '';
    public string $password = '';
    /** @var array<int> */
    public array $selectedServers = [];
    public string $expiry = '1m';
    public int $maxConnections = 1;     // 0 = unlimited
    public bool $isActive = true;
    public bool $extendExpiry = false;

    // packages (optional)
    public ?int $packageId = null;

    public function mount(VpnUser $vpnUser): void
    {
        $this->vpnUser         = $vpnUser;
        $this->username        = $vpnUser->username;
        $this->password        = (string) ($vpnUser->plain_password ?? '');
        $this->selectedServers = $vpnUser->vpnServers->pluck('id')->all();
        $this->maxConnections  = (int) ($vpnUser->max_connections ?? 1);
        $this->isActive        = (bool) $vpnUser->is_active;

        // derive a duration bucket from current expiry
        if ($vpnUser->expires_at) {
            $m = now()->diffInMonths($vpnUser->expires_at, false);
            $this->expiry =
                $m <= 1 ? '1m' :
                ($m <= 3 ? '3m' :
                ($m <= 6 ? '6m' : '12m'));
        }

        // try to preselect a package that matches current max_connections (best-effort)
        $pkg = Package::where('max_connections', $this->maxConnections)->orderBy('price_credits')->first();
        $this->packageId = $pkg?->id;
    }

    public function save(): void
    {
        $this->validate([
            'username'        => 'required|unique:vpn_users,username,' . $this->vpnUser->id,
            'password'        => 'nullable|min:6',
            'expiry'          => 'required|in:1m,3m,6m,12m',
            'selectedServers' => 'required|array|min:1',
            // allow 0 = unlimited
            'maxConnections'  => 'required|integer|min:0|max:100',
            'isActive'        => 'boolean',
            'extendExpiry'    => 'boolean',
            'packageId'       => 'nullable|exists:packages,id',
        ]);

        $update = [
            'username'        => $this->username,
            'is_active'       => $this->isActive,
        ];

        // apply package if selected, else keep manual maxConnections
        if ($this->packageId) {
            $pkg = Package::find($this->packageId);
            if ($pkg) {
                $update['max_connections'] = (int) $pkg->max_connections;   // 0 = unlimited supported
            }
        } else {
            $update['max_connections'] = (int) $this->maxConnections;
        }

        // extend expiry only when requested
        if ($this->extendExpiry) {
            $months = (int) rtrim($this->expiry, 'm');
            $update['expires_at'] = now()->addMonths($months);
        }

        // update password only if provided
        if ($this->password !== '') {
            $update['plain_password'] = $this->password;
            $update['password']       = bcrypt($this->password);
        }

        $this->vpnUser->update($update);

        // sync server assignments
        $before = $this->vpnUser->vpnServers()->pluck('id')->all();
        $this->vpnUser->vpnServers()->sync($this->selectedServers);
        $after  = $this->vpnUser->vpnServers()->pluck('id')->all();

        // if server list changed, push credentials; no call to VpnConfigBuilder::generate()
        if ($before !== $after) {
            foreach ($this->vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
                Log::info("🚀 Synced OpenVPN creds to {$server->name} ({$server->ip_address})");
            }

            // Optional: queue WG peer setup only if you use WG today
            if (config('services.wireguard.autogen', false)) {
                AddWireGuardPeer::dispatch($this->vpnUser);
                Log::info("🔧 WG peer setup queued for {$this->vpnUser->username}");
            }
        }

        Log::info("✅ VPN user updated", [
            'user'            => $this->vpnUser->username,
            'expires_at'      => $this->vpnUser->expires_at,
            'max_connections' => $this->vpnUser->max_connections,
            'is_active'       => $this->vpnUser->is_active,
            'servers'         => $this->vpnUser->vpnServers()->pluck('name')->all(),
            'package_id'      => $this->packageId,
        ]);

        session()->flash('success', "✅ {$this->vpnUser->username} updated.");
    }

    public function render(): View
    {
        return view('livewire.pages.admin.edit-vpn-user', [
            'servers'  => VpnServer::orderBy('name')->get(),
            'packages' => Package::orderBy('price_credits')->get(),
        ]);
    }
}