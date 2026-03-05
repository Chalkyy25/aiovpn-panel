<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class ConnectionsByServer extends BaseWidget
{
    protected static ?string $heading = 'Live Connections by Server';
    protected static ?string $pollingInterval = '15s';
    protected static ?int $sort = 50;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 1,
    ];

    public function table(Table $table): Table
    {
        $resellerId = auth()->id();
        $now = now();

        $liveCounts = VpnConnection::query()
            ->select('vpn_server_id', DB::raw('COUNT(*) as live_active'))
            ->live($now)
            ->whereHas('vpnUser', fn ($q) => $q->where('client_id', $resellerId))
            ->whereNotNull('vpn_server_id')
            ->groupBy('vpn_server_id');

        return $table
            ->query(
                VpnServer::query()
                    ->where('is_online', true)
                    ->leftJoinSub($liveCounts, 'lc', fn ($join) => $join->on('vpn_servers.id', '=', 'lc.vpn_server_id'))
                    ->select('vpn_servers.*', DB::raw('COALESCE(lc.live_active,0) as live_active'))
                    ->orderByDesc('live_active')
                    ->orderBy('name')
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Server')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('live_active')
                    ->label('Live')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? strtoupper((string) $state) : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_mgmt_at')
                    ->since()
                    ->label('Last Mgmt')
                    ->toggleable(),
            ]);
    }
}
