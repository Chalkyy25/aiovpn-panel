<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Storage;
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
}
