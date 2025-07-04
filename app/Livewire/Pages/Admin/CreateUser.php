<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\SyncOpenVPNCredentials;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    public $name = '';
    public $email = '';
    public $password = '';
    public $role = '';
    public $vpn_server_id = ''; // For client assignment

    public $vpnServers = [];

    public function mount()
    {
        $this->vpnServers = VpnServer::all();
    }

    public function save()
    {
        // ✅ Validation adjusted based on role
        $rules = [
            'name' => 'required',
            'role' => 'required|in:admin,reseller,client',
        ];

        if (in_array($this->role, ['admin', 'reseller'])) {
            $rules['email'] = 'required|email|unique:users,email';
            $rules['password'] = 'required|min:6';
        }

        if ($this->role === 'client') {
            $rules['vpn_server_id'] = 'required|exists:vpn_servers,id';
        }

        $this->validate($rules);

        // 🔑 Generate random password for clients
        $randomPassword = $this->role === 'client' ? Str::random(12) : $this->password;

        // ✨ Create the user
        $user = User::create([
            'name' => $this->name,
            'email' => $this->role === 'client' ? null : $this->email,
            'password' => bcrypt($randomPassword),
            'role' => $this->role,
        ]);

        // 🛠️ Create VPN User for clients
        if ($this->role === 'client') {
            $vpnServer = VpnServer::find($this->vpn_server_id);

            VpnUser::create([
                'vpn_server_id' => $vpnServer->id,
                'username' => $this->name, // Client chosen username
                'password' => $randomPassword,
                'client_id' => $user->id,
            ]);

            // 🚀 Dispatch VPN credentials sync
            SyncOpenVPNCredentials::dispatch($vpnServer);
        }

        // ✅ Redirect back with success message showing generated password
        return redirect()->route('admin.users.index')
            ->with('success', 'User created. Password: ' . $randomPassword);
    }

    public function render()
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
        ]);
    }
}
