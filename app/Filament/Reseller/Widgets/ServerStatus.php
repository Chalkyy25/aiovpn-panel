<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class ServerStatus extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';
    protected static ?string $heading = 'Server Status';
    protected static ?int $sort = 70;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 1,
    ];

    public function table(Table $table): Table
    {
        $resellerId = auth()->id();
        $now = now();

        $liveCounts = VpnConnection::query()
            ->select('vpn_server_id', DB::raw('COUNT(*) as my_live_active'))
            ->live($now)
            ->whereHas('vpnUser', fn ($q) => $q->where('client_id', $resellerId))
            ->whereNotNull('vpn_server_id')
            ->groupBy('vpn_server_id');

        return $table
            ->query(
                VpnServer::query()
                    ->where('is_online', true)
                    ->leftJoinSub($liveCounts, 'lc', fn ($join) => $join->on('vpn_servers.id', '=', 'lc.vpn_server_id'))
                    ->select('vpn_servers.*', DB::raw('COALESCE(lc.my_live_active,0) as my_live_active'))
                    ->orderBy('name')
            )
            ->defaultSort('name', 'asc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(10)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('is_online')
                    ->label('Status')
                    ->state(fn (VpnServer $record) => (bool) $record->is_online)
                    ->formatStateUsing(fn (bool $state) => $state ? 'ONLINE' : 'OFFLINE')
                    ->badge()
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('my_live_active')
                    ->label('My Live')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? strtoupper((string) $state) : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_mgmt_at')
                    ->label('Last Mgmt')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ]);
    }
}
