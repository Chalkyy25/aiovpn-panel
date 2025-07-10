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
        $this->vpnUser = $vpnUser->load('vpnServers');
    }

    public function handle(): void
    {
        foreach ($this->vpnUser->vpnServers as $server) {
            $ip = $server->ip_address;
            $username = $this->vpnUser->username;

            Log::info("🔑 Generating embedded .ovpn for {$username} on {$server->name}");

            // ✅ Skip if cert already exists
            if ($this->checkClientCertExists($ip, $username)) {
                Log::info("🔎 Client cert for {$username} already exists on {$ip}, skipping generation.");
            } else {
                $this->generateClientCert($ip, $username);
            }

            // 🔹 Fetch files
            $ca = $this->fetchRemoteFile($ip, '/etc/openvpn/ca.crt', 'CA cert');
            $ta = $this->fetchRemoteFile($ip, '/etc/openvpn/ta.key', 'TLS auth key');
            $cert = $this->fetchRemoteFile($ip, "/etc/openvpn/easy-rsa/pki/issued/{$username}.crt", 'Client cert');
            $key = $this->fetchRemoteFile($ip, "/etc/openvpn/easy-rsa/pki/private/{$username}.key", 'Client key');

            if (!$ca || !$ta || !$cert || !$key) {
                Log::error("❌ Missing one or more required cert/key files for {$server->name}.");
                continue;
            }

            // 🔹 Load template
            $templatePath = 'ovpn_templates/client.ovpn';
            if (!Storage::exists($templatePath)) {
                Log::error("❌ Missing OVPN template at {$templatePath}");
                return;
            }

            $template = Storage::get($templatePath);

            // 🔹 Replace variables
            $config = str_replace(
                ['{{SERVER_IP}}', '{{USERNAME}}'],
                [$ip, $username],
                $template
            );

            // 🔹 Embed certs and keys
            $config .= "\n\n<ca>\n{$ca}\n</ca>";
            $config .= "\n\n<cert>\n{$cert}\n</cert>";
            $config .= "\n\n<key>\n{$key}\n</key>";
            $config .= "\n\n<tls-auth>\n{$ta}\n</tls-auth>\nkey-direction 1";

            // 🔹 Save to public folder
            $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
            $fileName = "public/ovpn_configs/{$safeServerName}_{$username}.ovpn";

            Storage::put($fileName, $config);
            Storage::setVisibility($fileName, 'public');

            Log::info("✅ Embedded .ovpn generated at storage/app/{$fileName}");
        }
    }

    private function runSshCommand(string $ip, string $command): array
    {
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $sshUser = 'root';

        $fullCommand = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} '{$command}'";
        exec($fullCommand, $output, $status);

        return [$status, $output];
    }

    private function checkClientCertExists(string $ip, string $clientUsername): bool
    {
        [$status, $output] = $this->runSshCommand($ip, "test -f /etc/openvpn/easy-rsa/pki/issued/{$clientUsername}.crt");

        return $status === 0;
    }

    private function generateClientCert(string $ip, string $clientUsername): bool
    {
        Log::info("🔨 Generating client certificate for {$clientUsername} on {$ip}");

        [$status, $output] = $this->runSshCommand($ip, "cd /etc/openvpn/easy-rsa && ./easyrsa build-client-full {$clientUsername} nopass");

        if ($status !== 0) {
            Log::error("❌ Failed to generate client cert for {$clientUsername} on {$ip}");
            return false;
        }

        Log::info("✅ Generated client cert for {$clientUsername} on {$ip}");
        return true;
    }

    private function fetchRemoteFile(string $ip, string $remotePath, string $label): ?string
    {
        [$status, $output] = $this->runSshCommand($ip, "cat {$remotePath}");

        if ($status !== 0 || empty($output)) {
            Log::error("❌ Failed to fetch {$label} from {$ip} (status {$status})");
            return null;
        }

        return implode("\n", $output);
    }
}
