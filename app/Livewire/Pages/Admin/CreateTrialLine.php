<?php
// app/Livewire/Pages/Admin/CreateTrialLine.php
namespace App\Livewire\Pages\Admin;

use App\Jobs\AddWireGuardPeer;
use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateTrialLine extends Component
{
    public string $username = '';
    public array  $selectedServers = [];
    public int    $step = 1;

    public $servers;

    public function mount(): void
    {
        $this->username = 'trial-' . Str::lower(Str::random(6));
        $this->servers  = VpnServer::orderBy('name')->get(['id','name','ip_address']);
    }

    public function rules(): array
    {
        return [
            'username'         => 'required|string|min:3|max:50|alpha_dash|unique:vpn_users,username',
            'selectedServers'  => 'required|array|min:1',
            'selectedServers.*'=> 'exists:vpn_servers,id',
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

        // One active trial per creator (admin/reseller) at a time
        $creatorId = auth()->id();
        $hasActive = VpnUser::activeTrials()
            ->where('client_id', $creatorId) // or track a separate owner/reseller field if you prefer
            ->exists();

        if ($hasActive) {
            $this->addError('username', 'You already have an active trial. Please wait until it expires.');
            return;
        }

        DB::transaction(function () use ($creatorId) {
            $plainPassword = Str::random(10);

            $vpnUser = VpnUser::create([
                'username'        => $this->username,
                'plain_password'  => $plainPassword,
                'password'        => bcrypt($plainPassword),
                'client_id'       => $creatorId,         // owner
                'max_connections' => 1,                  // trials: 1 device
                'is_active'       => true,
                'is_trial'        => true,
                'expires_at'      => now()->addDay(),    // 24 hours
            ]);

            $vpnUser->vpnServers()->sync($this->selectedServers);
            $vpnUser->refresh();

            // Generate & sync OpenVPN configs
            VpnConfigBuilder::generate($vpnUser);
            foreach ($vpnUser->vpnServers as $server) {
                SyncOpenVPNCredentials::dispatch($server);
            }

            // WG peer (if you use it)
            AddWireGuardPeer::dispatch($vpnUser);

            session()->flash('success', "🎉 Trial line created: {$vpnUser->username}. Password: {$plainPassword} (expires in 24h)");
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