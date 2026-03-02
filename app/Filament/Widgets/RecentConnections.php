<?php

namespace App\Filament\Widgets;

use App\Models\VpnUserConnection;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentConnections extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';
    protected static ?string $heading = 'Recent Connections';
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
    'default' => 1, // full width on mobile
    'lg'      => 2, // wider on desktop
];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnUserConnection::query()
                    ->with(['vpnUser', 'vpnServer'])
                    ->latest('updated_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('vpnUser.username')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vpnServer.name')
                    ->label('Server')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_connected')
                    ->label('Connected')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('client_ip')
                    ->label('Client IP')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('seen_at')
                    ->label('Seen')
                    ->since()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(10);
    }
}
