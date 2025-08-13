<?php

namespace App\Livewire\Pages\Reseller;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\Package;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;

class CreateClientLine extends Component
{
    public int $step = 1;

    public ?string $username = null;
    public array $selectedServers = [];
    public string $expiry = '1m';
    public ?int $packageId = null;

    public int $priceCredits = 0;
    public $servers;
    public $packages;

    public function mount()
    {
        $this->username = 'user-' . Str::random(6);
        $this->servers  = VpnServer::orderBy('name')->get(['id','name','ip_address']);
        $this->packages = Package::orderBy('price_credits')->get();

        if ($this->packages->count()) {
            $first = $this->packages->first();
            $this->packageId    = $first->id;
            $this->priceCredits = (int) $first->price_credits;
        }
    }

    public function updatedPackageId()
    {
        $pkg = $this->packages->firstWhere('id', $this->packageId);
        $this->priceCredits = $pkg ? (int) $pkg->price_credits : 0;
    }

    public function next()
    {
        $this->validateStep1();
        $this->step = 2;
    }

    public function back()
    {
        $this->step = 1;
    }

    public function purchase()
    {
        $this->validateStep1();

        $reseller = auth()->user();
        $pkg = $this->packages->firstWhere('id', $this->packageId);
        if (!$pkg) {
            $this->addError('packageId', 'Invalid package.');
            return;
        }
        if ($reseller->credits < $pkg->price_credits) {
            $this->addError('packageId', 'Not enough credits.');
            return;
        }

        $months = (int) rtrim($this->expiry, 'm');

        DB::transaction(function () use ($reseller, $pkg, $months) {
            // charge credits
            $reseller->deductCredits(
                (int) $pkg->price_credits,
                'Create client line',
                ['username' => $this->username, 'package_id' => $pkg->id]
            );

            $plainPassword = Str::random(12);

            $vpnUser = VpnUser::create([
                'username'        => $this->username,
                'plain_password'  => $plainPassword,
                'password'        => bcrypt($plainPassword),
                'max_connections' => $pkg->max_connections,
                'is_active'       => true,
                'expires_at'      => now()->addMonths($months),
                'client_id'       => $reseller->id, // owned by reseller
            ]);

            if (!empty($this->selectedServers)) {
                $vpnUser->vpnServers()->sync($this->selectedServers);
            }
            $vpnUser->refresh();

            VpnConfigBuilder::generate($vpnUser);
            foreach ($vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
            }
            AddWireGuardPeer::dispatch($vpnUser);

            session()->flash('success', "✅ Line {$vpnUser->username} created. Password: {$plainPassword}");
            Log::info('Reseller created line', ['reseller_id'=>$reseller->id,'vpn_user_id'=>$vpnUser->id]);
        });

        $this->reset(['username','selectedServers','expiry','packageId','priceCredits']);
        $this->expiry = '1m';
        $this->step = 3;
    }

    protected function validateStep1()
    {
        $this->validate([
            'username'          => 'required|string|min:3|max:50|unique:vpn_users,username',
            'selectedServers'   => 'required|array|min:1',
            'selectedServers.*' => 'exists:vpn_servers,id',
            'expiry'            => 'required|in:1m,3m,6m,12m',
            'packageId'         => 'required|exists:packages,id',
        ], [], ['selectedServers' => 'servers']);
    }

    public function render()
    {
        return view('livewire.pages.reseller.create-client-line', [
            'servers'      => $this->servers,
            'packages'     => $this->packages,
            'priceCredits' => $this->priceCredits,
            'adminCredits' => auth()->user()->credits, // same binding as admin blade
            'step'         => $this->step,
        ])->layout('layouts.app');
    }
}