<?php

namespace Tests\Feature;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\WireguardPeer;
use App\Services\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_returns_token_and_allows_me_endpoint(): void
    {
        $user = VpnUser::factory()->create([
            'username' => 'alice',
            'password' => Hash::make('secret'),
            'plain_password' => null,
            'is_active' => true,
            'expires_at' => now()->addDay(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'username' => 'alice',
            'password' => 'secret',
            'device_name' => 'Pixel-8',
        ]);

        $login->assertOk();
        $token = $login->json('token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $me = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me');

        $me->assertOk();
        $me->assertJsonPath('username', 'alice');
    }

    public function test_login_upgrades_plain_password_to_hashed_password(): void
    {
        $user = VpnUser::factory()->create([
            'username' => 'bob',
            'password' => null,
            'plain_password' => 'secret',
            'is_active' => true,
            'expires_at' => now()->addDay(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'username' => 'bob',
            'password' => 'secret',
        ]);

        $login->assertOk();

        $user->refresh();
        $this->assertNull($user->plain_password);
        $this->assertNotNull($user->password);
        $this->assertTrue(Hash::check('secret', $user->password));
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = VpnUser::factory()->create([
            'username' => 'carol',
            'password' => Hash::make('secret'),
            'plain_password' => null,
            'is_active' => true,
            'expires_at' => now()->addDay(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'username' => 'carol',
            'password' => 'secret',
        ]);

        $login->assertOk();
        $token = $login->json('token');

        $logout = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/logout');

        $logout->assertOk()->assertJson(['ok' => true]);

        $ping = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/ping');

        $ping->assertUnauthorized();
    }

    public function test_wg_servers_only_returns_assigned_wireguard_servers(): void
    {
        $user = VpnUser::factory()->create([
            'is_active' => true,
            'expires_at' => now()->addDay(),
        ]);

        $serverA = VpnServer::factory()->create([
            'enabled' => true,
            'wg_public_key' => 'pubkey-assigned',
            'wg_endpoint_host' => 'wg.example.com',
            'wg_port' => 51820,
        ]);

        $serverB = VpnServer::factory()->create([
            'enabled' => true,
            'wg_public_key' => 'pubkey-unassigned',
            'wg_endpoint_host' => 'wg2.example.com',
            'wg_port' => 51820,
        ]);

        Sanctum::actingAs($user, ['mobile']);

        $res = $this->getJson('/api/wg/servers');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($serverA->id, $ids);
        $this->assertContains($serverB->id, $ids);
    }

    public function test_wg_config_requires_server_assignment_and_is_mocked(): void
    {
        $user = VpnUser::factory()->create([
            'is_active' => true,
            'expires_at' => now()->addDay(),
        ]);

        $server = VpnServer::factory()->create([
            'enabled' => true,
            'wg_public_key' => 'pubkey',
            'wg_endpoint_host' => 'wg.example.com',
            'wg_port' => 51820,
        ]);

        Sanctum::actingAs($user, ['mobile']);

        // Mock the WireGuard service so no SSH/local wg commands run.

        $fakePeer = new WireguardPeer([
            'vpn_server_id' => $server->id,
            'vpn_user_id' => $user->id,
            'public_key' => 'client-pub',
            'ip_address' => '10.7.0.10',
            'allowed_ips' => '10.7.0.10/32',
            'revoked' => false,
        ]);

        $this->mock(WireGuardService::class, function ($mock) use ($server, $user, $fakePeer) {
            $mock->shouldReceive('ensurePeerForUser')
                ->once()
                ->withArgs(function ($s, $u) use ($server, $user) {
                    return (int) $s->id === (int) $server->id && (int) $u->id === (int) $user->id;
                })
                ->andReturn($fakePeer);

            $mock->shouldReceive('buildClientConfig')
                ->once()
                ->andReturn("[Interface]\nPrivateKey = test\n");
        });

        $ok = $this->get('/api/wg/config?server_id=' . $server->id);
        $ok->assertOk();
        $this->assertStringContainsString('[Interface]', $ok->getContent());
    }
}
