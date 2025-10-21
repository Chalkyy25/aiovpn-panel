<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VpnUser;
use App\Jobs\AddWireGuardPeer;
use App\Jobs\RemoveWireGuardPeer;

class WgRepairKeys extends Command
{
    protected $signature = 'wg:repair-keys 
                            {--push : Re-sync peers after fixing} 
                            {--generate : Generate keypair/address for users missing a private key}
                            {--cleanup : Remove stale peers that used the old public key}';

    protected $description = 'Repair WireGuard keys: derive/correct public keys from private; optionally generate missing keys and remove stale peers';

    public function handle(): int
    {
        $fixed   = 0;
        $created = 0;
        $pushed  = 0;
        $cleaned = 0;

        // 1) Users WITH private keys: ensure public matches derived
        VpnUser::query()
            ->whereNotNull('wireguard_private_key')
            ->chunkById(200, function ($users) use (&$fixed, &$pushed, &$cleaned) {
                foreach ($users as $user) {
                    $oldPub = $user->wireguard_public_key ?: '';
                    $derived = VpnUser::wgPublicFromPrivate($user->wireguard_private_key);

                    if (!$derived) {
                        $this->warn("⚠️  {$user->username}: could not derive from private key, skipping");
                        continue;
                    }

                    if ($oldPub !== $derived) {
                        $this->info("🔧 Fixing {$user->username}");
                        if ($oldPub) {
                            $this->line("    Old: {$oldPub}");
                        }
                        $this->line("    New: {$derived}");

                        $user->wireguard_public_key = $derived;
                        $user->saveQuietly();
                        $fixed++;

                        // Remove stale peers that still reference old public key
                        if ($this->option('cleanup') && $oldPub) {
                            if ($user->vpnServers()->exists()) {
                                $ghost = clone $user;
                                $ghost->wireguard_public_key = $oldPub; // target old peer
                                foreach ($user->vpnServers as $server) {
                                    RemoveWireGuardPeer::dispatch(clone $ghost, $server);
                                    $cleaned++;
                                }
                            }
                        }

                        if ($this->option('push')) {
                            AddWireGuardPeer::dispatch($user);
                            $pushed++;
                        }
                    }
                }
            });

        // 2) Users MISSING private key: optionally generate full materials
        if ($this->option('generate')) {
            VpnUser::query()
                ->whereNull('wireguard_private_key')
                ->chunkById(200, function ($users) use (&$created, &$pushed) {
                    foreach ($users as $user) {
                        $keys = VpnUser::generateWireGuardKeys();
                        $user->wireguard_private_key = $keys['private'];
                        $user->wireguard_public_key  = $keys['public'];

                        // assign /32 if missing
                        if (blank($user->wireguard_address)) {
                            do {
                                $last = random_int(2, 254);
                                $ip = "10.66.66.$last/32";
                            } while (VpnUser::where('wireguard_address', $ip)->exists());
                            $user->wireguard_address = $ip;
                        }

                        $user->saveQuietly();
                        $this->info("🔑 Generated WG keys for {$user->username} ({$user->wireguard_address})");
                        $created++;

                        if ($this->option('push')) {
                            AddWireGuardPeer::dispatch($user);
                            $pushed++;
                        }
                    }
                });
        }

        $this->info("✅ Done. Fixed={$fixed}, Generated={$created}, Pushed={$pushed}" . ($this->option('cleanup') ? ", Cleaned={$cleaned}" : ''));
        return self::SUCCESS;
    }
}