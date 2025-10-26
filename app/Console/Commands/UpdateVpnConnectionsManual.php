<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\UpdateVpnConnectionStatus;

class UpdateVpnConnectionsManual extends Command
{
    protected $signature = 'vpn:update-connections';
    protected $description = 'Manually update VPN connection status from all servers';

    public function handle()
    {
        $this->info('🔄 Starting manual VPN connection status update...');
        
        try {
            // Dispatch the job directly
            $job = new UpdateVpnConnectionStatus();
            $job->handle();
            
            $this->info('✅ VPN connection status update completed successfully!');
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to update VPN connection status: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
        }
    }
}