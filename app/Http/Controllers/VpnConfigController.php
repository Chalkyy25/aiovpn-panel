<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Response;

class VpnConfigController extends Controller
{
    public function download(VpnUser $vpnUser)
    {
        $path = storage_path("app/configs/{$vpnUser->id}.ovpn");

        if (!file_exists($path)) {
            abort(404, 'Config not found.');
        }

        return Response::download($path, "{$vpnUser->username}.ovpn", [
            'Content-Type' => 'application/x-openvpn-profile',
        ]);
    }
}
