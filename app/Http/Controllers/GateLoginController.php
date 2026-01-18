<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GateLoginController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required','string','max:128'],
            'password' => ['required','string','max:128'],
        ]);

        $dns = rtrim(env('XTREAM_DNS', ''), '/');
        if (!$dns) {
            return response()->json(['status' => 'error', 'message' => 'Server misconfigured'], 500);
        }

        $url = $dns . '/player_api.php';
        $resp = Http::timeout(8)->get($url, [
            'username' => $data['username'],
            'password' => $data['password'],
        ]);

        if (!$resp->ok()) {
            return response()->json(['status' => 'error', 'message' => 'Upstream error'], 502);
        }

        $json = $resp->json();
        $ui = $json['user_info'] ?? null;

        $auth   = (string)($ui['auth'] ?? '0');
        $status = strtolower((string)($ui['status'] ?? ''));
        $exp    = (int)($ui['exp_date'] ?? 0);

        $now = time();
        $valid = ($auth === '1') && ($status === 'active') && ($exp === 0 || $exp > $now); // some panels use 0 for "never"

        if (!$valid) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or expired'], 401);
        }

        $addons = array_values(array_filter(array_map('trim',
    explode(',', (string) env('GATE_ADDONS', ''))
)));

return response()->json([
    'status' => 'ok',
    'open'   => 'stremio',
    'addons' => $addons,
]);
    }
}