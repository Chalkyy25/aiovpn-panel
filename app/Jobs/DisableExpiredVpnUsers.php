<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

// server-side cleanup jobs you already have
use App\Jobs\RemoveOpenVPNUser;
use App\Jobs\RemoveWireGuardPeer;

class DisableExpiredVpnUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Fail any single attempt after 2 minutes. */
    public $timeout = 120;

    public function handle(): void
    {
        $now = now();

        // Pull in servers + current connections so we can kick cleanly
        VpnUser::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->with([
                'vpnServers:id',                               // to know where to revoke
            ])
            ->chunkById(200, function ($users) use ($now) {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user, $now) {
                        // 1) Flip the account switch
                        $user->update([
                            'is_active'      => false,
                            'deactivated_at' => $now,   // add column if you have it
                        ]);

                        // 2) Mark any live sessions as disconnected in DB
                        VpnUserConnection::where('vpn_user_id', $user->id)
                            ->where('is_connected', true)
                            ->update([
                                'is_connected'    => false,
                                'disconnected_at' => $now,
                            ]);
                    });

                    Log::info('⏰ Disabled expired VPN user', [
                        'user_id'    => $user->id,
                        'username'   => $user->username,
                        'expired_at' => optional($user->expires_at)->toDateTimeString(),
                    ]);

                    // 3) Revoke on servers so the client is kicked / can’t reconnect
                    //    OpenVPN: remove client, WG: remove peer on each server.
                    RemoveOpenVPNUser::dispatch($user);

                    foreach ($user->vpnServers as $server) {
                        // Remove peer for this user on each server (job already exists in your app)
                        RemoveWireGuardPeer::dispatch(clone $user, $server);
                    }
                }
            });
    }
}