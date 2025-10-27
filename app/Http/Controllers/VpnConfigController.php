<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        $variant = $request->string('variant')->lower()->value() ?: 'udp'; // Default to UDP for iPhone compatibility

        try {
            if ($proto === 'wg') {
                // Build WG config on-demand; return as download
                $content = VpnConfigBuilder::generateWireGuardConfigString($client, $vpnserver);
                $name    = $this->safeName("{$vpnserver->name}_{$client->username}.conf");

                return response($content, 200, [
                    'Content-Type'        => 'text/plain',
                    'Content-Disposition' => "attachment; filename=\"{$name}\"",
                    'Cache-Control'       => 'no-cache, no-store, must-revalidate',
                    'Pragma'              => 'no-cache',
                    'Expires'             => '0',
                ]);
            }

            // Default: OpenVPN config with variant support
            $content = VpnConfigBuilder::generateOpenVpnConfigString($client, $vpnserver, $variant);
            
            // Friendly names for variants
            $variantNames = [
                'udp' => 'UDP',
                'unified' => 'Unified',
                'stealth' => 'Stealth'
            ];
            $variantSuffix = $variantNames[$variant] ?? $variant;
            $name = $this->safeName("{$vpnserver->name}_{$client->username}_{$variantSuffix}.ovpn");

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
    public function downloadForServer(Request $request, VpnUser $vpnUser, VpnServer $vpnServer)
    {
        $variant = $request->string('variant')->lower()->value() ?: 'udp'; // Default to UDP
        
        try {
            $content = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer, $variant);
            
            // Friendly names for variants
            $variantNames = [
                'udp' => 'UDP',
                'unified' => 'Unified',
                'stealth' => 'Stealth'
            ];
            $variantSuffix = $variantNames[$variant] ?? $variant;
            $name = $this->safeName("{$vpnServer->name}_{$vpnUser->username}_{$variantSuffix}.ovpn");

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
            // Add configs for all variants
            foreach ($servers as $server) {
                $variants = [
                    'unified' => 'Smart Profile (TCP+UDP)',
                    'stealth' => 'TCP 443 Stealth',
                    'udp' => 'UDP Traditional'
                ];
                
                foreach ($variants as $variant => $description) {
                    try {
                        $ovpn = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $server, $variant);
                        $file = $this->safeName("{$server->name}_{$vpnUser->username}_{$variant}.ovpn");
                        $zip->addFromString($file, $ovpn);
                    } catch (Exception $e) {
                        // Log error but continue with other configs
                        Log::warning("Failed to generate {$variant} config for {$server->name}: " . $e->getMessage());
                    }
                }
                
                // Add WireGuard if supported
                if ($server->wg_public_key) {
                    try {
                        $wg = VpnConfigBuilder::generateWireGuardConfigString($vpnUser, $server);
                        $wgFile = $this->safeName("{$server->name}_{$vpnUser->username}_wireguard.conf");
                        $zip->addFromString($wgFile, $wg);
                    } catch (Exception $e) {
                        Log::warning("Failed to generate WireGuard config for {$server->name}: " . $e->getMessage());
                    }
                }
            }

            $zip->close();

            return response()->download($zipPath, basename($zipPath), [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])->deleteFileAfterSend();
        } catch (Exception $e) {
            // ensure we close/remove on error
            try { $zip->close(); } catch (\Throwable $t) {}
            @unlink($zipPath);
            return back()->with('error', 'Failed to generate config archive: '.$e->getMessage());
        }
    }

    /**
     * Live sessions JSON - using enhanced connectivity check
     */
    public function showLiveSessions(VpnServer $vpnServer)
    {
        try {
            // Use the enhanced connectivity check which includes session info
            $connectivity = VpnConfigBuilder::testOpenVpnConnectivity($vpnServer);

            return response()->json([
                'success'        => true,
                'server'         => [
                    'id' => $vpnServer->id, 
                    'name' => $vpnServer->name, 
                    'ip_address' => $vpnServer->ip_address
                ],
                'connectivity'   => $connectivity,
                'stealth_available' => $connectivity['openvpn_tcp_stealth'] ?? false,
                'wireguard_available' => $connectivity['wireguard'] ?? false,
                'private_dns_enabled' => $connectivity['private_dns'] ?? false,
                'timestamp'      => now()->toISOString(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false, 
                'error' => 'Failed to fetch server status: '.$e->getMessage()
            ], 500);
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