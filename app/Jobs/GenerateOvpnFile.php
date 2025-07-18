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

    /**
     * Create a new job instance.
     */
    public function __construct(VpnUser $vpnUser)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🚀 [OVPN] Starting config generation for user {$this->vpnUser->username}");

        foreach ($this->vpnUser->vpnServers as $server) {
            $ip = $server->ip_address;
            $username = $this->vpnUser->username;

            Log::info("🔧 [OVPN] Processing server: {$server->name} ({$ip})");

            // Check if client certificate already exists
            if ($this->checkClientCertExists($ip, $username)) {
                Log::info("🔎 [OVPN] Client cert for {$username} exists on {$ip}, skipping generation.");
            } else {
                if (!$this->generateClientCert($ip, $username)) {
                    Log::error("❌ [OVPN] Failed to generate cert for {$username} on {$ip}, skipping.");
                    continue;
                }
            }

            // Fetch required files for config embedding
            $files = [
                'ca'   => '/etc/openvpn/ca.crt',
                'ta'   => '/etc/openvpn/ta.key',
                'cert' => "/etc/openvpn/easy-rsa/pki/issued/{$username}.crt",
                'key'  => "/etc/openvpn/easy-rsa/pki/private/{$username}.key",
            ];

            $fetched = [];
            foreach ($files as $label => $path) {
                $content = $this->fetchRemoteFile($ip, $path, strtoupper($label));
                if (!$content) {
                    Log::error("❌ [OVPN] Missing {$label} for {$username} on {$ip}, skipping server.");
                    continue 2;
                }
                $fetched[$label] = $content;
            }

            // Load and build final config
            $templatePath = 'ovpn_templates/client.ovpn';
            if (!Storage::exists($templatePath)) {
                Log::error("❌ [OVPN] Missing template at {$templatePath}");
                return;
            }

            $template = Storage::get($templatePath);
            $config = str_replace(['{{SERVER_IP}}', '{{USERNAME}}'], [$ip, $username], $template);

            // Embed certificates and keys
            $config .= "\n\n<ca>\n{$fetched['ca']}\n</ca>";
            $config .= "\n\n<cert>\n{$fetched['cert']}\n</cert>";
            $config .= "\n\n<key>\n{$fetched['key']}\n</key>";
            $config .= "\n\n<tls-auth>\n{$fetched['ta']}\n</tls-auth>\nkey-direction 1";

            // Save final .ovpn file
            $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
            $fileName = "public/ovpn_configs/{$safeServerName}_{$username}.ovpn";

            Storage::put($fileName, $config);
            Storage::setVisibility($fileName, 'public');

            Log::info("✅ [OVPN] Generated config for {$username} on {$server->name}: storage/app/{$fileName}");
        }

        Log::info("🎉 [OVPN] Finished generating configs for {$this->vpnUser->username}");
    }

    /**
     * Run a generic SSH command.
     */
    private function runSshCommand(string $ip, string $command): array
    {
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $sshUser = 'root';
        $fullCommand = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} '{$command}'";
        exec($fullCommand, $output, $status);
        return [$status, $output];
    }

    /**
     * Check if client certificate exists on server.
     */
    private function checkClientCertExists(string $ip, string $clientUsername): bool
    {
        [$status, ] = $this->runSshCommand($ip, "test -f /etc/openvpn/easy-rsa/pki/issued/{$clientUsername}.crt");
        return $status === 0;
    }

    /**
     * Generate client certificate on server.
     */
    private function generateClientCert(string $ip, string $clientUsername): bool
    {
        Log::info("🔨 [OVPN] Generating client cert for {$clientUsername} on {$ip}");
        [$status, ] = $this->runSshCommand($ip, "cd /etc/openvpn/easy-rsa && ./easyrsa build-client-full {$clientUsername} nopass");

        if ($status !== 0) {
            Log::error("❌ [OVPN] Cert generation failed for {$clientUsername} on {$ip}");
            return false;
        }

        Log::info("✅ [OVPN] Generated cert for {$clientUsername} on {$ip}");
        return true;
    }

    /**
     * Fetch a remote file's content via SSH.
     */
    private function fetchRemoteFile(string $ip, string $remotePath, string $label): ?string
    {
        [$status, $output] = $this->runSshCommand($ip, "cat {$remotePath}");
        if ($status !== 0 || empty($output)) {
            Log::error("❌ [OVPN] Failed to fetch {$label} from {$ip} (status {$status})");
            return null;
        }
        return implode("\n", $output);
    }
}
