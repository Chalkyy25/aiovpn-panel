<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnConnectionResource\Pages;
use App\Filament\Resources\VpnConnectionResource\RelationManagers;
use App\Models\VpnConnection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VpnConnectionResource extends Resource
{
    protected static ?string $model = VpnConnection::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';


public static function canCreate(): bool
{
    return false;
}

public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
{
    return false;
}

public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
{
    return false;
}


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('vpn_server_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('vpn_user_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('protocol')
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('session_key')
                    ->maxLength(191),
                Forms\Components\TextInput::make('wg_public_key')
                    ->maxLength(64),
                Forms\Components\TextInput::make('client_ip')
                    ->maxLength(128),
                Forms\Components\TextInput::make('virtual_ip')
                    ->maxLength(128),
                Forms\Components\TextInput::make('endpoint')
                    ->maxLength(128),
                Forms\Components\TextInput::make('bytes_in')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('bytes_out')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\DateTimePicker::make('connected_at'),
                Forms\Components\DateTimePicker::make('last_seen_at'),
                Forms\Components\DateTimePicker::make('disconnected_at'),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('vpn_server_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vpn_user_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('protocol')
                    ->searchable(),
                Tables\Columns\TextColumn::make('session_key')
                    ->searchable(),
                Tables\Columns\TextColumn::make('wg_public_key')
                    ->searchable(),
                Tables\Columns\TextColumn::make('client_ip')
                    ->searchable(),
                Tables\Columns\TextColumn::make('virtual_ip')
                    ->searchable(),
                Tables\Columns\TextColumn::make('endpoint')
                    ->searchable(),
                Tables\Columns\TextColumn::make('bytes_in')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bytes_out')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('connected_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('disconnected_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
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
            'index' => Pages\ListVpnConnections::route('/'),
            'create' => Pages\CreateVpnConnection::route('/create'),
            'edit' => Pages\EditVpnConnection::route('/{record}/edit'),
        ];
    }
}
