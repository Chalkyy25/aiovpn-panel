<?php

namespace App\Console\Commands;

use App\Models\VpnServer;
use App\Services\VpnConfigBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateGenericStealthConfigs extends Command
{
    protected $signature = 'vpn:generate-generic-stealth {--server= : Specific server ID}';
    protected $description = 'Generate generic stealth OVPN configs for all servers (for mobile apps)';

    public function handle(): int
    {
        $this->info('🚀 Generating generic stealth configs for AIO Smarters App...');

        $query = VpnServer::query();
        
        if ($serverId = $this->option('server')) {
            $query->where('id', $serverId);
        }

        $servers = $query->where('status', 'active')->get();

        if ($servers->isEmpty()) {
            $this->error('No servers found!');
            return 1;
        }

        $configsGenerated = 0;
        $errors = [];

        foreach ($servers as $server) {
            try {
                $this->info("Generating stealth config for: {$server->name}");
                
                $configBuilder = new VpnConfigBuilder();
                $config = $configBuilder->generateGenericStealthConfig($server);
                $safeName = preg_replace('/[^\w\-]+/u', '_', $server->name);
                $filename = "generic_stealth_{$safeName}.ovpn";
                
                // Save to storage/app/generic-configs/
                Storage::disk('local')->put("generic-configs/{$filename}", $config);
                
                $this->line("  ✅ Generated: storage/app/generic-configs/{$filename}");
                $configsGenerated++;
                
            } catch (\Exception $e) {
                $error = "❌ Failed to generate config for {$server->name}: {$e->getMessage()}";
                $this->error($error);
                $errors[] = $error;
            }
        }

        $this->newLine();
        $this->info("🎉 Generated {$configsGenerated} generic stealth configs!");
        
        if (!empty($errors)) {
            $this->newLine();
            $this->error("Errors encountered:");
            foreach ($errors as $error) {
                $this->line($error);
            }
        }

        $this->newLine();
        $this->info("💡 Usage for AIO Smarters App:");
        $this->line("  • Use these configs as base templates");
        $this->line("  • App handles user authentication separately");
        $this->line("  • All configs use TCP 443 stealth mode");
        $this->line("  • Optimized for mobile platforms");

        return 0;
    }
}