<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;
use ZipArchive;

class VpnConfigController extends Controller
{
    public function clientDownload(VpnServer $vpnserver)
    {
        $client = Auth::guard('client')->user();
        abort_if(!$client, 401, 'Unauthenticated client');
        abort_if(empty($vpnserver->ip_address), 400, 'Server IP missing');
    
        // Small creds shim for the builder (needs username/password)
        $creds = (object)[
            'username' => $client->username,
            'password' => $client->password, // ensure this is plain, not hashed
        ];
    
        $config = \App\Services\VpnConfigBuilder::generateOpenVpnConfigString($creds, $vpnserver);
    
        $safe = Str::of($vpnserver->name)->replace([' ', '(', ')'], ['_', '', '']);
        $file = "{$safe}_{$client->username}.ovpn";
    
        return response($config)
            ->header('Content-Type', 'application/x-openvpn-profile')
            ->header('Content-Disposition', "attachment; filename=\"{$file}\"")
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function download(VpnUser $vpnUser, VpnServer $vpnServer = null) // Matches: admin/clients/{vpnUser}/config and client/vpn/{server}/download
    {
        // If server is provided, generate OpenVPN config with server name
        if ($vpnServer) {
            try {
                $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer);
                $fileName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name) . "_$vpnUser->username.ovpn";

                return response($configContent)
                    ->header('Content-Type', 'application/x-openvpn-profile')
                    ->header('Content-Disposition', "attachment; filename=\"$fileName\"")
                    ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');

            } catch (Exception $e) {
                abort(500, "Failed to generate OpenVPN config for $vpnServer->name: " . $e->getMessage());
            }
        }

        // Default behavior for WireGuard configs
        $path = "configs/{$vpnUser->username}_wg.conf";

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'WireGuard config not found for this user.');
        }

        return Storage::disk('local')->download($path);
    }

    public function downloadForServer(VpnUser $vpnUser, VpnServer $vpnServer) // Matches: admin/clients/{vpnUser}/config/{vpnServer}
    {
        try {
            // ✅ SECURITY FIX: Generate config on-demand instead of reading from disk
            $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer);

            $fileName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name) . "_$vpnUser->username.ovpn";

            return response($configContent)
                ->header('Content-Type', 'application/x-openvpn-profile')
                ->header('Content-Disposition', "attachment; filename=\"$fileName\"")
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (Exception $e) {
            abort(500, "Failed to generate OpenVPN config for $vpnServer->name: " . $e->getMessage());
        }
    }

    public function downloadAll(VpnUser $vpnUser) // Matches: admin/clients/{vpnUser}/configs/download-all
    {
        $servers = $vpnUser->vpnServers;

        if ($servers->isEmpty()) {
            return back()->with('error', 'No servers assigned to this user.');
        }

        try {
            $zipFileName = "{$vpnUser->username}_all_configs.zip";
            $zipFilePath = storage_path("app/temp/$zipFileName");

            // Ensure temp directory exists
            if (!is_dir(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            $zip = new ZipArchive();

            if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                // ✅ SECURITY FIX: Generate WireGuard config on-demand
                $wgConfigPath = VpnConfigBuilder::generateWireGuard($vpnUser);
                if ($wgConfigPath && file_exists($wgConfigPath)) {
                    $zip->addFile($wgConfigPath, "{$vpnUser->username}_wg.conf");
                }

                // ✅ SECURITY FIX: Generate OpenVPN configs on-demand
                foreach ($servers as $server) {
                    $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $server);
                    $fileName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name) . "_$vpnUser->username.ovpn";

                    // Add config content directly to ZIP
                    $zip->addFromString($fileName, $configContent);
                }

                $zip->close();

                $response = response()->download($zipFilePath)->deleteFileAfterSend();
                $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
                return $response;
            }

            return back()->with('error', 'Could not create ZIP file.');

        } catch (Exception $e) {
            return back()->with('error', 'Failed to generate config archive: ' . $e->getMessage());
        }
    }

    /**
     * Generate and download OpenVPN config without saving to file.
     */
    public function generateOpenVpnConfig(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        try {
            $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer);

            $fileName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name) . "_$vpnUser->username.ovpn";

            return response($configContent)
                ->header('Content-Type', 'application/x-openvpn-profile')
                ->header('Content-Disposition', "attachment; filename=\"$fileName\"");

        } catch (Exception $e) {
            return back()->with('error', 'Failed to generate OpenVPN config: ' . $e->getMessage());
        }
    }

    /**
     * Show live OpenVPN sessions for a server.
     */
    public function showLiveSessions(VpnServer $vpnServer)
    {
        try {
            $sessions = VpnConfigBuilder::getLiveOpenVpnSessions($vpnServer);

            return response()->json([
                'success' => true,
                'server' => [
                    'id' => $vpnServer->id,
                    'name' => $vpnServer->name,
                    'ip_address' => $vpnServer->ip_address
                ],
                'sessions' => $sessions,
                'total_sessions' => count($sessions),
                'timestamp' => now()->toISOString()
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch live sessions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate OpenVPN config preview (returns config as JSON for display).
     */
    public function previewOpenVpnConfig(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        try {
            $configContent = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer);

            return response()->json([
                'success' => true,
                'server' => [
                    'id' => $vpnServer->id,
                    'name' => $vpnServer->name,
                    'ip_address' => $vpnServer->ip_address
                ],
                'user' => [
                    'id' => $vpnUser->id,
                    'username' => $vpnUser->username
                ],
                'config_content' => $configContent,
                'config_lines' => count(explode("\n", $configContent)),
                'timestamp' => now()->toISOString()
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate config preview: ' . $e->getMessage()
            ], 500);
        }
    }
}
