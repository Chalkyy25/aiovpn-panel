<?php

namespace App\Filament\Widgets;

use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class NodeHealth extends BaseWidget
{
    protected static ?string $heading = 'Node Health';

    protected static ?int $sort = 6;

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'xl'      => 2,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnServer::query()
                    ->withCount('activeConnections')
                    ->orderByDesc('is_online')
                    ->orderBy('name')
            )
            ->columns([

                Tables\Columns\TextColumn::make('name')
                    ->label('Server')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('cpu_usage')
                    ->label('CPU')
                    ->suffix('%')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 85 => 'danger',
                        $state >= 60 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('memory_usage')
                    ->label('RAM')
                    ->suffix('%')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 90 => 'danger',
                        $state >= 70 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('load_average')
                    ->label('Load')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state >= 4 => 'danger',
                        $state >= 2 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('active_connections_count')
                    ->label('Users')
                    ->badge()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_mgmt_at')
                    ->label('Heartbeat')
                    ->since()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}