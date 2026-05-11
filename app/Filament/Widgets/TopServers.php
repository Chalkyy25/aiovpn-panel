<?php

namespace App\Filament\Widgets;

use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopServers extends BaseWidget
{
    protected static ?string $heading = 'Top Servers';

    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 2,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnServer::query()
                    ->withCount('activeConnections')
                    ->orderByDesc('active_connections_count')
            )
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'wireguard' => 'success',
                        'openvpn' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('active_connections_count')
                    ->label('Users')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('Status')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Heartbeat')
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deployment_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success', 'deployed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

            ])
            ->defaultPaginationPageOption(5);
    }
}