<?php

namespace App\Jobs;

use App\Models\VpnUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateOvpnFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;

    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser->load('vpnServers'); // updated relation
    }

    public function handle(): void
    {
        $sshUser = 'root';
        $sshKey = storage_path('app/ssh_keys/id_rsa');

        foreach ($this->vpnUser->vpnServers as $server) {

            $ip = $server->ip_address;
            Log::info("🔑 Generating embedded .ovpn for {$this->vpnUser->username} on {$server->name}");

            // 🔥 Generate client certificate automatically
            $this->generateClientCert($ip, $sshKey, $this->vpnUser->username);

            // 🔹 Fetch files
            $ca = $this->fetchRemoteFile($sshKey, $sshUser, $ip, '/etc/openvpn/ca.crt', 'CA cert');
            $ta = $this->fetchRemoteFile($sshKey, $sshUser, $ip, '/etc/openvpn/ta.key', 'TLS auth key');
            $cert = $this->fetchRemoteFile($sshKey, $sshUser, $ip, "/etc/openvpn/easy-rsa/pki/issued/{$this->vpnUser->username}.crt", 'Client cert');
            $key = $this->fetchRemoteFile($sshKey, $sshUser, $ip, "/etc/openvpn/easy-rsa/pki/private/{$this->vpnUser->username}.key", 'Client key');

            if (!$ca || !$ta || !$cert || !$key) {
                Log::error("❌ Missing one or more required cert/key files for {$server->name}.");
                continue; // Skip to next server
            }

            // 🔹 Load template
            $templatePath = 'ovpn_templates/client.ovpn';
            if (!Storage::exists($templatePath)) {
                Log::error("❌ Missing OVPN template at {$templatePath}");
                return;
            }

            $template = Storage::get($templatePath);

            // 🔹 Replace {{SERVER_IP}}
            $config = str_replace('{{SERVER_IP}}', $ip, $template);

            // 🔹 Embed all certificates and keys
            $config .= "\n\n<ca>\n{$ca}\n</ca>";
            $config .= "\n\n<cert>\n{$cert}\n</cert>";
            $config .= "\n\n<key>\n{$key}\n</key>";
            $config .= "\n\n<tls-auth>\n{$ta}\n</tls-auth>\nkey-direction 1";

            // 🔹 Save final .ovpn file to public folder
            $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
            $fileName = "public/ovpn_configs/{$safeServerName}_{$this->vpnUser->username}.ovpn";

            Storage::put($fileName, $config);
            Log::info("✅ Embedded .ovpn generated at storage/app/{$fileName}");
        }
    }

    private function fetchRemoteFile(string $sshKey, string $sshUser, string $ip, string $remotePath, string $label): ?string
    {
        $output = [];
        exec("ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'cat {$remotePath}'", $output, $status);

        if ($status !== 0 || empty($output)) {
            Log::error("❌ Failed to fetch {$label} from {$ip} (status {$status})");
            return null;
        }

        return implode("\n", $output);
    }

    private function generateClientCert(string $ip, string $sshKey, string $clientUsername): bool
    {
        Log::info("🔨 Generating client certificate for {$clientUsername} on {$ip}");

        $command = "ssh -i {$sshKey} -o StrictHostKeyChecking=no root@{$ip} 'cd /etc/openvpn/easy-rsa && ./easyrsa build-client-full {$clientUsername} nopass'";
        exec($command, $output, $status);

        if ($status !== 0) {
            Log::error("❌ Failed to generate client cert for {$clientUsername} on {$ip}");
            return false;
        }

        Log::info("✅ Generated client cert for {$clientUsername} on {$ip}");
        return true;
    }
}
