<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use App\Models\VpnUser;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class VpnUsers extends Component
{
    public $users;

    public function mount()
    {
        // 🔧 Load all VPN users with their assigned servers
        $this->users = VpnUser::with('vpnServer')->get();
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
        // 🔥 Delete the user and their associated files
        $user->delete();
        session()->flash('message', "User {$user->username} deleted successfully.");
        $this->users = VpnUser::with('vpnServer')->get(); // Refresh the list
    }
    public function generateOvpn(VpnUser $user)
    {
        // 🔄 Dispatch job to generate OVPN file
        \App\Jobs\GenerateOvpnFile::dispatch($user);
        session()->flash('message', "OVPN file generation for {$user->username} has been queued.");
    }

}
