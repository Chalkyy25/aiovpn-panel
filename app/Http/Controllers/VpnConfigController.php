namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class VpnConfigController extends Controller
{
    public function download(VpnUser $vpnUser)
    {
        // 🔑 WireGuard single config download (default to wg for simplicity)
        $path = "configs/{$vpnUser->username}_wg.conf";

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'WireGuard config not found for this user.');
        }

        return Storage::disk('local')->download($path);
    }

    public function downloadForServer(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        // 🔑 For OpenVPN per-server download
        $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $vpnServer->name);
        $path = "public/ovpn_configs/{$safeServerName}_{$vpnUser->username}.ovpn";

        if (!Storage::exists($path)) {
            abort(404, 'OpenVPN config for this server not found.');
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
        $zipFilePath = storage_path("app/configs/{$zipFileName}");

        $zip = new ZipArchive;

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach ($servers as $server) {
                // 🔑 Add WireGuard config if exists
                $wgConfig = storage_path("app/configs/{$vpnUser->username}_wg.conf");
                if (file_exists($wgConfig)) {
                    $zip->addFile($wgConfig, "{$vpnUser->username}_wg.conf");
                }

                // 🔑 Add OpenVPN config if exists
                $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
                $ovpnFile = storage_path("app/public/ovpn_configs/{$safeServerName}_{$vpnUser->username}.ovpn");
                if (file_exists($ovpnFile)) {
                    $zip->addFile($ovpnFile, "{$safeServerName}_{$vpnUser->username}.ovpn");
                }
            }

            $zip->close();
            return response()->download($zipFilePath)->deleteFileAfterSend(true);
        }

        return back()->with('error', 'Could not create ZIP file.');
    }
}
