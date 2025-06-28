<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\VpnUser;
use App\Models\VpnServer;

class GenerateClientCert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $vpnUser;
    protected $vpnServer;

    public function __construct(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        $this->vpnUser = $vpnUser;
        $this->vpnServer = $vpnServer;
    }

    public function handle()
    {
        $ssh = new \phpseclib3\Net\SSH2($this->vpnServer->ip_address);
        $key = \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey(file_get_contents('/path/to/your/private/key'));

        if (!$ssh->login('root', $key)) {
            throw new \Exception('SSH login failed');
        }

        // 🔧 Generate client cert + key
        $username = $this->vpnUser->username;

        $ssh->exec("cd /etc/openvpn/easy-rsa && ./easyrsa gen-req {$username} nopass");
        $ssh->exec("cd /etc/openvpn/easy-rsa && ./easyrsa sign-req client {$username}");

        // 🔧 Fetch generated files
        $cert = $ssh->exec("cat /etc/openvpn/easy-rsa/pki/issued/{$username}.crt");
        $key = $ssh->exec("cat /etc/openvpn/easy-rsa/pki/private/{$username}.key");

        // 🔧 Save locally for embedding
        Storage::put("client_certs/{$username}.crt", $cert);
        Storage::put("client_certs/{$username}.key", $key);

        // 🔧 Trigger your GenerateOvpnFile job next
        \App\Jobs\GenerateOvpnFile::dispatch($this->vpnUser, $this->vpnServer);
    }
}