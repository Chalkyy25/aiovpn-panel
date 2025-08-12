<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateVpnUser extends Component
{
    /** Wizard step: 1=form, 2=review, 3=done */
    public int $step = 1;

    /** Step 1 inputs */
    public ?string $username = null;
    public array $selectedServers = [];
    public string $expiry = '1m';    // 1m, 3m, 6m, 12m
    public ?int $packageId = null;

    /** Derived / display */
    public int $priceCredits = 0;
    public int $adminCredits = 0;

    /** Data sources */
    public $servers;   // VpnServer collection
    public $packages;  // Package collection

    /* ------------------------- Lifecycle ------------------------- */

    public function mount(): void
    {
        // Prefill a sensible username (can be edited)
        $this->username = $this->username ?: 'user-' . Str::random(6);

        $this->servers  = VpnServer::orderBy('name')->get(['id','name','ip_address']);
        $this->packages = Package::orderBy('price_credits')->get();

        if ($this->packages->isNotEmpty()) {
            $this->packageId = (int) $this->packages->first()->id;
        }

        $this->refreshCreditFigures();
    }

    public function render()
    {
        // Keep admin credit view fresh
        $this->adminCredits = (int) (auth()->user()->fresh()?->credits ?? 0);

        return view('livewire.pages.admin.create-vpn-user', [
            'servers'       => $this->servers,
            'packages'      => $this->packages,
            'adminCredits'  => $this->adminCredits,
            'priceCredits'  => $this->priceCredits,
            'step'          => $this->step,
        ]);
    }

    /* ----------------------- Reactive updates ----------------------- */

    public function updatedPackageId(): void
    {
        $this->refreshCreditFigures();
    }

    public function updatedExpiry(): void
    {
        $this->refreshCreditFigures();
    }

    private function refreshCreditFigures(): void
    {
        $pkg = $this->packages->firstWhere('id', $this->packageId);
        $this->priceCredits = (int) ($pkg->price_credits ?? 0);
        $this->adminCredits = (int) (auth()->user()->credits ?? 0);
    }

    /* ----------------------- Tab navigation ----------------------- */

    /** Clickable tabs, but don’t allow jumping to Done (3). */
    public function goTo(int $step): void
    {
        $step = max(1, min(3, $step));

        // Always allow moving backwards
        if ($step <= $this->step) {
            $this->step = $step;
            return;
        }

        // Enforce validation before entering Review
        if ($this->step === 1 && $step >= 2) {
            if (!$this->validateStep1()) return;
            $this->step = 2;
            return;
        }

        // Block direct jump to Done; must use purchase()
        if ($step >= 3) {
            $this->addError('step', 'Complete the purchase to finish.');
            return;
        }

        $this->step = $step;
    }

    public function next(): void
    {
        if ($this->step === 1) {
            if (!$this->validateStep1()) return;
            $this->step = 2;
        }
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    /* --------------------------- Actions --------------------------- */

    public function purchase(): void
    {
        if (!$this->validateStep1()) {
            $this->step = 1;
            return;
        }

        $admin = auth()->user();
        $pkg   = $this->packages->firstWhere('id', $this->packageId);

        if (!$pkg) {
            $this->addError('packageId', 'Invalid package selected.');
            return;
        }
        if ($admin->credits < $pkg->price_credits) {
            $this->addError('packageId', 'Not enough credits for this package.');
            return;
        }

        $months = (int) rtrim($this->expiry, 'm');

        DB::transaction(function () use ($admin, $pkg, $months) {
            // 1) Deduct credits (atomic) + log
            $admin->deductCredits(
                (int) $pkg->price_credits,
                'Create VPN user',
                ['username' => $this->username, 'package_id' => $pkg->id]
            );

            // 2) Create VPN user + password
            $plain = Str::random(12);

            $vpnUser = VpnUser::create([
                'username'        => $this->username,
                'plain_password'  => $plain,
                'password'        => bcrypt($plain),
                'max_connections' => (int) $pkg->max_connections,
                'is_active'       => true,
                'expires_at'      => now()->addMonths($months),
            ]);

            // 3) Attach servers
            if (!empty($this->selectedServers)) {
                $vpnUser->vpnServers()->sync($this->selectedServers);
            }
            $vpnUser->refresh();

            // 4) Prepare OVPN metadata + sync creds to each server
            VpnConfigBuilder::generate($vpnUser);
            foreach ($vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
                Log::info("🚀 OpenVPN creds synced to {$server->name} ({$server->ip_address})");
            }

            // 5) WG peer setup (optional)
            AddWireGuardPeer::dispatch($vpnUser);

            // 6) UI feedback
            session()->flash('success', "✅ VPN user {$vpnUser->username} created. Password: {$plain}");

            Log::info('✅ VPN user created via wizard', [
                'admin_id'   => $admin->id,
                'username'   => $vpnUser->username,
                'servers'    => $vpnUser->vpnServers->pluck('id')->all(),
                'package_id' => $pkg->id,
                'expires_at' => $vpnUser->expires_at,
            ]);
        });

        // Success → Done
        $this->reset(['username','selectedServers','expiry','packageId','priceCredits']);
        $this->username = 'user-' . Str::random(6);
        $this->expiry   = '1m';
        $this->refreshCreditFigures();

        $this->step = 3;
    }

    /* ------------------------- Validation ------------------------- */

    private function validateStep1(): bool
    {
        $this->resetErrorBag();

        $this->validate([
            'username'           => 'required|string|min:3|max:50|unique:vpn_users,username',
            'selectedServers'    => 'required|array|min:1',
            'selectedServers.*'  => 'exists:vpn_servers,id',
            'expiry'             => 'required|in:1m,3m,6m,12m',
            'packageId'          => 'required|exists:packages,id',
        ], [], [
            'selectedServers' => 'servers',
        ]);

        // Credit gate (shows inline on the page)
        if ($this->adminCredits < $this->priceCredits) {
            $this->addError('packageId', 'Not enough credits for this package.');
            return false;
        }

        return true;
    }
}