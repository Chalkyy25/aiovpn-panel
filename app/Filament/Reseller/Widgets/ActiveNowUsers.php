<?php

namespace App\Filament\Reseller\Widgets;

use App\Filament\Reseller\Resources\VpnUserResource;
use App\Models\VpnConnection;
use App\Models\VpnUser;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\DB;

class ActiveNowUsers extends BaseWidget
{
    protected static ?int $sort = 20;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 2,
    ];

    public function table(Table $table): Table
    {
        $resellerId = auth()->id();
        $now = now();

        // 1 row per vpn_user_id with live count + most recent seen time
        $livePerUser = VpnConnection::query()
            ->live($now)
            ->whereHas('vpnUser', fn ($q) => $q->where('client_id', $resellerId))
            ->select([
                'vpn_user_id',
                DB::raw('COUNT(*) as live_connections'),
                DB::raw('MIN(connected_at) as live_connected_at'),
                DB::raw('MAX(last_seen_at) as live_last_seen_at'),
            ])
            ->groupBy('vpn_user_id');

        return $table
            ->heading('Active Now Users')
            ->query(
                VpnUser::query()
                    ->where('client_id', $resellerId)
                    ->joinSub($livePerUser, 'live', fn ($join) => $join->on('vpn_users.id', '=', 'live.vpn_user_id'))
                    ->select('vpn_users.*', 'live.live_connections', 'live.live_connected_at', 'live.live_last_seen_at')
                    ->with([
                        'sessionConnections' => fn ($q) => $q
                            ->live($now)
                            ->with('vpnServer:id,name,country_code'),
                    ])
                    // Longest-connected first: oldest connected_at first (NULLs last)
                    ->orderByRaw('live.live_connected_at IS NULL')
                    ->orderBy('live.live_connected_at')
                    ->orderByDesc('live.live_last_seen_at')
            )
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->url(fn (VpnUser $record): string => VpnUserResource::getUrl('edit', ['record' => $record])),

                Tables\Columns\TagsColumn::make('active_protocols')
                    ->label('Config')
                    ->state(function (VpnUser $u): array {
                        $protocols = $u->sessionConnections
                            ->pluck('protocol')
                            ->filter()
                            ->map(fn ($p) => strtoupper((string) $p))
                            ->unique()
                            ->values();

                        return $protocols->all();
                    })
                    ->separator(', '),

                Tables\Columns\TagsColumn::make('active_countries')
                    ->label('Country')
                    ->state(function (VpnUser $u): array {
                        $countries = $u->sessionConnections
                            ->map(function ($conn): ?string {
                                $server = $conn->vpnServer;
                                if (!$server) return null;

                                $name = $server->country_name;
                                if (filled($name)) {
                                    return (string) $name;
                                }

                                $code = strtoupper((string) ($server->country_code ?? ''));
                                return $code !== '' ? $code : null;
                            })
                            ->filter()
                            ->unique()
                            ->values();

                        return $countries->all();
                    })
                    ->separator(', '),

                Tables\Columns\TextColumn::make('live_connections')
                    ->label('Live')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('online_since')
                    ->label('Online Since')
                    ->state(function (VpnUser $u) {
                        $fromJoin = $u->getAttribute('live_connected_at');
                        if ($fromJoin) {
                            return $fromJoin;
                        }

                        $first = $u->sessionConnections
                            ->filter(fn ($c) => $c->connected_at !== null)
                            ->sortBy('connected_at')
                            ->first();

                        return $first?->connected_at;
                    })
                    ->since()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('live_last_seen_at')
                    ->label('Last Seen')
                    ->since()
                    ->sortable(),
            ]);
    }
}
