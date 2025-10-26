<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnUser;
use Livewire\Attributes\Layout;
use App\Jobs\RemoveWireGuardPeer;

#[Layout('layouts.app')]
class VpnUsers extends Component
{
    public $users;

    public function mount()
    {
        // 🔧 Load all VPN users with their assigned servers
        $this->users = VpnUser::with('vpnServers')->get();
    }

    public function render()
    {
        return view('livewire.pages.admin.vpn-users')
            ->with([
                'users' => $this->users,
                'title' => 'VPN Test Users'
            ]);
    }

    public function deleteUser(VpnUser $user)
    {
        // 🔥 Remove WireGuard peer before deleting the user
        RemoveWireGuardPeer::dispatch($user);

        // 💾 Delete the user from DB
        $user->delete();

        session()->flash('message', "User {$user->username} deleted successfully.");
        $this->users = VpnUser::with('vpnServers')->get(); // Refresh the list
    }

    public function generateOvpn(VpnUser $user)
    {
        // �️ Dispatch job to generate modern stealth OVPN configs
        \App\Jobs\GenerateOvpnFile::dispatch($user);
        session()->flash('message', "Modern stealth OVPN configs for {$user->username} have been queued. Includes unified (TCP 443 + UDP fallback), stealth, and traditional variants.");
    }
}
