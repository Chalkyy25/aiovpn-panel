<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateOvpnFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;
    protected $server = null;

    /**
     * Create a new job instance.
     *
     * @param VpnUser $vpnUser
     * @param object|null $server
     */
    public function __construct(VpnUser $vpnUser, $server = null)
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
        $this->server = $server;
    }

    /**
     * Execute the job - now uses modern VpnConfigBuilder with stealth support.
     */
    public function handle(): void
    {
        Log::info("� Generating modern OVPN configs (stealth-enabled) for user: {$this->vpnUser->username}");

        // If a specific server is provided, generate only for that server
        if ($this->server) {
            $this->generateForServer($this->server);
            return;
        }

        // Otherwise, generate for all servers associated with the user
        if ($this->vpnUser->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$this->vpnUser->username}");
            return;
        }

        foreach ($this->vpnUser->vpnServers as $server) {
            $this->generateForServer($server);
        }

        Log::info("✅ Completed modern OVPN config generation for user: {$this->vpnUser->username}");
    }

    /**
     * Generate modern stealth-enabled OVPN configs for a specific server.
     *
     * @param object $server
     * @return void
     */
    protected function generateForServer($server): void
    {
        Log::info("🔧 Generating stealth OVPN configs for server: {$server->name} ({$server->ip_address})");

        // Test server connectivity and get recommendations
        $connectivity = VpnConfigBuilder::testOpenVpnConnectivity($server);
        $recommendedVariant = VpnConfigBuilder::getRecommendedVariant($server);
        
        Log::info("📊 Server {$server->name} connectivity", [
            'tcp_stealth' => $connectivity['openvpn_tcp_stealth'] ?? false,
            'udp_available' => $connectivity['openvpn_udp'] ?? false,
            'wireguard' => $connectivity['wireguard'] ?? false,
            'recommended' => $recommendedVariant
        ]);

        // Generate all available config variants
        $variants = ['unified', 'stealth', 'udp'];
        $generatedConfigs = [];

        foreach ($variants as $variant) {
            try {
                // Test if we can generate this variant
                $config = VpnConfigBuilder::generateOpenVpnConfigString($this->vpnUser, $server, $variant);
                
                if ($config) {
                    $generatedConfigs[] = [
                        'variant' => $variant,
                        'size' => strlen($config),
                        'filename' => "{$server->name}_{$this->vpnUser->username}_{$variant}.ovpn"
                    ];
                    
                    Log::info("✅ Generated {$variant} config for {$server->name}", [
                        'size_bytes' => strlen($config),
                        'has_stealth' => str_contains($config, '443 tcp'),
                        'is_unified' => substr_count($config, 'remote ') > 1
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Failed to generate {$variant} config for {$server->name}: " . $e->getMessage());
            }
        }

        // Try to generate WireGuard if server supports it
        if ($connectivity['wireguard'] ?? false) {
            try {
                $wgConfig = VpnConfigBuilder::generateWireGuardConfigString($this->vpnUser, $server);
                if ($wgConfig) {
                    $generatedConfigs[] = [
                        'variant' => 'wireguard',
                        'size' => strlen($wgConfig),
                        'filename' => "{$server->name}_{$this->vpnUser->username}_wireguard.conf"
                    ];
                    Log::info("✅ Generated WireGuard config for {$server->name}");
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Failed to generate WireGuard config for {$server->name}: " . $e->getMessage());
            }
        }

        Log::info("🎉 Config generation summary for {$server->name}", [
            'configs_generated' => count($generatedConfigs),
            'variants' => array_column($generatedConfigs, 'variant'),
            'recommended_variant' => $recommendedVariant,
            'total_size_bytes' => array_sum(array_column($generatedConfigs, 'size'))
        ]);
    }
}
