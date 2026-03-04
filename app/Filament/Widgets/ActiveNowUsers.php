<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\VpnUserResource;
use App\Models\VpnConnection;
use App\Models\VpnUser;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ActiveNowUsers extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';
    protected static ?string $heading = 'Active Now Users';
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 2,
    ];

    public function table(Table $table): Table
    {
        $now = now();

        // 1 row per vpn_user_id with live count + most recent seen time
        $livePerUser = VpnConnection::query()
            ->live($now)
            ->select([
                'vpn_user_id',
                DB::raw('COUNT(*) as live_connections'),
                DB::raw('MAX(last_seen_at) as live_last_seen_at'),
            ])
            ->groupBy('vpn_user_id');

        return $table
            ->query(
                VpnUser::query()
                    ->joinSub($livePerUser, 'live', fn ($join) => $join->on('vpn_users.id', '=', 'live.vpn_user_id'))
                    ->select('vpn_users.*', 'live.live_connections', 'live.live_last_seen_at')
                    ->orderByDesc('live.live_last_seen_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->url(fn (VpnUser $record): string => VpnUserResource::getUrl('edit', ['record' => $record])),

                Tables\Columns\TextColumn::make('live_connections')
                    ->label('Live')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('live_last_seen_at')
                    ->label('Last Seen')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('d M Y')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('is_active')
                    ->label('Enabled')
                    ->badge()
                    ->state(fn (VpnUser $u) => $u->is_active ? 'Yes' : 'No')
                    ->color(fn (VpnUser $u) => $u->is_active ? 'success' : 'danger')
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->defaultPaginationPageOption(10);
    }
}
