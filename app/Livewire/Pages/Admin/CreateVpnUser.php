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
    public string $expiry = '1m';     // 1m, 3m, 6m, 12m
    public ?int $packageId = null;    // chosen package

    /** Derived / display */
    public int $priceCredits = 0;

    /** Lists for selects */
    public $servers;   // VpnServer collection
    public $packages;  // Package collection

    public function mount(): void
    {
        // sensible default username
        $this->username = 'user-' . Str::random(6);

        $this->servers  = VpnServer::orderBy('name')->get(['id','name','ip_address']);
        $this->packages = Package::orderBy('price_credits')->get();

        if ($this->packages->count()) {
            $this->packageId = $this->packages->first()->id;
            $this->priceCredits = (int) $this->packages->first()->price_credits;
        }
    }

    public function updatedPackageId(): void
    {
        $pkg = $this->packages->firstWhere('id', $this->packageId);
        $this->priceCredits = $pkg ? (int) $pkg->price_credits : 0;
    }

    /** Step 1 -> Step 2 */
    public function next(): void
    {
        $this->validateStep1();
        $this->step = 2;
    }

    /** Step 2 -> Step 1 */
    public function back(): void
    {
        $this->step = 1;
    }

    /** Finalize purchase + create user */
    public function purchase(): void
    {
        $this->validateStep1();

        $admin = auth()->user(); // admin only page
        $pkg   = $this->packages->firstWhere('id', $this->packageId);

        if (!$pkg) {
            $this->addError('packageId', 'Invalid package selected.');
            return;
        }
        if ($admin->credits < $pkg->price_credits) {
            $this->addError('packageId', 'Not enough credits for this package.');
            return;
        }

        // Calculate expiry months
        $months = (int) rtrim($this->expiry, 'm');

        DB::transaction(function () use ($admin, $pkg, $months) {
            // 1) Deduct credits + log transaction
            $admin->deductCredits(
                (int) $pkg->price_credits,
                'Create VPN user',
                ['username' => $this->username, 'package_id' => $pkg->id]
            );

            // 2) Create VPN user (generate password NOW; review kept blank)
            $plainPassword = Str::random(12);

            $vpnUser = VpnUser::create([
                'username'        => $this->username,
                'plain_password'  => $plainPassword,          // you already use this field
                'password'        => bcrypt($plainPassword),  // auth credential
                'max_connections' => $pkg->max_connections,
                'is_active'       => true,
                'expires_at'      => now()->addMonths($months),
            ]);

            // 3) Attach servers
            if (!empty($this->selectedServers)) {
                $vpnUser->vpnServers()->sync($this->selectedServers);
            }
            $vpnUser->refresh();

            // 4) Generate OVPN on-demand metadata (no disk) and queue sync per server
            VpnConfigBuilder::generate($vpnUser);
            foreach ($vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
                Log::info("🚀 Synced OpenVPN credentials to {$server->name} ({$server->ip_address})");
            }

            // 5) WireGuard peer (optional)
            AddWireGuardPeer::dispatch($vpnUser);

            // 6) Notify UI
            session()->flash(
                'success',
                "✅ VPN user {$vpnUser->username} created. Password: {$plainPassword}"
            );

            Log::info('✅ VPN user created via wizard', [
                'admin_id'   => $admin->id,
                'username'   => $vpnUser->username,
                'servers'    => $vpnUser->vpnServers->pluck('id')->all(),
                'package_id' => $pkg->id,
                'expires_at' => $vpnUser->expires_at,
            ]);
        });

        // success -> done page
        $this->reset(['username','selectedServers','expiry','packageId','priceCredits']);
        $this->expiry = '1m';
        $this->step = 3;
    }

    protected function validateStep1(): void
    {
        $this->validate([
            'username'        => 'required|string|min:3|max:50|unique:vpn_users,username',
            'selectedServers' => 'required|array|min:1',
            'selectedServers.*' => 'exists:vpn_servers,id',
            'expiry'          => 'required|in:1m,3m,6m,12m',
            'packageId'       => 'required|exists:packages,id',
        ], [], [
            'selectedServers' => 'servers',
        ]);
    }

    public function render()
    {
        return view('livewire.pages.admin.create-vpn-user', [
            'servers'      => $this->servers,
            'packages'     => $this->packages,
            'adminCredits' => auth()->user()->credits,
            'step'         => $this->step,
            'priceCredits' => $this->priceCredits,
        ]);
    }
}