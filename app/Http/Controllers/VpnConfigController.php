<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use ZipArchive;

class VpnConfigController extends Controller
{
    /**
     * Client downloads an OpenVPN/WireGuard config for a specific server.
     * Route: client/vpn/{vpnserver}/download  (auth:client)
     */
    public function clientDownload(Request $request, VpnServer $vpnserver)
    {
        /** @var VpnUser|null $client */
        $client = Auth::guard('client')->user();
        abort_unless($client instanceof VpnUser, 403, 'Not authenticated as client.');

        // Ensure assignment
        abort_unless(
            $client->vpnServers()->whereKey($vpnserver->id)->exists(),
            403,
            'Server not assigned to your account.'
        );

        $proto = $request->string('proto')->lower()->value(); // 'ovpn'|'wg'|''

        try {
            if ($proto === 'wg') {
                // Build WG config on-demand; return as download
                $content = VpnConfigBuilder::generateWireGuardString($client, $vpnserver);
                $name    = $this->safeName("{$vpnserver->name}_{$client->username}.conf");

                return response($content, 200, [
                    'Content-Type'        => 'text/plain',
                    'Content-Disposition' => "attachment; filename=\"{$name}\"",
                    'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                    'Pragma'              => 'no-cache',
                    'Expires'             => '0',
                ]);
            }

            // Default: OpenVPN config (no cleartext bcrypt!)
            $content = VpnConfigBuilder::generateOpenVpnConfigString($client, $vpnserver);
            $name    = $this->safeName("{$vpnserver->name}_{$client->username}.ovpn");

            return response($content, 200, [
                'Content-Type'        => 'application/x-openvpn-profile',
                'Content-Disposition' => "attachment; filename=\"{$name}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                'Pragma'              => 'no-cache',
                'Expires'             => '0',
            ]);
        } catch (Exception $e) {
            report($e);
            abort(500, 'Failed to generate config: '.$e->getMessage());
        }
    }

    /**
     * Admin: generate config for a specific server (on-demand).
     * Route: admin/clients/{vpnUser}/config/{vpnServer}
     */
    public function downloadForServer(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        try {
            $content = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer);
            $name    = $this->safeName("{$vpnServer->name}_{$vpnUser->username}.ovpn");

            return response($content, 200, [
                'Content-Type'        => 'application/x-openvpn-profile',
                'Content-Disposition' => "attachment; filename=\"{$name}\"",
                'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                'Pragma'              => 'no-cache',
                'Expires'             => '0',
            ]);
        } catch (Exception $e) {
            abort(500, "Failed to generate OpenVPN config for {$vpnServer->name}: ".$e->getMessage());
        }
    }

    /**
     * Admin: zip all configs (WG + all OVPN) for a user.
     * Route: admin/clients/{vpnUser}/configs/download-all
     */
    public function downloadAll(VpnUser $vpnUser)
    {
        $servers = $vpnUser->vpnServers()->select('id','name')->get();
        if ($servers->isEmpty()) {
            return back()->with('error', 'No servers assigned to this user.');
        }

        $zipName = $this->safeName("{$vpnUser->username}_all_configs.zip");
        $zipPath = storage_path("app/temp/{$zipName}");

        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Could not create ZIP file.');
        }

        try {
            // WG (optional)
            if (method_exists(VpnConfigBuilder::class, 'generateWireGuardString')) {
                $wg = VpnConfigBuilder::generateWireGuardString($vpnUser, null);
                if ($wg) {
                    $zip->addFromString($this->safeName("{$vpnUser->username}_wg.conf"), $wg);
                }
            }

            // OVPN per server
            foreach ($servers as $server) {
                $ovpn = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $server);
                $file = $this->safeName("{$server->name}_{$vpnUser->username}.ovpn");
                $zip->addFromString($file, $ovpn);
            }

            $zip->close();

            return response()->download($zipPath)->deleteFileAfterSend()
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (Exception $e) {
            // ensure we close/remove on error
            try { $zip->close(); } catch (\Throwable $t) {}
            @unlink($zipPath);
            return back()->with('error', 'Failed to generate config archive: '.$e->getMessage());
        }
    }

    /**
     * Live sessions JSON (unchanged, just type-hinted).
     */
    public function showLiveSessions(VpnServer $vpnServer)
    {
        try {
            $sessions = VpnConfigBuilder::getLiveOpenVpnSessions($vpnServer);

            return response()->json([
                'success'        => true,
                'server'         => ['id' => $vpnServer->id, 'name' => $vpnServer->name, 'ip_address' => $vpnServer->ip_address],
                'sessions'       => $sessions,
                'total_sessions' => count($sessions),
                'timestamp'      => now()->toISOString(),
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => 'Failed to fetch live sessions: '.$e->getMessage()], 500);
        }
    }

    /** ---------- Helpers ---------- */
    private function safeName(string $name): string
    {
        // collapse spaces, strip unsafe chars
        $name = preg_replace('/[^\w\-.]+/u', '_', $name);
        return trim($name, '_');
    }
}