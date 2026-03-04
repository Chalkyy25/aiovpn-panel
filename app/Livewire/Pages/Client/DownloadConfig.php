<?php

namespace App\Livewire\Pages\Client;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use App\Services\WireGuardService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.client')]
class DownloadConfig extends Component
{
    public VpnUser $vpnUser;

    /** @var array<int, array<string,mixed>> */
    public array $availableConfigs = [];

    public function mount(): void
    {
        // 1) Admin (web guard) override: /download-config?vpn_user_id=123
        $web = Auth::guard('web');
        if ($web->check() && ($web->user()->role ?? null) === 'admin') {
            $vpnUserId = (int) request()->query('vpn_user_id', 0);
            abort_unless($vpnUserId > 0, 404);

            $this->vpnUser = VpnUser::query()->findOrFail($vpnUserId);
        } else {
            // 2) Normal client flow
            $client = Auth::guard('client');
            abort_unless($client->check(), 403);

            /** @var VpnUser $u */
            $u = $client->user();
            $this->vpnUser = $u;
        }

        // Build list of available configs (no WG generation here)
        $this->availableConfigs = VpnConfigBuilder::generate($this->vpnUser);
    }

    public function downloadConfig(int $serverId, string $variant = 'unified')
    {
        $server = VpnServer::query()->findOrFail($serverId);

        // Ensure user has access to this server
        if (! $this->vpnUser->vpnServers()->whereKey($serverId)->exists()) {
            session()->flash('error', 'Server not assigned to your account.');
            return null;
        }

        try {
            if ($variant === 'wireguard') {
                /** @var WireGuardService $wg */
                $wg = app(WireGuardService::class);

                // Guarantees a peer exists
                $peer    = $wg->ensurePeerForUser($server, $this->vpnUser);
                $content = $wg->buildClientConfig($server, $peer);

                $filename    = sprintf('%s_%s_wireguard.conf', $server->name, $this->vpnUser->username);
                $contentType = 'text/plain';
            } else {
                $content = VpnConfigBuilder::generateOpenVpnConfigString(
                    $this->vpnUser,
                    $server,
                    $variant
                );

                $filename    = sprintf('%s_%s_%s.ovpn', $server->name, $this->vpnUser->username, $variant);
                $contentType = 'application/x-openvpn-profile';
            }

            return response($content, 200, [
                'Content-Type'        => $contentType,
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            ]);
        } catch (\Throwable $e) {
            Log::error('DownloadConfig failed', [
                'vpn_user_id' => $this->vpnUser->id,
                'server_id'   => $serverId,
                'variant'     => $variant,
                'error'       => $e->getMessage(),
            ]);

            session()->flash('error', 'Failed to generate config.');
            return null;
        }
    }

    public function render()
    {
        return view('livewire.pages.client.download-config', [
            'configs' => $this->availableConfigs,
            'user'    => $this->vpnUser,
        ]);
    }
}