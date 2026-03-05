<?php

namespace App\Filament\Reseller\Widgets;

use App\Models\VpnConnection;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentConnections extends BaseWidget
{
    protected static ?int $sort = 60;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'xl' => 2,
    ];

    public function table(Table $table): Table
    {
        $resellerId = auth()->id();

        return $table
            ->heading('Recent Connections')
            ->query(
                VpnConnection::query()
                    ->whereHas('vpnUser', fn (Builder $q) => $q->where('client_id', $resellerId))
                    ->latest('last_seen_at')
            )
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('vpnUser.username')
                    ->label('User')
                    ->searchable(),
                Tables\Columns\TextColumn::make('vpnServer.name')
                    ->label('Server')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->label('Config')
                    ->formatStateUsing(fn ($state) => $state ? strtoupper((string) $state) : '—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('client_ip')
                    ->label('Client IP')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
