<?php

namespace App\Filament\Widgets;

use App\Models\VpnServer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ConnectionsByServer extends BaseWidget
{
    protected static ?string $heading = 'Connections by Server';
    protected static ?string $pollingInterval = '15s';

    // Makes it sit nicely next to ServerStatus on desktop
    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnServer::query()->orderByDesc('online_users')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Server')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('online_users')
                    ->label('Active')
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('protocol')
                    ->label('Protocol')
                    ->badge(),
            ])
            ->paginated(false);
    }
}
