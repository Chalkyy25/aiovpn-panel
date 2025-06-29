<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
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
        Log::info("🔧 Starting GenerateClientCert for user {$this->vpnUser->username} on server {$this->vpnServer->name}");

        $sshKeyPath = storage_path('app/ssh_keys/id_rsa');
        if (!file_exists($sshKeyPath)) {
            Log::error("❌ SSH private key not found at {$sshKeyPath}");
            return;
        }

        $ssh = new \phpseclib3\Net\SSH2($this->vpnServer->ip_address);
        $key = \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($sshKeyPath));

        if (!$ssh->login($this->vpnServer->ssh_user, $key)) {
            Log::error("❌ SSH login failed for {$this->vpnServer->ip_address}");
            return;
        }

        $username = $this->vpnUser->username;

        Log::info("🔧 Generating cert + key for {$username}");

        $ssh->exec("cd /etc/openvpn/easy-rsa && ./easyrsa gen-req {$username} nopass");
        $ssh->exec("cd /etc/openvpn/easy-rsa && echo yes | ./easyrsa sign-req client {$username}");

        // 🔧 Fetch generated files
        $cert = $ssh->exec("cat /etc/openvpn/easy-rsa/pki/issued/{$username}.crt");
        $key = $ssh->exec("cat /etc/openvpn/easy-rsa/pki/private/{$username}.key");

        if (empty($cert) || empty($key)) {
            Log::error("❌ Failed to fetch generated cert or key for {$username}");
            return;
        }

        // 🔧 Save locally for embedding
        Storage::put("client_certs/{$username}.crt", $cert);
        Storage::put("client_certs/{$username}.key", $key);

        Log::info("✅ Generated and saved cert + key for {$username}");

        // 🔧 Trigger GenerateOvpnFile job
        \App\Jobs\GenerateOvpnFile::dispatch($this->vpnUser);

        Log::info("🚀 Dispatched GenerateOvpnFile job for {$username}");
    }
}
