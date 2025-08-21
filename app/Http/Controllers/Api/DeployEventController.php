<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpnServer; // or VpnServer if you named it differently
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\ServerMgmtEvent;

class DeployEventController extends Controller
{
    public function store(Request $request, VpnServer $server)
    {
        $status  = (string) $request->string('status');
        $message = (string) $request->string('message');

        Log::info("APPEND_LOG: [$status] $message");

        if ($status === 'mgmt') {
            // sample parser: looks for 'clients=N [comma,list]'
            $clients = 0; $cnList = '';
            if (preg_match('/clients=(\d+)\s*\[([^\]]*)\]/', $message, $m)) {
                $clients = (int) $m[1];
                $cnList  = trim($m[2]);
            }
            broadcast(new ServerMgmtEvent(
                serverId: $server->id,
                ts: now()->toIso8601String(),
                clients: $clients,
                cnList: $cnList,
                raw: $message,
            ))->toOthers();
        }

        return response()->noContent();
    }
}