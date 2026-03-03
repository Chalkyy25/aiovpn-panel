<?php

namespace Tests\Feature;

use App\Jobs\SyncOpenVPNCredentials;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class VpnUserServerSyncDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_vpn_servers_dispatches_openvpn_sync_with_int_server_id_only_once(): void
    {
        Bus::fake();

        /** @var VpnUser $user */
        $user = VpnUser::factory()->create();

        /** @var VpnServer $server */
        $server = VpnServer::factory()->create();

        // First sync attaches server => should dispatch once
        $user->syncVpnServers([$server->id], context: 'test.first');

        // Second sync is a no-op => should NOT dispatch again
        $user->syncVpnServers([(string) $server->id], context: 'test.second');

        $this->assertTrue(
            $user->vpnServers()->whereKey($server->id)->exists(),
            'Expected vpn_server_user pivot row to exist.'
        );

        Bus::assertDispatchedTimes(SyncOpenVPNCredentials::class, 1);

        Bus::assertDispatched(SyncOpenVPNCredentials::class, function (SyncOpenVPNCredentials $job) use ($server): bool {
            return is_int($job->vpnServerId) && $job->vpnServerId === (int) $server->id;
        });
    }

    public function test_sync_openvpn_credentials_job_constructor_accepts_vpnserver_model_or_int(): void
    {
        $server = new VpnServer();
        $server->id = 123;

        $jobFromModel = new SyncOpenVPNCredentials($server);
        $this->assertSame(123, $jobFromModel->vpnServerId);

        $jobFromInt = new SyncOpenVPNCredentials(123);
        $this->assertSame(123, $jobFromInt->vpnServerId);
    }
}
