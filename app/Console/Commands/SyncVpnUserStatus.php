<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\VpnUser;
use Carbon\Carbon;

class SyncVpnUserStatus extends Command
{
    protected $signature = 'vpn:sync-users';
    protected $description = 'Parse OpenVPN log and update online status of VPN users';

    public function handle(): int
    {
        $logFile = '/etc/openvpn/auth/auth.log';

        if (!file_exists($logFile)) {
            Log::warning("⚠️ OpenVPN auth log not found at $logFile");
            $this->warn("Auth log not found.");
            return 1;
        }

        $logContent = file_get_contents($logFile);
        if (!$logContent) {
            $this->warn("Log file empty or unreadable.");
            return 1;
        }

        $onlineUsernames = [];

        // Match lines like: "Login OK: [username] from IP"
        preg_match_all('/Login OK: \[(.*?)]/', $logContent, $matches);

        if (!empty($matches[1])) {
            $onlineUsernames = array_unique($matches[1]);
        }

        $now = Carbon::now();

        // Reset all to offline first (if you want to)
        VpnUser::where('is_online', true)->update(['is_online' => false]);

        foreach ($onlineUsernames as $username) {
            $user = VpnUser::where('username', $username)->first();

            if ($user) {
                $user->is_online = true;
                $user->last_seen_at = $now;
                $user->save();

                Log::info("✅ User $username marked online at $now");
            }
        }

        $this->info(count($onlineUsernames) . " user(s) updated.");
        return 0;
    }
}
