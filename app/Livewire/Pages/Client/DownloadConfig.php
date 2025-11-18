<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use App\Services\WireGuardService;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DownloadConfig extends Component
{
    public VpnUser $vpnUser;
    public $availableConfigs = [];

    public function mount()
    {
        $user = auth()->user();

        // Admin can download configs for arbitrary vpn_user via ?user_id=xx
        if ($user && ($user->role === 'admin' || $user->is_admin)) {
            $userId = request()->get('user_id');
            $this->vpnUser = VpnUser::findOrFail($userId);
        } else {
            // For normal logins, make sure we end up with a VpnUser instance
            $this->vpnUser = $user->vpnUser ?? $user;
        }

        // Only describes which servers/variants are available – no WG key generation here
        $this->availableConfigs = VpnConfigBuilder::generate($this->vpnUser);
    }

    public function downloadConfig($serverId, $variant = 'unified')
    {
        $server = VpnServer::findOrFail($serverId);

        // Ensure user has access to this server
        if (! $this->vpnUser->vpnServers()->whereKey($serverId)->exists()) {
            session()->flash('error', 'Server not assigned to your account.');
            return;
        }

        try {
            if ($variant === 'wireguard') {
                /** @var WireGuardService $wg */
                $wg = app(WireGuardService::class);

                // 🔑 This guarantees a real peer exists on the WG server
                $peer    = $wg->ensurePeerForUser($server, $this->vpnUser);
                $content = $wg->buildClientConfig($server, $peer);

                $filename    = "{$server->name}_{$this->vpnUser->username}_wireguard.conf";
                $contentType = 'text/plain';
            } else {
                // OpenVPN configs still come from the existing builder
                $content = VpnConfigBuilder::generateOpenVpnConfigString(
                    $this->vpnUser,
                    $server,
                    $variant
                );

                $filename    = "{$server->name}_{$this->vpnUser->username}_{$variant}.ovpn";
                $contentType = 'application/x-openvpn-profile';
            }

            return response($content, 200, [
                'Content-Type'        => $contentType,
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (\Throwable $e) {
            Log::error('DownloadConfig failed', [
                'vpn_user_id' => $this->vpnUser->id ?? null,
                'server_id'   => $serverId,
                'variant'     => $variant,
                'error'       => $e->getMessage(),
            ]);

            session()->flash('error', 'Failed to generate config: ' . $e->getMessage());
            return;
        }
    }

    public function render()
    {
        return view('livewire.pages.client.download-config', [
            'configs' => $this->availableConfigs,
        ]);
    }
}