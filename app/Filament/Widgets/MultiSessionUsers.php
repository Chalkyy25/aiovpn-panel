<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\VpnUserResource;
use App\Models\VpnConnection;
use App\Models\VpnUser;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class MultiSessionUsers extends BaseWidget
{
    protected static ?string $heading = 'Multi-Session Users';

    protected static ?string $pollingInterval = '10s';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 2,
    ];

    public function table(Table $table): Table
    {
        $now = now();

        $livePerUser = VpnConnection::query()
            ->live($now)
            ->select([
                'vpn_user_id',
                DB::raw('COUNT(*) as live_connections'),
                DB::raw('MAX(last_seen_at) as live_last_seen_at'),
            ])
            ->groupBy('vpn_user_id')
            ->having('live_connections', '>', 1);

        return $table
            ->query(
                VpnUser::query()
                    ->joinSub($livePerUser, 'live', fn ($join) => $join->on('vpn_users.id', '=', 'live.vpn_user_id'))
                    ->select('vpn_users.*', 'live.live_connections', 'live.live_last_seen_at')
                    ->with([
                        'sessionConnections' => fn ($q) => $q
                            ->live($now)
                            ->select([
                                'id',
                                'vpn_user_id',
                                'vpn_server_id',
                                'protocol',
                                'client_ip',
                                'last_seen_at',
                            ])
                            ->with('vpnServer:id,name')
                            ->orderByDesc('last_seen_at'),
                    ])
                    ->orderByDesc('live.live_connections')
                    ->orderByDesc('live.live_last_seen_at')
                    ->orderBy('vpn_users.username')
            )
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->url(fn (VpnUser $record): string => VpnUserResource::getUrl('edit', ['record' => $record])),

                Tables\Columns\TextColumn::make('live_connections')
                    ->label('Connections')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TagsColumn::make('protocols')
                    ->label('Protocols')
                    ->state(function (VpnUser $u): array {
                        return $u->sessionConnections
                            ->pluck('protocol')
                            ->filter()
                            ->map(fn ($p) => strtoupper((string) $p))
                            ->unique()
                            ->values()
                            ->all();
                    })
                    ->separator(', '),

                Tables\Columns\TagsColumn::make('servers')
                    ->label('Servers')
                    ->state(function (VpnUser $u): array {
                        return $u->sessionConnections
                            ->map(fn ($conn): ?string => $conn->vpnServer?->name)
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();
                    })
                    ->separator(', '),

                Tables\Columns\TagsColumn::make('client_ips')
                    ->label('Client IPs')
                    ->state(function (VpnUser $u): array {
                        return $u->sessionConnections
                            ->pluck('client_ip')
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();
                    })
                    ->separator(', '),
            ])
            ->defaultPaginationPageOption(10);
    }
}
