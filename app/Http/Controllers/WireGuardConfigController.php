<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;

class WireGuardConfigController extends Controller
{
    public function download(VpnUser $user, VpnServer $server)
    {
        // Only allow if the user is linked to this server
        abort_unless($user->vpnServers()->whereKey($server->id)->exists(), 403);

        // Build the client config (uses fields already in DB)
        $conf = \App\Services\VpnConfigBuilder::generateWireGuardConfig($user, $server);

        $filename = sprintf('%s-%s.conf', $user->username, str_replace(' ', '-', $server->name));
        return response($conf, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}