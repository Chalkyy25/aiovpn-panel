<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ConnectionsByServer extends BaseWidget
{
    protected static ?string $heading = 'Live Connections by Server';
    protected static ?string $pollingInterval = '15s';
    protected int|string|array $columnSpan = 1;

    private const OPENVPN_STALE_SECONDS = 120;
    private const WIREGUARD_STALE_SECONDS = 300;

    public function table(Table $table): Table
    {
        $now = now();
        $ovpnCutoff = $now->copy()->subSeconds(self::OPENVPN_STALE_SECONDS);
        $wgCutoff   = $now->copy()->subSeconds(self::WIREGUARD_STALE_SECONDS);

        // Subquery counts only LIVE connections per server
        $liveCounts = VpnConnection::query()
            ->select('vpn_server_id', DB::raw('COUNT(*) as live_active'))
            ->where('is_active', 1)
            ->whereNotNull('last_seen_at')
            ->where(function ($q) use ($ovpnCutoff, $wgCutoff) {
                $q->where(function ($q) use ($ovpnCutoff) {
                    $q->where('protocol', 'OPENVPN')->where('last_seen_at', '>=', $ovpnCutoff);
                })->orWhere(function ($q) use ($wgCutoff) {
                    $q->where('protocol', 'WIREGUARD')->where('last_seen_at', '>=', $wgCutoff);
                });
            })
            ->groupBy('vpn_server_id');

        return $table
            ->query(
                VpnServer::query()
                    ->leftJoinSub($liveCounts, 'lc', fn ($join) => $join->on('vpn_servers.id', '=', 'lc.vpn_server_id'))
                    ->select('vpn_servers.*', DB::raw('COALESCE(lc.live_active,0) as live_active'))
                    ->orderByDesc('live_active')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Server')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('live_active')->label('Live')->badge()->sortable(),
                Tables\Columns\TextColumn::make('protocol')->badge(),
                Tables\Columns\TextColumn::make('last_mgmt_at')->since()->label('Last Mgmt')->toggleable(),
            ])
            ->paginated(false);
    }
}