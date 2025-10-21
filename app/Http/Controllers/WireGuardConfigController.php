<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\WireGuardConfigBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class WireGuardConfigController extends Controller
{
    public function download(Request $request, VpnUser $user, VpnServer $server)
    {
        // Only admins reach this route per your middleware, but keep a safety check
        if (Gate::denies('view-vpn-user', $user)) {
            abort(403);
        }

        // Ensure the user is actually linked to the server
        if (! $user->vpnServers()->whereKey($server->id)->exists()) {
            abort(404, 'User is not associated with this server.');
        }

        // Build config text
        $conf = WireGuardConfigBuilder::build($user, $server);

        // Save to a predictable path and stream as a download
        $filename = sprintf('%s-%s.conf', $user->username, str_replace(' ', '-', $server->name));
        $path = "configs/wireguard/{$filename}";

        Storage::put($path, $conf); // uses default disk; change to 'local' if you prefer

        return response()->download(
            Storage::path($path),
            $filename,
            ['Content-Type' => 'text/plain']
        );
    }
}