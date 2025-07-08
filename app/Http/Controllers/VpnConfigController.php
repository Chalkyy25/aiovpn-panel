<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class VpnConfigController extends Controller
{
    public function download(VpnUser $vpnUser)
    {
        // ✅ Default to first assigned server if only one
        $server = $vpnUser->vpnServers->first();

        if (!$server) {
            abort(404, 'No server assigned to this user.');
        }

        $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
        $path = "public/ovpn_configs/{$safeServerName}_{$vpnUser->username}.ovpn";

        if (!Storage::exists($path)) {
            abort(404, 'OVPN config not found for this server.');
        }

        return Storage::download($path);
    }

    public function downloadForServer(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name);
        $path = "public/ovpn_configs/{$safeServerName}_{$vpnUser->username}.ovpn";

        if (!Storage::exists($path)) {
            abort(404, 'OVPN config for this server not found.');
        }

        return Storage::download($path);
    }

    public function downloadAll(VpnUser $vpnUser)
    {
        $servers = $vpnUser->vpnServers;

        if ($servers->isEmpty()) {
            return back()->with('error', 'No servers assigned to this user.');
        }

        $zipFileName = "{$vpnUser->username}_all_configs.zip";
        $zipFilePath = storage_path("app/public/ovpn_configs/{$zipFileName}");

        $zip = new ZipArchive;

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($servers as $server) {
                $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
                $fileName = "{$safeServerName}_{$vpnUser->username}.ovpn";
                $path = storage_path("app/public/ovpn_configs/{$fileName}");

                if (file_exists($path)) {
                    $zip->addFile($path, $fileName);
                }
            }

            $zip->close();

            return response()->download($zipFilePath)->deleteFileAfterSend(true);
        }

        return back()->with('error', 'Could not create ZIP file.');
    }
}
