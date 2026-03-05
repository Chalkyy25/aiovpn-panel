<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentConnections extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';
    protected static ?string $heading = 'Recent Connections';
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg'      => 2, // right 2/3 of last desktop row
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VpnConnection::query()
                    ->with(['vpnUser', 'vpnServer'])
                    ->orderByDesc('last_seen_at')
                    ->orderByDesc('updated_at')
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

                Tables\Columns\IconColumn::make('is_live')
                    ->label('Live')
                    ->boolean()
                    ->state(function (VpnConnection $c): bool {
                        if (! $c->is_active) {
                            return false;
                        }

                        $seen = $c->last_seen_at;
                        if (! $seen) {
                            return false;
                        }

                        $now = now();

                        return match (strtoupper((string) $c->protocol)) {
                            'WIREGUARD' => $seen->greaterThanOrEqualTo($now->copy()->subSeconds(VpnConnection::WIREGUARD_STALE_SECONDS)),
                            default => $seen->greaterThanOrEqualTo($now->copy()->subSeconds(VpnConnection::OPENVPN_STALE_SECONDS)),
                        };
                    })
                    ->sortable(query: function ($query, string $direction) {
                        // Basic sort: is_active first, then last_seen_at.
                        return $query
                            ->orderBy('is_active', $direction === 'asc' ? 'asc' : 'desc')
                            ->orderBy('last_seen_at', 'desc');
                    }),

                Tables\Columns\TextColumn::make('client_ip')
                    ->label('Client IP')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('connected_at')
                    ->label('Connected')
                    ->since()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_seen_at')
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
