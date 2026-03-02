<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\Resources\VpnUserResource\Pages;
use App\Models\VpnUser;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VpnUserResource extends Resource
{
    protected static ?string $model = VpnUser::class;

    protected static ?string $navigationIcon  = 'heroicon-o-key';
    protected static ?string $navigationLabel = 'VPN Users';
    protected static ?string $navigationGroup = 'VPN';
    protected static ?int $navigationSort     = 10;

    protected static ?string $modelLabel      = 'VPN User';
    protected static ?string $pluralModelLabel = 'VPN Users';

    public static function form(Form $form): Form
    {
        // Your create/edit pages already define the form, so keep this minimal
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('vpnServer.name')
                    ->label('Server')
                    ->badge()
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('max_connections')
                    ->label('Max')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->sortable()
                    ->color(fn ($record) =>
                        $record->expires_at && $record->expires_at->isPast()
                            ? 'danger'
                            : 'success'
                    ),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->searchPlaceholder('Search username / server...')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),

                Tables\Filters\SelectFilter::make('vpn_server_id')
                    ->label('Server')
                    ->relationship('vpnServer', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->paginated([10, 25, 50]);
    }

    /**
     * OPTIONAL BUT RECOMMENDED:
     * If resellers should only see their own VPN users, lock it here.
     * Adjust the field name if yours is different (created_by / reseller_id / user_id).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('created_by', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVpnUsers::route('/'),
            'create' => Pages\CreateVpnUser::route('/create'),
            'edit'   => Pages\EditVpnUser::route('/{record}/edit'),
        ];
    }
}