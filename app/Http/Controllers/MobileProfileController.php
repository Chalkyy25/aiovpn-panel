<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\VpnUser;

class MobileProfileController extends Controller
{
    public function index(Request $request)
    {
        /** @var VpnUser $user */
        $user = $request->user();

        // Be defensive: don't select columns that may not exist
        // We'll read the common fields and ignore missing ones.
        $servers = $user->vpnServers()->get()->map(function ($s) {
            return [
                'id'        => $s->id,
                'name'      => $s->name ?? ('Server '.$s->id),
                'ip'        => $s->ip_address ?? $s->ip ?? null,
                'country'   => $s->country ?? null,
                'city'      => $s->city ?? null,
                'proto'     => $s->proto ?? 'udp',
                'port'      => $s->port  ?? 1194,
            ];
        });

        return response()->json([
            'id'        => $user->id,
            'username'  => $user->username,
            'expires'   => $user->expires_at,
            'max_conn'  => (int) $user->max_connections,
            'servers'   => $servers,
        ]);
    }

    public function show(Request $request, VpnUser $user)
    {
        if ($request->user()->id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // You can later accept a server id param; for now pick first assigned
        $server = $user->vpnServers()->first();
        if (!$server) {
            return response("No server assigned to this user", 404);
        }

        // Pull CA / TLS from DB if present, otherwise from standard file paths
        $ca  = trim((string)($server->ca_cert ?? @file_get_contents('/etc/openvpn/server/ca.crt') ?: ''));
        $tls = trim((string)($server->tls_key ?? @file_get_contents('/etc/openvpn/server/ta.key') ?: ''));

        // Basic proto/port with sane defaults
        $proto = $server->proto ?? 'udp';
        $port  = $server->port  ?? 1194;
        $host  = $server->ip_address ?? $server->ip ?? '127.0.0.1';

        $config = view('vpn.ovpn-template', compact('host','port','proto','ca','tls'))->render();

        return response($config, 200, [
            'Content-Type'        => 'application/x-openvpn-profile',
            'Content-Disposition' => "attachment; filename=aio-{$user->username}.ovpn",
        ]);
    }
}
