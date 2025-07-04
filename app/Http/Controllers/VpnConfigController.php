<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use ZipArchive;

class VpnConfigController extends Controller
{
    public function download(VpnUser $vpnUser)
    {
        $server = $vpnUser->vpnServer; // Adjust if using single server per user now

        if (!$server) {
            abort(404, 'No server assigned to this user.');
        }

        $path = "public/ovpn_configs/{$server->name}_{$vpnUser->username}.ovpn";

        if (!Storage::exists($path)) {
            abort(404, 'OVPN config not found.');
        }

        return Storage::download($path);
    }

    public function downloadForServer(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        $path = "public/ovpn_configs/{$vpnServer->name}_{$vpnUser->username}.ovpn";

        if (!Storage::exists($path)) {
            abort(404, 'OVPN config for this server not found.');
        }

        return Storage::download($path);
    }

    public function downloadAll(VpnUser $vpnUser)
    {
        $servers = $vpnUser->vpnServers; // Use relation for many-to-many

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
