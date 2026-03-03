<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\Resources\VpnServerResource\Pages;
use App\Models\VpnServer;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VpnServerResource extends Resource
{
    protected static ?string $model = VpnServer::class;

    protected static ?string $navigationIcon  = 'heroicon-o-server-stack';
    protected static ?string $navigationLabel = 'Servers';
    protected static ?string $navigationGroup = null;
    protected static ?int    $navigationSort  = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Read-only list of available servers. Intentionally does not expose admin-only fields.
        // Resellers should only see servers that are currently online.
        return parent::getEloquentQuery()
            ->where('is_online', true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Server')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('display_location')
                    ->label('Location')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('protocol')
                    ->label('Protocol')
                    ->badge()
                    ->state(fn (VpnServer $s) => strtoupper((string) $s->protocol))
                    ->color(fn (VpnServer $s) => $s->isWireGuard() ? 'warning' : 'info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('endpoint')
                    ->label('Port')
                    ->state(function (VpnServer $s): string {
                        $port = $s->displayPort();
                        $transport = $s->displayTransport();

                        if ($s->isWireGuard()) {
                            return "WG :{$port}";
                        }

                        return strtoupper((string) ($transport ?: 'udp')) . " :{$port}";
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (VpnServer $s) => $s->is_online ? 'Online' : 'Offline')
                    ->color(fn (VpnServer $s) => $s->is_online ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('online_users')
                    ->label('Users')
                    ->badge()
                    ->color('gray')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_sync_at')
                    ->label('Last Sync')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVpnServers::route('/'),
        ];
    }
}
