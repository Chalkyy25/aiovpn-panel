<?php

namespace App\Filament\Widgets;

use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ServerStatus extends BaseWidget
{
    protected static ?string $heading = 'VPN Network Status';

    protected static ?string $pollingInterval = '10s';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnServer::query()
                    ->orderByDesc('is_online')
                    ->orderBy('name')
            )

            ->columns([

                /*
                |--------------------------------------------------------------------------
                | SERVER
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('name')
                    ->label('Server')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                /*
                |--------------------------------------------------------------------------
                | LOCATION
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('location')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                /*
                |--------------------------------------------------------------------------
                | STATUS
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (VpnServer $record) => $record->is_online)
                    ->formatStateUsing(fn (bool $state) =>
                        $state ? 'ONLINE' : 'OFFLINE'
                    )
                    ->badge()
                    ->color(fn (bool $state) =>
                        $state ? 'success' : 'danger'
                    ),

                /*
                |--------------------------------------------------------------------------
                | USERS
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('online_users')
                    ->label('Users')
                    ->badge()
                    ->sortable()
                    ->color(fn ($state) =>
                        $state > 0 ? 'success' : 'gray'
                    ),

                /*
                |--------------------------------------------------------------------------
                | PROTOCOL
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) =>
                        strtoupper($state ?? 'unknown')
                    )
                    ->color(fn (?string $state) =>
                        match ($state) {
                            'wireguard' => 'success',
                            'openvpn'   => 'warning',
                            default     => 'gray',
                        }
                    ),

                /*
                |--------------------------------------------------------------------------
                | HEARTBEAT
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('last_mgmt_at')
                    ->label('Heartbeat')
                    ->since()
                    ->sortable()
                    ->color(function ($state) {

                        if (! $state) {
                            return 'danger';
                        }

                        return now()->diffInSeconds($state) < 30
                            ? 'success'
                            : 'danger';
                    }),

                /*
                |--------------------------------------------------------------------------
                | DEPLOYMENT
                |--------------------------------------------------------------------------
                */

                Tables\Columns\TextColumn::make('deployment_status')
                    ->label('Deploy')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) =>
                        strtoupper($state ?? 'UNKNOWN')
                    )
                    ->colors([
                        'warning' => 'queued',
                        'info'    => 'running',
                        'success' => ['success', 'deployed'],
                        'danger'  => 'failed',
                        'gray'    => 'pending',
                    ]),

            ])

            ->actions([

                Tables\Actions\Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->label('View')
                    ->url(fn (VpnServer $record) =>
                        static::getResource()::getUrl('edit', [
                            'record' => $record,
                        ])
                    ),

            ])

            ->paginated([10, 25, 50])

            ->defaultPaginationPageOption(10)

            ->striped()

            ->defaultSort('is_online', 'desc');
    }
}