<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseConnections extends Command
{
    protected $signature = 'vpn:diagnose-connections {--minutes=10 : Lookback window in minutes}';

    protected $description = 'Quickly diagnose whether vpn_connections or vpn_user_connections is receiving live updates.';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        if ($minutes <= 0) {
            $minutes = 10;
        }

        $since = now()->subMinutes($minutes);

        $this->line('Lookback window: last ' . $minutes . ' minute(s)');
        $this->line('Since: ' . $since->toDateTimeString());
        $this->newLine();

        $this->section('vpn_connections');
        $this->tableStatsForVpnConnections($since);
        $this->newLine();

        $this->section('vpn_user_connections');
        $this->tableStatsForVpnUserConnections($since);
        $this->newLine();

        $this->line('Tip: if only one table shows fresh updates, that is your active data source.');
        $this->line('Note: your scheduler currently has sync jobs commented out in app/Console/Kernel.php, so API event ingestion may be the main writer.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->info('== ' . $title . ' ==');
    }

    private function tableStatsForVpnConnections($since): void
    {
        if (!DB::getSchemaBuilder()->hasTable('vpn_connections')) {
            $this->warn('Table does not exist.');
            return;
        }

        $total = DB::table('vpn_connections')->count();
        $recent = DB::table('vpn_connections')->where('updated_at', '>=', $since)->count();

        $latest = DB::table('vpn_connections')
            ->select('id', 'protocol', 'vpn_server_id', 'vpn_user_id', 'is_active', 'last_seen_at', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $this->line("Total rows: {$total}");
        $this->line("Updated in window: {$recent}");

        if ($latest->isEmpty()) {
            $this->line('No rows found.');
            return;
        }

        $this->line('Latest 5 rows (by updated_at):');
        $this->table(
            ['id', 'protocol', 'server', 'user', 'active', 'last_seen_at', 'updated_at'],
            $latest->map(fn ($r) => [
                $r->id,
                $r->protocol,
                $r->vpn_server_id,
                $r->vpn_user_id,
                (int) $r->is_active,
                (string) ($r->last_seen_at ?? ''),
                (string) ($r->updated_at ?? ''),
            ])->all(),
        );
    }

    private function tableStatsForVpnUserConnections($since): void
    {
        if (!DB::getSchemaBuilder()->hasTable('vpn_user_connections')) {
            $this->warn('Table does not exist.');
            return;
        }

        $total = DB::table('vpn_user_connections')->count();
        $recent = DB::table('vpn_user_connections')->where('updated_at', '>=', $since)->count();

        $latest = DB::table('vpn_user_connections')
            ->select('id', 'protocol', 'vpn_server_id', 'vpn_user_id', 'is_connected', 'seen_at', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $this->line("Total rows: {$total}");
        $this->line("Updated in window: {$recent}");

        if ($latest->isEmpty()) {
            $this->line('No rows found.');
            return;
        }

        $this->line('Latest 5 rows (by updated_at):');
        $this->table(
            ['id', 'protocol', 'server', 'user', 'connected', 'seen_at', 'updated_at'],
            $latest->map(fn ($r) => [
                $r->id,
                $r->protocol,
                $r->vpn_server_id,
                $r->vpn_user_id,
                (int) $r->is_connected,
                (string) ($r->seen_at ?? ''),
                (string) ($r->updated_at ?? ''),
            ])->all(),
        );
    }
}
