<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VpnServer; // or VpnServer if you named it differently
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\ServerMgmtEvent;

class DeployEventController extends Controller
{
    public function store($serverId, Request $request)
    {
        // Resolve server model in a forgiving way
        $server = \App\Models\VpnServer::find($serverId); // adjust if your model differs
        if (!$server) {
            // avoid 500s – just log and 204
            Log::warning("DeployEvent: unknown server id={$serverId}");
            return response()->noContent();
        }

        $status  = (string) $request->input('status', '');
        $message = (string) $request->input('message', '');

        // Quietly log once (optional)
        // Log::info("DeployEvent[$serverId]: {$status} {$message}");

        if ($status === 'mgmt') {
            $ts      = self::kv($message, 'ts') ?? now()->toIso8601String();
            $clients = (int) (self::kv($message, 'clients') ?? 0);

            // Extract [alice,bob] at end of line
            $cnList = '';
            if (preg_match('/\[(.*?)\]\s*$/', $message, $m)) {
                $cnList = trim($m[1]);
            }

            // Broadcast now (no queue)
            event(new ServerMgmtEvent(
                (int) $server->id,
                $ts,
                $clients,
                $cnList,
                $message
            ));
        }

        return response()->noContent();
    }

    // tiny helper: key=value extractor
    private static function kv(string $line, string $key): ?string
    {
        return preg_match('/\b'.preg_quote($key,'/').'=([^\s\]]+)/', $line, $m) ? $m[1] : null;
    }
}