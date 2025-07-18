<?php

namespace App\Livewire\Client;

use App\Models\VpnUser;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class DownloadConfig extends Component
{
    public function download()
    {
        $user = Auth::user();

        $vpnUser = VpnUser::where('user_id', $user->id)->with('vpnServer')->firstOrFail();
        $server = $vpnUser->vpnServer;

        $ssh = new SSH2($server->ip_address);
        $key = PublicKeyLoader::load(file_get_contents(storage_path('ssh/id_rsa')));

        if (!$ssh->login($server->ssh_user, $key)) {
            abort(500, 'SSH login failed');
        }

        $ta = $ssh->exec('cat /etc/openvpn/ta.key');
        $ca = $ssh->exec('cat /etc/openvpn/ca.crt');

        $ovpn = view('vpn.client-config', [
            'ip' => $server->ip_address,
            'port' => $server->vpn_port ?? 1194,
            'username' => $vpnUser->username,
            'ta' => trim($ta),
            'ca' => trim($ca),
        ])->render();

        return response($ovpn)
            ->header('Content-Type', 'application/x-openvpn-profile')
            ->header('Content-Disposition', 'attachment; filename="' . $vpnUser->username . '.ovpn"');
    }

    public function render()
    {
        return view('livewire.client.download-config');
    }
}
