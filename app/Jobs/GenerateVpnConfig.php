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

class GenerateVpnConfig implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnUser $vpnUser;
    protected string $protocol;

    /**
     * @param VpnUser $vpnUser
     * @param string $protocol ('openvpn', 'wireguard', or 'all')
     */
    public function __construct(VpnUser $vpnUser, string $protocol = 'all')
    {
        $this->vpnUser = $vpnUser->load('vpnServers');
        $this->protocol = strtolower($protocol);
    }

    public function handle(): void
    {
        Log::info("🚀 Generating modern stealth VPN configs for {$this->vpnUser->username} using {$this->protocol} protocol(s).");

        foreach ($this->vpnUser->vpnServers as $server) {
            $serverConnectivity = VpnConfigBuilder::testOpenVpnConnectivity($server);
            
            if ($this->protocol === 'openvpn' || $this->protocol === 'all') {
                $this->generateModernOvpnConfigs($server, $serverConnectivity);
            }
            
            if ($this->protocol === 'wireguard' || $this->protocol === 'all') {
                $this->generateWireguardConfig($server, $serverConnectivity);
            }
        }

        Log::info("🎉 Finished generating modern VPN configurations for {$this->vpnUser->username}.");
    }

    /**
     * Generate modern OpenVPN configs with stealth support
     */
    protected function generateModernOvpnConfigs($server, $connectivity): void
    {
        Log::info("🔧 Generating modern OVPN configs for server: {$server->name} ({$server->ip_address})");

        $variants = [];
        
        // Determine which variants to generate based on server capabilities
        if ($connectivity['openvpn_tcp_stealth'] && $connectivity['openvpn_udp']) {
            $variants[] = 'unified'; // Best option: stealth + fallback
        }
        
        if ($connectivity['openvpn_tcp_stealth']) {
            $variants[] = 'stealth'; // TCP 443 stealth
        }
        
        if ($connectivity['openvpn_udp']) {
            $variants[] = 'udp'; // Traditional UDP
        }

        if (empty($variants)) {
            Log::warning("❌ No OpenVPN services detected on {$server->name}");
            return;
        }

        $generatedConfigs = [];
        
        foreach ($variants as $variant) {
            try {
                $config = VpnConfigBuilder::generateOpenVpnConfigString($this->vpnUser, $server, $variant);
                
                $generatedConfigs[] = [
                    'variant' => $variant,
                    'size' => strlen($config),
                    'has_stealth' => str_contains($config, '443 tcp'),
                    'is_unified' => substr_count($config, 'remote ') > 1
                ];
                
                Log::info("✅ Generated {$variant} OpenVPN config", [
                    'server' => $server->name,
                    'user' => $this->vpnUser->username,
                    'size_bytes' => strlen($config),
                    'variant' => $variant
                ]);
                
            } catch (\Exception $e) {
                Log::error("❌ Failed to generate {$variant} config for {$server->name}: " . $e->getMessage());
            }
        }

        Log::info("📊 OpenVPN config generation summary", [
            'server' => $server->name,
            'user' => $this->vpnUser->username,
            'configs_generated' => count($generatedConfigs),
            'variants' => array_column($generatedConfigs, 'variant'),
            'stealth_enabled' => in_array('stealth', array_column($generatedConfigs, 'variant')) || 
                               in_array('unified', array_column($generatedConfigs, 'variant'))
        ]);
    }

    /**
     * Generate WireGuard config using modern builder
     */
    protected function generateWireguardConfig($server, $connectivity): void
    {
        if (!($connectivity['wireguard'] ?? false)) {
            Log::info("ℹ️ WireGuard not available on {$server->name}, skipping");
            return;
        }

        Log::info("🔧 Generating WireGuard config for server: {$server->name} ({$server->ip_address})");

        try {
            $config = VpnConfigBuilder::generateWireGuardConfigString($this->vpnUser, $server);
            
            Log::info("✅ Generated WireGuard config", [
                'server' => $server->name,
                'user' => $this->vpnUser->username,
                'size_bytes' => strlen($config)
            ]);
            
        } catch (\Exception $e) {
            Log::error("❌ Failed to generate WireGuard config for {$server->name}: " . $e->getMessage());
        }
    }
}
