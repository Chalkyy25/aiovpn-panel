<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ProvisioningController extends Controller
{
    public function start(Request $req, VpnServer $server)
    {
        $payload = [
            'deployment_status' => 'running',
            'deployment_log'    => trim(($server->deployment_log ?? '')."\n".$req->input('message','▶ start')),
            'is_deploying'      => true,
        ];

        if (! Schema::hasColumn('vpn_servers', 'is_deploying')) {
            unset($payload['is_deploying']);
        }

        $server->forceFill($payload)->save();
        return response()->json(['ok' => true]);
    }

    public function update(Request $req, VpnServer $server)
    {
        $line = $req->input('message', '');
        if ($line !== '') {
            $server->update(['deployment_log' => trim(($server->deployment_log ?? '')."\n".$line)]);
        }
        return response()->json(['ok' => true]);
    }

    public function finish(Request $req, VpnServer $server)
    {
        $ok = (bool) $req->boolean('ok', false);
        $payload = [
            'deployment_status' => $ok ? 'success' : 'failed',
            'status'            => $ok ? 'online' : 'offline',
            'deployment_log'    => trim(($server->deployment_log ?? '')."\n".($req->input('message','■ done'))),
            'is_deploying'      => false,
        ];

        if (! Schema::hasColumn('vpn_servers', 'is_deploying')) {
            unset($payload['is_deploying']);
        }

        $server->forceFill($payload)->save();
        return response()->json(['ok' => true]);
    }

    public function passwordFile(Request $req, VpnServer $server)
    {
        // Build a simple "username password" per line, for all active users attached to this server.
        // Prefer a real credential source. Here we fall back to plain_password if you store it.
        $lines = $server->vpnUsers()
            ->where('is_active', true)
            ->get()
            ->map(function (VpnUser $u) {
                // If you hash passwords for OpenVPN, replace this with the unhashed source you control.
                $pwd = $u->plain_password ?? $u->password_plain ?? '';
                return trim($u->username.' '.$pwd);
            })
            ->filter(fn($line) => $line !== '')
            ->implode("\n");

        return response($lines."\n", Response::HTTP_OK, [
            'Content-Type'        => 'text/plain',
            'Cache-Control'       => 'no-store',
            'X-OpenVPN-Psw-Count' => (string) substr_count($lines, "\n") + (strlen($lines) ? 1 : 0),
        ]);
    }
}