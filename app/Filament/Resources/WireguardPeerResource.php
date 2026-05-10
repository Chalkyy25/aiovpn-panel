<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WireguardPeerResource\Pages;
use App\Models\WireguardPeer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WireguardPeerResource extends Resource
{
    protected static ?string $model = WireguardPeer::class;

    // Hide from sidebar navigation
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'VPN';
    protected static ?string $navigationLabel = 'WireGuard Peers';
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                Forms\Components\Select::make('vpn_server_id')
                    ->relationship('vpnServer', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Select::make('vpn_user_id')
                    ->relationship('vpnUser', 'username')
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\TextInput::make('public_key')
                    ->label('Public Key')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('ip_address')
                    ->label('Virtual IP')
                    ->required(),

                Forms\Components\TextInput::make('allowed_ips')
                    ->label('Allowed IPs'),

                Forms\Components\TextInput::make('dns')
                    ->label('DNS'),

                Forms\Components\Toggle::make('revoked')
                    ->label('Revoked'),

                Forms\Components\DateTimePicker::make('last_handshake_at')
                    ->label('Last Handshake'),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('vpnServer.name')
                    ->label('Server')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vpnUser.username')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('Virtual IP')
                    ->searchable(),

                Tables\Columns\TextColumn::make('last_handshake_at')
                    ->label('Last Handshake')
                    ->since()
                    ->sortable(),

                Tables\Columns\IconColumn::make('revoked')
                    ->label('Revoked')
                    ->boolean(),

                Tables\Columns\TextColumn::make('transfer_rx_bytes')
                    ->label('Download')
                    ->formatStateUsing(fn ($state) => self::formatBytes($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('transfer_tx_bytes')
                    ->label('Upload')
                    ->formatStateUsing(fn ($state) => self::formatBytes($state))
                    ->sortable(),

            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function formatBytes($bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWireguardPeers::route('/'),
            'create' => Pages\CreateWireguardPeer::route('/create'),
            'edit' => Pages\EditWireguardPeer::route('/{record}/edit'),
        ];
    }
}