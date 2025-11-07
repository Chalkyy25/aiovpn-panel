<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
     * @param VpnUser     $vpnUser
     * @param object|null $server
     */
    public function __construct(VpnUser $vpnUser, $server = null)
    {
        // ensure pivot is available when running without explicit server
        $this->vpnUser = $vpnUser->load('vpnServers');
        $this->server  = $server;
    }

    /**
     * Execute the job: generate full config pack (OVPN + WG client configs).
     */
    public function handle(): void
    {
        $user = $this->vpnUser;
        $userId = $user->id;

        Log::info("🌀 Modern stealth OVPN/WG config pack queued for user: {$user->username}");

        // If a specific server is provided, treat as a single-target run.
        if ($this->server) {
            $this->setProgress($userId, 5, "Generating configs for {$this->server->name}");
            $this->generateForServer($this->server);
            $this->setProgress($userId, 100, "Config pack complete for {$user->username}");
            Log::info("✅ Completed modern config pack generation for user: {$user->username} (single server)");
            return;
        }

        // Otherwise, generate for all linked servers.
        if ($user->vpnServers->isEmpty()) {
            Log::warning("⚠️ No VPN servers associated with user {$user->username}");
            $this->setProgress($userId, 100, "No servers linked for {$user->username}");
            return;
        }

        $servers = $user->vpnServers;
        $count   = max(1, $servers->count());
        $step    = max(1, intdiv(100, $count));
        $current = 1;

        $this->setProgress($userId, $current, "Starting config pack for {$user->username}");

        foreach ($servers as $server) {
            $label = $server->name;

            // pre-server bump
            $this->setProgress(
                $userId,
                min($current + 1, 99),
                "Generating configs for {$label}"
            );

            $this->generateForServer($server);

            // post-server bump
            $current = min($current + $step, 99);
            $this->setProgress(
                $userId,
                $current,
                "{$label} configs complete"
            );
        }

        $this->setProgress($userId, 100, "Config pack complete for {$user->username}");
        Log::info("✅ Completed modern OVPN/WG config pack generation for user: {$user->username}");
    }

    /**
     * Generate modern stealth-enabled OpenVPN and WireGuard configs for a specific server.
     *
     * @param object $server
     */
    protected function generateForServer($server): void
    {
        Log::info("🔧 Generating stealth OVPN configs for server: {$server->name} ({$server->ip_address})");

        // Connectivity and recommendation
        $connectivity        = VpnConfigBuilder::testOpenVpnConnectivity($server);
        $recommendedVariant  = VpnConfigBuilder::getRecommendedVariant($server);

        Log::info("📊 Server {$server->name} connectivity", [
            'tcp_stealth'   => $connectivity['openvpn_tcp_stealth'] ?? false,
            'udp_available' => $connectivity['openvpn_udp'] ?? false,
            'wireguard'     => $connectivity['wireguard'] ?? false,
            'recommended'   => $recommendedVariant,
        ]);

        $variants         = ['unified', 'stealth', 'udp'];
        $generatedConfigs = [];

        // OpenVPN variants
        foreach ($variants as $variant) {
            try {
                $config = VpnConfigBuilder::generateOpenVpnConfigString($this->vpnUser, $server, $variant);

                if ($config) {
                    $generatedConfigs[] = [
                        'variant'  => $variant,
                        'size'     => strlen($config),
                        'filename' => "{$server->name}_{$this->vpnUser->username}_{$variant}.ovpn",
                    ];

                    Log::info("✅ Generated {$variant} config for {$server->name}", [
                        'size_bytes'  => strlen($config),
                        'has_stealth' => str_contains($config, '443'),
                        'is_unified'  => substr_count($config, 'remote ') > 1,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("⚠️ Failed to generate {$variant} config for {$server->name}: ".$e->getMessage());
            }
        }

        // WireGuard client config
        if ($connectivity['wireguard'] ?? false) {
            try {
                $wgConfig = VpnConfigBuilder::generateWireGuardConfigString($this->vpnUser, $server);

                if ($wgConfig) {
                    $generatedConfigs[] = [
                        'variant'  => 'wireguard',
                        'size'     => strlen($wgConfig),
                        'filename' => "{$server->name}_{$this->vpnUser->username}_wireguard.conf",
                    ];

                    Log::info("✅ Generated WireGuard config for {$server->name}");
                }
            } catch (\Throwable $e) {
                Log::warning("⚠️ Failed to generate WireGuard config for {$server->name}: ".$e->getMessage());
            }
        }

        Log::info("🎉 Config generation summary for {$server->name}", [
            'configs_generated'   => count($generatedConfigs),
            'variants'            => array_column($generatedConfigs, 'variant'),
            'recommended_variant' => $recommendedVariant,
            'total_size_bytes'    => array_sum(array_column($generatedConfigs, 'size')),
        ]);
    }

    /**
     * Store per-user progress for Livewire to poll.
     */
    protected function setProgress(int $userId, int $percent, string $message): void
    {
        $percent = max(0, min(100, $percent));

        Cache::put("config_progress:{$userId}", [
            'percent'    => $percent,
            'message'    => $message,
            'updated_at' => now()->toDateTimeString(),
        ], now()->addMinutes(10));
    }
}