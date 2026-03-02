<?php

namespace App\Filament\Widgets;

use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ServerStatus extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';
    protected static ?string $heading = 'Server Status';
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(VpnServer::query()->orderBy('name'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('is_online')
                    ->label('Status')
                    ->state(fn (VpnServer $record) => (bool) $record->is_online)
                    ->formatStateUsing(fn (bool $state) => $state ? 'ONLINE' : 'OFFLINE')
                    ->badge()
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('online_users')
                    ->label('Users')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_mgmt_at')
                    ->label('Last Mgmt')
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('deployment_status')
                    ->label('Deploy')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name', 'asc')
            ->defaultPaginationPageOption(10);
    }
}
