<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;
use ZipArchive;

class VpnConfigController extends Controller
{
    public function download(VpnUser $vpnUser) // Matches: admin/clients/{vpnUser}/config
    {
        $path = "configs/{$vpnUser->username}_wg.conf";

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'WireGuard config not found for this user.');
        }

        return Storage::disk('local')->download($path);
    }

    public function downloadForServer(VpnUser $vpnUser, VpnServer $vpnServer) // Matches: admin/clients/{vpnUser}/config/{vpnServer}
    {
        $fileName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name) . "_$vpnUser->username.ovpn";
        $path = "public/ovpn_configs/$fileName";

        if (!Storage::exists($path)) {
            abort(404, "OpenVPN config not found for $vpnServer->name.");
        }

        return Storage::download($path);
    }

    public function downloadAll(VpnUser $vpnUser) // Matches: admin/clients/{vpnUser}/configs/download-all
    {
        $servers = $vpnUser->vpnServers;

        if ($servers->isEmpty()) {
            return back()->with('error', 'No servers assigned to this user.');
        }

        $zipFileName = "{$vpnUser->username}_all_configs.zip";
        $zipFilePath = storage_path("app/configs/$zipFileName");

        $zip = new ZipArchive();

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Include WireGuard config
            $wgConfig = storage_path("app/configs/{$vpnUser->username}_wg.conf");
            if (file_exists($wgConfig)) {
                $zip->addFile($wgConfig, "{$vpnUser->username}_wg.conf");
            }

            // Include OpenVPN configs for all assigned servers
            foreach ($servers as $server) {
                $fileName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name) . "_$vpnUser->username.ovpn";
                $ovpnPath = storage_path("app/public/ovpn_configs/$fileName");

                if (file_exists($ovpnPath)) {
                    $zip->addFile($ovpnPath, $fileName);
                }
            }

            $zip->close();

            return response()->download($zipFilePath)->deleteFileAfterSend();
        }

        return back()->with('error', 'Could not create ZIP file.');
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
