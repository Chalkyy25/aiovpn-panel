<?php

namespace App\Livewire\Pages\Admin;

use App\Jobs\CreateVpnUser as CreateVpnUserJob;
use App\Models\Package;
use App\Models\VpnServer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateVpnUser extends Component
{
    public ?string $username = null;

    /** @var array<int> */
    public array $selectedServers = [];

    // 1m|3m|6m|12m
    public string $expiry = '1m';

    public ?int $packageId = null;

    public int $priceCredits = 0;
    public int $adminCredits = 0;

    /** @var \Illuminate\Support\Collection<int,VpnServer> */
    public $servers;

    public bool $selectAllServers = false;

    /** @var \Illuminate\Support\Collection<int,Package> */
    public $packages;

    public function mount(): void
    {
        $this->username ??= 'user-' . Str::lower(Str::random(6));

        $this->servers = VpnServer::orderBy('name')->get([
            'id',
            'name',
            'ip_address',
        ]);

        $this->packages = Package::orderBy('price_credits')->get([
            'id',
            'name',
            'price_credits',
            'max_connections',
        ]);

        if ($this->packageId === null && $this->packages->isNotEmpty()) {
            $this->packageId = (int) $this->packages->first()->id;
        }

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

    public function updatedPackageId(): void
    {
        $this->recalcCredits();
    }

    public function updatedExpiry(): void
    {
        $this->recalcCredits();
    }

    private function recalcCredits(): void
    {
        $pkg = $this->packages->firstWhere('id', $this->packageId);

        $months = match ($this->expiry) {
            '1m' => 1,
            '3m' => 3,
            '6m' => 6,
            '12m' => 12,
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
            'selectedServers'   => ['required', 'array', 'min:1'],
            'selectedServers.*' => ['integer', Rule::exists('vpn_servers', 'id')],
            'expiry'            => ['required', Rule::in(['1m', '3m', '6m', '12m'])],
            'packageId'         => ['required', Rule::exists('packages', 'id')],
        ];
    }

    public function toggleAllServers(): void
    {
        if (count($this->selectedServers) === $this->servers->count()) {
            // Uncheck all
            $this->selectedServers = [];
        } else {
            // Select all server IDs as ints
            $this->selectedServers = $this->servers
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }
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

        // If user selected "ALL SERVERS"
        if ($this->selectAllServers) {
            $this->selectedServers = VpnServer::where('enabled', 1)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        // Revalidate servers after override
        $this->validate([
            'selectedServers'   => ['required', 'array', 'min:1'],
            'selectedServers.*' => ['integer', Rule::exists('vpn_servers', 'id')],
        ]);

        $months = (int) rtrim($this->expiry, 'm');
        $plain  = Str::random(5);

        // Now + N months = real expiry
        $expiresAt = Carbon::now()->addMonths($months);

        try {
            DB::transaction(function () use ($admin, $pkg, $months): void {
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

            // Queue user creation WITH expiry info
            CreateVpnUserJob::dispatch(
                $this->username,
                $this->selectedServers,
                $admin->id,
                $plain,
                $expiresAt
            );

            session()->flash(
                'success',
                "VPN user {$this->username} queued for creation. Password: {$plain}"
                . ($admin->role === 'admin' ? " (no credits deducted)" : "")
            );

            return to_route('admin.vpn-users.index');
        } catch (\Throwable $e) {
            Log::error('CreateVpnUser failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addError('username', 'Creation failed. Please try again.');
            return;
        }
    }
}