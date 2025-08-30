<?php
// app/Livewire/Pages/Admin/CreateTrialLine.php
namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateTrialLine extends Component
{
    public string $username = '';
    /** @var array<int> */
    public array $selectedServers = [];
    public int $step = 1;

    /** @var \Illuminate\Support\Collection<array{id:int,name:string,ip_address:string}> */
    public $servers;

    public function mount(): void
    {
        $this->username = 'trial-' . Str::lower(Str::random(6));
        $this->servers  = VpnServer::orderBy('name')->get(['id','name','ip_address']);
    }

    public function rules(): array
    {
        return [
            'username'          => 'required|string|min:3|max:50|alpha_dash|unique:vpn_users,username',
            'selectedServers'   => 'required|array|min:1',
            'selectedServers.*' => 'integer|exists:vpn_servers,id',
        ];
    }

    public function next(): void
    {
        $this->validate();
        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    public function goTo(int $step): void
    {
        $this->step = max(1, min(3, $step));
    }

    public function createTrial(): void
    {
        $this->validate();

        $creatorId = auth()->id();

        DB::transaction(function () use ($creatorId) {
            $plainPassword = Str::random(10);

            $vpnUser = VpnUser::create([
                'username'        => $this->username,
                'plain_password'  => $plainPassword,
                'password'        => bcrypt($plainPassword),
                'client_id'       => $creatorId,
                'max_connections' => 1,                 // trials: 1 device
                'is_active'       => true,
                'is_trial'        => true,
                'expires_at'      => now()->addDay(),   // 24 hours
            ]);

            // Link servers
            $vpnUser->vpnServers()->sync($this->selectedServers);
            $vpnUser->refresh();

            // Push OpenVPN creds to each server so the user can auth immediately
            foreach ($vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
            }

            // Optional WG peer setup (only if feature enabled)
            if (config('services.wireguard.autogen', false)) {
                AddWireGuardPeer::dispatch($vpnUser);
            }

            session()->flash(
                'success',
                "🎉 Trial created: {$vpnUser->username} — Password: {$plainPassword} (expires in 24h)"
            );

            Log::info('Trial line created', [
                'creator_id' => $creatorId,
                'vpn_user'   => $vpnUser->id,
                'servers'    => $vpnUser->vpnServers->pluck('id')->all(),
                'expires_at' => $vpnUser->expires_at,
            ]);
        });

        $this->step = 3;
    }

    public function render()
    {
        return view('livewire.pages.admin.create-trial-line', [
            'servers' => $this->servers,
            'step'    => $this->step,
        ]);
    }
}