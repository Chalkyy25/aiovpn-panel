<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RealtimeConnectionFeed extends BaseWidget
{
    protected static ?string $heading = 'Realtime Connection Feed';

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg'      => 2,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnConnection::query()
                    ->with(['vpnUser', 'vpnServer'])
                    ->where('is_active', 1)
                    ->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '>=', now()->subMinutes(5))
                    ->latest('last_seen_at')
            )
            ->columns([

                Tables\Columns\TextColumn::make('vpnUser.username')
                    ->label('User')
                    ->searchable()
                    ->weight('bold')
                    ->placeholder('Unknown'),

                Tables\Columns\TextColumn::make('vpnServer.name')
                    ->label('Server')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Unknown'),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->color(fn (string $state): string => match (strtoupper($state)) {
                        'WIREGUARD' => 'success',
                        'OPENVPN'   => 'warning',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('client_ip')
                    ->label('Client IP')
                    ->copyable()
                    ->placeholder('N/A'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Live')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->since()
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(10);
    }
}