<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class DownloadConfig extends Component
{
    public VpnUser $vpnUser;
    public $availableConfigs = [];

    public function mount()
    {
        $user = auth()->user();
        
        // Check if user is admin through role or other method
        if ($user && ($user->role === 'admin' || $user->is_admin)) {
            // expect ?user_id=xx
            $userId = request()->get('user_id');
            $this->vpnUser = VpnUser::findOrFail($userId);
        } else {
            $this->vpnUser = $user->vpnUser ?? $user;
        }

        // Generate list of available configs using new builder
        $this->availableConfigs = VpnConfigBuilder::generate($this->vpnUser);
    }

    public function downloadConfig($serverId, $variant = 'unified')
    {
        $server = VpnServer::findOrFail($serverId);
        
        // Ensure user has access to this server
        if (!$this->vpnUser->vpnServers()->whereKey($serverId)->exists()) {
            session()->flash('error', 'Server not assigned to your account.');
            return;
        }

        try {
            if ($variant === 'wireguard') {
                $content = VpnConfigBuilder::generateWireGuardConfigString($this->vpnUser, $server);
                $filename = "{$server->name}_{$this->vpnUser->username}_wireguard.conf";
                $contentType = 'text/plain';
            } else {
                $content = VpnConfigBuilder::generateOpenVpnConfigString($this->vpnUser, $server, $variant);
                $filename = "{$server->name}_{$this->vpnUser->username}_{$variant}.ovpn";
                $contentType = 'application/x-openvpn-profile';
            }

            return response($content, 200, [
                'Content-Type' => $contentType,
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);

        } catch (\Exception $e) {
            session()->flash('error', 'Failed to generate config: ' . $e->getMessage());
            return;
        }
    }

    public function render()
    {
        return view('livewire.pages.client.download-config', [
            'configs' => $this->availableConfigs
        ]);
    }
}