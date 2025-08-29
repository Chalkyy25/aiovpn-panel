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

    public string $username = '';
    public string $password = '';
    /** @var array<int> */
    public array $selectedServers = [];
    public string $expiry = '12m';     // renewal term to use only if expired
    public int    $maxConnections = 1; // 0 = unlimited
    public bool   $isActive = true;

    public ?int $packageId = null;

    public function mount(VpnUser $vpnUser): void
    {
        $this->vpnUser         = $vpnUser;
        $this->username        = $vpnUser->username;
        $this->password        = (string) ($vpnUser->plain_password ?? '');
        $this->selectedServers = $vpnUser->vpnServers->pluck('id')->all();
        $this->maxConnections  = (int) ($vpnUser->max_connections ?? 1);
        $this->isActive        = (bool) $vpnUser->is_active;

        // choose a default "renewal term" based on existing expiry (purely UI)
        if ($vpnUser->expires_at) {
            $m = now()->diffInMonths($vpnUser->expires_at, false);
            $this->expiry = $m <= 1 ? '1m' : ($m <= 3 ? '3m' : ($m <= 6 ? '6m' : '12m'));
        }

        // preselect a package by current max connections if any
        $pkg = Package::where('max_connections', $this->maxConnections)->orderBy('price_credits')->first();
        $this->packageId = $pkg?->id;
    }

    public function save(): void
    {
        $this->validate([
            'username'        => 'required|unique:vpn_users,username,' . $this->vpnUser->id,
            'password'        => 'nullable|min:6',
            'expiry'          => 'required|in:1m,3m,6m,12m',   // only used when expired
            'selectedServers' => 'required|array|min:1',
            'maxConnections'  => 'required|integer|min:0|max:100',
            'isActive'        => 'boolean',
            'packageId'       => 'nullable|exists:packages,id',
        ]);

        $update = [
            'username'  => $this->username,
            'is_active' => $this->isActive,
        ];

        // package -> max connections (0 = unlimited)
        if ($this->packageId) {
            if ($pkg = Package::find($this->packageId)) {
                $update['max_connections'] = (int) $pkg->max_connections;
            }
        } else {
            $update['max_connections'] = (int) $this->maxConnections;
        }

        // Only set expires_at if the user is expired (or never had one)
        $isExpired = is_null($this->vpnUser->expires_at) || now()->greaterThanOrEqualTo($this->vpnUser->expires_at);
        if ($isExpired) {
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
        $before = $this->vpnUser->vpnServers()
    ->pluck('vpn_servers.id')
    ->all();

$this->vpnUser->vpnServers()->sync($this->selectedServers);

$after  = $this->vpnUser->vpnServers()
    ->pluck('vpn_servers.id')
    ->all();

        if ($before !== $after) {
            foreach ($this->vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
                Log::info("🚀 Synced OpenVPN creds to {$server->name} ({$server->ip_address})");
            }

            if (config('services.wireguard.autogen', false)) {
                AddWireGuardPeer::dispatch($this->vpnUser);
                Log::info("🔧 WG peer setup queued for {$this->vpnUser->username}");
            }
        }

        Log::info('✅ VPN user updated', [
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