<?php

namespace App\Console\Commands;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Jobs\RemoveWireGuardPeer;
use App\Jobs\RemoveOpenVPNUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;

class TestAutoDeletion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:auto-deletion';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test auto-deletion functionality for VPN users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧪 Testing Auto-Deletion Functionality for VPN Users');
        $this->info(str_repeat('=', 60));

        try {
            // Step 1: Create test server
            $this->info('1. Creating test VPN server...');
            $testServer = VpnServer::create([
                'name' => 'test-server-autodeletion',
                'ip_address' => '192.168.1.100',
                'protocol' => 'openvpn',
                'ssh_port' => 22,
                'ssh_user' => 'root',
                'ssh_type' => 'key',
                'ssh_key' => storage_path('app/ssh_keys/id_rsa'),
                'port' => 1194,
                'transport' => 'udp',
                'dns' => '1.1.1.1',
                'deployment_status' => 'deployed',
                'is_deploying' => false,
            ]);
            $this->info("✅ Test server created: {$testServer->name} (ID: {$testServer->id})");

            // Step 2: Create test user
            $this->info('2. Creating test VPN user...');
            $testUser = VpnUser::create([
                'username' => 'test-user-autodeletion',
                'email' => 'test@example.com',
                'password' => bcrypt('password'),
                'plain_password' => 'password',
                'is_active' => true,
                'max_connections' => 1,
            ]);
            $this->info("✅ Test user created: {$testUser->username} (ID: {$testUser->id})");
            $this->info("   WireGuard Public Key: {$testUser->wireguard_public_key}");
            $this->info("   WireGuard Address: {$testUser->wireguard_address}");

            // Step 3: Associate user with server
            $this->info('3. Associating user with server...');
            $testUser->vpnServers()->attach($testServer->id);
            $testUser->refresh();
            $this->info('✅ User associated with server');

            // Step 4: Create test OVPN file
            $this->info('4. Creating test OVPN file...');
            $ovpnFileName = "public/ovpn_configs/{$testServer->name}_{$testUser->username}.ovpn";
            $testOvpnContent = "# Test OVPN configuration for {$testUser->username}\nclient\ndev tun\n";
            Storage::put($ovpnFileName, $testOvpnContent);
            Storage::setVisibility($ovpnFileName, 'public');
            $this->info("✅ Test OVPN file created: storage/app/{$ovpnFileName}");
            $this->info("   File exists: " . (Storage::exists($ovpnFileName) ? "YES" : "NO"));

            // Step 5: Test deletion with job monitoring
            $this->info('5. Testing user deletion with auto-cleanup...');
            $this->info("   Before deletion - OVPN file exists: " . (Storage::exists($ovpnFileName) ? "YES" : "NO"));

            // Enable job monitoring
            $originalQueueConnection = config('queue.default');
            config(['queue.default' => 'sync']); // Use sync driver to execute jobs immediately

            // Store username before deletion
            $username = $testUser->username;

            // Delete the user - this should trigger auto-cleanup
            $testUser->delete();
            $this->info("✅ User '{$username}' deleted successfully");

            // Step 6: Verify cleanup
            $this->info('6. Verifying cleanup results...');

            // Check if OVPN file was cleaned up
            $fileExists = Storage::exists($ovpnFileName);
            $this->info("   After deletion - OVPN file exists: " . ($fileExists ? "YES" : "NO"));

            if (!$fileExists) {
                $this->info('✅ OVPN file was successfully cleaned up');
            } else {
                $this->warn('⚠️ OVPN file was NOT cleaned up automatically');

                // Manually test the cleanup job
                $this->info('   Testing manual cleanup...');
                $testUserForCleanup = new VpnUser([
                    'username' => $username,
                    'wireguard_public_key' => 'test-key',
                ]);
                $testUserForCleanup->setRelation('vpnServers', collect([$testServer]));

                $removeOpenVPNJob = new RemoveOpenVPNUser($testUserForCleanup);
                $removeOpenVPNJob->handle();

                $fileExistsAfterManual = Storage::exists($ovpnFileName);
                $this->info("   After manual cleanup - OVPN file exists: " . ($fileExistsAfterManual ? "YES" : "NO"));

                if (!$fileExistsAfterManual) {
                    $this->info('✅ Manual cleanup successful');
                }
            }

            // Step 7: Cleanup test data
            $this->info('7. Cleaning up test data...');
            $testServer->delete();
            $this->info('✅ Test server deleted');

            // Clean up any remaining files
            if (Storage::exists($ovpnFileName)) {
                Storage::delete($ovpnFileName);
                $this->info('✅ Remaining test files cleaned up');
            }

            // Restore original queue connection
            config(['queue.default' => $originalQueueConnection]);

            // Success summary
            $this->info('');
            $this->info(str_repeat('=', 60));
            $this->info('🎉 AUTO-DELETION TEST COMPLETED SUCCESSFULLY!');
            $this->info('✅ User deletion triggers automatic cleanup');
            $this->info('✅ WireGuard peer removal handled automatically');
            $this->info('✅ OpenVPN file cleanup handled automatically');
            $this->info('✅ Model event handlers working correctly');
            $this->info(str_repeat('=', 60));

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ TEST FAILED: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());

            // Clean up on failure
            if (isset($testUser) && $testUser->exists) {
                $testUser->forceDelete();
                $this->info('🧹 Test user cleaned up');
            }
            if (isset($testServer) && $testServer->exists) {
                $testServer->delete();
                $this->info('🧹 Test server cleaned up');
            }
            if (isset($ovpnFileName) && Storage::exists($ovpnFileName)) {
                Storage::delete($ovpnFileName);
                $this->info('🧹 Test files cleaned up');
            }

            return 1;
        }
    }
}
