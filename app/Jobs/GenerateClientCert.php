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

    protected VpnUser $vpnUser;
    protected VpnServer $vpnServer;

    public function __construct(VpnUser $vpnUser, VpnServer $vpnServer)
    {
        $this->vpnUser = $vpnUser;
        $this->vpnServer = $vpnServer;
    }

public function handle(): void
{
    Log::info("🔧 Starting GenerateClientCert for user {$this->vpnUser->username} on server {$this->vpnServer->name}");

    $sshKeyPath = storage_path('app/ssh_keys/id_rsa');
    if (!file_exists($sshKeyPath)) {
        Log::error("❌ SSH private key not found at {$sshKeyPath}");
        return;
    }

    $ip = $this->vpnServer->ip_address;
    $username = $this->vpnUser->username;
    $sshUser = $this->vpnServer->ssh_user ?? 'root';
    $sshPort = $this->vpnServer->ssh_port ?? 22;

    $script = <<<BASH
cd /etc/openvpn/easy-rsa
EASYRSA_BATCH=1 ./easyrsa gen-req {$username} nopass
echo 'yes' | EASYRSA_BATCH=1 ./easyrsa sign-req client {$username}
echo "===CERT==="
cat /etc/openvpn/easy-rsa/pki/issued/{$username}.crt
echo "===KEY==="
cat /etc/openvpn/easy-rsa/pki/private/{$username}.key
BASH;

    $cmd = "ssh -i {$sshKeyPath} -p {$sshPort} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null {$sshUser}@{$ip} 'bash -se'";

    Log::info("🔧 Running SSH command to generate client cert for {$username}");

    $process = proc_open($cmd, [
        0 => ['pipe','r'],
        1 => ['pipe','w'],
        2 => ['pipe','w'],
    ], $pipes);

    if (!is_resource($process)) {
        Log::error("❌ Failed to open SSH process for {$ip}");
        return;
    }

    fwrite($pipes[0], $script);
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        Log::error("❌ SSH script failed with exit code {$exitCode}: {$error}");
        return;
    }

    // 🔧 Parse outputs
    $cert = '';
    $key = '';
    if (preg_match('/===CERT===(.*?)===KEY===/s', $output, $matches)) {
        $cert = trim($matches[1]);
    }
    if (preg_match('/===KEY===(.*)$/s', $output, $matches)) {
        $key = trim($matches[1]);
    }

    if (empty($cert) || empty($key)) {
        Log::error("❌ Failed to parse cert or key output for {$username}");
        Log::debug("Full output:\n" . $output);
        return;
    }

    // 🔧 Save locally
    Storage::put("client_certs/{$username}.crt", $cert);
    Storage::put("client_certs/{$username}.key", $key);

    Log::info("✅ Generated and saved cert + key for {$username}");

    // 🔧 Trigger GenerateOvpnFile job
    \App\Jobs\GenerateOvpnFile::dispatch($this->vpnUser);

    Log::info("🚀 Dispatched GenerateOvpnFile job for {$username}");
}

}
