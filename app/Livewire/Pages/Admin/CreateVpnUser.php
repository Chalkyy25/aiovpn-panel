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
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateVpnUser extends Component
{
    /* ----------------------------- Inputs ----------------------------- */
    public ?string $username = null;
    /** @var array<int> */
    public array $selectedServers = [];
    public string $expiry = '1m';           // 1m|3m|6m|12m
    public ?int $packageId = null;

    /* ---------------------------- Derived ----------------------------- */
    public int $priceCredits = 0;           // months × package.price_credits
    public int $adminCredits = 0;

    /* ------------------------------ Data ------------------------------ */
    /** @var \Illuminate\Support\Collection<int,VpnServer> */
    public $servers;
    /** @var \Illuminate\Support\Collection<int,Package> */
    public $packages;

    /* --------------------------- Lifecycle ---------------------------- */

    public function mount(): void
    {
        $this->username = $this->username ?: 'user-' . Str::lower(Str::random(6));

        $this->servers  = VpnServer::orderBy('name')->get(['id', 'name', 'ip_address']);
        $this->packages = Package::orderBy('price_credits')->get(['id','name','price_credits','max_connections']);

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

    /* ------------------------ Reactive updates ------------------------ */

    public function updatedPackageId(): void { $this->recalcCredits(); }
    public function updatedExpiry(): void    { $this->recalcCredits(); }

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

    /* ------------------------------ Rules ----------------------------- */

    protected function rules(): array
    {
        return [
            'username'          => [
                'required',
                'string',
                'alpha_dash',
                'min:3',
                'max:50',
                Rule::unique('vpn_users', 'username'),
            ],
            'selectedServers'   => ['required','array','min:1'],
            'selectedServers.*' => ['integer', Rule::exists('vpn_servers','id')],
            'expiry'            => ['required', Rule::in(['1m','3m','6m','12m'])],
            'packageId'         => ['required', Rule::exists('packages','id')],
        ];
    }

    /* ----------------------------- Action ----------------------------- */

    public function save()
    {
        $this->validate();

        $admin = auth()->user();
        $pkg   = $this->packages->firstWhere('id', $this->packageId);
        if (! $pkg) {
            $this->addError('packageId', 'Invalid package selected.');
            return;
        }

        // Skip credit check for full admins
        if ($admin->role !== 'admin' && $admin->credits < $this->priceCredits) {
            $this->addError('packageId', 'Not enough credits for this package.');
            return;
        }

        $months = (int) rtrim($this->expiry, 'm');

        try {
            DB::transaction(function () use ($admin, $pkg, $months) {
                // 1) Deduct credits (non-admin only)
                if ($admin->role !== 'admin') {
                    $admin->deductCredits(
                        $this->priceCredits,
                        'Create VPN user',
                        ['username' => $this->username, 'package_id' => $pkg->id, 'months' => $months]
                    );
                }

                // 2) Create VPN user (VpnUser::booted() ensures password fallback too)
                $plain = Str::random(10);

                /** @var VpnUser $vpnUser */
                $vpnUser = VpnUser::create([
                    'username'        => $this->username,
                    'plain_password'  => $plain,                 // model mutator will also fill hashed password
                    'max_connections' => (int) $pkg->max_connections,
                    'is_active'       => true,
                    'expires_at'      => now()->addMonths($months),
                ]);

                // 3) Attach servers (unique pivot protected by DB unique index)
                $vpnUser->vpnServers()->sync($this->selectedServers);
                $vpnUser->refresh();

                // 4) Generate OVPN artifact(s) locally and queue sync per server
                VpnConfigBuilder::generate($vpnUser);
                foreach ($vpnUser->vpnServers as $server) {
                    // leave default queue (Horizon "default-high")
                    SyncOpenVPNCredentials::dispatch($server);
                }

                // 5) WireGuard peer provisioning (queue: wg)
                AddWireGuardPeer::dispatch($vpnUser)->onQueue('wg');

                // 6) Flash message (password reveal once)
                $msg = "✅ VPN user {$vpnUser->username} created. Password: {$plain}";
                if ($admin->role === 'admin') {
                    $msg .= " (no credits deducted)";
                }
                session()->flash('success', $msg);
            });

            return to_route('admin.vpn-users.index');

        } catch (\Throwable $e) {
            Log::error('CreateVpnUser failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->addError('username', 'Creation failed. Please try again.');
            return;
        }
    }
}