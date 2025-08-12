<?php

namespace App\Jobs;

use App\Models\VpnUser;
use App\Jobs\RemoveOpenVPNUser;
use App\Jobs\RemoveWireGuardPeer;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DisableExpiredVpnUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function handle(): void
    {
        // Find active users whose expiry is in the past
        VpnUser::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    // Flip the switch
                    $user->update(['is_active' => false]);

                    Log::info('⏰ Disabled expired VPN user', [
                        'username'   => $user->username,
                        'user_id'    => $user->id,
                        'expired_at' => optional($user->expires_at)->toDateTimeString(),
                        'is_trial'   => (bool) $user->is_trial,
                    ]);

                    // (Optional) Kick cleanup tasks
                    // If you want to immediately revoke on servers:
                    if ($user->vpnServers()->exists()) {
                        RemoveOpenVPNUser::dispatch($user);
                        foreach ($user->vpnServers as $server) {
                            RemoveWireGuardPeer::dispatch(clone $user, $server);
                        }
                    }
                }
            });
    }
}