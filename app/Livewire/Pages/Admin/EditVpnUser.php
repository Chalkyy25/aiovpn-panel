<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
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
    public array  $selectedServers = [];
    public string $expiry = '12m';        // renewal term selector
    public int    $maxConnections = 1;    // 0 = unlimited
    public bool   $isActive = true;
    public ?int   $packageId = null;

    public function mount(VpnUser $vpnUser): void
    {
        $this->vpnUser         = $vpnUser;
        $this->username        = $vpnUser->username;
        $this->password        = (string) ($vpnUser->plain_password ?? '');
        $this->selectedServers = $vpnUser->vpnServers->pluck('id')->all();
        $this->maxConnections  = (int) ($vpnUser->max_connections ?? 1);
        $this->isActive        = (bool) $vpnUser->is_active;

        // UI default for "Renewal Term" (purely a selector)
        if ($vpnUser->expires_at) {
            $m = now()->diffInMonths($vpnUser->expires_at, false);
            $this->expiry = $m <= 1 ? '1m' : ($m <= 3 ? '3m' : ($m <= 6 ? '6m' : '12m'));
        }

        // Preselect a package that matches current max_connections if possible
        $this->packageId = optional(
            Package::where('max_connections', $this->maxConnections)->orderBy('price_credits')->first()
        )->id;
    }

    protected function rules(): array
    {
        return [
            'username'        => 'required|unique:vpn_users,username,' . $this->vpnUser->id,
            'password'        => 'nullable|min:6',
            'expiry'          => 'required|in:1m,3m,6m,12m',
            'selectedServers' => 'required|array|min:1',
            'maxConnections'  => 'required|integer|min:0|max:100',
            'isActive'        => 'boolean',
            'packageId'       => 'nullable|exists:packages,id',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $update = [
            'username'  => $this->username,
            'is_active' => $this->isActive,
            'max_connections' => (int) $this->maxConnections, // may be overridden by package
        ];

        // Package → max_connections (0 = unlimited)
        if ($this->packageId && ($pkg = Package::find($this->packageId))) {
            $update['max_connections'] = (int) $pkg->max_connections;
        }

        // Compute renewal policy
        $update['expires_at'] = $this->computeRenewalExpiry();

        // Update password only if provided
        if ($this->password !== '') {
            $update['plain_password'] = $this->password;
            $update['password']       = bcrypt($this->password);
        }

        DB::transaction(function () use ($update) {
            // Update user
            $this->vpnUser->update($update);

            // Determine server changes (QUALIFIED column to avoid ambiguity)
            $before = $this->vpnUser->vpnServers()->pluck('vpn_servers.id')->all();

            // Sync assignments
            $this->vpnUser->vpnServers()->sync($this->selectedServers);

            $after  = $this->vpnUser->vpnServers()->pluck('vpn_servers.id')->all();

            // If server list changed, push OpenVPN creds (and optionally WG peer)
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
        });

        Log::info('✅ VPN user updated', [
            'user'            => $this->vpnUser->username,
            'expires_at'      => optional($this->vpnUser->fresh()->expires_at)?->toDateTimeString(),
            'max_connections' => $this->vpnUser->max_connections,
            'is_active'       => $this->vpnUser->is_active,
            'servers'         => $this->vpnUser->vpnServers()->pluck('name')->all(),
            'package_id'      => $this->packageId,
        ]);

        session()->flash('success', "✅ {$this->vpnUser->username} updated.");
        $this->resetErrorBag();
        redirect()->route('admin.vpn-users.index');
    }

    /**
     * Renewal policy:
     * - If expired or no expiry: now + selected months.
     * - If still active: anchor at current expires_at, bump **one year forward**,
     *   then add the selected term (1/3/6/12 months) — preserving the day as best as possible.
     */
    private function computeRenewalExpiry()
    {
        $termMonths = (int) rtrim($this->expiry, 'm');
        $current    = $this->vpnUser->expires_at;

        // If no expiry or already expired → start from now
        if (is_null($current) || now()->greaterThanOrEqualTo($current)) {
            return now()->copy()->addMonths($termMonths);
        }

        // Active: keep cadence; push to next year, then add term
        $anchor = $current->copy()->addYear();     // same day/month, next year
        return $anchor->copy()->addMonths($termMonths);
    }

    public function render(): View
    {
        return view('livewire.pages.admin.edit-vpn-user', [
            'servers'  => VpnServer::orderBy('name')->get(),
            'packages' => Package::orderBy('price_credits')->get(),
        ]);
    }
}