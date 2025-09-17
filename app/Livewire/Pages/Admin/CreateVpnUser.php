<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateVpnUser extends Component
{
    // Form inputs
    public ?string $username = null;
    public array $selectedServers = [];
    public string $expiry = '1m';         // 1m|3m|6m|12m
    public ?int $packageId = null;

    // Derived
    public int $priceCredits = 0;         // months × package.price_credits
    public int $adminCredits = 0;

    // Data
    public $servers;
    public $packages;

    /* ------------------------- Lifecycle ------------------------- */

    public function mount(): void
    {
        $this->username = $this->username ?: 'user-' . Str::random(6);

        $this->servers  = VpnServer::orderBy('name')->get(['id','name','ip_address']);
        $this->packages = Package::orderBy('price_credits')->get();

        $this->packageId ??= (int) optional($this->packages->first())->id;
        $this->recalcCredits();
    }

    public function render()
    {
        // keep balance fresh for the UI
        $this->adminCredits = (int) (auth()->user()->fresh()?->credits ?? 0);

        return view('livewire.pages.admin.create-vpn-user', [
            'servers'  => $this->servers,
            'packages' => $this->packages,
        ]);
    }

    /* ----------------------- Reactive updates ----------------------- */

    public function updatedPackageId(): void   { $this->recalcCredits(); }
    public function updatedExpiry(): void      { $this->recalcCredits(); }

    private function recalcCredits(): void
    {
        $pkg = $this->packages->firstWhere('id', $this->packageId);

        $months = match ($this->expiry) {
            '1m' => 1, '3m' => 3, '6m' => 6, '12m' => 12,
            default => 1,
        };

        $rate = (int) ($pkg->price_credits ?? 0);
        $this->priceCredits = $months * $rate;

        $this->adminCredits = (int) (auth()->user()->fresh()?->credits ?? 0);
    }

    /* --------------------------- Actions --------------------------- */

    public function save()
{
    $this->validate([
        'username'           => 'required|string|alpha_dash|min:3|max:50|unique:vpn_users,username',
        'selectedServers'    => 'required|array|min:1',
        'selectedServers.*'  => 'exists:vpn_servers,id',
        'expiry'             => 'required|in:1m,3m,6m,12m',
        'packageId'          => 'required|exists:packages,id',
    ], [], ['selectedServers' => 'servers']);

    $admin = auth()->user();
    $pkg   = $this->packages->firstWhere('id', $this->packageId);

    if (! $pkg) {
        $this->addError('packageId', 'Invalid package selected.');
        return;
    }

    // ✅ Skip credit check if admin
    if ($admin->role !== 'admin' && $admin->credits < $this->priceCredits) {
        $this->addError('packageId', 'Not enough credits for this package.');
        return;
    }

    $months = (int) rtrim($this->expiry, 'm');

    DB::transaction(function () use ($admin, $pkg, $months) {
        // 1) Deduct credits only if NOT admin
        if ($admin->role !== 'admin') {
            $admin->deductCredits(
                $this->priceCredits,
                'Create VPN user',
                ['username' => $this->username, 'package_id' => $pkg->id, 'months' => $months]
            );
        }

        // 2) Create VPN user with random password
        $plain = Str::random(6);

        $vpnUser = VpnUser::create([
            'username'        => $this->username,
            'plain_password'  => $plain,
            'password'        => bcrypt($plain),
            'max_connections' => (int) $pkg->max_connections,
            'is_active'       => true,
            'expires_at'      => now()->addMonths($months),
        ]);

        // 3) Attach servers
        $vpnUser->vpnServers()->sync($this->selectedServers);
        $vpnUser->refresh();

        // 4) Build OVPN + sync creds
        VpnConfigBuilder::generate($vpnUser);
        foreach ($vpnUser->vpnServers as $server) {
            SyncOpenVPNCredentials::dispatch($server);
        }

        // 5) Optional WireGuard
        AddWireGuardPeer::dispatch($vpnUser);

        // 6) Flash for the listing page
        $msg = "✅ VPN user {$vpnUser->username} created. Password: {$plain}";
        if ($admin->role === 'admin') {
            $msg .= " (no credits deducted)";
        }
        session()->flash('success', $msg);
    });

    // 7) Redirect back to list
    return to_route('admin.vpn-users.index');
}
}