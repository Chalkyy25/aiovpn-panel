<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\CreateVpnUser as CreateVpnUserJob;
use App\Models\Package;
use App\Models\VpnServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateVpnUser extends Component
{
    public ?string $username = null;
    /** @var array<int> */
    public array $selectedServers = [];
    public string $expiry = '1m';           // 1m|3m|6m|12m
    public ?int $packageId = null;

    public int $priceCredits = 0;
    public int $adminCredits = 0;

    /** @var \Illuminate\Support\Collection<int,VpnServer> */
    public $servers;
    /** @var \Illuminate\Support\Collection<int,Package> */
    public $packages;

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
        $this->adminCredits = (int) (auth()->user()->fresh()?->credits ?? 0);

        return view('livewire.pages.admin.create-vpn-user', [
            'servers'  => $this->servers,
            'packages' => $this->packages,
        ]);
    }

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

    public function save()
    {
        $this->validate();

        $admin = auth()->user();
        $pkg   = $this->packages->firstWhere('id', $this->packageId);
        if (! $pkg) {
            $this->addError('packageId', 'Invalid package selected.');
            return;
        }

        if ($admin->role !== 'admin' && $admin->credits < $this->priceCredits) {
            $this->addError('packageId', 'Not enough credits for this package.');
            return;
        }

        $months = (int) rtrim($this->expiry, 'm');
        $plain  = Str::random(10);

        try {
            // 1) Credits in a transaction (if needed)
            DB::transaction(function () use ($admin, $pkg, $months) {
                if ($admin->role !== 'admin') {
                    $admin->deductCredits(
                        $this->priceCredits,
                        'Create VPN user',
                        [
                            'username'   => $this->username,
                            'package_id' => $pkg->id,
                            'months'     => $months,
                        ]
                    );
                }
            });

            // 2) Queue full provisioning (creates VpnUser, WG keys, OVPN, peers)
            CreateVpnUserJob::dispatch(
                $this->username,
                $this->selectedServers,
                $admin->id,
                $plain
            );

            // 3) One-time credentials shown to admin
            $msg = "VPN user {$this->username} queued for creation. Password: {$plain}";
            if ($admin->role === 'admin') {
                $msg .= " (no credits deducted)";
            }
            session()->flash('success', $msg);

            return to_route('admin.vpn-users.index');

        } catch (\Throwable $e) {
            Log::error('CreateVpnUser Livewire failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $this->addError('username', 'Creation failed. Please try again.');
            return;
        }
    }
}