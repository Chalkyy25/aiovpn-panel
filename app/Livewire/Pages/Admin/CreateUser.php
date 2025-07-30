<?php

namespace App\Livewire\Pages\Admin;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Jobs\CreateVpnUser; // Dispatch queued job
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    // Form inputs
    public $username = '';
    public $deviceName = '';
    public $selectedServers = [];
    public $vpnServers = [];
    public $server;

    /**
     * Mount the component with the selected server.
     *
     * @param VpnServer $server The VPN server for which the client is being created.
     */
    public function mount(VpnServer $server): void
    {
        $this->server = $server;
        $this->vpnServers = VpnServer::all(); // Optional if allowing multi-server selection
    }

    /**
     * Save the new VPN user and trigger queued jobs.
     */
    public function save(): void
    {
        $this->validate(
            [
                'username' => 'nullable|string|min:3',
                'deviceName' => 'nullable|string|min:2',
                'selectedServers' => 'required|array|min:1',
            ],
            [
                'username.min' => 'The username must be at least 3 characters.',
                'deviceName.min' => 'The device name must be at least 2 characters.',
                'selectedServers.required' => 'You must select at least one server.',
                'selectedServers.min' => 'At least one server must be selected.',
            ]
        );

        if ($this->username && VpnUser::where('username', $this->username)->exists()) {
            $this->addError('username', 'This username is already taken. Please choose another.');
            return;
        }

        $finalUsername = $this->username ?: 'user-' . Str::random(6);
        $plainPassword = Str::random(8);

        dispatch(new CreateVpnUser(
            username: $finalUsername,
            serverIds: $this->selectedServers,
            clientId: null,
            password: $plainPassword
        ));

        Log::info("✅ Queued VPN user creation for $finalUsername (password: $plainPassword) on servers: ", $this->selectedServers);

        session()->flash('success', "✅ VPN Client $finalUsername created for device $this->deviceName");

        // Use Livewire's redirect() helper
        $this->redirect(route('admin.vpn-user-list')); // Execute redirection
    }

    /**
     * Render the component view.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.pages.admin.create-user', [
            'vpnServers' => $this->vpnServers,
            'server' => $this->server,
        ]);
    }
}
