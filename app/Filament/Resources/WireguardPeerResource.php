<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WireguardPeerResource\Pages;
use App\Filament\Resources\WireguardPeerResource\RelationManagers;
use App\Models\WireguardPeer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WireguardPeerResource extends Resource
{
    protected static ?string $model = WireguardPeer::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('vpn_server_id')
                    ->required()
                    ->numeric(),
                Forms\Components\Select::make('vpn_user_id')
                    ->relationship('vpnUser', 'id')
                    ->required(),
                Forms\Components\TextInput::make('public_key')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('preshared_key')
                    ->maxLength(255),
                Forms\Components\Textarea::make('private_key_encrypted')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('ip_address')
                    ->required()
                    ->maxLength(45),
                Forms\Components\TextInput::make('allowed_ips')
                    ->maxLength(255),
                Forms\Components\TextInput::make('dns')
                    ->maxLength(255),
                Forms\Components\Toggle::make('revoked')
                    ->required(),
                Forms\Components\DateTimePicker::make('last_handshake_at'),
                Forms\Components\TextInput::make('transfer_rx_bytes')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('transfer_tx_bytes')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('vpn_server_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vpnUser.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('public_key')
                    ->searchable(),
                Tables\Columns\TextColumn::make('preshared_key')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('allowed_ips')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dns')
                    ->searchable(),
                Tables\Columns\IconColumn::make('revoked')
                    ->boolean(),
                Tables\Columns\TextColumn::make('last_handshake_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('transfer_rx_bytes')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('transfer_tx_bytes')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
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
