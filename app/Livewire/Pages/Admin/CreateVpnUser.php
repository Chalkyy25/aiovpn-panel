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

    // UI fields (read-only in Blade)
    public int $priceCredits = 0;        // total cost (months * credits_per_month)
    public int $adminCredits = 0;        // current admin/reseller credits
    public int $creditsLeft = 0;         // adminCredits - priceCredits
    public int $maxConnections = 1;      // from package.max_connections
    public string $expiresAtPreview = ''; // formatted expire date preview

    /** @var \Illuminate\Support\Collection<int,VpnServer> */
    public $servers;

    public bool $selectAllServers = false;

    /** @var \Illuminate\Support\Collection<int,Package> */
    public $packages;

    public function mount(): void
    {
        $this->username ??= 'user-' . Str::lower(Str::random(6));

        $this->servers = VpnServer::query()
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address']);

        $this->packages = Package::query()
            ->active()
            ->orderBy('price_credits')
            ->get(['id', 'name', 'price_credits', 'max_connections']);

        if (! $this->packageId && $this->packages->isNotEmpty()) {
            $this->packageId = (int) $this->packages->first()->id;
        }

        $this->syncComputedFields();
    }

    public function render()
    {
        // keep fresh on every render
        $this->adminCredits = (int) (auth()->user()->fresh()?->credits ?? 0);
        $this->creditsLeft  = max(0, $this->adminCredits - $this->priceCredits);

        return view('livewire.pages.admin.create-vpn-user', [
            'servers'  => $this->servers,
            'packages' => $this->packages,
        ]);
    }

    public function updatedPackageId(): void
    {
        $this->syncComputedFields();
    }

    public function updatedExpiry(): void
    {
        $this->syncComputedFields();
    }

    private function monthsFromExpiry(): int
    {
        return match ($this->expiry) {
            '1m'  => 1,
            '3m'  => 3,
            '6m'  => 6,
            '12m' => 12,
            default => 1,
        };
    }

    private function syncComputedFields(): void
    {
        $pkg = $this->packages->firstWhere('id', $this->packageId);

        $months = $this->monthsFromExpiry();
        $rate   = (int) ($pkg->price_credits ?? 0);

        $this->priceCredits   = $months * $rate;
        $this->maxConnections = (int) ($pkg->max_connections ?? 1);

        $expiresAt = Carbon::now()->addMonths($months);
        $this->expiresAtPreview = $expiresAt->format('Y-m-d H:i');

        $this->adminCredits = (int) (auth()->user()->fresh()?->credits ?? 0);
        $this->creditsLeft  = max(0, $this->adminCredits - $this->priceCredits);
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
            $this->selectedServers = [];
            return;
        }

        $this->selectedServers = $this->servers
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
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

        // If user selected "ALL SERVERS"
        if ($this->selectAllServers) {
            $this->selectedServers = VpnServer::query()
                ->where('enabled', 1)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        // Revalidate servers after override
        $this->validate([
            'selectedServers'   => ['required', 'array', 'min:1'],
            'selectedServers.*' => ['integer', Rule::exists('vpn_servers', 'id')],
        ]);

        // Never trust computed UI values — recompute server-side
        $months      = $this->monthsFromExpiry();
        $rate        = (int) $pkg->price_credits;
        $totalCost   = $months * $rate;
        $expiresAt   = Carbon::now()->addMonths($months);
        $plainPass   = Str::random(5);

        // Credit check for non-admin (resellers)
        if ($admin->role !== 'admin' && (int) $admin->credits < $totalCost) {
            $this->addError('packageId', 'Not enough credits for this purchase.');
            return;
        }

        try {
            DB::transaction(function () use ($admin, $pkg, $months, $totalCost): void {
                if ($admin->role !== 'admin') {
                    $admin->deductCredits(
                        $totalCost,
                        'Create VPN user',
                        [
                            'username'      => $this->username,
                            'package_id'    => $pkg->id,
                            'months'        => $months,
                            'connections'   => (int) $pkg->max_connections,
                            'credits_per_m' => (int) $pkg->price_credits,
                        ]
                    );
                }
            });

            CreateVpnUserJob::dispatch(
                $this->username,
                $this->selectedServers,
                $admin->id,
                $plainPass,
                $expiresAt
            );

            session()->flash(
                'success',
                "VPN user {$this->username} queued for creation. Password: {$plainPass}"
                . ($admin->role === 'admin' ? " (no credits deducted)" : "")
            );

            return to_route('admin.vpn-users.index');
        } catch (\Throwable $e) {
            Log::error('CreateVpnUser failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addError('username', 'Creation failed. Please try again.');
            return;
        }
    }
}